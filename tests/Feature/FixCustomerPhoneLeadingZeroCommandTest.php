<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FixCustomerPhoneLeadingZeroCommandTest extends TestCase
{
    private ?string $backupPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('clients')->insert([
            ['id' => 1, 'name' => 'Missing Zero', 'phone' => '3123456789', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Already Correct', 'phone' => '03131234567', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'International', 'phone' => '+923141234567', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Blank', 'phone' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Duplicate Missing', 'phone' => '3151234567', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'Duplicate Correct', 'phone' => '03151234567', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('clients');
        if ($this->backupPath && is_file($this->backupPath)) {
            unlink($this->backupPath);
        }
        parent::tearDown();
    }

    public function test_dry_run_changes_nothing_and_apply_backs_up_and_updates_only_safe_numbers(): void
    {
        $this->assertSame(0, Artisan::call('customers:fix-phone-zero'));
        $this->assertStringContainsString('Dry run only', Artisan::output());
        $this->assertSame('3123456789', DB::table('clients')->where('id', 1)->value('phone'));

        $this->backupPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'customer-phone-command-'.uniqid().'.csv';
        $this->assertSame(0, Artisan::call('customers:fix-phone-zero', [
            '--apply' => true,
            '--backup' => $this->backupPath,
        ]));

        $this->assertSame('03123456789', DB::table('clients')->where('id', 1)->value('phone'));
        $this->assertSame('03131234567', DB::table('clients')->where('id', 2)->value('phone'));
        $this->assertSame('+923141234567', DB::table('clients')->where('id', 3)->value('phone'));
        $this->assertSame('3151234567', DB::table('clients')->where('id', 5)->value('phone'));
        $this->assertFileExists($this->backupPath);
        $backup = file_get_contents($this->backupPath);
        $this->assertStringContainsString('3123456789,03123456789', $backup);
        $this->assertStringNotContainsString('3151234567,03151234567', $backup);
    }

    public function test_future_customer_model_saves_are_normalized_automatically(): void
    {
        $customer = Client::create(['name' => 'New Customer', 'phone' => '316-1234567']);
        $international = Client::create(['name' => 'International Customer', 'phone' => '+923171234567']);

        $this->assertSame('03161234567', $customer->fresh()->phone);
        $this->assertSame('03161234567', $customer->fresh()->display_phone);
        $this->assertSame('+923171234567', $international->fresh()->phone);
        $this->assertSame('+923171234567', $international->fresh()->display_phone);
    }
}
