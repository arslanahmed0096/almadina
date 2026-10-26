<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    protected $table = 'transfers';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'id', 'Ref', 'date', 'user_id', 'from_warehouse_id', 'to_warehouse_id', 'time',
        'items', 'statut', 'approval_status', 'notes', 'GrandTotal', 'discount', 'shipping', 'TaxNet', 'tax_rate',
        'workflow_status', 'transfer_type', 'ownership_type', 'stock_transfer_company_id', 'transfer_value',
        'previous_outstanding', 'advance_applied', 'paid_amount', 'outstanding_balance', 'payment_status', 'financial_posted_at',
        'required_date', 'request_note', 'response_note', 'acknowledgement_note',
        'processed_by', 'acknowledged_by', 'dispatched_by', 'received_by', 'requested_at',
        'processed_at', 'acknowledged_at', 'dispatched_at', 'received_at',
        'driver_id', 'vehicle_details', 'dispatch_note', 'receiving_note', 'cancelled_by', 'cancelled_at',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'items' => 'double',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'transfer_value' => 'double',
        'previous_outstanding' => 'double',
        'advance_applied' => 'double',
        'paid_amount' => 'double',
        'outstanding_balance' => 'double',
        'financial_posted_at' => 'datetime',
        'required_date' => 'date:Y-m-d',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const WORKFLOW_PENDING_APPROVAL = 'pending_approval';
    public const WORKFLOW_PENDING_ACKNOWLEDGEMENT = 'pending_acknowledgement';
    public const WORKFLOW_ACKNOWLEDGED = 'acknowledged';
    public const WORKFLOW_READY_FOR_DISPATCH = 'ready_for_dispatch';
    public const WORKFLOW_DISPATCHED = 'dispatched';
    public const WORKFLOW_PARTIALLY_RECEIVED = 'partially_received';
    public const WORKFLOW_RECEIVED = 'received';
    public const WORKFLOW_COMPLETED = 'completed';
    public const WORKFLOW_CANCELLED = 'cancelled';
    public const WORKFLOW_DRAFT = 'draft';
    public const WORKFLOW_IN_TRANSIT = 'in_transit';
    public const WORKFLOW_RETURN_PENDING = 'return_pending';
    public const WORKFLOW_RETURN_IN_TRANSIT = 'return_in_transit';
    public const WORKFLOW_RETURN_RECEIVED = 'return_received';

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\TransferDetail');
    }

    public function from_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'from_warehouse_id');
    }

    public function to_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'to_warehouse_id');
    }

    public function histories()
    {
        return $this->hasMany(TransferStatusHistory::class)->orderBy('id');
    }

    public function driver() { return $this->belongsTo(Employee::class, 'driver_id'); }
    public function transferReturn() { return $this->hasOne(TransferReturn::class); }
    public function stockMovements() { return $this->hasMany(TransferStockMovement::class); }
    public function stockTransferCompany() { return $this->belongsTo(StockTransferCompany::class, 'stock_transfer_company_id'); }
    public function paymentAllocations() { return $this->hasMany(StockTransferPaymentAllocation::class); }
    public function ledgerEntries() { return $this->hasMany(StockTransferLedgerEntry::class); }

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Accessor to ensure OLD TRANSFERS SAFETY:
     * any existing row with a NULL approval_status is treated as "approved".
     */
    public function getApprovalStatusAttribute($value)
    {
        if ($value === null) {
            return 'approved';
        }

        return $value;
    }

    /**
     * Convenience helper for business logic.
     */
    public function isApproved()
    {
        return in_array($this->approval_status, ['approved', 'partially_approved'], true);
    }
}
