<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\UserWarehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductHistoryController extends BaseController
{
    private const TYPES = [
        'product_created',
        'purchase',
        'purchase_return',
        'sale',
        'sale_return',
        'shipment',
        'stock_adjustment',
        'opening_stock',
        'transfer',
        'damage',
        'pricing',
        'quotation',
        'service_job',
    ];

    public function index(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Product::class);
        $permissions = $user->effectivePermissionNames();
        $allowedTypes = $this->allowedTypes($permissions, $user->isSuperAdmin());
        $canViewPricing = $user->isSuperAdmin() || $permissions->contains('pricing_level_view');
        $canViewCost = $user->isSuperAdmin()
            || $permissions->contains('products_cost_view')
            || $canViewPricing;

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in($allowedTypes)],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        $product = Product::query()
            ->visibleTo($user)
            ->whereNull('products.deleted_at')
            ->with(['unit:id,ShortName', 'category:id,name', 'brand:id,name', 'variants' => function ($query) {
                $query->whereNull('deleted_at')->orderBy('name');
            }])
            ->findOrFail($id);

        $warehouseIds = $user->is_all_warehouses
            ? null
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($value) => (int) $value)->all();
        $recordUserId = $user->hasRecordView() ? null : (int) $user->id;

        $queries = array_intersect_key(
            $this->historyQueries($product, $warehouseIds, $recordUserId),
            array_flip($allowedTypes)
        );
        if (! empty($validated['type'])) {
            $queries = array_filter(
                $queries,
                fn ($key) => $key === $validated['type'],
                ARRAY_FILTER_USE_KEY
            );
        }

        $historyUnion = $this->unionQueries(array_values($queries));
        $history = DB::query()->fromSub($historyUnion, 'product_history');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $history->where(function ($query) use ($search) {
                $query->where('reference', 'like', "%{$search}%")
                    ->orWhere('warehouse_name', 'like', "%{$search}%")
                    ->orWhere('destination_warehouse_name', 'like', "%{$search}%")
                    ->orWhere('party_name', 'like', "%{$search}%")
                    ->orWhere('performed_by', 'like', "%{$search}%")
                    ->orWhere('variant_name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }
        if (! empty($validated['from'])) {
            $history->whereDate('occurred_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $history->whereDate('occurred_at', '<=', $validated['to']);
        }

        $totalRows = (clone $history)->count();
        $limit = (int) ($validated['limit'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);
        $rows = $history
            ->orderByDesc('occurred_at')
            ->orderByDesc('source_id')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->formatRow($row, $canViewCost))
            ->values();

        $allQueries = array_intersect_key(
            $this->historyQueries($product, $warehouseIds, $recordUserId),
            array_flip($allowedTypes)
        );
        $allEvents = DB::query()->fromSub(
            $this->unionQueries(array_values($allQueries)),
            'all_product_history'
        );
        $eventCounts = (clone $allEvents)
            ->select('event_type', DB::raw('COUNT(*) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        $stockRows = DB::table('product_warehouse as pw')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pw.product_variant_id')
            ->whereNull('pw.deleted_at')
            ->whereNull('w.deleted_at')
            ->where('pw.product_id', $product->id)
            ->when(is_array($warehouseIds), fn ($query) => $query->whereIn('pw.warehouse_id', $warehouseIds))
            ->select('pw.warehouse_id', 'w.name as warehouse_name', 'pw.product_variant_id', 'pv.name as variant_name', 'pv.code as variant_code', 'pw.qte')
            ->orderBy('w.name')
            ->orderBy('pv.name')
            ->get()
            ->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => (string) $row->warehouse_name,
                'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                'variant_name' => (string) ($row->variant_name ?? ''),
                'variant_code' => (string) ($row->variant_code ?? ''),
                'quantity' => (float) $row->qte,
            ]);

        return response()->json([
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'code' => (string) $product->code,
                'type' => (string) $product->type,
                'is_active' => (bool) $product->is_active,
                'image' => (string) (array_values(array_filter(explode(',', (string) $product->image)))[0] ?? 'no-image.png'),
                'category' => (string) optional($product->category)->name,
                'brand' => (string) optional($product->brand)->name,
                'unit' => (string) optional($product->unit)->ShortName,
                'cost' => $canViewCost ? (float) $product->cost : null,
                'price' => (float) $product->price,
                'fix_price' => (float) $product->fix_price,
                'mrp_price' => (float) $product->mrp_price,
                'created_at' => optional($product->created_at)->toDateTimeString(),
            ],
            'summary' => [
                'current_stock' => (float) $stockRows->sum('quantity'),
                'history_events' => (int) $eventCounts->sum(),
                'purchases' => (int) ($eventCounts['purchase'] ?? 0),
                'sales' => (int) ($eventCounts['sale'] ?? 0),
                'returns' => (int) (($eventCounts['sale_return'] ?? 0) + ($eventCounts['purchase_return'] ?? 0)),
                'stock_changes' => (int) (($eventCounts['opening_stock'] ?? 0) + ($eventCounts['stock_adjustment'] ?? 0) + ($eventCounts['transfer'] ?? 0) + ($eventCounts['damage'] ?? 0)),
                'pricing_changes' => (int) ($eventCounts['pricing'] ?? 0),
            ],
            'stock' => $stockRows,
            'event_counts' => $eventCounts,
            'types' => collect($allowedTypes)->map(fn ($type) => [
                'value' => $type,
                'label' => $this->typeLabel($type),
            ])->values(),
            'history' => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    /** @return array<string, Builder> */
    private function historyQueries(Product $product, ?array $warehouseIds, ?int $recordUserId = null): array
    {
        $productId = (int) $product->id;
        $nullNumber = 'CAST(NULL AS DECIMAL(15,2))';
        $nullText = 'CAST(NULL AS CHAR)';

        $purchase = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->leftJoin('providers as party', 'party.id', '=', 'h.provider_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'purchase' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, CASE WHEN LOWER(h.statut)='received' THEN d.quantity ELSE 0 END stock_effect, d.cost unit_cost, {$nullNumber} unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'supplier' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, h.statut status, h.notes notes, pv.name variant_name, CONCAT_WS(' | ',NULLIF(h.sales_tax_invoice_no,''),NULLIF(h.delivery_note_no,'')) detail, CONCAT('/app/purchases/detail/',h.id) link, d.company_rb_price company_rb_price, d.mrp_price mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $purchaseReturn = DB::table('purchase_return_details as d')
            ->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->leftJoin('providers as party', 'party.id', '=', 'h.provider_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->whereNull('d.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'purchase_return' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, -d.quantity stock_effect, d.cost unit_cost, {$nullNumber} unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'supplier' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, h.statut status, h.notes notes, pv.name variant_name, {$nullText} detail, CONCAT('/app/purchase_return/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $sale = DB::table('sale_details as d')
            ->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->leftJoin('shipment_items as si', 'si.sale_detail_id', '=', 'd.id')
            ->leftJoin('shipments as sh', function ($join) {
                $join->on('sh.id', '=', 'si.shipment_id')->whereNull('sh.deleted_at');
            })
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'sale' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, CASE WHEN LOWER(h.statut)='completed' AND sh.id IS NULL THEN -d.quantity ELSE 0 END stock_effect, {$nullNumber} unit_cost, d.price unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'customer' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, h.statut status, h.notes notes, pv.name variant_name, CONCAT('Payment: ',COALESCE(h.payment_statut,'-'),' | Shipping: ',COALESCE(h.shipping_status,'-')) detail, CONCAT('/app/sales/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $saleReturn = DB::table('sale_return_details as d')
            ->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'sale_return' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, d.quantity stock_effect, {$nullNumber} unit_cost, d.price unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'customer' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, h.statut status, h.notes notes, pv.name variant_name, {$nullText} detail, CONCAT('/app/sale_return/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $shipment = DB::table('shipment_items as si')
            ->join('shipments as sh', 'sh.id', '=', 'si.shipment_id')
            ->join('sale_details as d', 'd.id', '=', 'si.sale_detail_id')
            ->join('sales as sale', 'sale.id', '=', 'd.sale_id')
            ->leftJoin('clients as party', 'party.id', '=', 'sale.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sale.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'si.shipped_by')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('sh.deleted_at')->whereNull('sale.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('sale.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('sale.warehouse_id', $warehouseIds))
            ->selectRaw("'shipment' event_type, sh.id source_id, COALESCE(si.shipped_at,sh.created_at) occurred_at, sh.Ref reference, d.quantity quantity, -d.quantity stock_effect, {$nullNumber} unit_cost, {$nullNumber} unit_price, si.item_total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'customer' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, CASE WHEN LOWER(sh.status)='delivered' THEN 'delivered' ELSE 'shipped' END status, sh.shipping_details notes, pv.name variant_name, CONCAT('Sale: ',sale.Ref,' | Delivery: ',REPLACE(COALESCE(si.delivery_method,sh.delivery_method,'-'),'_',' '),CASE WHEN COALESCE(si.driver_name,sh.driver_name,'')='' THEN '' ELSE CONCAT(' | Driver: ',COALESCE(si.driver_name,sh.driver_name)) END) detail, CONCAT('/app/sales/detail/',sale.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $adjustment = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("CASE WHEN LOWER(COALESCE(h.notes,'')) LIKE 'opening stock%' THEN 'opening_stock' ELSE 'stock_adjustment' END event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, CASE WHEN LOWER(d.type)='add' THEN d.quantity ELSE -d.quantity END stock_effect, {$nullNumber} unit_cost, {$nullNumber} unit_price, {$nullNumber} total, w.name warehouse_name, {$nullText} destination_warehouse_name, {$nullText} party_name, {$nullText} party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, d.type status, h.notes notes, pv.name variant_name, {$nullText} detail, CONCAT('/app/adjustments/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $transfer = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.from_warehouse_id')
            ->leftJoin('warehouses as dw', 'dw.id', '=', 'h.to_warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->where(function ($scope) use ($warehouseIds) {
                $scope->whereIn('h.from_warehouse_id', $warehouseIds)->orWhereIn('h.to_warehouse_id', $warehouseIds);
            }))
            ->selectRaw("'transfer' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, 0 stock_effect, d.cost unit_cost, {$nullNumber} unit_price, d.total total, w.name warehouse_name, dw.name destination_warehouse_name, {$nullText} party_name, {$nullText} party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, COALESCE(h.approval_status,h.statut) status, h.notes notes, pv.name variant_name, {$nullText} detail, CONCAT('/app/transfers/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $damage = DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'damage' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, -d.quantity stock_effect, {$nullNumber} unit_cost, {$nullNumber} unit_price, {$nullNumber} total, w.name warehouse_name, {$nullText} destination_warehouse_name, {$nullText} party_name, {$nullText} party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, 'recorded' status, h.notes notes, pv.name variant_name, {$nullText} detail, CONCAT('/app/damages/edit/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $pricing = DB::table('pricing_level_details as d')
            ->join('pricing_levels as h', 'h.id', '=', 'd.pricing_level_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->selectRaw("'pricing' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, CONCAT('PL-',h.id) reference, {$nullNumber} quantity, 0 stock_effect, d.cost unit_cost, d.price unit_price, {$nullNumber} total, {$nullText} warehouse_name, {$nullText} destination_warehouse_name, {$nullText} party_name, {$nullText} party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, 'updated' status, {$nullText} notes, pv.name variant_name, 'Pricing level update' detail, CONCAT('/app/pricing-levels/edit/',h.id) link, d.company_rb_price company_rb_price, d.mrp_price mrp_price, d.fix_price fix_price, d.wholesale_price wholesale_price, d.min_price min_price");

        $quotation = DB::table('quotation_details as d')
            ->join('quotations as h', 'h.id', '=', 'd.quotation_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'h.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId)
            ->when($recordUserId, fn ($q) => $q->where('h.user_id', $recordUserId))
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('h.warehouse_id', $warehouseIds))
            ->selectRaw("'quotation' event_type, h.id source_id, COALESCE(CONCAT(h.date,' ',COALESCE(h.time,'00:00:00')),h.created_at) occurred_at, h.Ref reference, d.quantity quantity, 0 stock_effect, {$nullNumber} unit_cost, d.price unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'customer' party_type, COALESCE(NULLIF(CONCAT_WS(' ',u.firstname,u.lastname),''),u.username) performed_by, h.statut status, h.notes notes, pv.name variant_name, 'No stock movement' detail, CONCAT('/app/quotations/detail/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $serviceJob = DB::table('service_job_items as d')
            ->join('service_jobs as h', 'h.id', '=', 'd.service_job_id')
            ->leftJoin('clients as party', 'party.id', '=', 'h.client_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->whereNull('h.deleted_at')->whereNull('d.deleted_at')->where('d.product_id', $productId)
            ->when(is_array($warehouseIds), fn ($q) => $q->whereIn('d.warehouse_id', $warehouseIds))
            ->selectRaw("'service_job' event_type, h.id source_id, COALESCE(h.delivered_at,h.completed_at,h.created_at) occurred_at, h.Ref reference, d.quantity quantity, CASE WHEN d.stock_deducted=1 THEN -d.quantity ELSE 0 END stock_effect, {$nullNumber} unit_cost, d.unit_price unit_price, d.total total, w.name warehouse_name, {$nullText} destination_warehouse_name, party.name party_name, 'customer' party_type, {$nullText} performed_by, h.status status, COALESCE(d.notes,h.notes) notes, pv.name variant_name, CONCAT('Item type: ',COALESCE(d.type,'-')) detail, CONCAT('/app/service/jobs/details/',h.id) link, {$nullNumber} company_rb_price, {$nullNumber} mrp_price, {$nullNumber} fix_price, {$nullNumber} wholesale_price, {$nullNumber} min_price");

        $created = DB::query()->selectRaw("'product_created' event_type, ? source_id, ? occurred_at, ? reference, {$nullNumber} quantity, 0 stock_effect, ? unit_cost, ? unit_price, {$nullNumber} total, {$nullText} warehouse_name, {$nullText} destination_warehouse_name, {$nullText} party_name, {$nullText} party_type, {$nullText} performed_by, ? status, ? notes, {$nullText} variant_name, 'Product catalogue record created' detail, ? link, ? company_rb_price, ? mrp_price, ? fix_price, ? wholesale_price, ? min_price", [
            $product->id,
            optional($product->created_at)->toDateTimeString(),
            $product->code,
            $product->cost,
            $product->price,
            $product->is_active ? 'active' : 'inactive',
            $product->note,
            '/app/products/detail/'.$product->id,
            $product->company_rb_price,
            $product->mrp_price,
            $product->fix_price,
            $product->wholesale_price,
            $product->min_price,
        ]);

        return [
            'product_created' => $created,
            'purchase' => $purchase,
            'purchase_return' => $purchaseReturn,
            'sale' => $sale,
            'sale_return' => $saleReturn,
            'shipment' => $shipment,
            'stock_adjustment' => (clone $adjustment)->whereRaw("LOWER(COALESCE(h.notes,'')) NOT LIKE 'opening stock%'"),
            'opening_stock' => (clone $adjustment)->whereRaw("LOWER(COALESCE(h.notes,'')) LIKE 'opening stock%'"),
            'transfer' => $transfer,
            'damage' => $damage,
            'pricing' => $pricing,
            'quotation' => $quotation,
            'service_job' => $serviceJob,
        ];
    }

    /** @param array<int, Builder> $queries */
    private function unionQueries(array $queries): Builder
    {
        $union = $this->normalizeHistoryQuery(array_shift($queries));
        foreach ($queries as $query) {
            $union->unionAll($this->normalizeHistoryQuery($query));
        }

        return $union;
    }

    /**
     * This installation contains legacy tables with different utf8mb4 collations.
     * Normalize text at the union boundary so history can safely combine them.
     */
    private function normalizeHistoryQuery(Builder $query): Builder
    {
        $text = fn (string $column) => "CONVERT({$column} USING utf8mb4) COLLATE utf8mb4_unicode_ci as {$column}";

        return DB::query()
            ->fromSub($query, 'history_source')
            ->selectRaw(implode(', ', [
                $text('event_type'),
                'source_id',
                'CAST(occurred_at AS DATETIME) as occurred_at',
                $text('reference'),
                'quantity',
                'stock_effect',
                'unit_cost',
                'unit_price',
                'total',
                $text('warehouse_name'),
                $text('destination_warehouse_name'),
                $text('party_name'),
                $text('party_type'),
                $text('performed_by'),
                $text('status'),
                $text('notes'),
                $text('variant_name'),
                $text('detail'),
                $text('link'),
                'company_rb_price',
                'mrp_price',
                'fix_price',
                'wholesale_price',
                'min_price',
            ]));
    }

    private function formatRow(object $row, bool $canViewCost): array
    {
        $costBasedEvent = in_array($row->event_type, ['purchase', 'purchase_return', 'transfer'], true);

        return [
            'event_type' => (string) $row->event_type,
            'event_label' => $this->typeLabel((string) $row->event_type),
            'source_id' => (int) $row->source_id,
            'occurred_at' => $row->occurred_at,
            'reference' => (string) ($row->reference ?? ''),
            'quantity' => $row->quantity === null ? null : (float) $row->quantity,
            'stock_effect' => (float) ($row->stock_effect ?? 0),
            'unit_cost' => ! $canViewCost || $row->unit_cost === null ? null : (float) $row->unit_cost,
            'unit_price' => $row->unit_price === null ? null : (float) $row->unit_price,
            'total' => (! $canViewCost && $costBasedEvent) || $row->total === null ? null : (float) $row->total,
            'warehouse_name' => (string) ($row->warehouse_name ?? ''),
            'destination_warehouse_name' => (string) ($row->destination_warehouse_name ?? ''),
            'party_name' => (string) ($row->party_name ?? ''),
            'party_type' => (string) ($row->party_type ?? ''),
            'performed_by' => (string) ($row->performed_by ?? ''),
            'status' => (string) ($row->status ?? ''),
            'notes' => (string) ($row->notes ?? ''),
            'variant_name' => (string) ($row->variant_name ?? ''),
            'detail' => (string) ($row->detail ?? ''),
            'link' => (string) ($row->link ?? ''),
            'pricing' => [
                'company_rb_price' => ! $canViewCost || $row->company_rb_price === null ? null : (float) $row->company_rb_price,
                'mrp_price' => ! $canViewCost || $row->mrp_price === null ? null : (float) $row->mrp_price,
                'fix_price' => ! $canViewCost || $row->fix_price === null ? null : (float) $row->fix_price,
                'wholesale_price' => ! $canViewCost || $row->wholesale_price === null ? null : (float) $row->wholesale_price,
                'min_price' => ! $canViewCost || $row->min_price === null ? null : (float) $row->min_price,
            ],
        ];
    }

    private function allowedTypes($permissions, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            return self::TYPES;
        }

        $map = [
            'product_created' => 'products_view',
            'purchase' => 'Purchases_view',
            'purchase_return' => 'Purchase_Returns_view',
            'sale' => 'Sales_view',
            'sale_return' => 'Sale_Returns_view',
            'shipment' => 'Sales_view',
            'stock_adjustment' => 'adjustment_view',
            'opening_stock' => 'adjustment_view',
            'transfer' => 'transfer_view',
            'damage' => 'damage_view',
            'pricing' => 'pricing_level_view',
            'quotation' => 'Quotations_view',
            'service_job' => 'service_jobs',
        ];

        return collect(self::TYPES)
            ->filter(fn ($type) => $permissions->contains($map[$type] ?? 'products_view'))
            ->values()
            ->all();
    }

    private function typeLabel(string $type): string
    {
        return [
            'product_created' => 'Product Created',
            'purchase' => 'Purchase',
            'purchase_return' => 'Purchase Return',
            'sale' => 'Sale',
            'sale_return' => 'Sale Return',
            'shipment' => 'Shipment / Delivery',
            'stock_adjustment' => 'Stock Adjustment',
            'opening_stock' => 'Opening Stock / Stock Add',
            'transfer' => 'Stock Transfer',
            'damage' => 'Damage',
            'pricing' => 'Pricing Change',
            'quotation' => 'Quotation',
            'service_job' => 'Service Job',
        ][$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}
