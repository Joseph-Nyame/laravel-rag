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

    /**
     * Retrieve the Agent models associated with this MultiAgent.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Agent>
     */
    public function get_agents()
    {
        if (empty($this->agent_ids)) {
            return collect(); // Return an empty collection if no agent_ids are set
        }
        return Agent::whereIn('id', $this->agent_ids)->get();
    }
}
