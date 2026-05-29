<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clip extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_id',
        'start_time',
        'end_time',
        'aspect_ratio',
        'status',
        'video_path',
        'subtitle_path',
        'progress',
        'error_message',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
