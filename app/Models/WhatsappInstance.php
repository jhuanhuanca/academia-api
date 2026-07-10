<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappInstance extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'evolution_instance',
        'evolution_apikey',
        'integration',
        'phone_e164',
        'status',
        'webhook_secret',
        'meta',
        'last_connected_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_connected_at' => 'datetime',
        'evolution_apikey' => 'encrypted',
    ];

    protected $hidden = [
        'evolution_apikey',
        'webhook_secret',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
