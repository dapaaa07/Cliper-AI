<?php

namespace App\Services;

use App\Models\Clip;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class YouTubeContextService
{
    /**
     * Get context for a specific clip, including original video metadata and nearby subtitles.
     */
    public function getContextForClip(Clip $clip): array
    {
        if (!$clip->video || !$clip->video->youtube_url) {
            return [];
        }

        $videoUrl = $clip->video->youtube_url;
        $videoId = $this->extractVideoId($videoUrl);
        
        if (!$videoId) {
            return [];
        }

        $context = $this->getOriginalMetadata($videoUrl, $videoId);
        
        // Parse nearby subtitles
        $nearbySubtitles = $this->getNearbySubtitles($videoUrl, $videoId, $clip->start_time, $clip->end_time);
        if ($nearbySubtitles) {
            $context['nearby_subtitles'] = $nearbySubtitles;
        }

        return $context;
    }

    /**
     * Fetch original metadata using yt-dlp, cached as JSON.
     */
    private function getOriginalMetadata(string $videoUrl, string $videoId): array
    {
        $cacheDir = storage_path('app/youtube_context');
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . '/' . $videoId . '_metadata.json';

        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data) {
                return $data;
            }
        }

        // Fetch using yt-dlp
        $venvPath = base_path('python_venv/bin/yt-dlp');
        $ytdlpBin = file_exists($venvPath) ? $venvPath : 'yt-dlp';
        
        $cookiesPath = base_path('cookies.txt');
        $cookiesArgs = file_exists($cookiesPath) ? ['--cookies', $cookiesPath] : [];

        $processArgs = array_merge([$ytdlpBin, '--dump-json', '--skip-download', $videoUrl], $cookiesArgs);

        $process = new Process($processArgs);
        $process->setTimeout(120);

        try {
            $process->mustRun();
            $output = $process->getOutput();
            $data = json_decode($output, true);

            if ($data) {
                // Extract only needed fields to keep context size manageable
                $summary = [
                    'title' => $data['title'] ?? '',
                    'channel' => $data['uploader'] ?? $data['channel'] ?? '',
                    'description' => substr($data['description'] ?? '', 0, 1200), // Limit description
                    'tags' => array_slice($data['tags'] ?? [], 0, 15), // Limit tags
                    'categories' => $data['categories'] ?? [],
                    'duration' => $data['duration'] ?? 0,
                ];

                file_put_contents($cacheFile, json_encode($summary));
                return $summary;
            }
        } catch (\Exception $e) {
            Log::error('yt-dlp dump-json failed: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch and parse nearby subtitles.
     */
    private function getNearbySubtitles(string $videoUrl, string $videoId, string $startTimeStr, string $endTimeStr): string
    {
        $cacheDir = storage_path('app/youtube_context');
        $subPrefix = $cacheDir . '/' . $videoId;
        
        // We will look for .vtt files starting with $videoId
        $vttFiles = glob($subPrefix . '*.vtt');

        if (empty($vttFiles)) {
            // Download subtitles
            $venvPath = base_path('python_venv/bin/yt-dlp');
            $ytdlpBin = file_exists($venvPath) ? $venvPath : 'yt-dlp';
            
            $cookiesPath = base_path('cookies.txt');
            $cookiesArgs = file_exists($cookiesPath) ? ['--cookies', $cookiesPath] : [];

            $processArgs = array_merge([
                $ytdlpBin, 
                '--skip-download', 
                '--write-subs', 
                '--write-auto-subs', 
                '--sub-langs', 'id,en', 
                '--sub-format', 'vtt', 
                '-o', $subPrefix, 
                $videoUrl
            ], $cookiesArgs);

            $process = new Process($processArgs);
            $process->setTimeout(120);
            
            try {
                $process->mustRun();
            } catch (\Exception $e) {
                Log::warning('yt-dlp subtitle download failed or no subtitles found: ' . $e->getMessage());
            }

            $vttFiles = glob($subPrefix . '*.vtt');
        }

        if (empty($vttFiles)) {
            return '';
        }

        // Just take the first VTT found (usually Indonesian or English)
        $vttFile = $vttFiles[0];
        
        return $this->parseVttNearby($vttFile, $startTimeStr, $endTimeStr);
    }

    private function parseVttNearby(string $vttFile, string $startTimeStr, string $endTimeStr): string
    {
        if (!file_exists($vttFile)) {
            return '';
        }

        $lines = file($vttFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        $startSec = $this->timeToSeconds($startTimeStr) - 15;
        $endSec = $this->timeToSeconds($endTimeStr) + 15;
        
        $startSec = max(0, $startSec);

        $extractedText = [];
        $isInRange = false;

        foreach ($lines as $line) {
            // Check if line is a timestamp line like 00:00:15.000 --> 00:00:17.500
            if (strpos($line, '-->') !== false) {
                $parts = explode('-->', $line);
                if (count($parts) == 2) {
                    $lineStartSec = $this->timeToSeconds(trim($parts[0]));
                    $lineEndSec = $this->timeToSeconds(trim($parts[1]));

                    if ($lineStartSec <= $endSec && $lineEndSec >= $startSec) {
                        $isInRange = true;
                    } else {
                        $isInRange = false;
                    }
                }
                continue;
            }

            // If it's a metadata block (WEBVTT, Style, etc) or time signature, skip
            if (strpos($line, 'WEBVTT') !== false || strpos($line, 'Kind:') !== false || strpos($line, 'Language:') !== false || preg_match('/^[0-9]+$/', $line)) {
                continue;
            }

            // Strip vtt tags like <c.colorE5E5E5> or <00:00:15.500>
            $cleanLine = strip_tags($line);
            $cleanLine = preg_replace('/<[^>]*>/', '', $cleanLine);
            $cleanLine = trim($cleanLine);

            if ($isInRange && !empty($cleanLine)) {
                // Avoid duplicates consecutive lines
                $lastExtracted = end($extractedText);
                if ($lastExtracted !== $cleanLine) {
                    $extractedText[] = $cleanLine;
                }
            }
        }

        $result = implode(' ', $extractedText);
        // Clean up excessive whitespace
        $result = preg_replace('/\s+/', ' ', $result);
        
        return substr($result, 0, 2500); // Limit to 2500 chars max
    }

    public function getFullTranscriptWithTimestamps(string $videoUrl, string $videoId): string
    {
        $cacheDir = storage_path('app/youtube_context');
        $subPrefix = $cacheDir . '/' . $videoId;
        
        $vttFiles = glob($subPrefix . '*.vtt');

        if (empty($vttFiles)) {
            $venvPath = base_path('python_venv/bin/yt-dlp');
            $ytdlpBin = file_exists($venvPath) ? $venvPath : 'yt-dlp';
            
            $cookiesPath = base_path('cookies.txt');
            $cookiesArgs = file_exists($cookiesPath) ? ['--cookies', $cookiesPath] : [];

            $processArgs = array_merge([
                $ytdlpBin, 
                '--skip-download', 
                '--write-subs', 
                '--write-auto-subs', 
                '--sub-langs', 'id,en,all', 
                '--sub-format', 'vtt', 
                '-o', $subPrefix, 
                $videoUrl
            ], $cookiesArgs);

            $process = new Process($processArgs);
            $process->setTimeout(120);
            
            $outputStr = '';
            try {
                $process->run();
                $outputStr = $process->getOutput() . "\n" . $process->getErrorOutput();
            } catch (\Exception $e) {
                Log::warning('yt-dlp subtitle download failed in getFullTranscriptWithTimestamps: ' . $e->getMessage());
                $outputStr = $e->getMessage();
            }

            $vttFiles = glob($subPrefix . '*.vtt');

            if (empty($vttFiles)) {
                throw new \Exception("Transcript tidak ditemukan. Debug yt-dlp:\n" . substr($outputStr, 0, 500));
            }
        }

        if (empty($vttFiles)) {
            throw new \Exception("File .vtt tidak ditemukan di direktori cache setelah yt-dlp berjalan.");
        }

        return $this->parseVttWithFullTimestamps($vttFiles[0]);
    }

    private function parseVttWithFullTimestamps(string $vttFile): string
    {
        if (!file_exists($vttFile)) {
            return '';
        }

        $lines = file($vttFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $extractedText = [];
        $currentTimestamp = '';

        foreach ($lines as $line) {
            if (strpos($line, '-->') !== false) {
                $parts = explode('-->', $line);
                if (count($parts) == 2) {
                    // Get only the start timestamp, drop milliseconds
                    $startStr = trim($parts[0]);
                    $timeParts = explode('.', $startStr);
                    $currentTimestamp = $timeParts[0];
                }
                continue;
            }

            if (strpos($line, 'WEBVTT') !== false || strpos($line, 'Kind:') !== false || strpos($line, 'Language:') !== false || preg_match('/^[0-9]+$/', $line)) {
                continue;
            }

            $cleanLine = strip_tags($line);
            $cleanLine = preg_replace('/<[^>]*>/', '', $cleanLine);
            $cleanLine = trim($cleanLine);

            if (!empty($cleanLine) && $currentTimestamp !== '') {
                $formattedLine = "[{$currentTimestamp}] {$cleanLine}";
                $lastExtracted = end($extractedText);
                // Basic deduplication for overlapping auto-subs
                if (!$lastExtracted || strpos($lastExtracted, $cleanLine) === false) {
                    $extractedText[] = $formattedLine;
                }
            }
        }

        return implode("\n", $extractedText);
    }

    /**
     * Download audio only from YouTube using yt-dlp.
     * Returns absolute path to the downloaded MP3 file.
     */
    public function downloadAudioOnly(string $videoUrl, string $videoId): string
    {
        $cacheDir = storage_path('app/youtube_context');
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $audioPath = $cacheDir . '/' . $videoId . '.mp3';

        if (file_exists($audioPath)) {
            return $audioPath;
        }

        $venvPath = base_path('python_venv/bin/yt-dlp');
        $ytdlpBin = file_exists($venvPath) ? $venvPath : 'yt-dlp';
        
        $cookiesPath = base_path('cookies.txt');
        $cookiesArgs = file_exists($cookiesPath) ? ['--cookies', $cookiesPath] : [];

        // Command: yt-dlp -f bestaudio -x --audio-format mp3 -o cacheDir/videoId.%(ext)s url
        $processArgs = array_merge([
            $ytdlpBin,
            '-f', 'bestaudio',
            '-x',
            '--audio-format', 'mp3',
            '-o', $cacheDir . '/' . $videoId . '.%(ext)s',
            $videoUrl
        ], $cookiesArgs);

        $process = new Process($processArgs);
        $process->setTimeout(300); // 5 minutes

        Log::info("YouTubeContextService: Downloading audio only for video {$videoId}...");
        $process->mustRun();
        
        if (!file_exists($audioPath)) {
            throw new \Exception("Gagal mengunduh atau mengonversi audio ke MP3.");
        }

        Log::info("YouTubeContextService: Audio downloaded successfully at {$audioPath}");
        return $audioPath;
    }

    private function timeToSeconds(string $timeStr): float
    {
        $parts = explode(':', $timeStr);
        $seconds = 0;
        if (count($parts) == 3) {
            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + (float)$parts[2];
        } elseif (count($parts) == 2) {
            $seconds = ($parts[0] * 60) + (float)$parts[1];
        } else {
            $seconds = (float)$parts[0];
        }
        return $seconds;
    }

    private function extractVideoId(string $url): ?string
    {
        preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([\w-]{11})/', $url, $matches);
        return $matches[1] ?? null;
    }
}
