<?php

namespace App\Services\MultiAgent\Context;

/**
 * A generic key-value store for shared context in the multi-agent system.
 *
 * Stores data that agents can access and update during query processing,
 * reducing agent isolation by enabling data sharing. Designed to work with
 * test agents of any type, supporting arbitrary data (e.g., Qdrant payloads,
 * agent outputs, session data).
 */
class SharedContext
{
    /**
     * The internal storage for context data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Set a value in the context by key.
     *
     * @param string $key The key to store the data under.
     * @param mixed $value The value to store (e.g., array, string, object).
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Get a value from the context by key.
     *
     * @param string $key The key to retrieve.
     * @param mixed $default The default value if the key doesn't exist.
     * @return mixed The stored value or the default.
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if a key exists in the context.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Merge new data into the context.
     *
     * @param array $data An array of key-value pairs to merge.
     * @return void
     */
    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    /**
     * Clear all data in the context.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Get all context data.
     *
     * @return array The entire context data array.
     */
    public function all(): array
    {
        return $this->data;
    }
}