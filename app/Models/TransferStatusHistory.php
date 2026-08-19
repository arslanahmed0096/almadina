<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferStatusHistory extends Model
{
    protected $fillable = [
        'transfer_id', 'performed_by', 'warehouse_id', 'previous_status', 'new_status',
        'action', 'note', 'metadata',
    ];

    protected $casts = [
        'transfer_id' => 'integer',
        'performed_by' => 'integer',
        'warehouse_id' => 'integer',
        'metadata' => 'array',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
