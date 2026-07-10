<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowEdge extends Model
{
    protected $fillable = [
        'flow_id',
        'from_node_id',
        'to_node_id',
        'trigger_type',
        'trigger_key',
        'priority',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'to_node_id');
    }
}
