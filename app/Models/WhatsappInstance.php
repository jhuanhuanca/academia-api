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
        'meta_phone_number_id',
        'meta_waba_id',
        'meta_access_token',
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
        'meta_access_token' => 'encrypted',
    ];

    protected $hidden = [
        'evolution_apikey',
        'meta_access_token',
        'webhook_secret',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function usesMetaCloud(): bool
    {
        return $this->integration === 'meta_cloud';
    }
}
