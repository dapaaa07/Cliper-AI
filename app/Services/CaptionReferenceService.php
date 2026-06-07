<?php

namespace App\Services;

use App\Models\CaptionReference;
use App\Models\Clip;

class CaptionReferenceService
{
    /**
     * Get a set of random caption references to serve as style guides for the AI.
     */
    public function pickReferencesForClip(Clip $clip, int $limit = 5): array
    {
        return CaptionReference::where('is_active', true)
            ->orderByDesc('quality_score')
            ->inRandomOrder()
            ->take($limit)
            ->get()
            ->map(function ($ref) {
                return [
                    'hook' => $ref->hook_example,
                    'description' => $ref->description_example,
                    'hashtags' => $ref->hashtags_example,
                    'platform' => $ref->platform,
                ];
            })
            ->toArray();
    }
}
