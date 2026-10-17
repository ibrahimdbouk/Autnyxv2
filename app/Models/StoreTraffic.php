<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Root Cause drivers — door-counter visitors per store and day (the optional "store_traffic" import). */
class StoreTraffic extends Model
{
    protected $table = 'store_traffic';

    protected $fillable = ['tenant_id', 'import_id', 'store_id', 'location', 'date', 'visitors'];

    protected $casts = ['date' => 'date', 'visitors' => 'float'];
}
