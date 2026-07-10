<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'method',
        'destination',
        'payload_sent',
        'status',
        'delivered_at',
    ];

    protected $casts = [
        'payload_sent' => 'array',
        'delivered_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
