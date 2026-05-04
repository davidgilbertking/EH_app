<?php

namespace App\Models;

use App\Models\Concerns\ResolvesStoragePathCase;
use Illuminate\Database\Eloquent\Model;

class AncientOne extends Model
{
    use ResolvesStoragePathCase;

    protected $fillable = ['slug', 'name', 'image_path', 'bg_image_path', 'sort_order'];

    public function imageUrl(): ?string
    {
        return $this->publicStorageAsset($this->image_path);
    }

    /**
     * URL of the high-resolution upscale used as Home-screen background.
     * Falls back to the thumbnail so the screen is never bg-less while the
     * upscale is missing for a given Ancient.
     */
    public function bgImageUrl(): ?string
    {
        $path = $this->bg_image_path ?: $this->image_path;
        return $this->publicStorageAsset($path);
    }
}
