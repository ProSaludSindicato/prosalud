<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuration extends Model
{
    public $timestamps = false;
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get configuration value by key.
     */
    public static function get(string $key, $default = null)
    {
        $config = static::where('key', $key)->first();
        
        if (!$config) {
            return $default;
        }

        return $config->getValue();
    }

    /**
     * Set configuration value by key.
     */
    public static function set(string $key, $value, string $type = 'string', ?string $description = null): self
    {
        $config = static::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) || is_object($value) ? json_encode($value) : (string) $value,
                'type' => $type,
                'description' => $description,
                'updated_at' => now(),
            ]
        );

        return $config;
    }

    /**
     * Get the typed value based on the type field.
     */
    public function getValue()
    {
        if (empty($this->value)) {
            return null;
        }

        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'json' => json_decode($this->value, true),
            'float' => (float) $this->value,
            default => $this->value,
        };
    }

    /**
     * Set the value and automatically determine type if not set.
     */
    public function setValueAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['value'] = json_encode($value);
            if (empty($this->attributes['type'])) {
                $this->attributes['type'] = 'json';
            }
        } elseif (is_bool($value)) {
            $this->attributes['value'] = $value ? '1' : '0';
            if (empty($this->attributes['type'])) {
                $this->attributes['type'] = 'boolean';
            }
        } elseif (is_int($value)) {
            $this->attributes['value'] = (string) $value;
            if (empty($this->attributes['type'])) {
                $this->attributes['type'] = 'integer';
            }
        } elseif (is_float($value)) {
            $this->attributes['value'] = (string) $value;
            if (empty($this->attributes['type'])) {
                $this->attributes['type'] = 'float';
            }
        } else {
            $this->attributes['value'] = (string) $value;
            if (empty($this->attributes['type'])) {
                $this->attributes['type'] = 'string';
            }
        }
    }
}
