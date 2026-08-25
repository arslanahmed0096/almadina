<?php

namespace App\Services\Procurement;

use App\Models\ProcurementAudit;
use Illuminate\Database\Eloquent\Model;

class ProcurementAuditService
{
    public function record(Model $model, string $action, array $old = [], array $new = [], ?string $notes = null, ?int $purchaseOrderId = null): ProcurementAudit
    {
        $request = request();

        return ProcurementAudit::create([
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'purchase_order_id' => $purchaseOrderId ?? ($model->purchase_order_id ?? ($model instanceof \App\Models\PurchaseOrder ? $model->id : null)),
            'user_id' => auth('api')->id() ?: auth()->id(),
            'action' => $action,
            'reference' => $model->number ?? $model->Ref ?? null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'notes' => $notes,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
        ]);
    }
}
