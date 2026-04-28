<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SoundFolder extends Model
{
    public const MODE_RANDOM_POS_FADE = 'random_pos_fade';
    public const MODE_FROM_START_NO_FADE = 'from_start_no_fade';

    protected $fillable = ['slug', 'name', 'mode'];

    public function tracks(): HasMany
    {
        return $this->hasMany(SoundTrack::class);
    }
}
