<?php

namespace Tests\Feature;

use App\Http\Controllers\PolicyController;
use App\Models\BusinessPolicy;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerCreditService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerCreditPolicyTest extends TestCase
{
    private CustomerCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-21 12:00:00');
        $this->createTables();
        $this->service = app(CustomerCreditService::class);
        BusinessPolicy::create([
            'policy_key' => CustomerCreditService::POLICY_KEY,
            'policy_name' => 'Credit Limit Policy',
            'policy_value' => '30',
            'is_active' => true,
        ]);
        DB::table('clients')->insert(['id' => 1, 'name' => 'Customer', 'credit_limit' => 100000, 'opening_balance' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['shipment_items', 'shipments', 'sale_returns', 'sales', 'clients', 'policies'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_all_supported_policy_values_can_be_saved(): void
    {
        $controller = new PolicyController;
        foreach (CustomerCreditService::ALLOWED_DAYS as $days) {
            $response = $controller->update($this->request(['allowed_credit_days' => $days, 'is_active' => true], true), $this->service);
            $this->assertSame($days, $response->getData(true)['policy']['allowed_credit_days']);
        }
    }

    public function test_unsupported_policy_value_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new PolicyController)->update($this->request(['allowed_credit_days' => 7, 'is_active' => true], true), $this->service);
    }

    public function test_unauthorized_user_cannot_update_policy(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new PolicyController)->update($this->request(['allowed_credit_days' => 10, 'is_active' => true], false), $this->service);
    }

    public function test_non_overdue_invoice_reduces_available_credit_without_blocking(): void
    {
        $this->sale(50000, 0, '2026-09-01');
        $result = $this->service->eligibility(Client::find(1), 40000);
        $this->assertTrue($result['allowed']);
        $this->assertSame(50000.0, $result['available_credit']);
    }

    public function test_purchase_equal_to_available_credit_is_allowed(): void
    {
        $this->sale(50000, 0, '2026-09-01');
        $this->assertTrue($this->service->eligibility(Client::find(1), 50000)['allowed']);
    }

    public function test_purchase_exceeding_available_credit_is_blocked(): void
    {
        $this->sale(50000, 0, '2026-09-01');
        $result = $this->service->eligibility(Client::find(1), 60000);
        $this->assertFalse($result['allowed']);
        $this->assertSame('CREDIT_LIMIT_EXCEEDED', $result['rejection_code']);
    }

    public function test_overdue_invoice_blocks_credit_despite_unused_limit(): void
    {
        $this->sale(50000, 0, '2026-08-20');
        $result = $this->service->eligibility(Client::find(1), 20000);
        $this->assertFalse($result['allowed']);
        $this->assertSame('OVERDUE_CREDIT_INVOICE', $result['rejection_code']);
        $this->assertCount(1, $result['overdue_invoices']);
    }

    public function test_partially_paid_invoice_only_uses_remaining_balance(): void
    {
        $this->sale(50000, 20000, '2026-09-01');
        $result = $this->service->eligibility(Client::find(1), 70000);
        $this->assertTrue($result['allowed']);
        $this->assertSame(30000.0, $result['total_outstanding_credit']);
    }

    public function test_partially_paid_overdue_invoice_blocks_credit(): void
    {
        $this->sale(50000, 20000, '2026-08-01');
        $this->assertSame('OVERDUE_CREDIT_INVOICE', $this->service->eligibility(Client::find(1), 1)['rejection_code']);
    }

    public function test_fully_paid_invoice_no_longer_uses_or_blocks_credit(): void
    {
        $this->sale(50000, 50000, '2026-08-01');
        $result = $this->service->eligibility(Client::find(1), 100000);
        $this->assertTrue($result['allowed']);
        $this->assertSame(0.0, $result['total_outstanding_credit']);
    }

    public function test_fully_returned_invoice_no_longer_uses_or_blocks_credit(): void
    {
        $sale = $this->sale(50000, 0, '2026-08-01');
        DB::table('sale_returns')->insert(['sale_id' => $sale->id, 'client_id' => 1, 'GrandTotal' => 50000, 'paid_amount' => 0, 'statut' => 'completed']);
        $this->assertTrue($this->service->eligibility(Client::find(1), 100000)['allowed']);
    }

    public function test_invoice_due_today_is_not_overdue(): void
    {
        $this->sale(1000, 0, '2026-08-21');
        $this->assertTrue($this->service->eligibility(Client::find(1), 1000)['allowed']);
    }

    public function test_policy_snapshot_is_not_changed_when_policy_changes(): void
    {
        $sale = $this->sale(1000, 0, null, null);
        $this->service->applySnapshot($sale, 10)->save();
        BusinessPolicy::where('policy_key', CustomerCreditService::POLICY_KEY)->update(['policy_value' => '30']);
        $this->assertSame(10, $sale->fresh()->credit_days);
        $this->assertSame('2026-08-11', $sale->fresh()->credit_due_date->format('Y-m-d'));
    }

    public function test_disabled_policy_blocks_credit_but_not_cash(): void
    {
        BusinessPolicy::where('policy_key', CustomerCreditService::POLICY_KEY)->update(['is_active' => false]);
        $client = Client::find(1);
        $this->assertFalse($this->service->eligibility($client, 1)['allowed']);
        $this->assertTrue($this->service->eligibility($client, 0)['allowed']);
    }

    public function test_zero_credit_limit_blocks_credit(): void
    {
        DB::table('clients')->where('id', 1)->update(['credit_limit' => 0]);
        $this->assertSame('CREDIT_LIMIT_EXCEEDED', $this->service->eligibility(Client::find(1), 1)['rejection_code']);
    }

    private function sale(float $total, float $paid, ?string $dueDate, ?int $days = 30): Sale
    {
        $id = DB::table('sales')->insertGetId([
            'client_id' => 1, 'warehouse_id' => 1, 'user_id' => 1, 'date' => '2026-08-01',
            'Ref' => 'INV-'.(DB::table('sales')->count() + 1), 'GrandTotal' => $total, 'paid_amount' => $paid,
            'payment_statut' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'statut' => 'completed', 'credit_days' => $days, 'credit_due_date' => $dueDate,
        ]);
        return Sale::find($id);
    }

    private function request(array $data, bool $authorized): Request
    {
        $request = Request::create('/api/policies/credit-limit', 'PUT', $data);
        $user = new class extends User {
            public function isSuperAdmin(): bool { return (bool) $this->role_id; }
            public function effectivePermissionNames() { return collect(); }
        };
        $user->id = 1;
        $user->role_id = $authorized ? 1 : 0;
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    private function createTables(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id(); $table->string('policy_key')->unique(); $table->string('policy_name');
            $table->string('policy_value'); $table->boolean('is_active');
            $table->unsignedInteger('created_by')->nullable(); $table->unsignedInteger('updated_by')->nullable(); $table->timestamps();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->increments('id'); $table->string('name'); $table->decimal('credit_limit', 15, 2); $table->decimal('opening_balance', 15, 2)->default(0); $table->softDeletes();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('client_id'); $table->unsignedInteger('warehouse_id'); $table->unsignedInteger('user_id');
            $table->date('date'); $table->string('Ref'); $table->decimal('GrandTotal', 15, 2); $table->decimal('paid_amount', 15, 2);
            $table->string('payment_statut'); $table->string('statut'); $table->unsignedTinyInteger('credit_days')->nullable(); $table->date('credit_due_date')->nullable(); $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('sale_id'); $table->unsignedInteger('client_id');
            $table->decimal('GrandTotal', 15, 2); $table->decimal('paid_amount', 15, 2); $table->string('statut'); $table->softDeletes();
        });
    }
}
