<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. Recover only the unmistakable table set left by
        // an interrupted first run before tax_defaults was created.
        if (Schema::hasTable('tax_price_types') && Schema::hasTable('tax_warehouse') && ! Schema::hasTable('tax_defaults')) {
            Schema::dropIfExists('tax_warehouse');
            Schema::dropIfExists('tax_price_type');
            Schema::dropIfExists('tax_transaction_types');
            Schema::dropIfExists('taxes');
            Schema::dropIfExists('tax_price_types');
        }

        Schema::create('tax_price_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 64)->unique();
            $table->string('name', 100);
            $table->string('product_field', 64);
            $table->boolean('is_purchase')->default(false);
            $table->boolean('is_sale')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('scope_key', 80)->default('global');
            $table->unsignedInteger('company_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->text('description')->nullable();
            $table->string('calculation_type', 20)->default('percentage');
            $table->decimal('rate', 20, 6)->default(0);
            $table->string('behavior', 20)->default('additive');
            $table->date('effective_start_date')->nullable();
            $table->date('effective_end_date')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->unsignedInteger('updated_by')->nullable()->index();
            $table->timestamps(6);
            $table->softDeletes();
            $table->unique(['scope_key', 'code'], 'taxes_scope_code_unique');
            $table->index(['is_active', 'effective_start_date', 'effective_end_date'], 'taxes_effective_idx');
        });

        Schema::create('tax_transaction_types', function (Blueprint $table) {
            $table->unsignedInteger('tax_id');
            $table->string('transaction_type', 32);
            $table->primary(['tax_id', 'transaction_type']);
            $table->index('transaction_type');
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
        });

        Schema::create('tax_price_type', function (Blueprint $table) {
            $table->unsignedInteger('tax_id');
            $table->unsignedInteger('tax_price_type_id');
            $table->primary(['tax_id', 'tax_price_type_id']);
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
            $table->foreign('tax_price_type_id')->references('id')->on('tax_price_types')->restrictOnDelete();
        });

        Schema::create('tax_warehouse', function (Blueprint $table) {
            $table->unsignedInteger('tax_id');
            // warehouses.id is a legacy signed INT in this application.
            $table->integer('warehouse_id');
            $table->primary(['tax_id', 'warehouse_id']);
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });

        Schema::create('tax_defaults', function (Blueprint $table) {
            $table->increments('id');
            $table->string('scope_key', 80)->default('global');
            $table->unsignedInteger('company_id')->nullable()->index();
            $table->integer('warehouse_id')->nullable()->index();
            $table->string('transaction_type', 32);
            $table->unsignedInteger('tax_id');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps(6);
            $table->unique(['scope_key', 'transaction_type', 'tax_id'], 'tax_defaults_scope_type_tax_unique');
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
        });

        Schema::create('transaction_tax_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('transaction_type', 32);
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('transaction_line_id')->nullable();
            $table->unsignedInteger('tax_id')->nullable();
            $table->string('tax_name', 120);
            $table->string('tax_code', 40);
            $table->string('calculation_type', 20);
            $table->decimal('rate', 20, 6);
            $table->string('behavior', 20);
            $table->unsignedInteger('price_type_id')->nullable();
            $table->string('price_type_code', 64);
            $table->string('price_type_name', 100);
            $table->decimal('quantity', 20, 6)->default(0);
            $table->decimal('taxable_base', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_reversal')->default(false);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->timestamps(6);
            $table->index(['transaction_type', 'transaction_id'], 'tax_snapshot_transaction_idx');
            $table->index(['transaction_line_id', 'tax_id'], 'tax_snapshot_line_tax_idx');
            $table->index(['tax_code', 'created_at'], 'tax_snapshot_report_idx');
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('price_type_id')->references('id')->on('tax_price_types')->nullOnDelete();
            $table->foreign('reversal_of_id')->references('id')->on('transaction_tax_snapshots')->nullOnDelete();
        });

        Schema::create('tax_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('tax_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('event', 64);
            $table->string('auditable_type', 80)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps(6);
            $table->index(['tax_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });

        $now = now();
        $priceTypes = [
            ['code' => 'company_rb_price', 'name' => 'Company/RB Price', 'product_field' => 'company_rb_price', 'is_purchase' => 1, 'is_sale' => 0, 'sort_order' => 10],
            ['code' => 'mrp_price', 'name' => 'MRP Price', 'product_field' => 'mrp_price', 'is_purchase' => 1, 'is_sale' => 1, 'sort_order' => 20],
            ['code' => 'cost', 'name' => 'Cost Price', 'product_field' => 'cost', 'is_purchase' => 1, 'is_sale' => 0, 'sort_order' => 30],
            ['code' => 'fix_price', 'name' => 'Fixed Price', 'product_field' => 'fix_price', 'is_purchase' => 0, 'is_sale' => 1, 'sort_order' => 40],
            ['code' => 'price', 'name' => 'Sale Price', 'product_field' => 'price', 'is_purchase' => 0, 'is_sale' => 1, 'sort_order' => 50],
            ['code' => 'wholesale_price', 'name' => 'Wholesale Price', 'product_field' => 'wholesale_price', 'is_purchase' => 0, 'is_sale' => 1, 'sort_order' => 60],
            ['code' => 'min_price', 'name' => 'Minimum Price', 'product_field' => 'min_price', 'is_purchase' => 0, 'is_sale' => 1, 'sort_order' => 70],
        ];
        foreach ($priceTypes as $priceType) {
            DB::table('tax_price_types')->updateOrInsert(
                ['code' => $priceType['code']],
                $priceType + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $legacyDefaultRate = Schema::hasTable('settings') ? (string) (DB::table('settings')->value('default_tax') ?? 0) : '0';
        $gstId = $this->upsertTax('GST', 'General Sales Tax', $legacyDefaultRate, 'additive', 10, $now);
        $whtId = $this->upsertTax('WHT', 'Withholding Tax', '0.5', 'deductive', 20, $now);

        foreach (['purchase', 'sale_invoice', 'pos', 'sale_return', 'purchase_return'] as $type) {
            DB::table('tax_transaction_types')->updateOrInsert(['tax_id' => $gstId, 'transaction_type' => $type]);
        }
        foreach (['purchase', 'purchase_return'] as $type) {
            DB::table('tax_transaction_types')->updateOrInsert(['tax_id' => $whtId, 'transaction_type' => $type]);
        }

        $mrpId = DB::table('tax_price_types')->where('code', 'mrp_price')->value('id');
        $rbId = DB::table('tax_price_types')->where('code', 'company_rb_price')->value('id');
        $saleId = DB::table('tax_price_types')->where('code', 'price')->value('id');
        DB::table('tax_price_type')->updateOrInsert(['tax_id' => $gstId, 'tax_price_type_id' => $mrpId]);
        DB::table('tax_price_type')->updateOrInsert(['tax_id' => $gstId, 'tax_price_type_id' => $saleId]);
        DB::table('tax_price_type')->updateOrInsert(['tax_id' => $whtId, 'tax_price_type_id' => $rbId]);
        foreach (['purchase', 'sale_invoice', 'pos'] as $type) {
            DB::table('tax_defaults')->updateOrInsert(
                ['scope_key' => 'global', 'transaction_type' => $type, 'tax_id' => $gstId],
                ['company_id' => null, 'warehouse_id' => null, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $this->backfillHistoricalPurchaseTaxes($gstId, $whtId, (int) $mrpId, (int) $rbId);
    }

    private function backfillHistoricalPurchaseTaxes(int $gstId, int $whtId, int $mrpId, int $rbId): void
    {
        if (! Schema::hasTable('purchase_details') || ! Schema::hasTable('purchases') ||
            ! Schema::hasColumn('purchase_details', 'sales_tax') || ! Schema::hasColumn('purchase_details', 'withholding_tax')) {
            return;
        }

        DB::table('purchase_details as d')
            ->join('purchases as p', 'p.id', '=', 'd.purchase_id')
            ->select([
                'd.id', 'd.purchase_id', 'd.quantity', 'd.cost', 'd.company_rb_price', 'd.mrp_price',
                'd.discount', 'd.discount_method', 'd.TaxNet', 'd.sales_tax', 'd.withholding_tax',
                'p.created_at',
            ])->orderBy('d.id')->chunkById(500, function ($rows) use ($gstId, $whtId, $mrpId, $rbId) {
                $inserts = [];
                foreach ($rows as $row) {
                    $quantity = (float) $row->quantity;
                    $createdAt = $row->created_at ?: now();
                    if ((float) $row->sales_tax != 0.0) {
                        $inserts[] = [
                            'transaction_type' => 'purchase', 'transaction_id' => $row->purchase_id, 'transaction_line_id' => $row->id,
                            'tax_id' => $gstId, 'tax_name' => 'General Sales Tax', 'tax_code' => 'GST',
                            'calculation_type' => 'percentage', 'rate' => $row->TaxNet ?: 0, 'behavior' => 'additive',
                            'price_type_id' => $mrpId, 'price_type_code' => 'mrp_price', 'price_type_name' => 'MRP Price',
                            'quantity' => $row->quantity, 'taxable_base' => round((float) ($row->mrp_price ?: $row->cost) * $quantity, 6),
                            'tax_amount' => round((float) $row->sales_tax * $quantity, 6), 'priority' => 10,
                            'is_compound' => 0, 'is_reversal' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                        ];
                    }
                    if ((float) $row->withholding_tax != 0.0) {
                        $rb = (float) ($row->company_rb_price ?: $row->cost);
                        $discount = $row->discount_method === '2' ? (float) $row->discount : $rb * (float) $row->discount / 100;
                        $netUnit = max(0, $rb - $discount);
                        $historicalRate = $netUnit > 0 ? (float) $row->withholding_tax * 100 / $netUnit : 0;
                        $inserts[] = [
                            'transaction_type' => 'purchase', 'transaction_id' => $row->purchase_id, 'transaction_line_id' => $row->id,
                            'tax_id' => $whtId, 'tax_name' => 'Withholding Tax', 'tax_code' => 'WHT',
                            'calculation_type' => 'percentage', 'rate' => round($historicalRate, 6),
                            // Legacy WHT was added in the Vue calculation. Preserve that fact in its immutable snapshot.
                            'behavior' => 'additive', 'price_type_id' => $rbId, 'price_type_code' => 'company_rb_price',
                            'price_type_name' => 'Company/RB Price', 'quantity' => $row->quantity,
                            'taxable_base' => round($netUnit * $quantity, 6),
                            'tax_amount' => round((float) $row->withholding_tax * $quantity, 6), 'priority' => 20,
                            'is_compound' => 0, 'is_reversal' => 0, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                        ];
                    }
                }
                if ($inserts) DB::table('transaction_tax_snapshots')->insert($inserts);
            }, 'd.id', 'id');
    }

    private function upsertTax(string $code, string $name, string $rate, string $behavior, int $priority, $now): int
    {
        $existing = DB::table('taxes')->whereNull('company_id')->where('code', $code)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('taxes')->insertGetId([
            'scope_key' => 'global', 'company_id' => null, 'name' => $name, 'code' => $code, 'description' => $name,
            'calculation_type' => 'percentage', 'rate' => $rate, 'behavior' => $behavior,
            'priority' => $priority, 'is_compound' => 0, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_audits');
        Schema::dropIfExists('transaction_tax_snapshots');
        Schema::dropIfExists('tax_defaults');
        Schema::dropIfExists('tax_warehouse');
        Schema::dropIfExists('tax_price_type');
        Schema::dropIfExists('tax_transaction_types');
        Schema::dropIfExists('taxes');
        Schema::dropIfExists('tax_price_types');
    }
};
