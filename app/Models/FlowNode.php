<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowNode extends Model
{
    protected $fillable = [
        'flow_id',
        'node_key',
        'type',
        'name',
        'config',
        'position_x',
        'position_y',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(FlowEdge::class, 'from_node_id');
    }
}
