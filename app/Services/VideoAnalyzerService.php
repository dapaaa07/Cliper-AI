<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoAnalyzerService
{
    private YouTubeContextService $contextService;

    public function __construct(YouTubeContextService $contextService)
    {
        $this->contextService = $contextService;
    }

    /**
     * Analyzes the video transcript and returns top 3 viral moments.
     */
    public function analyzeViralMoments(Video $video): array
    {
        if (!$video->youtube_url) {
            throw new \Exception("Video URL is missing.");
        }

        preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([\w-]{11})/', $video->youtube_url, $matches);
        $videoId = $matches[1] ?? null;

        if (!$videoId) {
            throw new \Exception("Invalid YouTube URL.");
        }

        try {
            // Fetch full transcript with timestamps
            $transcript = $this->contextService->getFullTranscriptWithTimestamps($video->youtube_url, $videoId);

            if (empty(trim($transcript))) {
                throw new \Exception("Transcript kosong.");
            }

            return $this->callGeminiForTimestamps($video, $transcript);
        } catch (\Exception $e) {
            Log::info("VideoAnalyzerService: Gagal mendapatkan transcript, fallback ke metode analisa audio. Error: " . $e->getMessage());
            
            // Fallback: Download audio and upload to Gemini File API
            return $this->analyzeViralMomentsViaAudio($video, $videoId);
        }
    }

    private function callGeminiForTimestamps(Video $video, string $transcript): array
    {
        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("GEMINI_API_KEY tidak dikonfigurasi.");
        }
        
        $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

        $systemPrompt = <<<PROMPT
Anda adalah ahli pembuat konten viral TikTok, Reels, dan YouTube Shorts.
Tugas Anda adalah membaca transkrip video berikut beserta timestamp-nya, lalu memilih 3 bagian momen (clip) paling berpotensi VIRAL.

Syarat mutlak:
1. Setiap clip HARUS memiliki durasi MAKSIMAL 2 menit (120 detik). Jika bisa 30-60 detik lebih baik.
2. Pilih bagian yang paling memancing rasa penasaran, lucu, informatif, atau kontroversial.
3. Berikan nilai/skor (0-10) untuk seberapa tinggi potensi viral momen tersebut.
4. Format output HARUS MURNI ARRAY JSON seperti struktur di bawah ini tanpa markdown tambahan (tanpa ```json):
[
  {
    "start_time": "00:01:20",
    "end_time": "00:02:15",
    "score": 9.5,
    "reasoning": "Penjelasan singkat mengapa momen ini viral"
  }
]
PROMPT;

        $userPrompt = "Title: {$video->title}\n\nTranscript:\n" . $transcript;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt . "\n\n" . $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 4000,
                'responseMimeType' => 'application/json',
            ],
        ];

        try {
            $response = Http::timeout(180)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $payload);

            if ($response->successful()) {
                $content = $response->json('candidates.0.content.parts.0.text');
                $json = json_decode(trim($content), true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    return $json;
                } else {
                    Log::error('VideoAnalyzerService: Invalid JSON from Gemini.', ['output' => $content]);
                    throw new \Exception("AI gagal mengembalikan format JSON yang valid.");
                }
            } else {
                Log::error('VideoAnalyzerService: Gemini API Error.', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \Exception("Gagal menghubungi AI (Status " . $response->status() . ").");
            }
        } catch (\Exception $e) {
            Log::error('VideoAnalyzerService: Exception.', ['error' => $e->getMessage()]);
            throw new \Exception("Kesalahan saat analisa AI: " . $e->getMessage());
        }
    }

    /**
     * Fallback method to download video audio and send it directly to Gemini File API.
     */
    private function analyzeViralMomentsViaAudio(Video $video, string $videoId): array
    {
        // 1. Download audio file locally
        $audioPath = $this->contextService->downloadAudioOnly($video->youtube_url, $videoId);
        
        $apiKey = env('GEMINI_API_KEY');
        if (empty($apiKey)) {
            throw new \Exception("GEMINI_API_KEY tidak dikonfigurasi.");
        }
        
        $model = env('GEMINI_MODEL', 'gemini-3.5-flash');

        Log::info("VideoAnalyzerService: Mengunggah audio ke Gemini File API...");
        
        $fileUri = null;
        $fileName = null;
        try {
            // Step 1: Initiate Resumable Upload
            $response = Http::withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => filesize($audioPath),
                'X-Goog-Upload-Header-Content-Type' => 'audio/mp3',
            ])->post("https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}", [
                'file' => [
                    'display_name' => basename($audioPath)
                ]
            ]);

            if (!$response->successful()) {
                throw new \Exception("Inisiasi upload gagal: " . $response->body());
            }

            $uploadUrl = $response->header('x-goog-upload-url');
            if (!$uploadUrl) {
                throw new \Exception("x-goog-upload-url header tidak ditemukan.");
            }

            // Step 2: Upload actual binary bytes
            $uploadResponse = Http::withHeaders([
                'X-Goog-Upload-Offset' => 0,
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])->withBody(file_get_contents($audioPath), 'audio/mp3')
              ->put($uploadUrl);

            if (!$uploadResponse->successful()) {
                throw new \Exception("Upload binary gagal: " . $uploadResponse->body());
            }

            $fileData = $uploadResponse->json('file');
            $fileUri = $fileData['uri'] ?? null;
            $fileName = $fileData['name'] ?? null;

            if (!$fileUri || !$fileName) {
                throw new \Exception("Gagal mengambil file URI/Name dari respons.");
            }

            Log::info("VideoAnalyzerService: File berhasil diunggah. URI: {$fileUri}, Name: {$fileName}");

            // 3. Wait for file processing to complete (ACTIVE state)
            $this->waitForFileActive($fileName, $apiKey);

            // 4. Prompt Gemini with the file
            $systemPrompt = <<<PROMPT
Anda adalah ahli pembuat konten viral TikTok, Reels, dan YouTube Shorts.
Tugas Anda adalah menganalisa file audio video yang diunggah berikut, lalu memilih 3 bagian momen (clip) paling berpotensi VIRAL.

Syarat mutlak:
1. Analisa seluruh isi audio (percakapan, intonasi, tawa, teriakan, atau perubahan nada bicara yang menarik).
2. Setiap clip HARUS memiliki durasi MAKSIMAL 2 menit (120 detik). Jika bisa 30-60 detik lebih baik.
3. Tentukan waktu mulai (start_time) dan waktu selesai (end_time) dalam format HH:MM:SS sesuai dengan timeline audio tersebut.
4. Berikan nilai/skor (0-10) untuk seberapa tinggi potensi viral momen tersebut.
5. Format output HARUS MURNI ARRAY JSON seperti struktur di bawah ini tanpa markdown tambahan (tanpa ```json):
[
  {
    "start_time": "00:01:20",
    "end_time": "00:02:15",
    "score": 9.5,
    "reasoning": "Penjelasan singkat mengapa momen ini viral berdasarkan isi pembicaraan/suara di timeline tersebut"
  }
]
PROMPT;

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'fileData' => [
                                    'fileUri' => $fileUri,
                                    'mimeType' => 'audio/mp3',
                                ],
                            ],
                            [
                                'text' => $systemPrompt . "\n\nJudul Video: {$video->title}\nSilakan analisa audio di atas dan berikan 3 timestamp klip terbaik dengan skor potensi viralnya.",
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 4000,
                    'responseMimeType' => 'application/json',
                ],
            ];

            Log::info("VideoAnalyzerService: Mengirim permintaan analisa audio ke Gemini...");
            
            $geminiResponse = Http::timeout(180)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", $payload);

            if ($geminiResponse->successful()) {
                $content = $geminiResponse->json('candidates.0.content.parts.0.text');
                $json = json_decode(trim($content), true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    return $json;
                } else {
                    Log::error('VideoAnalyzerService: Invalid JSON from Gemini for audio analysis.', ['output' => $content]);
                    throw new \Exception("AI gagal mengembalikan format JSON yang valid untuk analisa audio.");
                }
            } else {
                Log::error('VideoAnalyzerService: Gemini API Error on audio analysis.', ['status' => $geminiResponse->status(), 'body' => $geminiResponse->body()]);
                throw new \Exception("Gagal menghubungi AI untuk analisa audio (Status " . $geminiResponse->status() . ").");
            }

        } finally {
            // Always clean up: delete file from Gemini File API and delete local file to save storage
            if ($fileName) {
                try {
                    Log::info("VideoAnalyzerService: Menghapus file {$fileName} dari Gemini...");
                    Http::delete("https://generativelanguage.googleapis.com/v1beta/{$fileName}?key={$apiKey}");
                } catch (\Exception $ex) {
                    Log::warning("VideoAnalyzerService: Gagal menghapus file dari Gemini: " . $ex->getMessage());
                }
            }
            if (file_exists($audioPath)) {
                Log::info("VideoAnalyzerService: Menghapus file audio lokal {$audioPath}...");
                unlink($audioPath);
            }
        }
    }

    /**
     * Poll status of uploaded file on Gemini File API until it is ACTIVE.
     */
    private function waitForFileActive(string $name, string $apiKey): void
    {
        $maxAttempts = 30; // 1 minute max (30 * 2 seconds)
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $response = Http::get("https://generativelanguage.googleapis.com/v1beta/{$name}?key={$apiKey}");
            if ($response->successful()) {
                $state = $response->json('state') ?? 'ACTIVE';
                if ($state === 'ACTIVE') {
                    Log::info("VideoAnalyzerService: File state is ACTIVE.");
                    return;
                }
                
                if ($state === 'FAILED') {
                    throw new \Exception("Pemrosesan file audio gagal di server Gemini.");
                }
                Log::info("VideoAnalyzerService: File state is {$state}, waiting...");
            } else {
                Log::warning("VideoAnalyzerService: Gagal mengambil status file dari Gemini (Status: " . $response->status() . ")");
            }

            $attempt++;
            sleep(2);
        }

        throw new \Exception("Timeout menunggu file menjadi aktif di server Gemini.");
    }
}
