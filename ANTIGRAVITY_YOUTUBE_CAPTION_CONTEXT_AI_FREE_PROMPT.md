# Prompt Antigravity: Perbaiki Generator Caption YouTube Dengan Konteks Link + Timestamp dan AI Gratis

Analisis dan update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper`.

Fitur upload YouTube sudah berhasil, tetapi caption/title yang dihasilkan masih terasa aneh karena generator saat ini hanya memakai transkrip clip pendek. Saya ingin generator caption lebih pintar: AI harus meneliti konteks dari **link YouTube original** dan **timestamp clip**, lalu membuat title + description/caption yang catchy ala TikTok/Shorts, natural, dan tetap sesuai isi video.

Gunakan AI yang gratis atau punya free tier. Jangan wajibkan API berbayar.

## Konteks Implementasi Saat Ini

File yang sudah ada:

- `app/Services/YouTubeMetadataService.php`
- `app/Jobs/UploadClipToYouTubeJob.php`
- `app/Http/Controllers/YouTubeController.php`
- `resources/views/results.blade.php`
- `app/Models/Clip.php`
- `app/Models/Video.php`

Saat ini `YouTubeMetadataService.php`:

- membaca transkrip dari `storage/app/public/clips/{clip_id}.txt`
- memakai `GROQ_API_KEY` jika tersedia
- fallback rule-based jika LLM gagal
- prompt hanya diberi:
  - title original
  - clip time
  - transcript

Masalah:

1. AI tidak diberi konteks video original secara cukup.
2. AI tidak benar-benar meneliti link YouTube dan timestamp.
3. Caption bisa terasa generik/aneh karena hanya merangkai transkrip.
4. Belum ada provider AI gratis yang fleksibel selain Groq.

## Target Perubahan

Perbaiki `YouTubeMetadataService` agar metadata upload dibuat berdasarkan:

- URL YouTube original: `$clip->video->youtube_url`
- Judul video original: `$clip->video->title`
- Timestamp clip: `$clip->start_time` sampai `$clip->end_time`
- Transkrip hasil clip: `clips/{clip_id}.txt`
- Metadata YouTube original dari `yt-dlp --dump-json --skip-download`
- Subtitle/caption original YouTube jika tersedia, terutama bagian yang dekat dengan timestamp clip
- Description/channel/tags/chapter original jika tersedia

Tujuan akhir:

- Title pendek dan natural untuk YouTube Shorts.
- Description/caption catchy ala TikTok/Shorts: punya hook kuat di awal, terasa seperti caption kreator, bukan ringkasan formal.
- Hashtag relevan, sedikit saja.
- Tidak menampilkan format aneh seperti JSON mentah, label `Caption:`, quote berantakan, atau template prompt.
- Default privacy tetap mengikuti config, jangan paksa public.

## Definisi Style Caption Yang Diinginkan

Caption harus terasa seperti caption TikTok/YouTube Shorts yang dibuat kreator manusia, bukan deskripsi arsip video.

Ciri style yang diinginkan:

- Baris pertama wajib berupa hook pendek yang bikin penasaran.
- Hook harus spesifik pada isi clip, bukan generik.
- Gunakan bahasa santai Indonesia jika transcript Indonesia.
- Boleh memakai gaya percakapan seperti `Ternyata...`, `Gue baru ngeh...`, `Ini yang sering orang skip...`, `Plot twist-nya di sini...`, `Jangan salah paham dulu...`, atau `Bagian ini penting banget...`.
- Jangan terlalu formal seperti `Cuplikan singkat dari video ini`, `Dalam video ini dibahas`, `Video ini menjelaskan`, atau `Berikut adalah`.
- Jangan clickbait kosong seperti `Viral banget!` kalau tidak ada konteks viral, `Wajib nonton sampai habis!` kalau tidak ada alasan kuat, atau `Nomor 3 bikin kaget` kalau tidak ada list.
- Jangan pakai terlalu banyak emoji. Maksimal 0-1 emoji jika memang cocok.
- Caption ringkas: 2-4 baris sebelum hashtag.
- Hashtag 3-6 saja, relevan, dan tidak spam.

Struktur description yang diinginkan:

```text
{hook spesifik 1 baris}

