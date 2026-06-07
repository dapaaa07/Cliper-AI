<?php

namespace App\Services;

use App\Models\Clip;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeMetadataService
{
    private YouTubeContextService $contextService;
    private CaptionReferenceService $referenceService;

    public function __construct(YouTubeContextService $contextService, CaptionReferenceService $referenceService)
    {
        $this->contextService = $contextService;
        $this->referenceService = $referenceService;
    }

    public function generateMetadata(Clip $clip): array
    {
        $transcript = $this->getTranscript($clip);
        $context = $this->contextService->getContextForClip($clip);
        
        $metadata = null;

        // Priority 1: Gemini
        if (env('GEMINI_API_KEY')) {
            $metadata = $this->generateWithGemini($clip, $transcript, $context);
        }

        // Priority 2: Groq
        if (!$metadata && env('GROQ_API_KEY')) {
            $metadata = $this->generateWithGroq($clip, $transcript, $context);
        }

        // Priority 3: Ollama
        if (!$metadata && env('OLLAMA_BASE_URL')) {
            $metadata = $this->generateWithOllama($clip, $transcript, $context);
        }

        // Fallback: Rule-based
        if (!$metadata) {
            $metadata = $this->generateRuleBased($clip, $transcript, $context);
        }

        return $this->sanitizeMetadata($metadata);
    }

    private function getTranscript(Clip $clip): string
    {
        if (!$clip->subtitle_path) {
            return '';
        }

        $txtPath = storage_path('app/public/' . str_replace('.srt', '.txt', $clip->subtitle_path));
        
        if (file_exists($txtPath)) {
            return substr(file_get_contents($txtPath), 0, 2500); // Limit to 2500 chars
        }

        return '';
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Kamu adalah penulis caption YouTube Shorts/TikTok.
Buat metadata upload yang catchy ala TikTok/Shorts, singkat, spesifik, dan sesuai isi video.
Jangan mengarang fakta yang tidak ada di konteks.
Jangan menyebut bahwa kamu AI.
Jangan menulis label seperti "Caption:" atau "Title:" di dalam field.
Jangan menulis JSON di dalam description.
Bahasa output harus mengikuti bahasa utama transcript/context.
Prioritaskan hook yang bikin penasaran pada baris pertama description.
Tulis seperti kreator manusia, bukan seperti ringkasan formal atau deskripsi arsip.
Hindari pembuka generik seperti "Cuplikan singkat", "Dalam video ini", "Video ini menjelaskan", dan "Berikut adalah".
Jangan clickbait kosong. Hook harus nyambung dengan isi clip.

Return valid JSON only:
{
  "title": "judul pendek max 90 karakter",
  "description": "caption 2-5 baris, hook di awal, hashtags di baris akhir",
  "tags": ["tag1", "tag2", "tag3"],
  "privacy_status": "private"
}
PROMPT;
    }

    private function getUserPrompt(Clip $clip, string $transcript, array $context): string
    {
        $originalTitle = $context['title'] ?? ($clip->video->title ?? '');
        $channel = $context['channel'] ?? '';
        $description = $context['description'] ?? '';
        $tags = implode(', ', $context['tags'] ?? []);
        $nearbySubs = $context['nearby_subtitles'] ?? '';
        $videoUrl = $clip->video->youtube_url ?? '';
        
        $references = $this->referenceService->pickReferencesForClip($clip, 3);
        $referenceText = '';
        if (!empty($references)) {
            $referenceText = "PENTING: TIRU SECARA PERSIS KERANGKA, TATA LETAK BARIS, DAN PENGGUNAAN EMOJI DARI CONTOH DI BAWAH INI!\n";
            $referenceText .= "Ganti isi teksnya dengan konteks clip, tapi pertahankan format visualnya (contoh: jika ada panah ke bawah, CTA subscribe, atau format spasi, tiru persis).\n\n";
            $referenceText .= "CONTOH REFERENSI GAYA:\n";
            foreach ($references as $i => $ref) {
                $referenceText .= "- Contoh " . ($i+1) . " (Platform: {$ref['platform']}):\n";
                $referenceText .= "  Description: " . str_replace("\n", "\n    ", $ref['description']) . "\n";
                $referenceText .= "  Hashtags: {$ref['hashtags']}\n";
            }
        }

        return <<<PROMPT
Original YouTube URL: {$videoUrl}
Original video title: {$originalTitle}
Channel/uploader: {$channel}
Original video description summary: {$description}
Original tags/categories: {$tags}
Clip timestamp: {$clip->start_time} - {$clip->end_time}
Nearby original subtitles around timestamp: {$nearbySubs}
Generated clip transcript: {$transcript}

{$referenceText}

Tugas:
- Pahami konteks video original dan bagian clip berdasarkan timestamp.
- Buat title yang pendek, tajam, dan cocok untuk Shorts.
- Tiru 100% gaya penulisan, emoji, paragraf, dan call-to-action dari referensi di atas.
- Buat description ala TikTok/Shorts dengan hook kuat di baris pertama.
- Jangan membuat description seperti ringkasan formal.
- Jangan mulai dengan "Cuplikan singkat", "Dalam video ini", atau "Video ini menjelaskan".
- Hashtag 3-6 saja, relevan.
- Jangan pakai hashtag random.
- Jangan pakai kalimat berlebihan seperti "wajib nonton sampai habis" kecuali memang cocok.
PROMPT;
    }

    private function generateWithGemini(Clip $clip, string $transcript, array $context): ?array
    {
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-3.5-flash');
        
        $prompt = $this->getSystemPrompt() . "\n\n" . $this->getUserPrompt($clip, $transcript, $context);

        try {
            $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.55,
                    'maxOutputTokens' => 700,
                    'responseMimeType' => 'application/json',
                ],
            ]);

            if ($response->successful()) {
                $content = $response->json('candidates.0.content.parts.0.text');
                $json = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($json['title'], $json['description'])) {
                    return $json;
                }
            } else {
                Log::warning('Gemini API failed', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (\Exception $e) {
            Log::error('Gemini LLM generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function generateWithGroq(Clip $clip, string $transcript, array $context): ?array
    {
        $apiKey = env('GROQ_API_KEY');
        $model = env('GROQ_CHAT_MODEL', 'llama-3.1-8b-instant');
        
        try {
            $response = Http::withToken($apiKey)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $this->getUserPrompt($clip, $transcript, $context)]
                ],
                'temperature' => 0.6,
                'max_tokens' => 700,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $json = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($json['title'], $json['description'])) {
                    return $json;
                }
            }
        } catch (\Exception $e) {
            Log::error('Groq LLM generation failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function generateWithOllama(Clip $clip, string $transcript, array $context): ?array
    {
        $baseUrl = rtrim(env('OLLAMA_BASE_URL'), '/');
        $model = env('OLLAMA_MODEL', 'llama3.1:8b');
        
        $prompt = $this->getSystemPrompt() . "\n\n" . $this->getUserPrompt($clip, $transcript, $context);

        try {
            $response = Http::timeout(15)->post("{$baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0.55
                ]
            ]);

            if ($response->successful()) {
                $content = $response->json('response');
                $json = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($json['title'], $json['description'])) {
                    return $json;
                }
            }
        } catch (\Exception $e) {
            Log::error('Ollama LLM generation failed or timed out', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function generateRuleBased(Clip $clip, string $transcript, array $context): array
    {
        $originalTitle = $context['title'] ?? ($clip->video->title ?? 'Video');
        
        // Use strong sentence from transcript for title
        $words = array_filter(explode(' ', trim($transcript)));
        $title = 'Momen Menarik dari ' . $originalTitle;
        $hook = '';
        
        $strongKeywords = ['ternyata', 'kenapa', 'gimana', 'jangan', 'penting', 'bahaya', 'viral', '?', '!'];
        $sentences = preg_split('/(?<=[.?!])\s+(?=[a-z])/i', $transcript);
        
        foreach ($sentences as $sentence) {
            foreach ($strongKeywords as $keyword) {
                if (stripos($sentence, $keyword) !== false) {
                    $title = $sentence;
                    $hook = $sentence;
                    break 2;
                }
            }
        }

        if (strlen($title) > 80) {
             $title = implode(' ', array_slice(explode(' ', $title), 0, 10)) . '...';
        }

        if (empty($hook)) {
             $hook = implode(' ', array_slice($words, 0, 10)) . '...';
        }

        // Snippet for description
        $snippet = implode(' ', array_slice($words, 10, 25));
        if (empty($snippet)) {
            $snippet = $context['nearby_subtitles'] ?? '';
            $snippet = implode(' ', array_slice(explode(' ', $snippet), 0, 25));
        }
        
        $description = <<<DESC
{$hook}

{$snippet}...

#shorts #clip
DESC;

        $tags = array_slice($context['tags'] ?? [], 0, 5);
        if (empty($tags)) {
            $tags = ['shorts', 'clip'];
        }

        return [
            'title' => substr($title, 0, 90),
            'description' => $description,
            'tags' => $tags,
            'privacy_status' => config('services.youtube.default_privacy', 'private'),
        ];
    }

    private function sanitizeMetadata(?array $metadata): array
    {
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $title = $metadata['title'] ?? '';
        $description = $metadata['description'] ?? '';
        $tags = $metadata['tags'] ?? [];

        // Title sanitation
        $title = strip_tags($title);
        $title = preg_replace('/^(Title|Judul|Caption|Here is)[\s:]*/i', '', $title);
        $title = trim($title);
        $title = substr($title, 0, 90);
        if (empty($title)) {
            $title = 'AI Video Clip';
        }

        // Description sanitation
        $description = strip_tags($description);
        $description = preg_replace('/^(Caption|Description|Deskripsi)[\s:]*/i', '', $description);
        $description = preg_replace('/```json/i', '', $description);
        $description = preg_replace('/```/i', '', $description);
        $description = trim($description);
        
        // Detect weird JSON outputs inside description
        if (strpos($description, '{') !== false && strpos($description, '"title"') !== false) {
            $description = 'Momen video klip menarik. #shorts';
        }

        // Fix generic intro if possible
        $genericIntros = ['cuplikan singkat', 'dalam video ini', 'video ini menjelaskan', 'berikut adalah'];
        foreach ($genericIntros as $intro) {
            if (stripos($description, $intro) === 0) {
                // If the description starts with a generic intro, we try to strip the first sentence.
                $parts = preg_split('/(?<=[.?!])\s+(?=[A-Za-z])/i', $description, 2);
                if (count($parts) > 1) {
                    $description = $parts[1];
                } else {
                    $description = preg_replace('/^' . preg_quote($intro, '/') . '.*?\.\s*/i', '', $description);
                }
            }
        }

        $description = substr($description, 0, 4500);

        // Tags sanitation
        if (!is_array($tags)) {
            $tags = [];
        }
        $cleanTags = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim(str_replace('#', '', $tag)));
            if (!empty($tag) && strlen($tag) <= 30) {
                $cleanTags[] = $tag;
            }
        }
        $cleanTags = array_unique($cleanTags);
        $cleanTags = array_slice($cleanTags, 0, 10);

        return [
            'title' => $title,
            'description' => $description,
            'tags' => $cleanTags,
            'privacy_status' => config('services.youtube.default_privacy', 'private'), // Force default
        ];
    }
}
