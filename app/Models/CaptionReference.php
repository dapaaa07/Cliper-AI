<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptionReference extends Model
{
    protected $fillable = [
        'platform',
        'category',
        'language',
        'title_example',
        'description_example',
        'hook_example',
        'hashtags_example',
        'source_url',
        'notes',
        'is_active',
        'quality_score',
        'content_hash',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'quality_score' => 'integer',
    ];
}
