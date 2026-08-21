<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Services\CustomerCreditService;
use Illuminate\Console\Command;

class BackfillCreditDueDates extends Command
{
    protected $signature = 'invoices:backfill-credit-due-dates {--chunk=500} {--dry-run}';
    protected $description = 'Safely backfill policy snapshots for valid legacy unpaid credit invoices';

    public function handle(CustomerCreditService $creditService): int
    {
        $days = $creditService->policy()['allowed_credit_days'];
        $updated = 0;
        Sale::query()->whereNull('deleted_at')->whereNull('credit_due_date')
            ->where('statut', 'completed')->whereNotIn('statut', ['cancelled', 'canceled', 'voided'])
            ->whereRaw('(GrandTotal - paid_amount) > 0.009')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($sales) use ($creditService, $days, &$updated) {
                foreach ($sales as $sale) {
                    if ($creditService->outstandingForSale($sale) <= 0.009) continue;
                    $creditService->applySnapshot($sale, $days);
                    if (! $this->option('dry-run')) $sale->save();
                    $updated++;
                }
            });
        $this->info(($this->option('dry-run') ? 'Would update ' : 'Updated ').$updated.' invoice(s).');
        return self::SUCCESS;
    }
}
