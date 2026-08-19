<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TransferPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return mixed
     */
    public function viewAny(User $user)
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return mixed
     */
    public function view(User $user)
    {
        return $this->hasPermission($user, 'transfer_view');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return mixed
     */
    public function create(User $user)
    {
        return $this->hasPermission($user, 'transfer_request')
            || $this->hasPermission($user, 'transfer_add');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return mixed
     */
    public function update(User $user)
    {
        return $this->hasPermission($user, 'transfer_edit');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return mixed
     */
    public function delete(User $user)
    {
        return $this->hasPermission($user, 'transfer_delete');
    }

    public function process(User $user)
    {
        return $this->hasPermission($user, 'transfer_approve')
            || $this->hasPermission($user, 'transfer_partial_approve')
            || $this->hasPermission($user, 'transfer_decline');
    }

    public function acknowledge(User $user)
    {
        return $this->hasPermission($user, 'transfer_acknowledge');
    }

    public function dispatch(User $user)
    {
        return $this->hasPermission($user, 'transfer_dispatch');
    }

    public function receive(User $user)
    {
        return $this->hasPermission($user, 'transfer_receive');
    }

    public function Stock_Transfer_Report(User $user)
    {
        $permission = Permission::where('name', 'Stock_Transfer_Report')->first();

        return $user->hasRole($permission->roles);
    }

    private function hasPermission(User $user, string $name): bool
    {
        return $user->isSuperAdmin() || $user->effectivePermissionNames()->contains($name);
    }

    public function check_record(User $user, $transfer)
    {
        return $user->id === $transfer->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return mixed
     */
    public function restore(User $user)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\Transfer  $transfer
     * @return mixed
     */
    public function forceDelete(User $user)
    {
        //
    }
}
