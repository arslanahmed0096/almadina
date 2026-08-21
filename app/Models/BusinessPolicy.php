<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessPolicy extends Model
{
    protected $table = 'policies';

    protected $fillable = [
        'policy_key', 'policy_name', 'policy_value', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
