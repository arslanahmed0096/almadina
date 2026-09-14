<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTargetHistory extends Model
{
    protected $fillable = ['supplier_target_id', 'user_id', 'event', 'old_values', 'new_values'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    public function target()
    {
        return $this->belongsTo(SupplierTarget::class, 'supplier_target_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
