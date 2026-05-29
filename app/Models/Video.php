<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasUuids;

    protected $fillable = [
        'youtube_url',
        'title',
        'thumbnail',
        'duration',
        'status',
    ];

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
