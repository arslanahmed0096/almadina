<?php

namespace App\Console;

use App\Models\UserLoginSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
        'App\Console\Commands\DatabaseBackUp',
        'App\Console\Commands\WooCommerceSync',
        'App\\Console\\Commands\\WooCommercePushProducts',
        'App\\Console\\Commands\\NotifyOverduePurchaseOrders',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        $schedule->command('database:backup');

        $schedule->command('assets:check-validation-due')->daily();

        $schedule->command('invoices:send-overdue-credit-reminders')
            ->dailyAt('08:00')
            ->withoutOverlapping();

        $schedule->command('procurement:notify-overdue')->dailyAt('08:15')->withoutOverlapping();

        $schedule->call(function () {
            UserLoginSession::purgeExpiredHistory();
        })->hourly()->name('purge-expired-login-history')->withoutOverlapping();

        /**
         * Shared hosting friendly queue processing:
         * Run a single queue job each minute (database driver).
         *
         * IMPORTANT: You must have a cron that runs `php artisan schedule:run` every minute.
         * This prevents Woo sync batches from getting stuck at "queued_next_batch" when no
         * long-running queue worker (Supervisor/systemd) is available.
         */
        $schedule->command('queue:work database --once --queue=default --sleep=1 --tries=1 --timeout='.((int) env('QUEUE_WORKER_TIMEOUT', 1200)))
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
