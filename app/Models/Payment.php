<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'provider',
        'external_id',
        'idempotency_key',
        'status',
        'amount',
        'currency',
        'qr_payload',
        'qr_media_asset_id',
        'receipt_media_asset_id',
        'expires_at',
        'paid_at',
        'confirmation_token',
        'confirmation_expires_at',
        'receipt_submitted_at',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_response' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'receipt_submitted_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function receiptMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'receipt_media_asset_id');
    }
}
