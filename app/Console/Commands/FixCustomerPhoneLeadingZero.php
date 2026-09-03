<?php

namespace App\Console\Commands;

use App\Support\CustomerPhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FixCustomerPhoneLeadingZero extends Command
{
    protected $signature = 'customers:fix-phone-zero
        {--apply : Apply the changes; without this option the command is a dry run}
        {--include-deleted : Include soft-deleted customers}
        {--backup= : CSV backup path; relative paths are stored below storage/app}';

    protected $description = 'Safely add the missing leading zero to Pakistani customer mobile numbers';

    public function handle(): int
    {
        $query = DB::table('clients')->select(['id', 'name', 'phone', 'deleted_at'])->orderBy('id');
        if (! $this->option('include-deleted')) {
            $query->whereNull('deleted_at');
        }
        $customers = $query->get();

        $owners = [];
        foreach ($customers as $customer) {
            $key = CustomerPhoneNormalizer::identityKey($customer->phone);
            if ($key !== null) {
                $owners[$key][] = (int) $customer->id;
            }
        }

        $eligible = [];
        $conflicts = [];
        $blank = 0;
        $alreadyCorrect = 0;
        foreach ($customers as $customer) {
            if (trim((string) $customer->phone) === '') {
                $blank++;
                continue;
            }
            if (CustomerPhoneNormalizer::isLocalMobile($customer->phone)) {
                $alreadyCorrect++;
                continue;
            }
            if (! CustomerPhoneNormalizer::isMissingLeadingZero($customer->phone)) {
                continue;
            }

            $key = CustomerPhoneNormalizer::identityKey($customer->phone);
            $otherOwners = array_filter($owners[$key] ?? [], fn (int $id) => $id !== (int) $customer->id);
            if ($otherOwners) {
                $conflicts[] = $customer;
                continue;
            }
            $eligible[] = $customer;
        }

        $this->table(['Category', 'Count'], [
            ['Customers scanned', $customers->count()],
            ['Safe corrections', count($eligible)],
            ['Already correct local mobiles', $alreadyCorrect],
            ['Blank phones', $blank],
            ['Duplicate conflicts skipped', count($conflicts)],
            ['Other formats left unchanged', $customers->count() - count($eligible) - count($conflicts) - $alreadyCorrect - $blank],
        ]);

        if (! $this->option('apply')) {
            $this->warn('Dry run only: no customer records were changed.');
            $this->line('Run again with --apply after reviewing these totals.');

            return self::SUCCESS;
        }

        if (! $eligible) {
            $this->info('No safe customer phone corrections are required.');

            return self::SUCCESS;
        }

        try {
            $backupPath = $this->writeBackup($eligible);
        } catch (\Throwable $exception) {
            $this->error('No records were changed because the CSV backup could not be created: '.$exception->getMessage());

            return self::FAILURE;
        }

        $updated = 0;
        $concurrentSkips = 0;
        DB::transaction(function () use ($eligible, &$updated, &$concurrentSkips) {
            foreach (array_chunk($eligible, 500) as $chunk) {
                foreach ($chunk as $customer) {
                    $changed = DB::table('clients')
                        ->where('id', $customer->id)
                        ->where('phone', $customer->phone)
                        ->update([
                            'phone' => CustomerPhoneNormalizer::normalize($customer->phone),
                            'updated_at' => now(),
                        ]);
                    $changed === 1 ? $updated++ : $concurrentSkips++;
                }
            }
        });

        $this->info("Updated {$updated} customer phone number(s).");
        $this->line("Backup: {$backupPath}");
        if ($concurrentSkips > 0) {
            $this->warn("Skipped {$concurrentSkips} record(s) that changed after the scan started.");
        }
        if ($conflicts) {
            $this->warn(count($conflicts).' duplicate conflict(s) were not changed.');
        }

        return self::SUCCESS;
    }

    private function writeBackup(array $customers): string
    {
        $path = $this->backupPath();
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path} for writing.");
        }

        try {
            fputcsv($handle, ['customer_id', 'customer_name', 'old_phone', 'new_phone']);
            foreach ($customers as $customer) {
                fputcsv($handle, [
                    $customer->id,
                    $customer->name,
                    $customer->phone,
                    CustomerPhoneNormalizer::normalize($customer->phone),
                ]);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function backupPath(): string
    {
        $requested = trim((string) $this->option('backup'));
        if ($requested === '') {
            return storage_path('app/backups/customer-phones-'.now()->format('Ymd-His-u').'.csv');
        }
        if (str_starts_with($requested, '/') || str_starts_with($requested, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $requested)) {
            return $requested;
        }

        return storage_path('app/'.$requested);
    }
}
