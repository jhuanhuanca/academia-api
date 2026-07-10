<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'phone_e164',
        'wa_name',
        'name',
        'email',
        'document_id',
        'tags',
        'custom_fields',
        'opt_in_at',
        'last_message_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'custom_fields' => 'array',
        'opt_in_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