{1-2 baris konteks kenapa clip ini menarik}

#shorts #fyp #podcast
```

Contoh style bagus:

```text
Ternyata bagian kecil ini yang sering bikin orang salah paham.

Pas dengar konteks lengkapnya, maksudnya jadi beda banget.

#shorts #podcast #mindset
```

```text
Jangan buru-buru setuju sebelum dengar bagian ini.

Argumennya simpel, tapi lumayan nusuk kalau dipikir lagi.

#shorts #diskusi #fyp
```

Contoh style yang harus dihindari:

```text
Cuplikan singkat dari video original.

Dalam video ini, pembicara menjelaskan tentang topik yang menarik.

#shorts #clip #podcast
```

## Provider AI Gratis Yang Diinginkan

Buat sistem provider berurutan. Gunakan provider pertama yang tersedia:

1. **Gemini API free tier** jika `GEMINI_API_KEY` ada.
2. **Groq API free tier** jika `GROQ_API_KEY` ada.
3. **Ollama local** jika `OLLAMA_BASE_URL` aktif dan model tersedia.
4. Fallback rule-based yang lebih baik.

Catatan:

- Jangan butuh OpenAI paid API.
- Jangan hardcode API key.
- Semua provider harus optional.
- Kalau provider gagal, lanjut ke provider berikutnya.
- Log error provider secukupnya, jangan log API key.

Tambahkan env di `.env.example`:

```env
# Free AI metadata providers, optional. Priority: Gemini -> Groq -> Ollama -> fallback.
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash
GROQ_CHAT_MODEL=llama-3.1-8b-instant
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1:8b
```

User ingin memakai **Gemini 3.5 Flash** untuk generator caption. Gunakan `GEMINI_MODEL=gemini-3.5-flash` sebagai default. Jika Google AI Studio/API memakai model id yang sedikit berbeda untuk Gemini 3.5 Flash, jangan hardcode sampai app gagal; tetap baca model dari env `GEMINI_MODEL` dan dokumentasikan agar user bisa mengganti model id sesuai daftar model yang tersedia di AI Studio.

## Tambahkan Service Konteks YouTube

Buat service baru:

`app/Services/YouTubeContextService.php`

Tanggung jawab:

1. Ambil metadata original video via `yt-dlp`:

```bash
yt-dlp --dump-json --skip-download <youtube_url>
```

Ambil field jika tersedia:

- `title`
- `channel` / `uploader`
- `description`
- `tags`
- `categories`
- `chapters`
- `duration`
- `webpage_url`

2. Ambil subtitle original YouTube jika tersedia.

Gunakan `yt-dlp` untuk download subtitle otomatis/manual ke temporary folder non-public:

```bash
yt-dlp --skip-download --write-subs --write-auto-subs --sub-langs "id,en" --sub-format "vtt" -o "<tmp_dir>/%(id)s" <youtube_url>
```

Jika subtitle tersedia, parse VTT dan ambil hanya potongan yang berada dekat timestamp clip:

- mulai: `clip_start - 15 detik`
- akhir: `clip_end + 15 detik`

Jangan masukkan subtitle full video ke prompt karena terlalu panjang.

3. Jika subtitle original tidak tersedia, tetap gunakan:

- transkrip clip hasil project
- metadata video original
- description original yang dipotong secukupnya

4. Cache konteks agar tidak menjalankan `yt-dlp` berkali-kali untuk video yang sama.

Untuk MVP, boleh cache file JSON di:

`storage/app/youtube_context/{video_id}.json`

Jangan simpan di public path.

5. Timeout proses `yt-dlp` supaya upload tidak menggantung.

Gunakan Symfony Process, timeout 60-120 detik.

## Update `YouTubeMetadataService.php`

Ubah constructor agar menerima `YouTubeContextService`:

```php
public function __construct(
    private YouTubeContextService $contextService
) {}
```

Flow `generateMetadata(Clip $clip)`:

1. Ambil transcript clip dari `.txt`.
2. Ambil konteks video:

```php
$context = $this->contextService->getContextForClip($clip);
```

3. Coba generate dengan Gemini.
4. Jika gagal, coba Groq.
5. Jika gagal, coba Ollama.
6. Jika gagal, fallback rule-based improved.
7. Selalu sanitize output sebelum dipakai upload YouTube.

## Prompt AI Yang Lebih Bagus

Gunakan prompt yang eksplisit dan tidak membuat output aneh.

System prompt:

```text
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
```

User prompt harus berisi:

```text
Original YouTube URL: ...
Original video title: ...
Channel/uploader: ...
Original video description summary: ...
Original tags/categories: ...
Clip timestamp: 00:01:20 - 00:01:48
Nearby original subtitles around timestamp: ...
Generated clip transcript: ...

