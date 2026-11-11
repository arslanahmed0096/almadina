<?php

namespace App\Services\Procurement;

use App\Enums\GatePassStatus;
use App\Models\GatePass;
use App\Models\GatePassItem;
use App\Models\ProcurementStockMovement;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Unit;
use App\Models\UserWarehouse;
use App\Services\Tax\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GatePassService
{
    public function __construct(
        private ProcurementAuditService $audit,
        private PurchaseOrderProgressService $progress,
        private ProcurementNotificationService $notifications,
    ) {}

    public function create(?PurchaseOrder $order, array $data, $user): GatePass
    {
        $warehouseId = $order?->warehouse_id ?? (int) $data['warehouse_id'];
        $this->assertWarehouse($user, $warehouseId);

        return DB::transaction(function () use ($order, $data, $user) {
            $order = $order ? PurchaseOrder::lockForUpdate()->findOrFail($order->id) : null;
            if ($order && ! in_array($order->status, ['draft', 'issued', 'partially_received', 'fully_received', 'partially_invoiced', 'fully_invoiced', 'partially_purchased'], true)) {
                throw ValidationException::withMessages(['purchase_order_id' => ['Gate Passes can only be recorded against an open Purchase Order.']]);
            }
            if ($order?->status === 'draft') {
                $order->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $user->id]);
                $this->audit->record($order, 'issued', ['status' => 'draft'], ['status' => 'issued'], 'Automatically issued when the first Gate Pass was recorded.');
            }
            $gatePass = GatePass::create([
                'number' => 'PENDING-'.Str::uuid(), 'supplier_gate_pass_number' => $data['supplier_gate_pass_number'] ?? null,
                'purchase_order_id' => $order?->id, 'receipt_type' => $order ? 'purchase_order' : 'direct',
                'provider_id' => $order?->provider_id ?? $data['provider_id'],
                'warehouse_id' => $order?->warehouse_id ?? $data['warehouse_id'],
                'delivered_at' => $data['delivered_at'], 'bilty_number' => $data['bilty_number'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null, 'driver_name' => $data['driver_name'] ?? null,
                'driver_phone' => $data['driver_phone'] ?? null, 'received_by' => $user->id,
                'notes' => $data['notes'] ?? null, 'status' => ! empty($data['submit_for_verification']) ? GatePassStatus::PendingVerification->value : GatePassStatus::Draft->value,
            ]);
            $gatePass->update(['number' => 'GP-'.date('Y').'-'.str_pad((string) $gatePass->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($data['items'] as $index => $row) {
                $poItem = $order ? PurchaseOrderItem::where('purchase_order_id', $order->id)->find($row['purchase_order_item_id'] ?? 0) : null;
                if ($order && ! $poItem) {
                    throw ValidationException::withMessages(["items.$index.purchase_order_item_id" => ['The product is not on this Purchase Order.']]);
                }
                $product = $poItem?->product ?? Product::whereNull('deleted_at')->findOrFail($row['product_id']);
                $variant = $poItem?->variant;
                if (! $order && ! empty($row['product_variant_id'])) {
                    $variant = ProductVariant::where('product_id', $product->id)->findOrFail($row['product_variant_id']);
                }
                $unit = $poItem?->unit ?? (! empty($row['unit_id']) ? Unit::findOrFail($row['unit_id']) : $product->unitPurchase);
                $delivered = (string) $row['delivered_quantity'];
                $accepted = (string) ($row['accepted_quantity'] ?? 0);
                $rejected = (string) ($row['rejected_quantity'] ?? 0);
                if (bccomp($delivered, '0', 6) !== 1) {
                    throw ValidationException::withMessages(["items.$index.delivered_quantity" => ['Delivered quantity must be greater than zero.']]);
                }
                if (bccomp(Decimal::add($accepted, $rejected), $delivered, 6) === 1) {
                    throw ValidationException::withMessages(["items.$index.accepted_quantity" => ['Accepted plus rejected quantity cannot exceed delivered quantity.']]);
                }
                $gatePass->items()->create([
                    'purchase_order_item_id' => $poItem?->id, 'product_id' => $product->id,
                    'product_variant_id' => $variant?->id, 'unit_id' => $unit?->id,
                    'product_name' => $poItem?->product_name ?? $product->name,
                    'variant_name' => $poItem?->variant_name ?? $variant?->name,
                    'sku' => $poItem?->sku ?? ($variant?->code ?: $product->code),
                    'unit_name' => $poItem?->unit_name ?? ($unit?->ShortName ?: $unit?->name),
                    'default_unit_cost' => $poItem?->unit_price ?? ($row['unit_cost'] ?? $variant?->cost ?? $product->cost ?? 0),
                    'delivered_quantity' => $delivered,
                    'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                    'short_quantity' => $row['short_quantity'] ?? 0, 'over_delivery_reason' => $row['over_delivery_reason'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
            }
            $this->audit->record($gatePass, $order ? 'recorded' : 'direct_receipt_recorded', [], $gatePass->load('items')->toArray(), $gatePass->notes, $order?->id);
            $this->notifications->send('gate_passes_confirm', $gatePass->warehouse_id, 'gate_pass_recorded', "Gate Pass {$gatePass->number} requires verification.", $gatePass->number, '/app/procurement/gate-passes/'.$gatePass->id);

            return $gatePass->fresh(['items.purchaseOrderItem', 'purchaseOrder', 'provider', 'warehouse']);
        });
    }

    public function confirm(GatePass $gatePass, $user): GatePass
    {
        $this->assertWarehouse($user, $gatePass->warehouse_id);

        return DB::transaction(function () use ($gatePass, $user) {
            $gatePass = GatePass::lockForUpdate()->findOrFail($gatePass->id);
            if (! in_array($gatePass->status, [GatePassStatus::Draft->value, GatePassStatus::PendingVerification->value], true)) {
                throw ValidationException::withMessages(['status' => ['This Gate Pass has already been finalized.']]);
            }
            $order = $gatePass->purchase_order_id ? PurchaseOrder::lockForUpdate()->findOrFail($gatePass->purchase_order_id) : null;
            $acceptedTotal = '0';
            $rejectedTotal = '0';
            foreach ($gatePass->items()->with('purchaseOrderItem')->lockForUpdate()->get() as $item) {
                $poItem = $item->purchase_order_item_id ? PurchaseOrderItem::lockForUpdate()->findOrFail($item->purchase_order_item_id) : null;
                $alreadyReceived = $poItem ? (string) DB::table('gate_pass_items as i')->join('gate_passes as g', 'g.id', '=', 'i.gate_pass_id')
                    ->where('i.purchase_order_item_id', $poItem->id)->where('g.id', '<>', $gatePass->id)
                    ->whereIn('g.status', [GatePassStatus::Accepted->value, GatePassStatus::PartiallyAccepted->value])->sum('i.accepted_quantity') : '0';

                $remaining = $poItem ? Decimal::sub($poItem->ordered_quantity, $alreadyReceived) : null;
                if ($poItem && bccomp($item->accepted_quantity, $remaining, 6) === 1) {
                    if (! $user->canProcurement('procurement_over_delivery_approve') || blank($item->over_delivery_reason)) {
                        throw ValidationException::withMessages(['items' => ["Accepted quantity for {$poItem->product_name} exceeds the remaining {$remaining}. An authorized reason is required."]]);
                    }
                    $item->update(['over_delivery_approved' => true, 'over_delivery_approved_by' => $user->id]);
                    $this->audit->record($item, 'over_delivery_approved', ['remaining' => $remaining], ['accepted' => $item->accepted_quantity], $item->over_delivery_reason, $order?->id);
                }
                if (bccomp($item->accepted_quantity, '0', 6) === 1) {
                    $this->receiveStock($gatePass, $item, $user->id);
                }
                $acceptedTotal = Decimal::add($acceptedTotal, $item->accepted_quantity);
                $rejectedTotal = Decimal::add($rejectedTotal, $item->rejected_quantity);
            }
            $status = bccomp($acceptedTotal, '0', 6) === 0 ? GatePassStatus::Rejected->value
                : (bccomp($rejectedTotal, '0', 6) === 1 ? GatePassStatus::PartiallyAccepted->value : GatePassStatus::Accepted->value);
            $gatePass->update(['status' => $status, 'confirmed_at' => now(), 'confirmed_by' => $user->id]);
            $this->audit->record($gatePass, 'confirmed', [], ['status' => $status, 'accepted_quantity' => $acceptedTotal, 'rejected_quantity' => $rejectedTotal], null, $order?->id);
            if ($status === GatePassStatus::PartiallyAccepted->value) {
                $this->notifications->send('gate_passes_view', $gatePass->warehouse_id, 'gate_pass_partially_accepted', "Gate Pass {$gatePass->number} was partially accepted.", $gatePass->number, '/app/procurement/gate-passes/'.$gatePass->id);
            } elseif ($status === GatePassStatus::Rejected->value) {
                $this->notifications->send('gate_passes_view', $gatePass->warehouse_id, 'gate_pass_rejected', "Gate Pass {$gatePass->number} was rejected.", $gatePass->number, '/app/procurement/gate-passes/'.$gatePass->id);
            }
            $poStatus = $order ? $this->progress->refreshStatus($order) : null;
            if ($order && in_array($poStatus, ['fully_received', 'completed'], true)) {
                $this->notifications->send('purchase_orders_view', $order->warehouse_id, 'purchase_order_fully_received', "Purchase Order {$order->number} is fully received.", $order->number, '/app/procurement/purchase-orders/'.$order->id);
            }

            return $gatePass->fresh(['items.purchaseOrderItem', 'purchaseOrder']);
        });
    }

    public function updateQuantities(GatePass $gatePass, array $rows, $user): GatePass
    {
        $this->assertWarehouse($user, $gatePass->warehouse_id);

        return DB::transaction(function () use ($gatePass, $rows, $user) {
            $gatePass = GatePass::lockForUpdate()->findOrFail($gatePass->id);
            if (! in_array($gatePass->status, [
                GatePassStatus::Draft->value,
                GatePassStatus::PendingVerification->value,
                GatePassStatus::Accepted->value,
                GatePassStatus::PartiallyAccepted->value,
            ], true)) {
                throw ValidationException::withMessages([
                    'items' => ['Quantities cannot be changed on a rejected or cancelled Gate Pass.'],
                ]);
            }

            $items = GatePassItem::where('gate_pass_id', $gatePass->id)->lockForUpdate()->get()->keyBy('id');
            $submitted = collect($rows)->keyBy(fn ($row) => (int) $row['id']);
            if ($items->keys()->sort()->values()->all() !== $submitted->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'items' => ['Submit a quantity for every existing Gate Pass item. Products cannot be added or removed while editing.'],
                ]);
            }

            $isFinalized = in_array($gatePass->status, [
                GatePassStatus::Accepted->value,
                GatePassStatus::PartiallyAccepted->value,
            ], true);
            $before = $items->map(fn ($item) => [
                'id' => $item->id,
                'delivered_quantity' => (string) $item->delivered_quantity,
                'accepted_quantity' => (string) $item->accepted_quantity,
                'rejected_quantity' => (string) $item->rejected_quantity,
            ])->values()->all();

            foreach ($items as $item) {
                $newQuantity = (string) $submitted[$item->id]['delivered_quantity'];
                $usedByInvoices = (string) DB::table('supplier_invoice_items as invoice_items')
                    ->join('supplier_invoices as invoices', 'invoices.id', '=', 'invoice_items.supplier_invoice_id')
                    ->where('invoice_items.gate_pass_item_id', $item->id)
                    ->where('invoices.status', '<>', 'cancelled')
                    ->sum('invoice_items.quantity');
                $usedByPurchases = (string) DB::table('purchase_gate_pass_items as allocations')
                    ->join('purchases', 'purchases.id', '=', 'allocations.purchase_id')
                    ->where('allocations.gate_pass_item_id', $item->id)
                    ->whereNull('purchases.deleted_at')
                    ->where('purchases.posting_status', '<>', 'cancelled')
                    ->sum('allocations.quantity');
                $usedQuantity = Decimal::add($usedByInvoices, $usedByPurchases);
                if (bccomp($newQuantity, $usedQuantity, 6) === -1) {
                    throw ValidationException::withMessages([
                        'items' => ["{$item->product_name} cannot be reduced below {$usedQuantity} because that quantity is already invoiced or purchased."],
                    ]);
                }

                if ($item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::lockForUpdate()->findOrFail($item->purchase_order_item_id);
                    $receivedByOtherGatePasses = (string) DB::table('gate_pass_items as other_items')
                        ->join('gate_passes as other_gates', 'other_gates.id', '=', 'other_items.gate_pass_id')
                        ->where('other_items.purchase_order_item_id', $poItem->id)
                        ->where('other_items.id', '<>', $item->id)
                        ->whereIn('other_gates.status', [GatePassStatus::Accepted->value, GatePassStatus::PartiallyAccepted->value])
                        ->sum('other_items.accepted_quantity');
                    $available = Decimal::sub($poItem->ordered_quantity, $receivedByOtherGatePasses);
                    if (bccomp($newQuantity, $available, 6) === 1) {
                        throw ValidationException::withMessages([
                            'items' => ["{$item->product_name} cannot exceed the remaining Purchase Order quantity of {$available}."],
                        ]);
                    }
                }

                if ($isFinalized) {
                    $movement = ProcurementStockMovement::where('gate_pass_item_id', $item->id)->lockForUpdate()->first();
                    if (! $movement) {
                        throw ValidationException::withMessages([
                            'items' => ["The stock movement for {$item->product_name} is missing, so its quantity cannot be changed safely."],
                        ]);
                    }

                    $unit = $item->unit_id ? Unit::find($item->unit_id) : null;
                    $newStockQuantity = $newQuantity;
                    if ($unit && (float) $unit->operator_value > 0) {
                        $newStockQuantity = $unit->operator === '/'
                            ? Decimal::div($newStockQuantity, $unit->operator_value)
                            : Decimal::mul($newStockQuantity, $unit->operator_value);
                    }
                    $difference = Decimal::sub($newStockQuantity, $movement->quantity);

                    if (bccomp($difference, '0', 6) !== 0) {
                        $stockQuery = product_warehouse::whereNull('deleted_at')
                            ->where('warehouse_id', $movement->warehouse_id)
                            ->where('product_id', $movement->product_id);
                        $movement->product_variant_id
                            ? $stockQuery->where('product_variant_id', $movement->product_variant_id)
                            : $stockQuery->whereNull('product_variant_id');
                        $stock = $stockQuery->lockForUpdate()->firstOrFail();
                        if (bccomp($difference, '0', 6) === -1
                            && bccomp((string) $stock->qte, ltrim($difference, '-'), 6) === -1) {
                            throw ValidationException::withMessages([
                                'items' => ["{$item->product_name} cannot be reduced by ".ltrim($difference, '-')." because only {$stock->qte} remains in this warehouse."],
                            ]);
                        }
                        $stock->qte = (float) Decimal::add((string) $stock->qte, $difference);
                        $stock->save();
                    }

                    $metadata = $movement->metadata ?: [];
                    $metadata['accepted_quantity'] = $newQuantity;
                    $movement->update(['quantity' => $newStockQuantity, 'metadata' => $metadata]);
                }

                $item->update([
                    'delivered_quantity' => $newQuantity,
                    'accepted_quantity' => $newQuantity,
                    'rejected_quantity' => 0,
                    'short_quantity' => 0,
                ]);
            }

            $after = $gatePass->items()->get()->map(fn ($item) => [
                'id' => $item->id,
                'delivered_quantity' => (string) $item->delivered_quantity,
                'accepted_quantity' => (string) $item->accepted_quantity,
                'rejected_quantity' => (string) $item->rejected_quantity,
            ])->all();
            if ($before !== $after) {
                $this->audit->record(
                    $gatePass,
                    'quantities_updated_by_super_admin',
                    ['items' => $before],
                    ['items' => $after],
                    'Gate Pass quantities corrected by Super Admin.',
                    $gatePass->purchase_order_id
                );
            }
            if ($gatePass->purchase_order_id) {
                $this->progress->refreshStatus(PurchaseOrder::findOrFail($gatePass->purchase_order_id));
            }

            return $gatePass->fresh(['items.purchaseOrderItem', 'purchaseOrder', 'provider', 'warehouse']);
        });
    }

    public function reject(GatePass $gatePass, $user, string $reason): GatePass
    {
        return DB::transaction(function () use ($gatePass, $user, $reason) {
            $gatePass = GatePass::lockForUpdate()->findOrFail($gatePass->id);
            if (! in_array($gatePass->status, ['draft', 'pending_verification'], true)) {
                throw ValidationException::withMessages(['status' => ['Finalized Gate Passes require a stock reversal, not rejection.']]);
            }
            $gatePass->update(['status' => 'rejected', 'confirmed_at' => now(), 'confirmed_by' => $user->id, 'status_reason' => $reason]);
            $this->audit->record($gatePass, 'rejected', [], ['status' => 'rejected'], $reason, $gatePass->purchase_order_id);

            return $gatePass;
        });
    }

    public function cancel(GatePass $gatePass, $user, string $reason): GatePass
    {
        $this->assertWarehouse($user, $gatePass->warehouse_id);

        return DB::transaction(function () use ($gatePass, $user, $reason) {
            $gatePass = GatePass::lockForUpdate()->findOrFail($gatePass->id);
            if ($gatePass->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['This Gate Pass is already cancelled.']]);
            }
            if ($gatePass->supplierInvoices()->where('status', '<>', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['status' => ['Cancel linked supplier invoices before reversing this Gate Pass.']]);
            }
            if ($gatePass->purchases()->whereNull('purchases.deleted_at')->exists()) {
                throw ValidationException::withMessages(['status' => ['This Gate Pass is linked to a Purchase and cannot be cancelled.']]);
            }
            foreach (ProcurementStockMovement::where('gate_pass_id', $gatePass->id)->whereNull('reversed_at')->lockForUpdate()->get() as $movement) {
                $stockQuery = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $movement->warehouse_id)->where('product_id', $movement->product_id);
                $movement->product_variant_id ? $stockQuery->where('product_variant_id', $movement->product_variant_id) : $stockQuery->whereNull('product_variant_id');
                $stock = $stockQuery->lockForUpdate()->firstOrFail();
                if (bccomp((string) $stock->qte, (string) $movement->quantity, 6) === -1) {
                    throw ValidationException::withMessages(['stock' => ['Gate Pass stock can no longer be reversed because part of it has already left this warehouse.']]);
                }
                $stock->qte = (float) Decimal::sub((string) $stock->qte, (string) $movement->quantity);
                $stock->save();
                $movement->update(['reversed_at' => now(), 'reversed_by' => $user->id, 'reversal_reason' => $reason]);
            }
            $old = ['status' => $gatePass->status];
            $gatePass->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'status_reason' => $reason]);
            $this->audit->record($gatePass, 'cancelled_and_stock_reversed', $old, ['status' => 'cancelled'], $reason, $gatePass->purchase_order_id);
            if ($gatePass->purchase_order_id) {
                $this->progress->refreshStatus(PurchaseOrder::findOrFail($gatePass->purchase_order_id));
            }

            return $gatePass;
        });
    }

    private function receiveStock(GatePass $gatePass, $item, int $userId): void
    {
        if (ProcurementStockMovement::where('gate_pass_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['Stock has already been posted for this Gate Pass item.']]);
        }
        $unit = $item->unit_id ? Unit::find($item->unit_id) : null;
        $baseQty = (string) $item->accepted_quantity;
        if ($unit && (float) $unit->operator_value > 0) {
            $baseQty = $unit->operator === '/' ? Decimal::div($baseQty, $unit->operator_value) : Decimal::mul($baseQty, $unit->operator_value);
        }
        $stockQuery = product_warehouse::whereNull('deleted_at')->where('warehouse_id', $gatePass->warehouse_id)
            ->where('product_id', $item->product_id);
        $item->product_variant_id ? $stockQuery->where('product_variant_id', $item->product_variant_id) : $stockQuery->whereNull('product_variant_id');
        $stock = $stockQuery->lockForUpdate()->first();
        if (! $stock) {
            $stock = product_warehouse::create(['warehouse_id' => $gatePass->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'qte' => 0]);
        }
        $stock->qte = (float) Decimal::add((string) $stock->qte, $baseQty);
        $stock->save();
        ProcurementStockMovement::create([
            'purchase_order_id' => $gatePass->purchase_order_id, 'gate_pass_id' => $gatePass->id,
            'gate_pass_item_id' => $item->id, 'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id, 'warehouse_id' => $gatePass->warehouse_id,
            'quantity' => $baseQty, 'reference' => $gatePass->number, 'performed_by' => $userId,
            'metadata' => ['accepted_quantity' => (string) $item->accepted_quantity, 'unit_id' => $item->unit_id, 'physical_receipt' => true, 'receipt_type' => $gatePass->receipt_type],
        ]);
    }

    private function assertWarehouse($user, int $warehouseId): void
    {
        if ($user->isSuperAdmin() || $user->is_all_warehouses) {
            return;
        }
        if (! UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists()) {
            abort(403, 'Warehouse access denied.');
        }
    }
}
