<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\Procurement\ProcurementNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyPurchasePaymentDue extends Command
{
    protected $signature = 'purchases:notify-payment-due';

    protected $description = 'Notify authorized users about supplier invoices due in one or two days';

    public function handle(ProcurementNotificationService $notifications): int
    {
        $today = Carbon::today(config('app.timezone'));
        $dueDates = [
            $today->copy()->addDay()->toDateString(),
            $today->copy()->addDays(2)->toDateString(),
        ];
        $processed = 0;

        Purchase::query()
            ->with('provider:id,name')
            ->whereNull('deleted_at')
            ->where('statut', 'received')
            ->whereIn('payment_due_date', $dueDates)
            ->whereRaw('(GrandTotal - paid_amount) > 0.009')
            ->orderBy('id')
            ->chunkById(100, function ($purchases) use ($today, $notifications, &$processed) {
                foreach ($purchases as $purchase) {
                    $days = $today->diffInDays(Carbon::parse($purchase->payment_due_date)->startOfDay(), false);
                    if (! in_array($days, [1, 2], true)) {
                        continue;
                    }

                    $providerName = optional($purchase->provider)->name ?: 'supplier';
                    $outstanding = number_format(max(0, (float) $purchase->GrandTotal - (float) $purchase->paid_amount), 2, '.', '');
                    $dayLabel = $days === 1 ? 'day' : 'days';

                    $notifications->send(
                        'payment_purchases_view',
                        (int) $purchase->warehouse_id,
                        'purchase_payment_due_in_'.$days.'_days',
                        "Supplier invoice {$purchase->Ref} for {$providerName} is due in {$days} {$dayLabel}. Outstanding amount: {$outstanding}.",
                        $purchase->Ref,
                        '/app/purchases/detail/'.$purchase->id
                    );
                    $processed++;
                }
            });

        $this->info("Processed {$processed} supplier invoice due reminders.");

        return self::SUCCESS;
    }
}
