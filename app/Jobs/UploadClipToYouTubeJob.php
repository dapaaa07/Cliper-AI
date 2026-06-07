<?php

namespace App\Jobs;

use App\Models\Clip;
use App\Services\YouTubeMetadataService;
use App\Services\YouTubePublishScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class UploadClipToYouTubeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max for upload
    public $clip;

    public function __construct(Clip $clip)
    {
        $this->clip = $clip;
    }

    public function handle(YouTubeMetadataService $metadataService, YouTubePublishScheduler $publishScheduler): void
    {
        $clip = Clip::query()->with('video')->findOrFail($this->clip->id);

        try {
            // Read OAuth token
            if (!Storage::disk('local')->exists('youtube_token.json')) {
                throw new \Exception('YouTube OAuth token not found.');
            }
            $token = json_decode(Storage::disk('local')->get('youtube_token.json'), true);

            // Initialize Client
            $client = new \Google_Client();
            $client->setClientId(config('services.youtube.client_id'));
            $client->setClientSecret(config('services.youtube.client_secret'));
            $client->setAccessToken($token);

            // Refresh token if expired
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $token = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    Storage::disk('local')->put('youtube_token.json', json_encode($token));
                } else {
                    throw new \Exception('YouTube OAuth token expired and no refresh token available.');
                }
            }

            // Status: Generating Metadata
            $clip->update([
                'youtube_upload_status' => 'generating_metadata',
            ]);

            $metadata = $metadataService->generateMetadata($clip);
            $publishPlan = $publishScheduler->resolvePublishPlan();
            $privacyStatus = $publishPlan['privacy_status']; // override metadata privacy
            
            // Status: Uploading
            $clip->update([
                'youtube_upload_status' => 'uploading',
                'youtube_title' => $metadata['title'] ?? 'Clip Video',
                'youtube_description' => $metadata['description'] ?? '',
                'youtube_tags' => $metadata['tags'] ?? [],
                'youtube_privacy_status' => $privacyStatus,
                'youtube_publish_status' => $publishPlan['mode'],
                'youtube_publish_at' => $publishPlan['publish_at'],
                'youtube_publish_timezone' => $publishPlan['timezone'],
                'youtube_scheduled_for_local' => $publishPlan['publish_at_local'],
            ]);
            $clip->refresh();

            // Prepare Google API Service
            $service = new \Google_Service_YouTube($client);
            
            $finalTitle = $clip->youtube_title;
            $finalDescription = $clip->youtube_description;

            // Force #Shorts tag if vertical, to help YouTube algorithm classify it properly even if duration is up to 3 mins
            if ($clip->aspect_ratio === 'vertical') {
                if (stripos($finalTitle, '#shorts') === false) {
                    $finalTitle .= ' #Shorts';
                }
                if (stripos($finalDescription, '#shorts') === false) {
                    $finalDescription .= "\n\n#Shorts";
                }
            }

            // Prepare Video Snippet
            $snippet = new \Google_Service_YouTube_VideoSnippet();
            $snippet->setTitle($finalTitle);
            $snippet->setDescription($finalDescription);
            $snippet->setTags($clip->youtube_tags);
            $snippet->setCategoryId('22'); // People & Blogs

            // Prepare Video Status
            $status = new \Google_Service_YouTube_VideoStatus();
            $status->setPrivacyStatus($clip->youtube_privacy_status);

            if ($publishPlan['mode'] === 'scheduled_public' && $publishPlan['publish_at']) {
                $status->setPublishAt($publishPlan['publish_at']->toRfc3339String());
            }

            // Prepare Video
            $video = new \Google_Service_YouTube_Video();
            $video->setSnippet($snippet);
            $video->setStatus($status);

            // Fetch video file path
            $videoPath = storage_path('app/public/' . $clip->video_path);
            if (!file_exists($videoPath)) {
                throw new \Exception('Video file not found at: ' . $videoPath);
            }

            // Specify chunk size (e.g. 1MB) to help memory
            $chunkSizeBytes = 1 * 1024 * 1024;
            $client->setDefer(true);
            $insertRequest = $service->videos->insert('status,snippet', $video);
            
            $media = new \Google_Http_MediaFileUpload(
                $client,
                $insertRequest,
                'video/*',
                null,
                true,
                $chunkSizeBytes
            );
            $media->setFileSize(filesize($videoPath));
            
            // Read the media file and upload it in chunks
            $statusUpload = false;
            $handle = fopen($videoPath, "rb");
            while (!$statusUpload && !feof($handle)) {
                $chunk = fread($handle, $chunkSizeBytes);
                $statusUpload = $media->nextChunk($chunk);
            }
            fclose($handle);
            $client->setDefer(false);

            if ($statusUpload && isset($statusUpload->id)) {
                $finalStatus = $publishPlan['mode'] === 'scheduled_public' ? 'scheduled' : 'uploaded';
                
                $clip->update([
                    'youtube_upload_status' => $finalStatus,
                    'youtube_video_id' => $statusUpload->id,
                    'youtube_url' => 'https://www.youtube.com/watch?v=' . $statusUpload->id,
                    'youtube_uploaded_at' => now(),
                ]);
            } else {
                throw new \Exception('Upload failed. API returned no ID.');
            }
        } catch (\Exception $e) {
            Log::error('YouTube Upload Job failed', ['clip_id' => $clip->id, 'error' => $e->getMessage()]);
            $clip->update([
                'youtube_upload_status' => 'failed',
                'youtube_error_message' => $e->getMessage(),
            ]);
        }
    }
}
