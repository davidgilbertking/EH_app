<?php

namespace App\Lighting;

use App\Models\LightingState;
use Illuminate\Support\Facades\DB;

class LightingStore
{
    public function state(): LightingState
    {
        return LightingState::findOrFail(1);
    }

    public function atomic(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            // First statement takes a real write lock, including on SQLite where
            // SELECT FOR UPDATE is ignored. Never hold this across driver I/O.
            DB::table('lighting_states')->where('id', 1)->update(['id' => DB::raw('id')]);
            $state = $this->state();
            $result = $operation($state);
            if ($state->isDirty()) {
                $state->status_version++;
                $state->save();
            }

            return $result;
        }, 5);
    }

    public function event(LightingState $state, string $operation, ?array $target = null): void
    {
        DB::table('lighting_events')->insert([
            'timestamp_ms' => self::now(), 'intent_id' => $state->intent_id,
            'revision' => $state->revision, 'stage' => $state->stage,
            'operation' => $operation, 'target' => $target === null ? null : json_encode($target, JSON_THROW_ON_ERROR),
            'simulated' => config('lighting.driver') === 'mock',
        ]);
        $this->prune('lighting_events');
    }

    public function prune(string $table): void
    {
        $cutoff = DB::table($table)->orderByDesc('id')->skip(max(1, (int) config('lighting.history_limit', 256)))->value('id');
        if ($cutoff !== null) {
            DB::table($table)->where('id', '<=', $cutoff)->delete();
        }
    }

    public static function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
