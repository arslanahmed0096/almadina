<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\Procurement\ProcurementNotificationService;
use App\Services\Procurement\PurchaseOrderProgressService;
use Illuminate\Console\Command;

class NotifyOverduePurchaseOrders extends Command
{
    protected $signature = 'procurement:notify-overdue';

    protected $description = 'Notify authorized users about overdue Purchase Orders with pending quantities';

    public function handle(PurchaseOrderProgressService $progress, ProcurementNotificationService $notifications): int
    {
        PurchaseOrder::whereNotNull('expected_delivery_date')->whereDate('expected_delivery_date', '<', today())
            ->whereNotIn('status', ['draft', 'completed', 'cancelled'])->chunkById(100, function ($orders) use ($progress, $notifications) {
                foreach ($orders as $order) {
                    $remaining = $progress->progress($order)['totals']['remaining'];
                    if ($remaining <= 0) {
                        continue;
                    }
                    $notifications->send('purchase_orders_view', $order->warehouse_id, 'purchase_order_overdue', "Purchase Order {$order->number} is overdue with {$remaining} units pending.", $order->number, '/app/procurement/purchase-orders/'.$order->id);
                }
            });

        return self::SUCCESS;
    }
}
