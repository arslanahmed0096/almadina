<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxAudit extends Model
{
    protected $fillable = ['tax_id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'before', 'after', 'ip_address'];
    protected $casts = ['before' => 'array', 'after' => 'array'];
    public function tax() { return $this->belongsTo(Tax::class); }
}
