<?php

namespace Database\Seeders;

use App\Models\PurchaseDiscount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseDiscountSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();

        $defaults = [
            ['name' => 'Trade Discount', 'category' => 'on_invoice'],
            ['name' => 'Early Payment Discount', 'category' => 'on_invoice'],
            ['name' => 'FSM Discount', 'category' => 'further_discount'],
            ['name' => 'Quantity Discount', 'category' => 'further_discount'],
        ];

        foreach ($defaults as $discount) {
            PurchaseDiscount::firstOrCreate($discount, ['status' => 'active']);
        }
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissions = [
            'purchase_discounts.view' => 'View purchase discounts',
            'purchase_discounts.create' => 'Create purchase discounts',
            'purchase_discounts.update' => 'Update purchase discounts',
            'purchase_discounts.delete' => 'Delete unused purchase discounts',
        ];

        foreach ($permissions as $name => $label) {
            $permission = DB::table('permissions')->where('name', $name)->first();
            $permissionId = $permission?->id ?: DB::table('permissions')->insertGetId([
                'name' => $name,
                'label' => $label,
                'description' => $label,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($permission) {
                DB::table('permissions')->where('id', $permissionId)->update([
                    'label' => $label,
                    'description' => $label,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }

            if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
                continue;
            }
            $roleIds = DB::table('roles')->whereIn('name', ['Super Admin', 'Admin'])->orWhere('id', 1)->pluck('id')->unique();
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
}
