<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class YouTubePublishScheduler
{
    /**
     * Determine the publish plan based on current time and Golden Hour rules.
     */
    public function resolvePublishPlan(?CarbonInterface $now = null): array
    {
        $timezone = config('services.youtube.publish_timezone', 'Asia/Jakarta');
        $now = $now ?? Carbon::now($timezone);
        $currentTime = $now->copy();

        $hour = $currentTime->hour;
        $minute = $currentTime->minute;
        $timeFloat = $hour + ($minute / 60);

        $windowTolerance = config('services.youtube.golden_fixed_window_minutes', 30) / 60;
        
        $isGoldenHour = false;
        
        // 17:00 to 21:00 window
        if ($timeFloat >= 17.0 && $timeFloat < 21.0) {
            $isGoldenHour = true;
        } 
        // 07:00 window (e.g. 07:00 to 07:30)
        elseif ($timeFloat >= 7.0 && $timeFloat <= (7.0 + $windowTolerance)) {
            $isGoldenHour = true;
        } 
        // 12:00 window (e.g. 12:00 to 12:30)
        elseif ($timeFloat >= 12.0 && $timeFloat <= (12.0 + $windowTolerance)) {
            $isGoldenHour = true;
        }

        if ($isGoldenHour) {
            return $this->buildImmediatePlan($timezone);
        }

        // Outside golden hour, determine next slot
        $target = $currentTime->copy()->startOfDay();

        if ($timeFloat < 7.0) {
            $target->hour(7);
        } elseif ($timeFloat < 12.0) {
            $target->hour(12);
        } elseif ($timeFloat < 17.0) {
            $target->hour(17);
        } else {
            // >= 21:00, schedule for next day 07:00
            $target->addDay()->hour(7);
        }

        $leadMinutes = config('services.youtube.min_schedule_lead_minutes', 15);
        
        if ($currentTime->diffInMinutes($target, false) < $leadMinutes) {
            // If the schedule target is too close to now, just publish immediately
            return $this->buildImmediatePlan($timezone);
        }

        return $this->buildScheduledPlan($target, $timezone);
    }

    private function buildImmediatePlan(string $timezone): array
    {
        return [
            'mode' => 'immediate_public',
            'privacy_status' => 'public',
            'publish_at' => null,
            'publish_at_local' => null,
            'timezone' => $timezone,
        ];
    }

    private function buildScheduledPlan(CarbonInterface $targetLocal, string $timezone): array
    {
        $publishAtUtc = $targetLocal->copy()->setTimezone('UTC');
        
        return [
            'mode' => 'scheduled_public',
            'privacy_status' => 'private',
            'publish_at' => $publishAtUtc,
            'publish_at_local' => $targetLocal->format('Y-m-d H:i:s T'),
            'timezone' => $timezone,
        ];
    }
}
