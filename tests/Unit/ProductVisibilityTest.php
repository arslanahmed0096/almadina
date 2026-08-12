<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductVisibilityTest extends TestCase
{
    public function test_super_admin_can_browse_active_and_inactive_products(): void
    {
        $user = $this->userWithRoleId(1);
        $query = Product::query()->visibleTo($user)->getQuery();

        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse(collect($query->wheres)->contains(fn ($where) => ($where['column'] ?? null) === 'products.is_active'));
    }

    public function test_regular_user_product_queries_are_limited_to_active_products(): void
    {
        $user = $this->userWithRoleId(2);
        $query = Product::query()->visibleTo($user)->getQuery();
        $activeWhere = collect($query->wheres)->first(fn ($where) => ($where['column'] ?? null) === 'products.is_active');

        $this->assertFalse($user->isSuperAdmin());
        $this->assertNotNull($activeWhere);
        $this->assertSame(1, $activeWhere['value']);
    }

    private function userWithRoleId(int $roleId): User
    {
        $user = new User;
        $user->role_id = $roleId;
        $user->setRelation('roles', new Collection);
        $user->setRelation('permissionOverrides', new Collection);

        return $user;
    }
}
