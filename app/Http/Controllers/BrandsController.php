<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\ImageManagerStatic as Image;
use Maatwebsite\Excel\Facades\Excel;

class BrandsController extends Controller
{
    // ------------ GET ALL Brands -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Brand::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;

        $brands = Brand::where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $brands->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $brands = $brands->offset($offSet)
            ->limit($perPage);

        if (in_array($order, ['name', 'description'], true)) {
            $brands->orderByRaw('LOWER('.$order.') '.$dir);
        } else {
            $brands->orderBy($order, $dir);
        }

        $brands = $brands->get();

        return response()->json([
            'brands' => $brands,
            'totalRows' => $totalRows,
        ]);

    }

    // ---------------- STORE NEW Brand -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Brand::class);

        request()->validate([
            'name' => 'required',
        ]);

        $createdBrand = \DB::transaction(function () use ($request) {

            if ($request->hasFile('image')) {

                $image = $request->file('image');
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));

            } else {
                $filename = 'no-image.png';
            }

            $Brand = new Brand;

            $Brand->name = $request['name'];
            $Brand->description = $request['description'];
            $Brand->image = $filename;
            $Brand->save();

            return $Brand; // Return the created brand
        }, 10);

        return response()->json([
            'success' => true,
            'brand' => $createdBrand
        ], 201);

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ---------------- UPDATE Brand -------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Brand::class);

        request()->validate([
            'name' => 'required',
        ]);
        \DB::transaction(function () use ($request, $id) {
            $Brand = Brand::findOrFail($id);
            $currentImage = $Brand->image;

            if ($currentImage && $request->image != $currentImage) {
                $image = $request->file('image');
                $path = public_path().'/images/brands';
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));

                $BrandImage = $path.'/'.$currentImage;
                if (file_exists($BrandImage)) {
                    if ($currentImage != 'no-image.png') {
                        @unlink($BrandImage);
                    }
                }
            } elseif (! $currentImage && $request->image != 'null') {
                $image = $request->file('image');
                $path = public_path().'/images/brands';
                $filename = rand(11111111, 99999999).$image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(200, 200);
                $image_resize->save(public_path('/images/brands/'.$filename));
            } else {
                $filename = $currentImage ? $currentImage : 'no-image.png';
            }

            Brand::whereId($id)->update([
                'name' => $request['name'],
                'description' => $request['description'],
                'image' => $filename,
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Brand -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);

        Brand::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $brand_id) {
            Brand::whereId($brand_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);

    }

    // -------------- Import Brands from Excel ---------------\\
    public function import(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Brand::class);

        $v = Validator::make($request->all(), [
            'brands' => 'required|file|mimes:xls,xlsx|max:20480',
        ]);

        if ($v->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $v->errors()->all(),
            ], 422);
        }

        $rows = Excel::toArray([], $request->file('brands'));
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
                $normalized[] = $this->normalizeBrandImportRow($row);
            }
            $lineBase = 1;
        } else {
            $header = array_map(function ($value) {
                return $this->normalizeBrandImportKey((string) $value);
            }, $first);

            for ($i = 1; $i < count($sheet); $i++) {
                $row = $sheet[$i];
                $assoc = [];

                foreach ($header as $idx => $key) {
                    $assoc[$key] = $row[$idx] ?? null;
                }

                $normalized[] = $this->normalizeBrandImportRow($assoc);
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
            $description = trim((string) ($row['description'] ?? ''));

            if ($name === '') {
                $errors[] = "Row {$line}: name is required.";
                continue;
            }

            $nameKey = mb_strtolower($name, 'UTF-8');
            if (isset($seenNames[$nameKey])) {
                $errors[] = "Row {$line}: duplicate brand name \"{$name}\" found in file.";
                continue;
            }

            $seenNames[$nameKey] = true;
            $prepared[] = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
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
                $brand = Brand::withTrashed()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'], 'UTF-8')])
                    ->first();

                if ($brand) {
                    $wasDeleted = $brand->deleted_at !== null;
                    if ($row['description'] !== null) {
                        $brand->description = $row['description'];
                    }
                    if (! $brand->image) {
                        $brand->image = 'no-image.png';
                    }
                    if ($wasDeleted) {
                        $brand->deleted_at = null;
                        $restored++;
                    }
                    $brand->save();
                    $updated++;
                } else {
                    Brand::create([
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'image' => 'no-image.png',
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

    private function normalizeBrandImportKey(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
        $value = trim($value, '_');

        $map = [
            'brand' => 'name',
            'brand_name' => 'name',
            'title' => 'name',
            'desc' => 'description',
            'details' => 'description',
        ];

        return $map[$value] ?? $value;
    }

    private function normalizeBrandImportRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeBrandImportKey((string) $key)] = $value;
        }

        return $normalized;
    }
}
