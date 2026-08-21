<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverdueCreditReminder extends Model
{
    protected $fillable = ['sale_id', 'user_id', 'reminder_date'];

    protected $casts = ['reminder_date' => 'date'];
}
