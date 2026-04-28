<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Investigator extends Model
{
    protected $fillable = ['slug', 'name', 'gender', 'image_path', 'sort_order'];

    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
