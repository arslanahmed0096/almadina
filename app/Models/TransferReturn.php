<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferReturn extends Model
{
    protected $fillable = [
        'transfer_id', 'reference', 'from_warehouse_id', 'to_warehouse_id', 'driver_id',
        'vehicle_details', 'status', 'note', 'created_by', 'dispatched_by', 'received_by',
        'dispatched_at', 'received_at',
    ];

    protected $casts = ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];

    public const PENDING = 'return_pending';
    public const READY = 'return_ready_for_dispatch';
    public const IN_TRANSIT = 'return_in_transit';
    public const RECEIVED = 'return_received';

    public function transfer() { return $this->belongsTo(Transfer::class); }
    public function details() { return $this->hasMany(TransferReturnDetail::class); }
    public function driver() { return $this->belongsTo(Employee::class, 'driver_id'); }
    public function fromWarehouse() { return $this->belongsTo(Warehouse::class, 'from_warehouse_id'); }
    public function toWarehouse() { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
}
