<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserState extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['user_id', 'current_ancient_one_id', 'blobs'];

    protected $casts = [
        'blobs' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ancientOne(): BelongsTo
    {
        return $this->belongsTo(AncientOne::class, 'current_ancient_one_id');
    }
}
