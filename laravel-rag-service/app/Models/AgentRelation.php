<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRelation extends Model
{
    protected $fillable = [
        'source_agent_id',
        'target_agent_id',
        'join_key',
        'description',
        'confidence',
    ];

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'source_agent_id');
    }

    public function targetAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'target_agent_id');
    }
}
