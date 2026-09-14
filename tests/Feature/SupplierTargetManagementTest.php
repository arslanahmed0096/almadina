<?php

namespace Tests\Feature;

use App\Http\Requests\SaveSupplierTargetAllocationsRequest;
use App\Http\Requests\SaveSupplierTargetDetailsRequest;
use App\Http\Requests\SaveSupplierTargetLinesRequest;
use App\Models\SupplierTarget;
use App\Models\User;
use App\Policies\SupplierTargetPolicy;
use App\Services\Targets\TargetAchievementService;
use App\Services\Targets\TargetActivationService;
use App\Services\Targets\TargetAllocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SupplierTargetManagementTest extends TestCase
{
    private SupplierTarget $target;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('supplier_targets', function (Blueprint $t) {
            $t->id();
            $t->integer('supplier_id');
            $t->string('target_name');
            $t->string('period_type');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('measurement_type')->default('quantity');
            $t->string('status')->default('draft');
            $t->text('description')->nullable();
            $t->boolean('allocation_requires_review')->default(false);
            $t->integer('created_by');
            $t->integer('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('supplier_target_lines', function (Blueprint $t) {
            $t->id();
            $t->integer('supplier_target_id');
            $t->integer('product_id')->nullable();
            $t->integer('category_id')->nullable();
            $t->integer('unit_id')->nullable();
            $t->decimal('target_quantity', 20, 3);
            $t->timestamps();
        });
        Schema::create('supplier_target_allocations', function (Blueprint $t) {
            $t->id();
            $t->integer('supplier_target_id');
            $t->integer('warehouse_id');
            $t->decimal('allocated_quantity', 20, 3);
            $t->timestamps();
        });
        Schema::create('supplier_target_histories', function (Blueprint $t) {
            $t->id();
            $t->integer('supplier_target_id');
            $t->integer('user_id')->nullable();
            $t->string('event');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('providers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('category_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->integer('category_id');
            $t->integer('product_id');
            $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->integer('warehouse_id');
            $t->string('statut');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('sale_details', function (Blueprint $t) {
            $t->id();
            $t->integer('sale_id');
            $t->integer('product_id');
            $t->decimal('quantity', 20, 3);
            $t->timestamps();
        });
        Schema::create('shipment_items', function (Blueprint $t) {
            $t->id();
            $t->integer('sale_detail_id');
            $t->timestamp('shipped_at');
            $t->timestamps();
        });
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->integer('sale_id')->nullable();
            $t->date('date');
            $t->integer('warehouse_id');
            $t->string('statut');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('sale_return_details', function (Blueprint $t) {
            $t->id();
            $t->integer('sale_return_id');
            $t->integer('sale_detail_id')->nullable();
            $t->integer('product_id');
            $t->decimal('quantity', 20, 3);
            $t->timestamps();
        });
        DB::table('categories')->insert(['id' => 1, 'name' => 'Cooling']);
        DB::table('providers')->insert(['id' => 1, 'name' => 'Welcome']);
        DB::table('units')->insert(['id' => 1, 'name' => 'Piece']);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Refrigerator', 'category_id' => 1],
            ['id' => 2, 'name' => 'Freezer', 'category_id' => 1],
        ]);
        DB::table('warehouses')->insert([['id' => 1, 'name' => 'Main'], ['id' => 2, 'name' => 'Branch']]);
        $this->target = SupplierTarget::create([
            'supplier_id' => 1, 'target_name' => 'Annual 2026', 'period_type' => 'annual',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_by' => 1,
        ]);
        $this->target->lines()->create(['product_id' => 1, 'target_quantity' => 10]);
        $this->target->allocations()->create(['warehouse_id' => 1, 'allocated_quantity' => 10]);
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (array_reverse(Schema::getTableListing()) as $table) {
            Schema::drop($table);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    private function sale(float $quantity, string $status = 'completed', string $date = '2026-05-01', int $warehouse = 1): int
    {
        $sale = DB::table('sales')->insertGetId(['date' => $date, 'warehouse_id' => $warehouse, 'statut' => $status]);

        return DB::table('sale_details')->insertGetId(['sale_id' => $sale, 'product_id' => 1, 'quantity' => $quantity]);
    }

    private function metrics(?array $warehouses = null): array
    {
        return app(TargetAchievementService::class)->calculate($this->target->fresh(), $warehouses);
    }

    private function requestValidator(string $class, array $data)
    {
        $request = $class::create('/targets', 'POST', $data);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator;
    }

    public function test_annual_target_draft_can_be_created(): void
    {
        $draft = SupplierTarget::create(['supplier_id' => 2, 'target_name' => 'Draft', 'period_type' => 'annual',
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'draft', 'created_by' => 1]);
        $this->assertSame('draft', $draft->status);
    }

    public function test_monthly_target_is_supported(): void
    {
        $this->target->update(['period_type' => 'monthly', 'start_date' => '2026-05-01', 'end_date' => '2026-05-31']);
        $this->assertSame('monthly', $this->target->fresh()->period_type);
    }

    public function test_line_total_is_derived(): void
    {
        $this->assertSame(10.0, $this->target->fresh()->total_target);
    }

    public function test_allocation_total_is_derived(): void
    {
        $this->assertSame(10.0, $this->target->fresh()->allocated_total);
    }

    public function test_direct_product_is_resolved_once(): void
    {
        $ids = app(TargetAchievementService::class)->productIds($this->target->fresh());
        $this->assertSame([1], $ids->all());
    }

    public function test_category_line_expands_products(): void
    {
        $this->target->lines()->delete();
        $this->target->lines()->create(['category_id' => 1, 'target_quantity' => 20]);
        $this->assertSame([1, 2], app(TargetAchievementService::class)->productIds($this->target->fresh())->sort()->values()->all());
    }

    public function test_direct_and_category_membership_never_double_counts_product(): void
    {
        $this->target->lines()->create(['category_id' => 1, 'target_quantity' => 10]);
        $this->assertCount(2, app(TargetAchievementService::class)->productIds($this->target->fresh()));
    }

    public function test_completed_sale_increases_achievement(): void
    {
        $this->sale(4);
        $this->assertSame(4.0, $this->metrics()['achieved']);
    }

    public function test_cancelled_sale_does_not_count(): void
    {
        $this->sale(4, 'cancelled');
        $this->assertSame(0.0, $this->metrics()['achieved']);
    }

    public function test_deleted_sale_does_not_count(): void
    {
        $detail = $this->sale(4);
        DB::table('sales')->where('id', DB::table('sale_details')->where('id', $detail)->value('sale_id'))->update(['deleted_at' => now()]);
        $this->assertSame(0.0, $this->metrics()['achieved']);
    }

    public function test_sale_outside_date_range_does_not_count(): void
    {
        $this->sale(4, 'completed', '2025-12-31');
        $this->assertSame(0.0, $this->metrics()['achieved']);
    }

    public function test_sale_outside_allocated_warehouse_does_not_count(): void
    {
        $this->sale(4, 'completed', '2026-05-01', 2);
        $this->assertSame(0.0, $this->metrics()['achieved']);
    }

    public function test_user_warehouse_scope_filters_achievement(): void
    {
        $this->sale(4);
        $this->assertSame(0.0, $this->metrics([2])['achieved']);
    }

    public function test_shipped_item_from_ordered_sale_counts(): void
    {
        $detail = $this->sale(3, 'ordered', '2026-04-01');
        DB::table('shipment_items')->insert(['sale_detail_id' => $detail, 'shipped_at' => '2026-05-01 12:00:00']);
        $this->assertSame(3.0, $this->metrics()['achieved']);
    }

    public function test_unshipped_item_from_ordered_sale_does_not_count(): void
    {
        $this->sale(3, 'ordered');
        $this->assertSame(0.0, $this->metrics()['achieved']);
    }

    public function test_completed_return_reduces_achievement(): void
    {
        $detail = $this->sale(8);
        $sale = DB::table('sale_details')->where('id', $detail)->value('sale_id');
        $return = DB::table('sale_returns')->insertGetId(['sale_id' => $sale, 'date' => '2026-06-01', 'warehouse_id' => 1, 'statut' => 'completed']);
        DB::table('sale_return_details')->insert(['sale_return_id' => $return, 'sale_detail_id' => $detail, 'product_id' => 1, 'quantity' => 3]);
        $this->assertSame(5.0, $this->metrics()['achieved']);
    }

    public function test_cancelled_return_does_not_reduce_achievement(): void
    {
        $detail = $this->sale(8);
        $sale = DB::table('sale_details')->where('id', $detail)->value('sale_id');
        $return = DB::table('sale_returns')->insertGetId(['sale_id' => $sale, 'date' => '2026-06-01', 'warehouse_id' => 1, 'statut' => 'cancelled']);
        DB::table('sale_return_details')->insert(['sale_return_id' => $return, 'sale_detail_id' => $detail, 'product_id' => 1, 'quantity' => 3]);
        $this->assertSame(8.0, $this->metrics()['achieved']);
    }

    public function test_exceeding_target_keeps_zero_remaining_and_actual_percentage(): void
    {
        $this->sale(12);
        $metrics = $this->metrics();
        $this->assertSame(0.0, $metrics['remaining']);
        $this->assertSame(120.0, $metrics['percentage']);
        $this->assertSame('Exceeded', $metrics['status']);
    }

    public function test_annual_chart_contains_twelve_months(): void
    {
        $this->assertCount(12, $this->metrics()['monthly']);
    }

    public function test_monthly_chart_uses_weekly_buckets(): void
    {
        $this->target->update(['period_type' => 'monthly', 'start_date' => '2026-05-01', 'end_date' => '2026-05-31']);
        $this->assertGreaterThanOrEqual(4, $this->metrics()['monthly']->count());
    }

    public function test_warehouse_performance_is_calculated(): void
    {
        $this->sale(6);
        $row = $this->metrics()['warehouses']->first();
        $this->assertSame(6.0, $row['achieved']);
        $this->assertSame(60.0, $row['percentage']);
    }

    public function test_product_performance_is_calculated(): void
    {
        $this->sale(7);
        $row = $this->metrics()['lines']->first();
        $this->assertSame('Refrigerator', $row['name']);
        $this->assertSame(3.0, $row['remaining']);
    }

    public function test_equal_distribution_is_exact_with_rounding(): void
    {
        $values = app(TargetAllocationService::class)->equally(10, 3);
        $this->assertSame(10.0, array_sum($values));
        $this->assertSame([3.334, 3.333, 3.333], $values);
    }

    public function test_equal_distribution_rejects_zero_warehouses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TargetAllocationService::class)->equally(10, 0);
    }

    public function test_incomplete_allocation_cannot_activate(): void
    {
        $this->target->update(['status' => 'draft']);
        $this->target->allocations()->update(['allocated_quantity' => 9]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(TargetActivationService::class)->activate($this->target, 1);
    }

    public function test_complete_allocation_activates_target(): void
    {
        $this->target->update(['status' => 'draft']);
        $activated = app(TargetActivationService::class)->activate($this->target, 1);
        $this->assertSame('active', $activated->status);
    }

    public function test_activation_records_audit_history(): void
    {
        $this->target->update(['status' => 'draft']);
        app(TargetActivationService::class)->activate($this->target, 1);
        $this->assertDatabaseHas('supplier_target_histories', [
            'supplier_target_id' => $this->target->id, 'event' => 'activated', 'user_id' => 1,
        ]);
    }

    public function test_active_target_cannot_be_activated_twice(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(TargetActivationService::class)->activate($this->target, 1);
    }

    public function test_monthly_period_cannot_cross_months(): void
    {
        $validator = $this->requestValidator(SaveSupplierTargetDetailsRequest::class, [
            'supplier_id' => 1, 'target_name' => 'Monthly', 'period_type' => 'monthly',
            'start_date' => '2026-05-01', 'end_date' => '2026-06-01',
        ]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_date', $validator->errors()->toArray());
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $validator = $this->requestValidator(SaveSupplierTargetDetailsRequest::class, [
            'supplier_id' => 1, 'target_name' => 'Annual', 'period_type' => 'annual',
            'start_date' => '2026-12-31', 'end_date' => '2026-01-01',
        ]);
        $this->assertTrue($validator->fails());
    }

    public function test_duplicate_target_lines_are_rejected(): void
    {
        $line = ['type' => 'product', 'targetable_id' => 1, 'target_quantity' => 5];
        $validator = $this->requestValidator(SaveSupplierTargetLinesRequest::class, ['lines' => [$line, $line]]);
        $this->assertTrue($validator->fails());
    }

    public function test_overlapping_product_and_category_lines_are_rejected(): void
    {
        $validator = $this->requestValidator(SaveSupplierTargetLinesRequest::class, ['lines' => [
            ['type' => 'product', 'targetable_id' => 1, 'target_quantity' => 5],
            ['type' => 'category', 'targetable_id' => 1, 'target_quantity' => 5],
        ]]);
        $this->assertTrue($validator->fails());
    }

    public function test_zero_line_quantity_is_rejected(): void
    {
        $validator = $this->requestValidator(SaveSupplierTargetLinesRequest::class, ['lines' => [
            ['type' => 'product', 'targetable_id' => 1, 'target_quantity' => 0],
        ]]);
        $this->assertTrue($validator->fails());
    }

    public function test_negative_allocation_is_rejected(): void
    {
        $validator = $this->requestValidator(SaveSupplierTargetAllocationsRequest::class, ['allocations' => [
            ['warehouse_id' => 1, 'allocated_quantity' => -1],
        ]]);
        $this->assertTrue($validator->fails());
    }

    public function test_duplicate_warehouse_allocation_is_rejected(): void
    {
        $row = ['warehouse_id' => 1, 'allocated_quantity' => 5];
        $validator = $this->requestValidator(SaveSupplierTargetAllocationsRequest::class, ['allocations' => [$row, $row]]);
        $this->assertTrue($validator->fails());
    }

    public function test_view_permission_is_enforced(): void
    {
        $user = new class extends User
        {
            public array $names = [];

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function effectivePermissionNames()
            {
                return collect($this->names);
            }
        };
        $policy = new SupplierTargetPolicy;
        $this->assertFalse($policy->view($user, $this->target));
        $user->names = ['targets.view'];
        $this->assertTrue($policy->view($user, $this->target));
    }

    public function test_activation_requires_permission_and_draft_status(): void
    {
        $user = new class extends User
        {
            public array $names = ['targets.activate'];

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function effectivePermissionNames()
            {
                return collect($this->names);
            }
        };
        $policy = new SupplierTargetPolicy;
        $this->assertFalse($policy->activate($user, $this->target));
        $this->target->status = 'draft';
        $this->assertTrue($policy->activate($user, $this->target));
    }
}
