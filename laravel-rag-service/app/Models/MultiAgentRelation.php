<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultiAgentRelation extends Model
{
    protected $fillable = [
        'multi_agent_id',
        'source_agent_id',
        'target_agent_id',
        'join_key',
        'description',
        'suggested_confidence',
    ];

    public function multiAgent(): BelongsTo
    {
        return $this->belongsTo(MultiAgent::class);
    }

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'source_agent_id');
    }

    public function targetAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'target_agent_id');
    }
}
