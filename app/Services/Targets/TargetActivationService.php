<?php

namespace App\Services\Targets;

use App\Models\SupplierTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TargetActivationService
{
    public function activate(SupplierTarget $target, int $userId, ?callable $conflictCheck = null): SupplierTarget
    {
        return DB::transaction(function () use ($target, $userId, $conflictCheck) {
            $locked = SupplierTarget::lockForUpdate()->findOrFail($target->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft target can be activated.']]);
            }
            $lineTotal = round((float) $locked->lines()->sum('target_quantity'), 3);
            $allocationTotal = round((float) $locked->allocations()->sum('allocated_quantity'), 3);
            if ($lineTotal <= 0 || abs($lineTotal - $allocationTotal) > 0.0001) {
                throw ValidationException::withMessages(['allocations' => ['Allocation must exactly equal the product/category target total before activation.']]);
            }
            if ($conflictCheck) {
                $conflictCheck($locked);
            }
            $old = $locked->status;
            $locked->update(['status' => 'active', 'allocation_requires_review' => false, 'updated_by' => $userId]);
            $locked->histories()->create([
                'user_id' => $userId, 'event' => 'activated',
                'old_values' => ['status' => $old], 'new_values' => ['status' => 'active'],
            ]);

            return $locked->fresh();
        });
    }
}
