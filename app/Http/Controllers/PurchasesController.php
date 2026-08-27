<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\EmailMessage;
use App\Models\GatePass;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Product;
use App\Models\ProductWarehouseLocation;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PurchasesController extends BaseController
{
    // ------------- Show ALL Purchases ----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $param = [
            0 => 'like',
            1 => 'like',
            2 => '=',
            3 => 'like',
            4 => '=',
            5 => '=',
        ];
        $columns = [
            0 => 'Ref',
            1 => 'statut',
            2 => 'provider_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
        ];
        $data = [];
        $total = 0;

        // Check If User Has Permission View  All Records
        $Purchases = Purchase::with('facture', 'provider', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Purchases->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Purchases = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Purchases as $Purchase) {

            $item['id'] = $Purchase->id;
            $item['date'] = $Purchase['date'].' '.$Purchase['time'];
            $item['Ref'] = $Purchase->Ref;
            $item['warehouse_name'] = $Purchase['warehouse']->name;
            $item['discount'] = $Purchase->discount;
            $item['shipping'] = $Purchase->shipping;
            $item['statut'] = $Purchase->statut;
            $item['provider_id'] = $Purchase['provider']->id;
            $item['provider_name'] = $Purchase['provider']->name;
            $item['provider_email'] = $Purchase['provider']->email;
            $item['provider_tele'] = $Purchase['provider']->phone;
            $item['provider_code'] = $Purchase['provider']->code;
            $item['provider_adr'] = $Purchase['provider']->adresse;
            $item['GrandTotal'] = number_format($Purchase->GrandTotal, 2, '.', '');
            $item['paid_amount'] = number_format($Purchase->paid_amount, 2, '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], 2, '.', '');
            $item['payment_status'] = $Purchase->payment_statut;

            if (PurchaseReturn::where('purchase_id', $Purchase['id'])->where('deleted_at', '=', null)->exists()) {
                $PurchaseReturn = PurchaseReturn::where('purchase_id', $Purchase['id'])->where('deleted_at', '=', null)->first();
                $item['purchasereturn_id'] = $PurchaseReturn->id;
                $item['purchase_has_return'] = 'yes';
            } else {
                $item['purchase_has_return'] = 'no';
            }

            // Get documents count
            $item['documents_count'] = DB::table('purchase_documents')
                ->where('purchase_id', $Purchase['id'])
                ->whereNull('deleted_at')
                ->count();

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $payment_methods = PaymentMethod::whereNull('deleted_at')->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);
    }

    public function gatePassLookup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);
        $data = $request->validate(['number' => 'required|string|max:100']);
        $number = trim($data['number']);
        $query = GatePass::with([
            'provider:id,name',
            'warehouse:id,name',
            'items' => fn ($items) => $items->where('accepted_quantity', '>', 0),
            'items.product.unitPurchase',
            'items.variant',
            'items.unit',
        ]);
        $this->scopeGatePassesToUser($query, $request->user('api'));

        $gatePass = (clone $query)->where('number', $number)->first();
        if (! $gatePass) {
            $matches = (clone $query)->where('supplier_gate_pass_number', $number)->limit(2)->get();
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['number' => ['More than one Gate Pass uses this supplier number. Enter the internal GP number instead.']]);
            }
            $gatePass = $matches->first();
        }
        if (! $gatePass) {
            throw ValidationException::withMessages(['number' => ['Gate Pass not found.']]);
        }
        if (! in_array($gatePass->status, ['accepted', 'partially_accepted'], true)) {
            throw ValidationException::withMessages(['number' => ['Confirm the Gate Pass before adding it to a Purchase.']]);
        }
        if ($gatePass->items->isEmpty()) {
            throw ValidationException::withMessages(['number' => ['This Gate Pass has no accepted product quantity.']]);
        }
        $used = $this->gatePassItemUsedQuantities($gatePass->items->pluck('id'));
        $productIds = $gatePass->items->pluck('product_id')->filter()->unique()->values();
        $stocks = product_warehouse::where('warehouse_id', $gatePass->warehouse_id)
            ->whereNull('deleted_at')->whereIn('product_id', $productIds)
            ->get(['product_id', 'product_variant_id', 'qte'])
            ->keyBy(fn ($stock) => $stock->product_id.':'.($stock->product_variant_id ?: 0));
        $locations = collect();
        if (Schema::hasTable('product_warehouse_locations')) {
            $locations = ProductWarehouseLocation::with('location:id,code,name,is_active')
                ->where('warehouse_id', $gatePass->warehouse_id)->whereIn('product_id', $productIds)
                ->get()->keyBy('product_id');
        }

        $items = $gatePass->items->map(function ($item) use ($used, $stocks, $locations) {
            $previouslyUsed = (float) ($used[$item->id] ?? 0);
            $remaining = max(0, (float) $item->accepted_quantity - $previouslyUsed);
            $product = $item->product;
            $variant = $item->variant;
            $unit = $item->unit ?: $product?->unitPurchase;
            $baseCost = (float) ($variant?->cost ?? $product?->cost ?? 0);
            $rbPrice = (float) ($variant?->company_rb_price ?: $product?->company_rb_price ?: $baseCost);
            $mrpPrice = (float) ($variant?->mrp_price ?: $product?->mrp_price ?: $variant?->price ?: $product?->price ?: 0);
            if ($unit && (float) $unit->operator_value > 0) {
                if ($unit->operator === '/') {
                    $rbPrice /= (float) $unit->operator_value;
                    $mrpPrice /= (float) $unit->operator_value;
                } else {
                    $rbPrice *= (float) $unit->operator_value;
                    $mrpPrice *= (float) $unit->operator_value;
                }
            }
            $location = $locations->get($item->product_id)?->location;
            $stock = $stocks->get($item->product_id.':'.($item->product_variant_id ?: 0));

            return [
                'gate_pass_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'unit_id' => $item->unit_id,
                'product' => $item->product_name,
                'model' => $item->variant_name,
                'sku' => $item->sku,
                'accepted_quantity' => (float) $item->accepted_quantity,
                'previously_used' => $previouslyUsed,
                'quantity' => $remaining,
                'product_data' => [
                    'id' => $item->product_id,
                    'code' => $item->sku,
                    'name' => ($item->variant_name ? '['.$item->variant_name.']' : '').$item->product_name,
                    'purchase_unit_id' => $item->unit_id ?: $product?->unit_purchase_id,
                    'unitPurchase' => $unit?->ShortName ?: $unit?->name,
                    'qte' => (float) ($stock?->qte ?? 0),
                    'fix_cost' => $baseCost,
                    'company_rb_price' => $rbPrice,
                    'mrp_price' => $mrpPrice,
                    'discount' => (float) ($product?->discount ?? 0),
                    'DiscountNet' => 0,
                    'discount_method' => $product?->discount_method ?: '2',
                    'is_imei' => (bool) ($product?->is_imei ?? false),
                    'is_batch_tracked' => (bool) ($product?->is_batch_tracked ?? false),
                    'warehouse_location' => $location ? [
                        'id' => $location->id, 'code' => $location->code,
                        'name' => $location->name, 'is_active' => (bool) $location->is_active,
                    ] : null,
                ],
            ];
        })->filter(fn ($item) => $item['quantity'] > 0)->values();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['number' => ['This Gate Pass has already been fully used on Purchases or Supplier Invoices.']]);
        }

        return response()->json(['gate_pass' => [
            'id' => $gatePass->id,
            'number' => $gatePass->number,
            'supplier_gate_pass_number' => $gatePass->supplier_gate_pass_number,
            'provider_id' => $gatePass->provider_id,
            'provider' => $gatePass->provider,
            'warehouse_id' => $gatePass->warehouse_id,
            'warehouse' => $gatePass->warehouse,
            'items' => $items,
        ]]);
    }

    // ------ Store new Purchase -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
            'sales_tax_invoice_no' => 'nullable|string|max:100',
            'delivery_note_no' => 'nullable|string|max:100',
            'gate_pass_ids' => 'nullable|array|max:100',
            'gate_pass_ids.*' => 'integer|distinct|exists:gate_passes,id',
            'details.*.gate_pass_item_id' => 'nullable|integer|exists:gate_pass_items,id',
        ]);

        $this->assertProductsSelectable($request->user('api'), $request->input('details', []));

        \DB::transaction(function () use ($request) {
            $gatePassData = $this->resolveGatePassesForPurchase(
                $request->input('gate_pass_ids', []),
                (int) $request->supplier_id,
                (int) $request->warehouse_id,
                $request->input('details', []),
                $request->user('api')
            );
            $gatePasses = $gatePassData['gate_passes'];
            $gatePassAllocations = $gatePassData['allocations'];
            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->sales_tax_invoice_no = $request->sales_tax_invoice_no;
            $order->delivery_note_no = $request->delivery_note_no;
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = $request->GrandTotal;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->withholding_tax = $request->withholding_tax ?? 0;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            if ($gatePasses->isNotEmpty()) {
                $order->gate_pass_id = $gatePasses->first()->id;
                $purchaseOrderIds = $gatePasses->pluck('purchase_order_id')->filter()->unique();
                $order->purchase_order_id = $purchaseOrderIds->count() === 1 ? $purchaseOrderIds->first() : null;
                $order->inventory_already_received = true;
            }

            $order->save();
            if ($gatePasses->isNotEmpty()) {
                $order->gatePasses()->attach($gatePasses->pluck('id')->all());
                $now = now();
                DB::table('purchase_gate_pass_items')->insert($gatePassAllocations->map(fn ($quantity, $gatePassItemId) => [
                    'purchase_id' => $order->id,
                    'gate_pass_item_id' => $gatePassItemId,
                    'quantity' => $quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            $data = $request['details'];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'cost' => $value['Unit_cost'],
                    'company_rb_price' => $value['company_rb_price'] ?? $value['Unit_cost'],
                    'mrp_price' => $value['mrp_price'] ?? 0,
                    'purchase_unit_id' => $value['purchase_unit_id'],
                    'TaxNet' => $value['tax_percent'],
                    'sales_tax' => $value['taxe'] ?? 0,
                    'withholding_tax' => $value['withholding_tax'] ?? 0,
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $value['subtotal'],
                    'imei_number' => $value['imei_number'],
                ];

                if ($order->statut == 'received' && ! $order->inventory_already_received) {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }
            }
            PurchaseDetail::insert($orderDetails);

            // Managed tax snapshots are calculated server-side. Legacy aggregate
            // columns remain dual-written for backward-compatible reports/PDFs.
            app(\App\Services\Tax\TransactionTaxService::class)
                ->snapshotPurchase($order, array_values($data), $request->user('api'));

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            // Re-fetch in id order so position matches the input array order.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received' && ! $order->inventory_already_received) {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $data, $persisted);
            }
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    private function resolveGatePassesForPurchase(array $ids, int $providerId, int $warehouseId, array $details, $user)
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $submittedLines = collect($details)->filter(fn ($detail) => ! empty($detail['gate_pass_item_id']));
        if ($ids->isEmpty()) {
            if ($submittedLines->isNotEmpty()) {
                throw ValidationException::withMessages(['gate_pass_ids' => ['Gate Pass item lines require their Gate Pass reference.']]);
            }

            return ['gate_passes' => collect(), 'allocations' => collect()];
        }

        $query = GatePass::with('items')->whereIn('id', $ids)->lockForUpdate();
        $this->scopeGatePassesToUser($query, $user);
        $gatePasses = $query->get();
        if ($gatePasses->count() !== $ids->count()) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['One or more Gate Passes are unavailable or outside your warehouse access.']]);
        }
        if ($gatePasses->contains(fn ($gatePass) => ! in_array($gatePass->status, ['accepted', 'partially_accepted'], true))) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['Every Gate Pass must be confirmed before creating the Purchase.']]);
        }
        if ($gatePasses->contains(fn ($gatePass) => (int) $gatePass->provider_id !== $providerId)) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['All Gate Passes must belong to the selected supplier.']]);
        }
        if ($gatePasses->contains(fn ($gatePass) => (int) $gatePass->warehouse_id !== $warehouseId)) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['All Gate Passes must belong to the selected warehouse.']]);
        }
        $acceptedItems = $gatePasses->flatMap->items
            ->filter(fn ($item) => (float) $item->accepted_quantity > 0)
            ->keyBy('id');
        if ($acceptedItems->isEmpty()) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['The selected Gate Passes have no accepted product quantity.']]);
        }
        if ($submittedLines->isEmpty()) {
            throw ValidationException::withMessages(['details' => ['Add at least one Gate Pass product quantity.']]);
        }

        $representedGatePassIds = $submittedLines->map(function ($detail) use ($acceptedItems) {
            return (int) optional($acceptedItems->get((int) $detail['gate_pass_item_id']))->gate_pass_id;
        })->filter()->unique()->sort()->values();
        if ($representedGatePassIds->all() !== $ids->sort()->values()->all()) {
            throw ValidationException::withMessages(['gate_pass_ids' => ['Each selected Gate Pass must contribute at least one product line.']]);
        }

        $used = $this->gatePassItemUsedQuantities($acceptedItems->keys());
        $allocations = $submittedLines->groupBy(fn ($detail) => (int) $detail['gate_pass_item_id'])
            ->map(function ($lines, $gatePassItemId) use ($acceptedItems, $used) {
                $item = $acceptedItems->get((int) $gatePassItemId);
                if (! $item) {
                    throw ValidationException::withMessages(['details' => ['A submitted product does not belong to the selected Gate Passes.']]);
                }
                foreach ($lines as $line) {
                    $sameProduct = (int) ($line['product_id'] ?? 0) === (int) $item->product_id;
                    $sameVariant = (int) (($line['product_variant_id'] ?? null) ?: 0) === (int) ($item->product_variant_id ?: 0);
                    $sameUnit = (int) ($line['purchase_unit_id'] ?? 0) === (int) $item->unit_id;
                    if (! $sameProduct || ! $sameVariant || ! $sameUnit) {
                        throw ValidationException::withMessages(['details' => ['A Gate Pass product or unit was changed. Reload the Gate Pass and try again.']]);
                    }
                }
                $quantity = (float) $lines->sum(fn ($line) => (float) ($line['quantity'] ?? 0));
                $remaining = max(0, (float) $item->accepted_quantity - (float) ($used[$item->id] ?? 0));
                if ($quantity <= 0 || $quantity - $remaining > 0.000001) {
                    throw ValidationException::withMessages(['details' => ["Purchase quantity must be positive and cannot exceed the remaining {$remaining} for {$item->sku}."]]);
                }

                return $quantity;
            });

        return ['gate_passes' => $gatePasses, 'allocations' => $allocations];
    }

    private function gatePassItemUsedQuantities($gatePassItemIds)
    {
        $ids = collect($gatePassItemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $supplierInvoiceQuantities = DB::table('supplier_invoice_items as i')
            ->join('supplier_invoices as s', 's.id', '=', 'i.supplier_invoice_id')
            ->whereIn('i.gate_pass_item_id', $ids)->where('s.status', '<>', 'cancelled')
            ->selectRaw('i.gate_pass_item_id, SUM(i.quantity) as quantity')
            ->groupBy('i.gate_pass_item_id')->pluck('quantity', 'gate_pass_item_id');
        $purchaseQuantities = DB::table('purchase_gate_pass_items as i')
            ->join('purchases as p', 'p.id', '=', 'i.purchase_id')
            ->whereIn('i.gate_pass_item_id', $ids)->whereNull('p.deleted_at')
            ->where('p.posting_status', '<>', 'cancelled')
            ->selectRaw('i.gate_pass_item_id, SUM(i.quantity) as quantity')
            ->groupBy('i.gate_pass_item_id')->pluck('quantity', 'gate_pass_item_id');

        return $ids->mapWithKeys(fn ($id) => [
            $id => (float) ($supplierInvoiceQuantities[$id] ?? 0) + (float) ($purchaseQuantities[$id] ?? 0),
        ]);
    }

    private function scopeGatePassesToUser($query, $user): void
    {
        if (! $user->isSuperAdmin() && ! $user->is_all_warehouses) {
            $query->whereIn('warehouse_id', UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id'));
        }
    }

    // --------- Update Purchase  -------------\\

    public function update(Request $request, $id)
    {
        $linkedPurchase = Purchase::findOrFail($id);
        if ($linkedPurchase->supplier_invoice_id) {
            throw ValidationException::withMessages(['purchase' => ['Purchases linked to Supplier Invoices are immutable. Use the controlled cancellation/reversal workflow.']]);
        }
        if ($linkedPurchase->inventory_already_received) {
            return $this->updateGatePassPurchase($request, $linkedPurchase);
        }
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);

        request()->validate([
            'warehouse_id' => 'required',
            'supplier_id' => 'required',
            'sales_tax_invoice_no' => 'nullable|string|max:100',
            'delivery_note_no' => 'nullable|string|max:100',
        ]);

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();
                $new_purchase_details = $request['details'];
                $length = count($new_purchase_details);

                // Pharmacy: reverse all existing batch links for this purchase before
                // re-applying. The legacy stock-adjustment block below already reverses
                // and re-applies product_warehouse qte; we mirror that for batches.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Get Ids for new Details
                $new_products_id = [];
                foreach ($new_purchase_details as $new_detail) {
                    $new_products_id[] = $new_detail['id'];
                }

                // Init Data with old Parametre
                $old_products_id = [];
                foreach ($old_purchase_details as $key => $value) {
                    $old_products_id[] = $value->id;

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($value['purchase_unit_id'] !== null) {
                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->where('product_variant_id', $value['product_variant_id'])
                                    ->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }
                        }

                        // Delete Detail
                        if (! in_array($old_products_id[$key], $new_products_id)) {
                            $PurchaseDetail = PurchaseDetail::findOrFail($value->id);
                            $PurchaseDetail->delete();
                        }
                    }

                }

                // Update Data with New request
                foreach ($new_purchase_details as $key => $prod_detail) {

                    if ($prod_detail['no_unit'] !== 0) {
                        $unit_prod = Unit::where('id', $prod_detail['purchase_unit_id'])->first();

                        if ($request['statut'] == 'received') {

                            if ($prod_detail['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->where('product_variant_id', $prod_detail['product_variant_id'])
                                    ->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }

                        }

                        $orderDetails['purchase_id'] = $id;
                        $orderDetails['cost'] = $prod_detail['Unit_cost'];
                        $orderDetails['company_rb_price'] = $prod_detail['company_rb_price'] ?? $prod_detail['Unit_cost'];
                        $orderDetails['mrp_price'] = $prod_detail['mrp_price'] ?? 0;
                        $orderDetails['purchase_unit_id'] = $prod_detail['purchase_unit_id'];
                        $orderDetails['TaxNet'] = $prod_detail['tax_percent'];
                        $orderDetails['sales_tax'] = $prod_detail['taxe'] ?? 0;
                        $orderDetails['withholding_tax'] = $prod_detail['withholding_tax'] ?? 0;
                        $orderDetails['tax_method'] = $prod_detail['tax_method'];
                        $orderDetails['discount'] = $prod_detail['discount'];
                        $orderDetails['discount_method'] = $prod_detail['discount_Method'];
                        $orderDetails['quantity'] = $prod_detail['quantity'];
                        $orderDetails['product_id'] = $prod_detail['product_id'];
                        $orderDetails['product_variant_id'] = $prod_detail['product_variant_id'];
                        $orderDetails['total'] = $prod_detail['subtotal'];
                        $orderDetails['imei_number'] = $prod_detail['imei_number'];

                        if (! in_array($prod_detail['id'], $old_products_id)) {
                            PurchaseDetail::Create($orderDetails);
                        } else {
                            PurchaseDetail::where('id', $prod_detail['id'])->update($orderDetails);
                        }
                    }
                }

                $due = $request['GrandTotal'] - $current_Purchase->paid_amount;
                if ($due === 0.0 || $due < 0.0) {
                    $payment_statut = 'paid';
                } elseif ($due != $request['GrandTotal']) {
                    $payment_statut = 'partial';
                } elseif ($due == $request['GrandTotal']) {
                    $payment_statut = 'unpaid';
                }

                $current_Purchase->update([
                    'date' => $request['date'],
                    'sales_tax_invoice_no' => $request->input('sales_tax_invoice_no', $current_Purchase->sales_tax_invoice_no),
                    'delivery_note_no' => $request->input('delivery_note_no', $current_Purchase->delivery_note_no),
                    'provider_id' => $request['supplier_id'],
                    'warehouse_id' => $request['warehouse_id'],
                    'notes' => $request['notes'],
                    'tax_rate' => $request['tax_rate'],
                    'TaxNet' => $request['TaxNet'],
                    'withholding_tax' => $request['withholding_tax'] ?? 0,
                    'discount' => $request['discount'],
                    'shipping' => $request['shipping'],
                    'statut' => $request['statut'],
                    'GrandTotal' => $request['GrandTotal'],
                    'payment_statut' => $payment_statut,
                ]);

                // Pharmacy: re-apply batches to the now-persisted PurchaseDetail rows.
                if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                    // Build aligned arrays: skip rows the controller skipped (no_unit === 0).
                    $alignedInput = [];
                    foreach ($new_purchase_details as $row) {
                        if (($row['no_unit'] ?? 1) !== 0) {
                            $alignedInput[] = $row;
                        }
                    }
                    $persisted = PurchaseDetail::where('purchase_id', $id)
                        ->orderBy('id', 'asc')
                        ->get();
                    // Match by (product_id, product_variant_id) preserving input order.
                    $aligned = [];
                    $persistedByKey = [];
                    foreach ($persisted as $d) {
                        $k = $d->product_id . '|' . ($d->product_variant_id ?? '');
                        $persistedByKey[$k][] = $d;
                    }
                    foreach ($alignedInput as $row) {
                        $k = ($row['product_id'] ?? '') . '|' . ($row['product_variant_id'] ?? '');
                        $match = isset($persistedByKey[$k]) ? array_shift($persistedByKey[$k]) : null;
                        $aligned[] = $match;
                    }
                    $batchService->applyForPurchase($current_Purchase, $alignedInput, $aligned);
                }

                app(\App\Services\Tax\TransactionTaxService::class)
                    ->snapshotPurchase($current_Purchase->fresh(), array_values($new_purchase_details), $request->user('api'));
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);

    }

    private function updateGatePassPurchase(Request $request, Purchase $purchase)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        $validated = $request->validate([
            'date' => 'required|date',
            'supplier_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
            'sales_tax_invoice_no' => 'nullable|string|max:100',
            'delivery_note_no' => 'nullable|string|max:100',
            'details' => 'required|array|min:1',
            'details.*.id' => 'required|integer',
            'details.*.quantity' => 'required|numeric|min:0.000001',
        ]);

        if ((int) $validated['supplier_id'] !== (int) $purchase->provider_id
            || (int) $validated['warehouse_id'] !== (int) $purchase->warehouse_id) {
            throw ValidationException::withMessages(['purchase' => ['The supplier and warehouse cannot be changed for a Gate Pass Purchase.']]);
        }

        DB::transaction(function () use ($request, $purchase) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            $persisted = PurchaseDetail::where('purchase_id', $purchase->id)->orderBy('id')->get()->keyBy('id');
            $submitted = collect($request->input('details', []))->keyBy(fn ($detail) => (int) ($detail['id'] ?? 0));

            if ($persisted->keys()->sort()->values()->all() !== $submitted->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['details' => ['Gate Pass Purchase products cannot be added or removed.']]);
            }

            foreach ($persisted as $id => $detail) {
                $row = $submitted->get($id);
                if (abs((float) $detail->quantity - (float) ($row['quantity'] ?? 0)) > 0.000001
                    || (int) $detail->product_id !== (int) ($row['product_id'] ?? 0)
                    || (int) ($detail->product_variant_id ?: 0) !== (int) (($row['product_variant_id'] ?? null) ?: 0)
                    || (int) ($detail->purchase_unit_id ?: 0) !== (int) (($row['purchase_unit_id'] ?? null) ?: 0)) {
                    throw ValidationException::withMessages(['details' => ['Gate Pass Purchase products, units, and quantities cannot be changed.']]);
                }

                $detail->update([
                    'cost' => $row['Unit_cost'],
                    'company_rb_price' => $row['company_rb_price'] ?? $row['Unit_cost'],
                    'mrp_price' => $row['mrp_price'] ?? 0,
                    'TaxNet' => $row['tax_percent'] ?? 0,
                    'sales_tax' => $row['taxe'] ?? 0,
                    'withholding_tax' => $row['withholding_tax'] ?? 0,
                    'tax_method' => $row['tax_method'] ?? '1',
                    'discount' => $row['discount'] ?? 0,
                    'discount_method' => $row['discount_Method'] ?? '2',
                    'total' => $row['subtotal'] ?? 0,
                ]);
            }

            $grandTotal = (float) $request->input('GrandTotal', 0);
            $due = $grandTotal - (float) $purchase->paid_amount;
            $paymentStatus = $due <= 0 ? 'paid' : ($due < $grandTotal ? 'partial' : 'unpaid');
            $purchase->update([
                'date' => $request->date,
                'sales_tax_invoice_no' => $request->input('sales_tax_invoice_no'),
                'delivery_note_no' => $request->input('delivery_note_no'),
                'notes' => $request->input('notes'),
                'tax_rate' => $request->input('tax_rate', 0),
                'TaxNet' => $request->input('TaxNet', 0),
                'withholding_tax' => $request->input('withholding_tax', 0),
                'discount' => $request->input('discount', 0),
                'shipping' => $request->input('shipping', 0),
                'GrandTotal' => $grandTotal,
                'payment_statut' => $paymentStatus,
            ]);

            app(\App\Services\Tax\TransactionTaxService::class)
                ->snapshotPurchase($purchase->fresh(), array_values($request->input('details', [])), $request->user('api'));
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);
    }

    // ------ Delete Purchase -------------\\

    public function destroy(Request $request, $id)
    {
        if (Purchase::whereKey($id)->where(fn ($query) => $query->whereNotNull('supplier_invoice_id')->orWhere('inventory_already_received', true))->exists()) {
            throw ValidationException::withMessages(['purchase' => ['Posted procurement Purchases cannot be deleted. Use a controlled accounting reversal.']]);
        }
        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === current_Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                foreach ($old_purchase_details as $key => $value) {

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($current_Purchase->statut == 'received') {

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }
                        }
                    }
                }

                // Pharmacy: reverse batch links before soft-deleting the details.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                $current_Purchase->details()->delete();
                $current_Purchase->update([
                    'deleted_at' => Carbon::now(),
                ]);

                $Payment_purchase_data = PaymentPurchase::where('purchase_id', $id)->get();
                foreach ($Payment_purchase_data as $Payment_purchase) {
                    $account = Account::find($Payment_purchase->account_id);

                    if ($account) {
                        $account->update([
                            'balance' => $account->balance + $Payment_purchase->montant,
                        ]);
                    }

                    $Payment_purchase->delete();
                }

            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;

            foreach ($selectedIds as $purchase_id) {

                if (PurchaseReturn::where('purchase_id', $purchase_id)->where('deleted_at', '=', null)->exists()) {
                    return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
                } else {

                    $current_Purchase = Purchase::findOrFail($purchase_id);
                    if ($current_Purchase->inventory_already_received) {
                        throw ValidationException::withMessages(['purchase' => ['Purchases linked to Gate Passes or Supplier Invoices cannot be deleted in bulk.']]);
                    }

                    /**
                     * Warehouses restriction
                     * Allow if:
                     * - user has access to all warehouses (is_all_warehouses = 1)
                     * - OR sale warehouse_id is in user's assigned warehouses
                    */
                    $user_auth = auth()->user();

                    if (! $user_auth->is_all_warehouses) {
                        $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                            ->pluck('warehouse_id')
                            ->toArray();

                        if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'You are not allowed to access this sale (warehouse restriction).',
                            ], 403);
                        }
                    }

                    $old_purchase_details = PurchaseDetail::where('purchase_id', $purchase_id)->get();
                    // Check If User Has Permission view All Records
                    if (! $view_records) {
                        // Check If User->id === current_Purchase->id
                        $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                    }

                    // Pharmacy: reverse batch consumption (subtracts qty from product_batches,
                    // removes pivot rows) before warehouse stock is decremented below.
                    $batchService = app(BatchService::class);
                    if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                        $batchService->reverseForPurchaseDetails($old_purchase_details);
                    }

                    foreach ($old_purchase_details as $key => $value) {

                        // check if detail has purchase_unit_id Or Null
                        if ($value['purchase_unit_id'] !== null) {
                            $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                        } else {
                            $product_unit_purchase_id = Product::with('unitPurchase')
                                ->where('id', $value['product_id'])
                                ->first();
                            $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                        }

                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->where('product_variant_id', $value['product_variant_id'])
                                    ->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }
                        }
                    }

                    $current_Purchase->details()->delete();
                    $current_Purchase->update([
                        'deleted_at' => Carbon::now(),
                    ]);

                    $Payment_purchase_data = PaymentPurchase::where('purchase_id', $purchase_id)->get();
                    foreach ($Payment_purchase_data as $Payment_purchase) {
                        $account = Account::find($Payment_purchase->account_id);

                        if ($account) {
                            $account->update([
                                'balance' => $account->balance + $Payment_purchase->montant,
                            ]);
                        }

                        $Payment_purchase->delete();
                    }

                }
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);

    }

    // ---------------- Get Details Purchase -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::with(
            'details.product.unitPurchase',
            'details.batches.batch',
            'purchaseOrder:id,number',
            'gatePass:id,number,purchase_order_id',
            'gatePass.purchaseOrder:id,number',
            'gatePasses:id,number,purchase_order_id',
            'gatePasses.purchaseOrder:id,number'
        )
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $purchase_data['Ref'] = $purchase->Ref;
        $purchase_data['date'] = $purchase->date.' '.$purchase->time;
        $purchase_data['sales_tax_invoice_no'] = $purchase->sales_tax_invoice_no;
        $purchase_data['statut'] = $purchase->statut;
        $purchase_data['note'] = $purchase->notes;
        $purchase_data['discount'] = $purchase->discount;
        $purchase_data['shipping'] = $purchase->shipping;
        $purchase_data['tax_rate'] = $purchase->tax_rate;
        $purchase_data['TaxNet'] = $purchase->TaxNet;
        $purchase_data['supplier_name'] = $purchase['provider']->name;
        $purchase_data['supplier_email'] = $purchase['provider']->email;
        $purchase_data['supplier_phone'] = $purchase['provider']->phone;
        $purchase_data['supplier_adr'] = $purchase['provider']->adresse;
        $purchase_data['supplier_tax'] = $purchase['provider']->tax_number;
        $purchase_data['warehouse'] = $purchase['warehouse']->name;
        $purchase_data['GrandTotal'] = number_format($purchase->GrandTotal, 2, '.', '');
        $purchase_data['paid_amount'] = number_format($purchase->paid_amount, 2, '.', '');
        $purchase_data['due'] = number_format($purchase_data['GrandTotal'] - $purchase_data['paid_amount'], 2, '.', '');
        $purchase_data['payment_status'] = $purchase->payment_statut;
        $purchase_data = array_merge($purchase_data, $this->purchaseProcurementReferences($purchase));

        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            $PurchaseReturn = PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->first();
            $purchase_data['purchasereturn_id'] = $PurchaseReturn->id;
            $purchase_data['purchase_has_return'] = 'yes';
        } else {
            $purchase_data['purchase_has_return'] = 'no';
        }

        foreach ($purchase['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total;
            $data['cost'] = $detail->cost;
            $data['unit_purchase'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['discount'] = $detail->discount;

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $detail->batches->map(function ($b) {
                $expiry = optional($b->batch)->expiry_date;
                $mfg = optional($b->batch)->mfg_date;

                return [
                    'batch_no'    => optional($b->batch)->batch_no,
                    'expiry_date' => $expiry ? (is_string($expiry) ? $expiry : $expiry->toDateString()) : null,
                    'mfg_date'    => $mfg ? (is_string($mfg) ? $mfg : $mfg->toDateString()) : null,
                    'qty'         => $b->qty,
                    'unit_cost'   => $b->unit_cost,
                    'barcode'     => optional($b->batch)->barcode,
                    'notes'       => optional($b->batch)->notes,
                ];
            })->toArray();

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'purchase' => $purchase_data,
            'company' => $company,
            'taxes' => \App\Models\TransactionTaxSnapshot::where('transaction_type', 'purchase')->where('transaction_id', $id)->orderBy('priority')->get(),
        ]);

    }

    // --------------- Get Payments of Purchase ----------------\\

    public function Get_Payments(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $payments = PaymentPurchase::with('purchase', 'payment_method')
            ->where('purchase_id', $id)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $purchase->GrandTotal - $purchase->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    // --------------- Reference Number of Purchase ----------------\\

    private function purchaseProcurementReferences(Purchase $purchase): array
    {
        $gatePasses = collect($purchase->gatePasses);
        if ($purchase->gatePass && ! $gatePasses->contains('id', $purchase->gatePass->id)) {
            $gatePasses->push($purchase->gatePass);
        }

        $purchaseOrderNumbers = collect([$purchase->purchaseOrder?->number])
            ->merge($gatePasses->map(fn ($gatePass) => $gatePass->purchaseOrder?->number))
            ->filter()->unique()->values();

        return [
            'purchase_order_number' => $purchaseOrderNumbers->implode(', '),
            'gate_pass_number' => $gatePasses->pluck('number')->filter()->unique()->values()->implode(', '),
        ];
    }

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'PR' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->purchase_prefix) ? $setting->purchase_prefix : 'PR';

        // Get the last purchase with a reference that starts with the prefix
        $last = DB::table('purchases')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            
            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;

    }

    // -------------- purchase PDF -----------\\

    public function Purchase_pdf(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with(
            'details.product.unitPurchase',
            'purchaseOrder:id,number',
            'gatePass:id,number,purchase_order_id',
            'gatePass.purchaseOrder:id,number',
            'gatePasses:id,number,purchase_order_id',
            'gatePasses.purchaseOrder:id,number'
        )
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet, 2, '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount, 2, '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping, 2, '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['sales_tax_invoice_no'] = $Purchase_data->sales_tax_invoice_no;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal, 2, '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount, 2, '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], 2, '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        $purchase = array_merge($purchase, $this->purchaseProcurementReferences($Purchase_data));

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, 2, '.', '');
            $data['total'] = number_format($detail->total, 2, '.', '');
            $data['unit_purchase'] = $unit->ShortName;
            $data['cost'] = number_format($detail->cost, 2, '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, 2, '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->cost * $detail->discount / 100, 2, '.', '');
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($detail->cost, 2, '.', '');
            $data['discount'] = number_format($detail->discount, 2, '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, 2, '.', '');
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = number_format($detail->cost - $data['Net_cost'] - $data['DiscountNet'], 2, '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $purchaseBatchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'purchase' => $purchase,
            'details' => $details,
            'taxes' => \App\Models\TransactionTaxSnapshot::where('transaction_type', 'purchase')->where('transaction_id', $Purchase_data->id)->orderBy('priority')->get(),
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('purchase.pdf');

    }

    /**
     * Returns the purchase invoice HTML (rendered from pdf.purchase_pdf template) instead of a PDF.
     * Uses the same template (`pdf.purchase_pdf`) but returns raw HTML instead of a PDF.
     * This is used by the print functionality, which opens a popup, injects the HTML, and
     * calls window.print().
     */
    public function Purchase_PDF_Inline(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with(
            'details.product.unitPurchase',
            'purchaseOrder:id,number',
            'gatePass:id,number,purchase_order_id',
            'gatePass.purchaseOrder:id,number',
            'gatePasses:id,number,purchase_order_id',
            'gatePasses.purchaseOrder:id,number'
        )
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet, 2, '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount, 2, '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping, 2, '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['sales_tax_invoice_no'] = $Purchase_data->sales_tax_invoice_no;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal, 2, '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount, 2, '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], 2, '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        $purchase = array_merge($purchase, $this->purchaseProcurementReferences($Purchase_data));

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, 2, '.', '');
            $data['total'] = number_format($detail->total, 2, '.', '');
            $data['unit_purchase'] = $unit ? $unit->ShortName : '';
            $data['cost'] = number_format($detail->cost, 2, '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, 2, '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->cost * $detail->discount / 100, 2, '.', '');
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($detail->cost, 2, '.', '');
            $data['discount'] = number_format($detail->discount, 2, '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, 2, '.', '');
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = number_format($detail->cost - $data['Net_cost'] - $data['DiscountNet'], 2, '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $purchaseBatchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'purchase' => $purchase,
            'details' => $details,
            'taxes' => \App\Models\TransactionTaxSnapshot::where('transaction_type', 'purchase')->where('transaction_id', $Purchase_data->id)->orderBy('priority')->get(),
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        // When rendering as HTML in the browser, filesystem paths like public_path('images/...')
        // do not work as <img src>. Convert any ".../public/images/<file>" path (Windows or Unix)
        // into a proper web URL so logos/images display.
        try {
            $webImagesPath = rtrim(url('images'), '/').'/';
            $Html = preg_replace_callback(
                '~(?:[A-Za-z]:)?[\/\\\\][^"\']*?[\/\\\\]public[\/\\\\]images[\/\\\\]([^"\'>]+)~',
                function ($m) use ($webImagesPath) {
                    $file = ltrim($m[1], '/\\');
                    return $webImagesPath.$file;
                },
                $Html
            );
        } catch (\Throwable $e) {
            // If anything goes wrong, fall back to the original HTML.
        }

        // Return raw HTML so the print popup can inject it and call window.print().
        return response($Html);
    }

    // ---------------- Show Form Create Purchase ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $settings = Setting::where('deleted_at', '=', null)->first();
        $managedTaxes = \App\Models\Tax::query()
            ->with(['priceTypes:id,code,name', 'transactionTypes'])
            ->effective()->forTransaction('purchase')
            ->where(function ($query) use ($warehouses) {
                $query->whereDoesntHave('warehouses')
                    ->orWhereHas('warehouses', fn ($warehouseQuery) => $warehouseQuery->whereIn('warehouses.id', $warehouses->pluck('id')));
            })
            ->orderBy('priority')->get();

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
            'default_tax' => (float) ($settings->default_tax ?? 0),
            'managed_taxes' => $managedTaxes,
        ]);
    }

    // -------------Show Form Edit Purchase-----------\\

    public function edit(Request $request, $id)
    {
        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
        } else {

            $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $Purchase_data = Purchase::with([
                'details.product.unitPurchase',
                'gatePasses:id,number,supplier_gate_pass_number,purchase_order_id',
                'gatePass:id,number,supplier_gate_pass_number,purchase_order_id',
            ])
                ->where('deleted_at', '=', null)
                ->findOrFail($id);

            /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($Purchase_data->warehouse_id) || ! in_array($Purchase_data->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $details = [];
            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === Purchase->id
                $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
            }

            if ($Purchase_data->provider_id) {
                if (Provider::where('id', $Purchase_data->provider_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['supplier_id'] = $Purchase_data->provider_id;
                } else {
                    $purchase['supplier_id'] = '';
                }
            } else {
                $purchase['supplier_id'] = '';
            }

            if ($Purchase_data->warehouse_id) {
                if (Warehouse::where('id', $Purchase_data->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['warehouse_id'] = $Purchase_data->warehouse_id;
                } else {
                    $purchase['warehouse_id'] = '';
                }
            } else {
                $purchase['warehouse_id'] = '';
            }

            $purchase['date'] = $Purchase_data->date;
            $purchase['sales_tax_invoice_no'] = $Purchase_data->sales_tax_invoice_no;
            $purchase['delivery_note_no'] = $Purchase_data->delivery_note_no;
            $purchase['tax_rate'] = $Purchase_data->tax_rate;
            $purchase['TaxNet'] = $Purchase_data->TaxNet;
            $purchase['withholding_tax'] = $Purchase_data->withholding_tax ?? 0;
            $purchase['discount'] = $Purchase_data->discount;
            $purchase['shipping'] = $Purchase_data->shipping;
            $purchase['statut'] = $Purchase_data->statut;
            $purchase['notes'] = $Purchase_data->notes;

            // Pharmacy: prefetch batch links keyed by purchase_detail_id.
            $batchService = app(BatchService::class);
            $batchesByDetail = $batchService->batchesForPurchaseDetails($Purchase_data['details']);
            $gatePassAllocations = DB::table('purchase_gate_pass_items as allocation')
                ->join('gate_pass_items as item', 'item.id', '=', 'allocation.gate_pass_item_id')
                ->join('gate_passes as gate_pass', 'gate_pass.id', '=', 'item.gate_pass_id')
                ->where('allocation.purchase_id', $Purchase_data->id)
                ->get([
                    'item.id as gate_pass_item_id', 'item.product_id', 'item.product_variant_id', 'item.unit_id',
                    'gate_pass.number as gate_pass_number',
                ])
                ->keyBy(fn ($allocation) => $allocation->product_id.'|'.($allocation->product_variant_id ?: 0).'|'.($allocation->unit_id ?: 0));

            $detail_id = 0;
            foreach ($Purchase_data['details'] as $detail) {

                // -------check if detail has purchase_unit_id Or Null
                if ($detail->purchase_unit_id !== null) {
                    $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                    $data['no_unit'] = 1;
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $detail->product_id)
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    $data['no_unit'] = 0;
                }

                if ($detail->product_variant_id) {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('deleted_at', '=', null)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->where('warehouse_id', $Purchase_data->warehouse_id)
                        ->first();

                    $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                        ->where('id', $detail->product_variant_id)->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;

                    $data['code'] = $productsVariants->code;
                    $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                    $data['product_variant_id'] = $detail->product_variant_id;

                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } elseif ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }

                } else {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                        ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;
                    $data['product_variant_id'] = null;

                    $data['code'] = $detail['product']['code'];
                    $data['name'] = $detail['product']['name'];

                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } elseif ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }

                }

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['unitPurchase'] = $unit->ShortName;
                $data['purchase_unit_id'] = $unit->id;
                $allocationKey = $detail->product_id.'|'.($detail->product_variant_id ?: 0).'|'.($unit->id ?: 0);
                $gatePassAllocation = $gatePassAllocations->get($allocationKey);
                $data['gate_pass_item_id'] = $gatePassAllocation?->gate_pass_item_id;
                $data['gate_pass_number'] = $gatePassAllocation?->gate_pass_number;
                $data['is_gate_pass_item'] = (bool) $gatePassAllocation;

                $data['is_imei'] = $detail['product']['is_imei'];
                $data['imei_number'] = $detail->imei_number;

                if ($detail->discount_method == '2') {
                    $data['DiscountNet'] = $detail->discount;
                } else {
                    $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
                }

                $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
                $data['Unit_cost'] = $detail->cost;
                $data['company_rb_price'] = $detail->company_rb_price ?: $detail->cost;
                $data['mrp_price'] = $detail->mrp_price ?: $detail->cost;
                $data['withholding_tax'] = $detail->withholding_tax ?? 0;
                $data['tax_percent'] = $detail->TaxNet;
                $data['tax_method'] = $detail->tax_method;
                $data['discount'] = $detail->discount;
                $data['discount_Method'] = $detail->discount_method;

                if ($detail->tax_method == '1') {
                    $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                    $data['taxe'] = $detail->sales_tax ?? $tax_cost;
                    $data['subtotal'] = ($data['Net_cost'] + $data['taxe']) * $data['quantity'];
                } else {
                    $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                    $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                    $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
                }

                // Pharmacy: attach is_batch_tracked + existing batches for this line.
                $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
                $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

                $details[] = $data;
            }

            // get warehouses assigned to user
            $user_auth = auth()->user();
            if ($user_auth->is_all_warehouses) {
                $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
            } else {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
                $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
            }

            $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);
            $managedTaxes = \App\Models\Tax::with(['priceTypes:id,code,name', 'transactionTypes'])
                ->effective()->forTransaction('purchase')->forWarehouse($Purchase_data->warehouse_id)
                ->orderBy('priority')->get();

            return response()->json([
                'details' => $details,
                'purchase' => $purchase,
                'gate_passes' => $Purchase_data->gatePasses
                    ->merge($Purchase_data->gatePass ? collect([$Purchase_data->gatePass]) : collect())
                    ->unique('id')->values()->map(fn ($gatePass) => [
                        'id' => $gatePass->id,
                        'number' => $gatePass->number,
                        'supplier_gate_pass_number' => $gatePass->supplier_gate_pass_number,
                    ]),
                'suppliers' => $suppliers,
                'warehouses' => $warehouses,
                'managed_taxes' => $managedTaxes,
            ]);
        }
    }

    // ------------------- get_Products_by_purchase -----------------\\

    public function get_Products_by_purchase(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'create', PurchaseReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
        }

        $Return_detail['supplier_id'] = $Purchase_data->provider_id;
        $Return_detail['warehouse_id'] = $Purchase_data->warehouse_id;
        $Return_detail['purchase_id'] = $Purchase_data->id;
        $Return_detail['tax_rate'] = 0;
        $Return_detail['TaxNet'] = 0;
        $Return_detail['discount'] = 0;
        $Return_detail['shipping'] = 0;
        $Return_detail['statut'] = 'completed';
        $Return_detail['notes'] = '';

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

                $data['product_variant_id'] = $detail->product_variant_id;

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = $detail->quantity;
            $data['purchase_quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'purchase_return' => $Return_detail,
        ]);

    }

    // ------------------- Get barcode products for a Purchase -----------------\\

    public function get_barcode_products(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();

        $purchase = Purchase::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $products = [];

        foreach ($purchase->details as $detail) {
            $product = $detail->product;

            if (! $product) {
                continue;
            }

            $item = [];
            $barcodeValue = null;
            $product_price = $product->price;

            if ($detail->product_variant_id) {
                $variant = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                if ($variant) {
                    $item['code'] = $variant->code;
                    $item['name'] = '['.$variant->name.']'.$product->name;
                    $barcodeValue = $variant->code;
                    $product_price = $variant->price ?? $product->price;
                } else {
                    $item['code'] = $product->code;
                    $item['name'] = $product->name;
                    $barcodeValue = $product->code;
                }
            } else {
                $item['code'] = $product->code;
                $item['name'] = $product->name;
                $barcodeValue = $product->code;
            }

            // Apply discount
            $price_discounted = $product_price;
            if ($product->discount != 0.0) {
                if ($product->discount_method == '1') {
                    $discount = $product_price * $product->discount / 100;
                } else {
                    $discount = $product->discount;
                }
                $price_discounted = $product_price - $discount;
            }

            // Apply tax
            if ($product->TaxNet != 0.0) {
                if ($product->tax_method == '1') {
                    $tax_price = $price_discounted * $product->TaxNet / 100;
                    $net_price = $price_discounted + $tax_price;
                } else {
                    $net_price = $price_discounted;
                }
            } else {
                $net_price = $price_discounted;
            }

            $item['barcode'] = $barcodeValue;
            $item['Type_barcode'] = $product->Type_barcode ?: 'CODE128';
            $item['Net_price'] = number_format($net_price, 2, '.', '');
            $item['qte'] = $detail->quantity;

            $products[] = $item;
        }

        return response()->json([
            'warehouse_id' => $purchase->warehouse_id,
            'products' => $products,
        ]);
    }

    // ------------- Send Email -----------\\

    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of sale
        $emailMessage = EmailMessage::getForLocale('purchase');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }
        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);

        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, 2, '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, 2, '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, 2, '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $purchase['provider']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', $contact_name, $message_body);
        $message_body = str_replace('{business_name}', $business_name, $message_body);
        $message_body = str_replace('{invoice_url}', $invoice_url, $message_body);
        $message_body = str_replace('{invoice_number}', $invoice_number, $message_body);

        $message_body = str_replace('{total_amount}', $total_amount, $message_body);
        $message_body = str_replace('{paid_amount}', $paid_amount, $message_body);
        $message_body = str_replace('{due_amount}', $due_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();
        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);
        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);
        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, 2, '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, 2, '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, 2, '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        // twilio
        if ($default_sms_gateway->title == 'twilio') {
            try {

                $account_sid = env('TWILIO_SID');
                $auth_token = env('TWILIO_TOKEN');
                $twilio_number = env('TWILIO_FROM');

                $client = new Client_Twilio($account_sid, $auth_token);
                $client->messages->create($receiverNumber, [
                    'from' => $twilio_number,
                    'body' => $message_text]);

            } catch (Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        }
        // termii
        elseif ($default_sms_gateway->title == 'termii') {

            $client = new Client_termi;
            $url = 'https://api.ng.termii.com/api/sms/send';

            $payload = [
                'to' => $receiverNumber,
                'from' => env('TERMI_SENDER'),
                'sms' => $message_text,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => env('TERMI_KEY'),
            ];

            try {
                $response = $client->post($url, [
                    'json' => $payload,
                ]);

                $result = json_decode($response->getBody(), true);

                return response()->json($result);
            } catch (\Exception $e) {
                Log::error('Termii SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => 'Failed to send SMS'], 500);
            }

        }
        // ---- infobip
        elseif ($default_sms_gateway->title == 'infobip') {

            $BASE_URL = env('base_url');
            $API_KEY = env('api_key');
            $SENDER = env('sender_from');

            $configuration = (new Configuration)
                ->setHost($BASE_URL)
                ->setApiKeyPrefix('Authorization', 'App')
                ->setApiKey('Authorization', $API_KEY);

            $client = new Client_guzzle;

            $sendSmsApi = new SendSMSApi($client, $configuration);
            $destination = (new SmsDestination)->setTo($receiverNumber);
            $message = (new SmsTextualMessage)
                ->setFrom($SENDER)
                ->setText($message_text)
                ->setDestinations([$destination]);

            $request = (new SmsAdvancedTextualRequest)->setMessages([$message]);

            try {
                $smsResponse = $sendSmsApi->sendSmsMessage($request);
                echo 'Response body: '.$smsResponse;
            } catch (Throwable $apiException) {
                echo 'HTTP Code: '.$apiException->getCode()."\n";
            }

        }
        // ---- custom
        elseif ($default_sms_gateway->title == 'custom') {
            try {
                (new \App\Services\CustomSmsGateway)->send($receiverNumber, $message_text);
            } catch (\Throwable $e) {
                Log::error('Custom SMS Error: '.$e->getMessage());

                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json(['success' => true]);

    }

    // purchase_send_whatsapp
    public function purchase_send_whatsapp(Request $request)
    {

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);
        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, 2, '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, 2, '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, 2, '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // Check if the phone number is empty or null
        if (empty($receiverNumber) || $receiverNumber == null || $receiverNumber == 'null' || $receiverNumber == '') {
            return response()->json(['error' => 'Phone number is missing'], 400);
        }

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        return response()->json(['message' => $message_text, 'phone' => $receiverNumber]);

    }

    // ---------------- get_import_purchases ---------------\\

    public function get_import_purchases(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
        ]);
    }

    // ------ preview_import_purchases (parse CSV without saving) -------------\\

    public function preview_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        // request_products_csv returns a JsonResponse when parsing/validation fails
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        if ($data === null || !is_array($data)) {
            return response()->json([
                'status' => false,
                'msg' => 'Invalid CSV file',
            ]);
        }

        $rows = [];
        $subtotal = 0;

        foreach ($data as $row) {
            $product = Product::with('unitPurchase')
                ->where('deleted_at', '=', null)
                ->where('code', $row['productcode'])
                ->first();

            if (!$product) {
                continue;
            }

            $qty = (float) ($row['qty'] ?? 0);
            $cost = (float) $product->cost;
            $total = $qty * $cost;
            $subtotal += $total;

            $rows[] = [
                'code' => $product->code,
                'name' => $product->name,
                'qty' => $qty,
                'cost' => $cost,
                'total' => $total,
                'unit' => optional($product->unitPurchase)->ShortName,
                'is_batch_tracked' => (bool) ($product->is_batch_tracked ?? false),
                'product_id' => $product->id,
            ];
        }

        return response()->json([
            'status' => true,
            'rows' => $rows,
            'subtotal' => $subtotal,
            'count' => count($rows),
        ]);
    }

    // ------ store_import_purchases -------------\\

    public function store_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
        ]);

        // Pharmacy: decode batches payload (map of productcode → batches[]).
        $batchesByCode = [];
        $rawBatches = $request->input('batches_by_code');
        if (is_string($rawBatches) && $rawBatches !== '') {
            $decoded = json_decode($rawBatches, true);
            if (is_array($decoded)) {
                $batchesByCode = $decoded;
            }
        }

        \DB::transaction(function () use ($request, $data, $batchesByCode) {
            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = 0;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = 0;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $total = 0;
            $inputDetailsForBatches = [];
            foreach ($data as $key => $value) {

                $product = Product::where('deleted_at', '=', null)->where('code', $value['productcode'])->first();
                $unit = Unit::where('id', $product->unit_purchase_id)->first();

                $total += $value['qty'] * $product->cost;

                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['qty'],
                    'cost' => $product->cost,
                    'purchase_unit_id' => $product->unit_purchase_id,
                    'TaxNet' => 0,
                    'tax_method' => 1,
                    'discount' => 0,
                    'discount_method' => 2,
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'total' => $value['qty'] * $product->cost,
                    'imei_number' => null,
                ];

                $inputDetailsForBatches[] = [
                    'product_id' => $product->id,
                    'batches' => $batchesByCode[$value['productcode']] ?? [],
                ];

                if ($order->statut == 'received') {

                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->where('product_id', $product->id)
                        ->first();

                    if ($unit && $product_warehouse) {
                        if ($unit->operator == '/') {
                            $product_warehouse->qte += $value['qty'] / $unit->operator_value;
                        } else {
                            $product_warehouse->qte += $value['qty'] * $unit->operator_value;
                        }
                        $product_warehouse->save();
                    }
                }
            }
            PurchaseDetail::insert($orderDetails);

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received') {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $inputDetailsForBatches, $persisted);
            }

            //  Calculte Grand_Total
            $purchase_data = Purchase::where('id', $order->id)->first();

            $total_without_discount = $total - $purchase_data->discount;

            $TaxNet = ($total_without_discount * $purchase_data->tax_rate) / 100;

            $purchase_data->TaxNet = $TaxNet;

            $purchase_data->GrandTotal = $total_without_discount + $TaxNet + $purchase_data->shipping;
            $purchase_data->save();

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    // import Products
    public function request_products_csv(Request $request)
    {

        ini_set('max_execution_time', 2000);

        $file = $request->file('products');
        $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        if ($ext != 'csv') {
            return response()->json([
                'msg' => 'must be in csv format',
                'status' => false,
            ]);
        } else {
            // Read the CSV file
            $data = [];
            $rowcount = 0;
            if (($handle = fopen($file->getPathname(), 'r')) !== false) {
                $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
                $header = fgetcsv($handle, $max_line_length, ';'); // Use semicolon as the delimiter

                // Process the header row
                $escapedHeader = [];
                foreach ($header as $key => $value) {
                    $lheader = strtolower($value);
                    $escapedItem = preg_replace('/[^a-z]/', '', $lheader);
                    $escapedHeader[] = $escapedItem;
                }

                $header_colcount = count($header);
                while (($row = fgetcsv($handle, $max_line_length, ';')) !== false) { // Use semicolon as the delimiter
                    $row_colcount = count($row);
                    if ($row_colcount == $header_colcount) {
                        $entry = array_combine($escapedHeader, $row);
                        $data[] = $entry;
                    } else {
                        return null;
                    }
                    $rowcount++;
                }
                fclose($handle);
            } else {
                return null;
            }

            // Clean the data
            $cleanedData = [];
            foreach ($data as $row) {
                $cleanedRow = [];
                foreach ($row as $key => $value) {
                    $cleanedKey = trim($key);
                    $cleanedRow[$cleanedKey] = $value;
                }
                $cleanedData[] = $cleanedRow;
            }

            // Check for duplicate productcode in CSV
            $productCodes = array_column($cleanedData, 'productcode');
            if (count($productCodes) !== count(array_unique($productCodes))) {
                return response()->json([
                    'msg' => 'Duplicate product code found in CSV file',
                    'status' => false,
                ]);
            }

            // Validate productcode existence in the database
            $missingProductCodes = [];
            foreach ($productCodes as $code) {
                if (! Product::where('code', $code)->exists()) {
                    $missingProductCodes[] = $code;
                }
            }

            if (! empty($missingProductCodes)) {
                return response()->json([
                    'msg' => 'The following product codes do not exist in the database: '.implode(', ', $missingProductCodes),
                    'status' => false,
                ]);
            }

            // Define validation rules
            $rules = [];
            foreach ($cleanedData as $index => $row) {
                $rules[$index.'.productcode'] = 'required';
                $rules[$index.'.qty'] = 'required|numeric';
            }

            // Validate the data
            $validator = validator()->make($cleanedData, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'msg' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'status' => false,
                ]);
            }

            // Return the cleaned data
            return $cleanedData;

        }
    }

    // ------------- Get Purchase Documents ----------\\
    public function getDocuments($purchaseId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);
        
        $documents = DB::table('purchase_documents')
            ->where('purchase_id', $purchaseId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'documents' => $documents,
            'status' => true
        ]);
    }

    // ------------- Upload Purchase Documents ----------\\
    public function uploadDocuments(Request $request, $purchaseId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);

        $request->validate([
            'documents.*' => 'required|file|max:10240', // Max 10MB per file
        ]);

        $uploadedDocuments = [];

        if ($request->hasFile('documents')) {
            // Create directory if it doesn't exist
            $uploadPath = public_path('images/purchase_documents');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            foreach ($request->file('documents') as $file) {
                // Capture metadata BEFORE moving the file (tmp file is still readable)
                $originalName = $file->getClientOriginalName();
                $size = $file->getSize();
                $mimeType = $file->getMimeType();

                $filename = time() . '_' . Str::random(10) . '_' . $originalName;
                
                // Move file to public/images/purchase_documents
                $file->move($uploadPath, $filename);
                
                $relativePath = 'images/purchase_documents/' . $filename;

                $documentId = DB::table('purchase_documents')->insertGetId([
                    'purchase_id' => $purchaseId,
                    'name' => $originalName,
                    'path' => $relativePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $uploadedDocuments[] = $documentId;
            }
        }

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'documents' => $uploadedDocuments,
            'status' => true
        ]);
    }

    // ------------- Download Purchase Document ----------\\
    public function downloadDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found in database',
                'status' => false
            ], 404);
        }

        $filePath = public_path($document->path);

        if (!file_exists($filePath)) {
            return response()->json([
                'message' => 'Physical file not found on server',
                'status' => false,
                'path' => $document->path
            ], 404);
        }

        return response()->download($filePath, $document->name);
    }

    // ------------- Delete Purchase Document ----------\\
    public function deleteDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'delete', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found',
                'status' => false
            ], 404);
        }

        // Soft delete
        DB::table('purchase_documents')
            ->where('id', $documentId)
            ->update(['deleted_at' => Carbon::now()]);

        // Optionally delete the physical file
        $filePath = public_path($document->path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return response()->json([
            'message' => 'Document deleted successfully',
            'status' => true
        ]);
    }
}
