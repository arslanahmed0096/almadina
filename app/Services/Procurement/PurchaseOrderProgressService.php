<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

class PurchaseOrderProgressService
{
    public function progress(PurchaseOrder $order): array
    {
        $order->loadMissing([
            'items.gatePassItems.gatePass',
            'items.gatePassItems.supplierInvoiceItems.supplierInvoice',
            'items.gatePassItems.supplierInvoiceItems.supplierInvoice.purchase.details',
            'supplierInvoices.purchase',
        ]);

        $lines = $order->items->map(function ($item) {
            $validReceipts = $item->gatePassItems->filter(fn ($line) => in_array($line->gatePass?->status, ['accepted', 'partially_accepted'], true));
            $received = $validReceipts->sum(fn ($line) => (float) $line->accepted_quantity);
            $rejected = $validReceipts->sum(fn ($line) => (float) $line->rejected_quantity);
            $invoiceLines = $validReceipts->flatMap->supplierInvoiceItems
                ->filter(fn ($line) => $line->supplierInvoice?->status !== 'cancelled');
            $invoiced = $invoiceLines->sum(fn ($line) => (float) $line->quantity);
            $posted = $invoiceLines->filter(fn ($line) => $line->supplierInvoice?->purchase?->posting_status === 'posted')
                ->sum(fn ($line) => (float) $line->quantity);

            return [
                'purchase_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product' => $item->product_name,
                'model' => $item->variant_name,
                'sku' => $item->sku,
                'ordered' => (float) $item->ordered_quantity,
                'received' => $received,
                'rejected' => $rejected,
                'remaining' => max(0, (float) $item->ordered_quantity - $received),
                'invoiced' => $invoiced,
                'not_invoiced' => max(0, $received - $invoiced),
                'purchased' => $posted,
            ];
        })->values();

        $sum = fn (string $key) => (float) $lines->sum($key);

        return [
            'lines' => $lines,
            'totals' => [
                'ordered' => $sum('ordered'), 'received' => $sum('received'), 'rejected' => $sum('rejected'),
                'remaining' => $sum('remaining'), 'invoiced' => $sum('invoiced'),
                'not_invoiced' => $sum('not_invoiced'), 'purchased' => $sum('purchased'),
                'order_value' => (float) $order->grand_total,
                'invoiced_value' => (float) $order->supplierInvoices->where('status', '!=', 'cancelled')->sum('grand_total'),
                'purchased_value' => (float) $order->supplierInvoices->filter(fn ($invoice) => $invoice->purchase?->posting_status === 'posted')->sum('grand_total'),
            ],
        ];
    }

    public function refreshStatus(PurchaseOrder $order): string
    {
        if ($order->status === PurchaseOrderStatus::Cancelled->value || $order->status === PurchaseOrderStatus::Draft->value) {
            return $order->status;
        }

        $totals = $this->progress($order->fresh())['totals'];
        $ordered = $totals['ordered'];
        $status = PurchaseOrderStatus::Issued->value;
        if ($ordered > 0 && $totals['received'] >= $ordered && $totals['invoiced'] >= $ordered && $totals['purchased'] >= $ordered) {
            $status = PurchaseOrderStatus::Completed->value;
        } elseif ($totals['purchased'] > 0) {
            $status = PurchaseOrderStatus::PartiallyPurchased->value;
        } elseif ($ordered > 0 && $totals['invoiced'] >= $ordered) {
            $status = PurchaseOrderStatus::FullyInvoiced->value;
        } elseif ($totals['invoiced'] > 0) {
            $status = PurchaseOrderStatus::PartiallyInvoiced->value;
        } elseif ($ordered > 0 && $totals['received'] >= $ordered) {
            $status = PurchaseOrderStatus::FullyReceived->value;
        } elseif ($totals['received'] > 0) {
            $status = PurchaseOrderStatus::PartiallyReceived->value;
        }
        $order->forceFill(['status' => $status])->save();

        return $status;
    }
}