Tugas:
- Pahami konteks video original dan bagian clip berdasarkan timestamp.
- Buat title yang pendek, tajam, dan cocok untuk Shorts.
- Buat description ala TikTok/Shorts dengan hook kuat di baris pertama.
- Jangan membuat description seperti ringkasan formal.
- Jangan mulai dengan "Cuplikan singkat", "Dalam video ini", atau "Video ini menjelaskan".
- Hashtag 3-6 saja, relevan.
- Jangan pakai hashtag random.
- Jangan pakai kalimat berlebihan seperti "wajib nonton sampai habis" kecuali memang cocok.
```

Batasi panjang konteks:

- description original maksimal 1200 karakter
- nearby subtitles maksimal 2500 karakter
- clip transcript maksimal 2500 karakter
- tags original maksimal 15 tag

## Implement Provider Gemini

Tambahkan method:

`generateWithGemini(Clip $clip, string $transcript, array $context): ?array`

Endpoint:

```text
https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={GEMINI_API_KEY}
```

Payload minimal:

```php
[
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
]
```

Parse:

```php
$content = $response->json('candidates.0.content.parts.0.text');
```

Jika JSON valid dan punya title/description, return hasil yang sudah disanitize.

## Update Provider Groq

Saat ini Groq hanya menerima transcript. Ubah agar menerima konteks lengkap juga.

Gunakan model dari env:

```php
$model = env('GROQ_CHAT_MODEL', 'llama-3.1-8b-instant');
```

Pastikan tetap:

- `response_format: json_object`
- temperature sekitar 0.5-0.7
- max tokens 700

## Implement Provider Ollama Local

Tambahkan method:

`generateWithOllama(Clip $clip, string $transcript, array $context): ?array`

Endpoint:

```text
POST {OLLAMA_BASE_URL}/api/generate
```

Payload:

```json
{
  "model": "llama3.1:8b",
  "prompt": "...",
  "stream": false,
  "format": "json",
  "options": {
    "temperature": 0.55
  }
}
```

Parse field `response` sebagai JSON.

Jika Ollama tidak aktif, timeout cepat 5-10 detik lalu fallback.

## Sanitasi Output Wajib

Buat method:

`sanitizeMetadata(array $metadata): array`

Aturan:

- `title`:
  - strip tag HTML
  - hapus prefix seperti `Title:`, `Judul:`
  - max 90 karakter
  - jangan kosong
- `description`:
  - strip tag HTML
  - hapus blok markdown/code fence
  - hapus label awal `Caption:`, `Description:`, `Deskripsi:`
  - jika diawali kalimat generik seperti `Cuplikan singkat`, `Dalam video ini`, `Video ini menjelaskan`, atau `Berikut adalah`, regenerate sekali jika provider AI tersedia; jika tidak, fallback improved
  - max 4500 karakter
  - pastikan bukan JSON string mentah
  - jika terlalu aneh/kosong, fallback description
- `tags`:
  - array string
  - lowercase
  - hilangkan `#`
  - max 10 tags
  - max 30 karakter per tag
  - buang tag kosong/duplikat
- `privacy_status`:
  - selalu ambil dari `config('services.youtube.default_privacy', 'private')`
  - jangan percaya LLM untuk publish public

Tambahkan detector output aneh:

