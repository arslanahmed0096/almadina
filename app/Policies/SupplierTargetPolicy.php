<?php

namespace App\Policies;

use App\Models\SupplierTarget;
use App\Models\User;

class SupplierTargetPolicy
{
    private function allowed(User $user, string $permission): bool
    {
        return $user->isSuperAdmin() || $user->effectivePermissionNames()->contains($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'targets.view');
    }

    public function view(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.view');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'targets.create');
    }

    public function update(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.edit') && ! in_array($target->status, ['completed', 'cancelled'], true);
    }

    public function activate(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.activate') && $target->status === 'draft';
    }

    public function cancel(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.cancel') && ! in_array($target->status, ['completed', 'cancelled'], true);
    }

    public function complete(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.activate') && $target->status === 'active';
    }

    public function delete(User $user, SupplierTarget $target): bool
    {
        return $this->allowed($user, 'targets.delete') && $target->status === 'draft';
    }

    public function reports(User $user): bool
    {
        return $this->allowed($user, 'targets.reports');
    }

    public function export(User $user): bool
    {
        return $this->allowed($user, 'targets.export');
    }
}
