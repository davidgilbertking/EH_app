<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingState extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'color_barrier' => 'boolean',
            'desired_target' => 'array', 'observed' => 'array', 'transition' => 'array',
            'client_seq' => 'integer', 'revision' => 'integer', 'applied_revision' => 'integer',
            'status_version' => 'integer', 'dark_since_ms' => 'integer', 'worker_seen_ms' => 'integer',
            'read_revision' => 'integer',
            'control_expires_ms' => 'integer',
        ];
    }
}
