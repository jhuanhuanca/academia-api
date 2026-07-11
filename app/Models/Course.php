<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'uuid',
        'title',
        'slug',
        'description',
        'price',
        'currency',
        'delivery_type',
        'delivery_payload',
        'payment_qr_media_asset_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'delivery_payload' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function paymentQr(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'payment_qr_media_asset_id');
    }
}
