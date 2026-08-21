<?php

namespace App\Http\Controllers;

use App\Models\BusinessPolicy;
use App\Services\CustomerCreditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyController extends Controller
{
    public function current(CustomerCreditService $creditService)
    {
        return response()->json(['policy' => $creditService->policy()]);
    }

    public function show(Request $request, CustomerCreditService $creditService)
    {
        $this->authorizePermission($request, 'policies.view');

        return response()->json(['policy' => $creditService->policy()]);
    }

    public function update(Request $request, CustomerCreditService $creditService)
    {
        $this->authorizePermission($request, 'policies.update');
        $validated = $request->validate([
            'allowed_credit_days' => ['required', 'integer', Rule::in(CustomerCreditService::ALLOWED_DAYS)],
            'is_active' => ['required', 'boolean'],
        ]);

        $policy = BusinessPolicy::updateOrCreate(
            ['policy_key' => CustomerCreditService::POLICY_KEY],
            [
                'policy_name' => 'Credit Limit Policy',
                'policy_value' => (string) $validated['allowed_credit_days'],
                'is_active' => $validated['is_active'],
                'created_by' => BusinessPolicy::where('policy_key', CustomerCreditService::POLICY_KEY)->value('created_by') ?: $request->user('api')->id,
                'updated_by' => $request->user('api')->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Credit Limit Policy updated successfully.',
            'policy' => $creditService->policy(),
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user('api');
        $permissions = $user?->effectivePermissionNames() ?? collect();
        abort_unless($user && ($user->isSuperAdmin() || $permissions->contains($permission) || $permissions->contains('setting_system')), 403);
    }
}
