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
        'youtube_upload_status',
        'youtube_video_id',
        'youtube_url',
        'youtube_title',
        'youtube_description',
        'youtube_tags',
        'youtube_privacy_status',
        'youtube_error_message',
        'youtube_uploaded_at',
        'youtube_publish_status',
        'youtube_publish_at',
        'youtube_publish_timezone',
        'youtube_scheduled_for_local',
    ];

    protected $casts = [
        'youtube_tags' => 'array',
        'youtube_uploaded_at' => 'datetime',
        'youtube_publish_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
