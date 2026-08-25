<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementStockMovement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:6', 'metadata' => 'array', 'reversed_at' => 'datetime'];
}
