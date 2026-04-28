<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoundTrack extends Model
{
    protected $fillable = ['sound_folder_id', 'file_path', 'duration_seconds'];

    protected $casts = [
        'duration_seconds' => 'float',
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
