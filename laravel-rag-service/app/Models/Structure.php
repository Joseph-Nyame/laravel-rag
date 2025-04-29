<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class Structure extends Model
{
    protected $fillable = ['agent_id', 'schema'];
    protected $casts = ['schema' => 'array'];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function validate(array $data): bool
    {
        try {
            $required = $this->schema['required'] ?? [];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    Log::error("Validation failed: Missing required field", [
                        'field' => $field,
                        'agent_id' => $this->agent_id,
                    ]);
                    throw new \Exception("Missing required field: $field");
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Structure validation failed", [
                'agent_id' => $this->agent_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
