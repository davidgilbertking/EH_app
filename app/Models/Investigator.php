<?php

namespace App\Models;

use App\Models\Concerns\ResolvesStoragePathCase;
use Illuminate\Database\Eloquent\Model;

class Investigator extends Model
{
    use ResolvesStoragePathCase;

    protected $fillable = ['slug', 'name', 'gender', 'image_path', 'sort_order'];

    public function imageUrl(): ?string
    {
        return $this->publicStorageAsset($this->image_path);
    }
}
