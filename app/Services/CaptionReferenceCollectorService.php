<?php

namespace App\Services;

use App\Models\CaptionReference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class CaptionReferenceCollectorService
{
    public function collectFromUrls(array $urls, array $options = []): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'failed' => []];
        $max = env('CAPTION_COLLECTOR_MAX_PER_RUN', 30);
        $sleepMs = env('CAPTION_COLLECTOR_SLEEP_MS', 800);
        
        $count = 0;
        foreach ($urls as $url) {
            if ($count >= $max) break;
            
            $url = trim($url);
            if (empty($url)) continue;

            try {
                $platform = $this->detectPlatform($url);
                $metadata = $this->fetchMetadataViaYtDlp($url);
                
                if (!$metadata) {
                    throw new \Exception("Failed to fetch metadata from yt-dlp");
                }

                $referenceData = $this->extractCaptionReferenceFromMetadata($metadata, $platform, $options);
                if ($referenceData) {
                    $status = $this->saveReference($referenceData, $options);
                    if ($status === 'imported') {
                        $result['imported']++;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    $result['skipped']++;
                }
            } catch (\Exception $e) {
                $result['failed'][] = ['url' => $url, 'error' => $e->getMessage()];
            }

            $count++;
            usleep($sleepMs * 1000);
        }

        return $result;
    }

    public function collectFromYouTubeSearch(string $query, array $options = []): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'failed' => []];
        $apiKey = env('YOUTUBE_DATA_API_KEY');
        
        if (empty($apiKey)) {
            $result['failed'][] = ['url' => 'search', 'error' => 'YOUTUBE_DATA_API_KEY is not set'];
            return $result;
        }

        $maxResults = $options['max'] ?? env('CAPTION_COLLECTOR_MAX_PER_RUN', 25);
        $sleepMs = env('CAPTION_COLLECTOR_SLEEP_MS', 800);

        try {
            $searchResponse = Http::get('https://www.googleapis.com/youtube/v3/search', [
                'part' => 'id',
                'q' => $query,
                'maxResults' => $maxResults,
                'order' => 'relevance',
                'type' => 'video',
                'videoDuration' => 'short',
                'key' => $apiKey,
            ]);

            if (!$searchResponse->successful()) {
                throw new \Exception('YouTube API Search failed: ' . $searchResponse->body());
            }

            $videoIds = collect($searchResponse->json('items'))->pluck('id.videoId')->filter()->toArray();

            if (empty($videoIds)) {
                return $result;
            }

            $videosResponse = Http::get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'snippet,contentDetails',
                'id' => implode(',', $videoIds),
                'key' => $apiKey,
            ]);

            if (!$videosResponse->successful()) {
                throw new \Exception('YouTube API Videos failed: ' . $videosResponse->body());
            }

            foreach ($videosResponse->json('items') as $item) {
                // Approximate duration check, YouTube duration is ISO8601 (e.g. PT1M30S)
                $duration = $item['contentDetails']['duration'] ?? '';
                $durationSeconds = $this->parseIsoDuration($duration);
                
                if ($durationSeconds > 0 && $durationSeconds <= 180) {
                    $metadata = [
                        'title' => $item['snippet']['title'] ?? '',
                        'description' => $item['snippet']['description'] ?? '',
                        'tags' => $item['snippet']['tags'] ?? [],
                        'webpage_url' => 'https://www.youtube.com/watch?v=' . $item['id'],
                    ];

                    $referenceData = $this->extractCaptionReferenceFromMetadata($metadata, 'youtube_shorts', $options);
                    if ($referenceData) {
                        $status = $this->saveReference($referenceData, $options);
                        if ($status === 'imported') {
                            $result['imported']++;
                        } else {
                            $result['skipped']++;
                        }
                    } else {
                        $result['skipped']++;
                    }
                } else {
                     $result['skipped']++;
                }

                usleep($sleepMs * 1000);
            }
            
        } catch (\Exception $e) {
            $result['failed'][] = ['url' => 'search', 'error' => $e->getMessage()];
        }

        return $result;
    }

    private function detectPlatform(string $url): string
    {
        if (strpos($url, 'tiktok.com') !== false) {
            return 'tiktok';
        }
        return 'youtube_shorts';
    }

    private function fetchMetadataViaYtDlp(string $url): ?array
    {
        $venvPath = base_path('python_venv/bin/yt-dlp');
        $ytdlpBin = file_exists($venvPath) ? $venvPath : 'yt-dlp';

        $process = new Process([$ytdlpBin, '--dump-json', '--skip-download', $url]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();
        return json_decode($output, true);
    }

    private function extractCaptionReferenceFromMetadata(array $metadata, string $platform, array $options): ?array
    {
        $title = $metadata['title'] ?? '';
        $description = $metadata['description'] ?? '';
        
        $captionText = $platform === 'tiktok' ? $title : $description;
        if ($platform === 'tiktok' && empty($captionText)) {
            $captionText = $description;
        }

        $captionText = trim($captionText);
        
        // Clean tracking URLs and repeating whitespace
        $captionText = preg_replace('/https?:\/\/\S+/i', '', $captionText);
        $captionText = preg_replace('/\n{3,}/', "\n\n", $captionText);
        $captionText = trim($captionText);

        if (strlen($captionText) < 20) {
            return null; // Too short
        }

        // Extract Hook
        $lines = array_filter(explode("\n", $captionText), fn($line) => trim($line) !== '');
        $hook = count($lines) > 0 ? trim($lines[0]) : '';

        // Extract Hashtags
        $hashtags = [];
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $captionText, $matches);
        if (!empty($matches[1])) {
            $hashtags = array_unique($matches[1]);
        }
        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            $hashtags = array_unique(array_merge($hashtags, $metadata['tags']));
        }
        $hashtagsStr = implode(' ', array_map(fn($t) => '#' . $t, array_slice($hashtags, 0, 8)));

        // Generic checks
        $lowerCaption = strtolower($captionText);
        $genericPhrases = ['subscribe', 'like and share', 'follow for more'];
        $isGeneric = false;
        foreach ($genericPhrases as $phrase) {
            if (strpos($lowerCaption, $phrase) !== false) {
                $isGeneric = true;
                break;
            }
        }
        if ($isGeneric && count($lines) <= 2) {
            return null; // Reject generic short promotion
        }

        // Quality Score
        $score = $this->calculateQualityScore($captionText, $hook, count($lines), count($hashtags), $metadata['duration'] ?? 0);
        
        $includeLowQuality = $options['include_low_quality'] ?? false;
        if ($score < 40 && !$includeLowQuality) {
            return null;
        }

        return [
            'platform' => $platform,
            'category' => $options['category'] ?? 'clip',
            'language' => $options['language'] ?? env('CAPTION_COLLECTOR_DEFAULT_LANGUAGE', 'id'),
            'title_example' => substr($title, 0, 255),
            'description_example' => substr($captionText, 0, 1000),
            'hook_example' => substr($hook, 0, 255),
            'hashtags_example' => substr($hashtagsStr, 0, 255),
            'source_url' => $metadata['webpage_url'] ?? '',
            'notes' => 'Auto collected via yt-dlp / API. Score: ' . $score,
            'is_active' => true,
            'quality_score' => $score,
            'content_hash' => md5($captionText),
        ];
    }

    private function calculateQualityScore(string $caption, string $hook, int $lineCount, int $hashtagCount, $durationSeconds): int
    {
        $score = 0;
        
        $hookLen = strlen($hook);
        if ($hookLen >= 30 && $hookLen <= 140) $score += 25;
        
        if ($lineCount >= 2 && $lineCount <= 5) $score += 20;
        
        if ($hashtagCount >= 2 && $hashtagCount <= 8) $score += 15;
        
        $lowerCaption = strtolower($caption);
        $bonusWords = ['ternyata', 'jangan', 'gue kira', 'ini yang', 'plot twist', 'sering banget', 'baru sadar'];
        foreach ($bonusWords as $word) {
            if (strpos($lowerCaption, $word) !== false) {
                $score += 15;
                break;
            }
        }

        if ($durationSeconds > 0 && $durationSeconds <= 180) $score += 10;
        
        // Penalties
        if (strpos($lowerCaption, 'berikut adalah') !== false || strpos($lowerCaption, 'cuplikan singkat') !== false) {
            $score -= 20;
        }
        if (strpos($lowerCaption, 'subscribe to my channel') !== false || strpos($lowerCaption, 'link di bio') !== false) {
            $score -= 30;
        }

        return max(0, min(100, $score));
    }

    private function saveReference(array $data, array $options): string
    {
        if (!empty($data['source_url'])) {
            $exists = CaptionReference::where('source_url', $data['source_url'])->exists();
            if ($exists) {
                return 'skipped';
            }
        }

        if (!empty($data['content_hash'])) {
            $exists = CaptionReference::where('content_hash', $data['content_hash'])->exists();
            if ($exists) {
                return 'skipped';
            }
        }

        CaptionReference::create($data);
        return 'imported';
    }

    private function parseIsoDuration($duration)
    {
        preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $matches);
        $hours = isset($matches[1]) && $matches[1] !== '' ? (int)$matches[1] : 0;
        $minutes = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : 0;
        $seconds = isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 0;
        
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
