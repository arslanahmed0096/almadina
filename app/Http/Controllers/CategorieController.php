<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class CategorieController extends BaseController
{
    /**
     * GET /categories
     * Query params: page, limit, SortField, SortType, search
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Category::class);

        // Inputs with sane defaults
        $page = (int) $request->input('page', 1);
        $perPage = (string) $request->input('limit', '10');  // may be "-1"
        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->input('search', ''));

        // Whitelist fields to avoid SQL injection on orderBy
        $allowedSort = ['id', 'name', 'code', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortField, $allowedSort, true)) {
            $sortField = 'id';
        }

        $query = Category::query()
            ->whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            });

        // Total before paging (used by vue-good-table)
        $totalRows = (clone $query)->count();

        // Handle "show all" case
        if ($perPage === '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        } else {
            $perPage = max(1, (int) $perPage);
        }

        $offSet = ($page * $perPage) - $perPage;

        if (in_array($sortField, ['name', 'code'], true)) {
            $query->orderByRaw('LOWER(' . $sortField . ') ' . $sortType);
        } else {
            $query->orderBy($sortField, $sortType);
        }

        $categories = $query
            ->offset($offSet)
            ->limit($perPage)
            ->get();

        return response()->json([
            'categories' => $categories,
            'totalRows' => $totalRows,
        ]);
    }

    /**
     * POST /categories
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Category::class);

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'code' => 'required|string|max:190',
            'icon' => 'nullable|string|max:64',
            'status' => 'nullable|boolean',
        ]);

        $category = Category::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
            'status' => array_key_exists('status', $validated) ? (int) $validated['status'] : 1,
        ]);

        return response()->json([
            'success'  => true,
            'category' => $category,
        ], 201);
    }

    /**
     * GET /categories/{id}
     */
    public function show($id)
    {
        $category = Category::whereNull('deleted_at')->findOrFail($id);

        return response()->json($category);
    }

    /**
     * PUT /categories/{id}
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Category::class);

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'code' => 'required|string|max:190',
            'icon' => 'nullable|string|max:64',
            'status' => 'nullable|boolean',
        ]);

        Category::whereId($id)->update([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'icon' => $validated['icon'] ?? null,
            'status' => array_key_exists('status', $validated) ? (int) $validated['status'] : 1,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /categories/{id}
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);

        Category::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * POST /categories/delete/by_selection
     * Body: { selectedIds: [1,2,3] }
     */
    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Category::class);

        $selectedIds = (array) $request->input('selectedIds', []);
        if (empty($selectedIds)) {
            return response()->json(['success' => true]); // nothing to do
        }

        Category::whereIn('id', $selectedIds)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Category::class);

        $v = Validator::make($request->all(), [
            'categories' => 'required|file|mimes:xls,xlsx|max:20480',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $rows = Excel::toArray([], $request->file('categories'));
        $sheet = $rows[0] ?? [];

        if (empty($sheet)) {
            return response()->json([
                'status' => false,
                'errors' => ['No data found in the uploaded file.'],
            ], 422);
        }

        $first = $sheet[0] ?? [];
        $assocInput = is_array($first) && count(array_filter(array_keys($first), 'is_string')) > 0;

        $normalized = [];
        if ($assocInput) {
            foreach ($sheet as $row) {
                $normalized[] = $this->normalizeCategoryImportRow($row);
            }
            $lineBase = 1;
        } else {
            $header = array_map(function ($value) {
                return $this->normalizeCategoryImportKey((string) $value);
            }, $first);

            for ($i = 1; $i < count($sheet); $i++) {
                $row = $sheet[$i];
                $assoc = [];

                foreach ($header as $idx => $key) {
                    $assoc[$key] = $row[$idx] ?? null;
                }

                $normalized[] = $this->normalizeCategoryImportRow($assoc);
            }
            $lineBase = 2;
        }

        $normalized = array_values(array_filter($normalized, function ($row) {
            if (! is_array($row)) {
                return false;
            }

            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        if (empty($normalized)) {
            return response()->json([
                'status' => false,
                'errors' => ['No valid rows were found in the uploaded file.'],
            ], 422);
        }

        $errors = [];
        $prepared = [];
        $seenNames = [];

        foreach ($normalized as $i => $row) {
            $line = $i + $lineBase;
            $name = trim((string) ($row['name'] ?? ''));
            $statusValue = $row['status'] ?? 1;
            $status = $this->normalizeCategoryStatus($statusValue);

            if ($name === '') {
                $errors[] = "Row {$line}: category name is required.";
            }

            if ($status === null) {
                $errors[] = "Row {$line}: status must be active/inactive, yes/no, or 1/0.";
            }

            if ($name === '' || $status === null) {
                continue;
            }

            $nameKey = mb_strtolower($name, 'UTF-8');

            if (isset($seenNames[$nameKey])) {
                $errors[] = "Row {$line}: duplicate category name \"{$name}\" found in file.";
                continue;
            }

            $seenNames[$nameKey] = true;

            $prepared[] = [
                'name' => $name,
                'status' => $status,
            ];
        }

        if (! empty($errors)) {
            return response()->json([
                'status' => false,
                'errors' => $errors,
            ], 422);
        }

        $created = 0;
        $updated = 0;
        $restored = 0;

        DB::transaction(function () use ($prepared, &$created, &$updated, &$restored) {
            foreach ($prepared as $row) {
                $category = Category::withTrashed()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'], 'UTF-8')])
                    ->first();

                if ($category) {
                    $wasDeleted = $category->deleted_at !== null;
                    $category->name = $row['name'];
                    if (! $category->code) {
                        $category->code = $this->generateUniqueCategoryCode($row['name'], $category->id);
                    }
                    $category->status = $row['status'];
                    if ($wasDeleted) {
                        $category->deleted_at = null;
                        $restored++;
                    }
                    $category->save();
                    $updated++;
                } else {
                    Category::create([
                        'name' => $row['name'],
                        'code' => $this->generateUniqueCategoryCode($row['name']),
                        'status' => $row['status'],
                    ]);
                    $created++;
                }
            }
        }, 10);

        return response()->json([
            'status' => true,
            'imported' => count($prepared),
            'created' => $created,
            'updated' => $updated,
            'restored' => $restored,
            'warnings' => [],
        ]);
    }

    private function normalizeCategoryImportKey(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
        $value = trim($value, '_');

        $map = [
            'category' => 'name',
            'category_name' => 'name',
            'name_category' => 'name',
            'category_code' => 'code',
            'status_flag' => 'status',
            'is_active' => 'status',
        ];

        return $map[$value] ?? $value;
    }

    private function normalizeCategoryImportRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeCategoryImportKey((string) $key)] = $value;
        }

        return $normalized;
    }

    private function normalizeCategoryStatus($value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return 1;
        }

        $normalized = mb_strtolower(trim((string) $value), 'UTF-8');

        if (in_array($normalized, ['1', 'true', 'yes', 'active', 'enabled'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'inactive', 'disabled'], true)) {
            return 0;
        }

        return null;
    }

    private function generateUniqueCategoryCode(string $name, ?int $ignoreId = null): string
    {
        $sanitized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name));
        $base = substr($sanitized, 0, 3);

        if ($base === '') {
            $base = 'CAT';
        }

        $candidate = $base;
        $suffix = 1;

        while ($this->categoryCodeExists($candidate, $ignoreId)) {
            $candidate = $base . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            $suffix++;
        }

        return $candidate;
    }

    private function categoryCodeExists(string $code, ?int $ignoreId = null): bool
    {
        return Category::withTrashed()
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code, 'UTF-8')])
            ->exists();
    }
}
