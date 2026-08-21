<?php

namespace App\Console\Commands;

use App\Models\OverdueCreditReminder;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\OverdueCreditInvoiceNotification;
use App\Services\CustomerCreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendOverdueCreditReminders extends Command
{
    protected $signature = 'invoices:send-overdue-credit-reminders';
    protected $description = 'Send daily branch-scoped reminders for overdue credit invoices';

    public function handle(CustomerCreditService $creditService): int
    {
        $sent = 0;
        $creditService->overdueQuery()->with(['client:id,name', 'warehouse:id,name'])->chunkById(100, function ($sales) use ($creditService, &$sent) {
            foreach ($sales as $sale) {
                $invoice = $creditService->overdueInvoices((int) $sale->client_id)->firstWhere('id', (int) $sale->id);
                if (! $invoice) continue;

                foreach ($this->recipients($sale) as $user) {
                    $history = OverdueCreditReminder::firstOrCreate([
                        'sale_id' => $sale->id,
                        'user_id' => $user->id,
                        'reminder_date' => today(config('app.timezone'))->toDateString(),
                    ]);
                    if (! $history->wasRecentlyCreated) continue;
                    $user->notify(new OverdueCreditInvoiceNotification($sale, $invoice));
                    $sent++;
                }
            }
        });

        $this->info("Sent {$sent} overdue credit reminder(s).");
        return self::SUCCESS;
    }

    private function recipients(Sale $sale)
    {
        $roleNames = ['Branch Manager', 'Cashier', 'Accountant'];
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');

        return User::query()
            ->where('statut', 1)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($roleNames, $roleIds) {
                $query->whereIn('role_id', $roleIds)
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('roles.name', $roleNames));
            })
            ->where(function ($query) use ($sale) {
                $query->where('is_all_warehouses', 1)
                    ->orWhereExists(function ($subquery) use ($sale) {
                        $subquery->select(DB::raw(1))->from('user_warehouse')
                            ->whereColumn('user_warehouse.user_id', 'users.id')
                            ->where('user_warehouse.warehouse_id', $sale->warehouse_id);
                    });
            })
            ->distinct()
            ->get();
    }
}
