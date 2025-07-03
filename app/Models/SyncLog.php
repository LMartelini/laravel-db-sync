<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'record_id',
        'table_name',
        'action',
        'direction',
        'synced_at',
    ];
}
