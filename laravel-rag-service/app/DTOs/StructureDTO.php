<?php

namespace App\DTOs;

class StructureDTO
{
    public function __construct(
        public array $required,
        public array $optional,
        public array $specifics = []
    ) {
        // Validate schema
        $this->validate();
    }

    public static function fromArray(array $schema): self
    {
        return new self(
            required: $schema['required'] ?? [],
            optional: $schema['optional'] ?? [],
            specifics: $schema['specifics'] ?? []
        );
    }

    protected function validate(): void
    {
        // Ensure required and optional are arrays of strings
        if (!is_array($this->required) || !empty(array_filter($this->required, fn($v) => !is_string($v)))) {
            throw new \InvalidArgumentException('Required fields must be an array of strings.');
        }
        if (!is_array($this->optional) || !empty(array_filter($this->optional, fn($v) => !is_string($v)))) {
            throw new \InvalidArgumentException('Optional fields must be an array of strings.');
        }

        // Ensure specifics keys are in required or optional
        $allowedFields = array_merge($this->required, $this->optional);
        foreach ($this->specifics as $field => $spec) {
            if (!in_array($field, $allowedFields)) {
                throw new \InvalidArgumentException("Specific field '$field' must be in required or optional fields.");
            }
            if (!isset($spec['type']) || !is_string($spec['type'])) {
                throw new \InvalidArgumentException("Specific field '$field' must have a string 'type'.");
            }
        }
    }

    public function toArray(): array
    {
        return [
            'schema' => [
                'required' => $this->required,
                'optional' => $this->optional,
                'specifics' => $this->specifics,
            ],
        ];
    }
}