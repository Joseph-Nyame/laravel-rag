<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MultiAgent extends Model
{
    protected $fillable = ['name', 'agent_ids'];

    protected $casts = [
        'agent_ids' => 'array', // Cast JSON to array
    ];

    public function relations(): HasMany
    {
        return $this->hasMany(MultiAgentRelation::class);
    }
}