- description mengandung `{` dan `"title"`
- description diawali ```json`
- title diawali `Here is`, `Berikut`, `Caption`
- description terlalu mirip prompt/template

Jika terdeteksi, fallback ke rule-based improved.

## Fallback Rule-Based Improved

Jika semua AI gagal, buat fallback yang lebih natural dibanding sekarang.

Gunakan:

- judul original
- transkrip clip
- subtitle sekitar timestamp jika ada

Title fallback:

- Ambil kalimat paling kuat dari transkrip, bukan selalu 8 kata pertama.
- Prioritaskan kalimat dengan tanda tanya, seruan, atau kata seperti:
  - `ternyata`
  - `kenapa`
  - `gimana`
  - `jangan`
  - `penting`
  - `bahaya`
  - `viral` hanya jika ada di transcript
- Jika tidak ada, gunakan:

`Momen Menarik dari {originalTitle}`

Description fallback:

```text
{hook singkat dari kalimat terbaik transcript}

{alasan singkat kenapa bagian ini menarik, berdasarkan transcript/context}

#shorts #clip #podcast
```

Jika konten bukan podcast, tag `podcast` jangan wajib. Gunakan tags original/category kalau tersedia.

Fallback jangan lagi memakai kalimat `Cuplikan singkat dari...` atau `Momen menarik dari...` sebagai pembuka description. Pembuka harus berbentuk hook.

## UI Tambahan Opsional Tapi Disarankan

Di `resources/views/results.blade.php`, kalau `youtube_title` dan `youtube_description` sudah tersimpan, tampilkan preview kecil supaya user bisa melihat hasil caption.

Tambahkan juga tombol `Regenerate Caption` sebelum upload jika mudah:

```php
Route::post('/clips/{clip}/youtube/regenerate-metadata', [YouTubeController::class, 'regenerateMetadata'])->name('clips.youtube.regenerateMetadata');
```

Namun kalau terlalu besar, cukup perbaiki generator dulu.

## Acceptance Criteria

Fitur dianggap berhasil jika:

1. Caption/title dibuat dari kombinasi:
   - URL YouTube original
   - metadata original YouTube
   - timestamp clip
   - transcript clip
   - subtitle original sekitar timestamp jika tersedia
2. AI provider gratis bisa dipakai:
   - Gemini jika `GEMINI_API_KEY`
   - Groq jika `GROQ_API_KEY`
   - Ollama lokal jika tersedia
   - fallback rule-based jika semua gagal
3. Output tidak lagi berupa caption aneh seperti template, JSON mentah, atau label field.
4. Title maksimal 90 karakter.
5. Description punya hook TikTok/Shorts yang kuat di baris pertama, natural, catchy, dan sesuai isi.
6. Hashtag 3-6 relevan, tidak spam.
7. Privacy tetap tidak public secara default.
8. Upload YouTube flow yang sudah berhasil jangan dirusak.

## Testing Manual

Jalankan:

```bash
cd /opt/lampp/htdocs/ai-clipper
php artisan test
```

Test manual:

1. Isi salah satu provider gratis:

```env
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-3.5-flash
```

atau:

```env
GROQ_API_KEY=...
GROQ_CHAT_MODEL=llama-3.1-8b-instant
```

atau lokal:

```bash
ollama serve
ollama pull llama3.1:8b
```

2. Proses clip dari link YouTube.
3. Upload ke YouTube.
4. Cek `youtube_title`, `youtube_description`, dan `youtube_tags` di database atau UI.
5. Pastikan caption lebih nyambung dengan konteks video dan timestamp, bukan hanya potongan kata awal transkrip.
6. Pastikan jika provider AI dimatikan, fallback tetap menghasilkan caption yang layak.

## Catatan Penting

- Jangan menggunakan API berbayar wajib.
- Jangan browsing web umum. Cukup gunakan data dari YouTube link original via `yt-dlp` dan subtitle/metadata yang tersedia.
- Jangan mengubah upload YouTube yang sudah berhasil kecuali bagian metadata generation.
- Jangan upload ulang video hanya untuk test metadata.
- Jangan log API key atau OAuth token.
