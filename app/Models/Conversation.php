<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'whatsapp_instance_id',
        'flow_id',
        'flow_version',
        'current_node_id',
        'status',
        'context',
        'assigned_user_id',
        'started_at',
        'closed_at',
    ];

    protected $casts = [
        'context' => 'array',
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'current_node_id');
    }

    public function whatsappInstance(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstance::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
