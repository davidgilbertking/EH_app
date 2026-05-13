<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoundTrack extends Model
{
    protected $fillable = [
        'sound_folder_id',
        'file_path',
        'duration_seconds',
        'integrated_lufs',
        'true_peak_dbtp',
        'normalization_gain_db',
        'loudness_analyzed_at',
    ];

    protected $casts = [
        'duration_seconds' => 'float',
        'integrated_lufs' => 'float',
        'true_peak_dbtp' => 'float',
        'normalization_gain_db' => 'float',
        'loudness_analyzed_at' => 'datetime',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(SoundFolder::class, 'sound_folder_id');
    }

    public function streamUrl(): string
    {
        return route('audio.stream', ['track' => $this->id]);
    }
}
