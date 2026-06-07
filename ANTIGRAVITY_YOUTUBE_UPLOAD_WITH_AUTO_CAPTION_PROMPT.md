# Prompt Antigravity: Tombol Upload YouTube + Caption Otomatis Catchy TikTok

Analisis dan update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper`.

Target fitur: setelah video clip selesai diproses dan tampil di halaman hasil, tambahkan tombol aksi untuk **upload otomatis ke YouTube**. Upload harus memakai video final yang sudah diproses, lalu membuat title/description/caption otomatis dari transkrip dengan gaya catchy ala TikTok.

Penting: upload ke YouTube adalah aksi eksternal. Jangan upload otomatis tanpa klik user. Tombol boleh bernama `Upload ke YouTube`, tetapi proses harus terjadi setelah user menekan tombol dan aplikasi punya OAuth credential YouTube yang valid.

## Konteks Project Saat Ini

File penting:

- Halaman hasil: `resources/views/results.blade.php`
- Controller utama: `app/Http/Controllers/VideoController.php`
- Routes: `routes/web.php`
- Model clip: `app/Models/Clip.php`
- Model video: `app/Models/Video.php`
- Migration clips: `database/migrations/2026_05_29_062606_create_clips_table.php`
- Pipeline clip: `app/PythonScripts/clipper_bot.py`
- Transkrip text hasil pipeline: `storage/app/public/clips/{clip_id}.txt`
- Video final: `storage/app/public/clips/{clip_id}.mp4`

Saat ini halaman `results.blade.php` menampilkan:

- video player
- transkripsi teks dari `.txt`
- tombol download MP4
- tombol download SRT/VTT

Tambahkan tombol upload YouTube di area action buttons setiap clip.

## Target UX

Di setiap result card, tambahkan action:

- Tombol utama/secondary: `Upload ke YouTube`
- Status kecil:
  - `Belum diupload`
  - `Menyiapkan caption...`
  - `Mengupload...`
  - `Uploaded`
  - `Gagal upload`
- Jika sudah uploaded, tampilkan link YouTube hasil upload.

Flow yang diinginkan:

1. User selesai memproses clip.
2. Di halaman hasil muncul tombol `Upload ke YouTube`.
3. User klik tombol.
4. Jika belum OAuth YouTube, redirect ke Google OAuth.
5. Setelah OAuth selesai, aplikasi lanjut upload clip yang dipilih.
6. Sistem generate title + description/caption + hashtags otomatis dari transkrip.
7. Video diupload ke YouTube.
8. Status clip berubah menjadi uploaded dan link YouTube tampil di halaman hasil.

## Metadata YouTube Yang Dibuat Otomatis

Buat caption/title dari:

- Transkrip `storage/app/public/clips/{clip_id}.txt`
- Judul video original dari `videos.title`
- Start/end time clip
- Aspect ratio clip

Output metadata:

- `youtube_title`: pendek, catchy, maksimal 90 karakter.
- `youtube_description`: gaya TikTok/Shorts, menarik, ada hook, ringkas, tidak lebay berlebihan.
- `youtube_tags`: array tag relevan.
- `youtube_privacy_status`: default `private` atau `unlisted`, jangan default `public`.

Gaya caption:

- Hook di baris pertama.
- Bahasa mengikuti transkrip. Jika transkrip Indonesia, pakai Indonesia.
- Jangan mengarang fakta di luar transkrip.
- Cocok untuk YouTube Shorts/TikTok style:
  - punchy
  - curiosity hook
  - tidak terlalu panjang
  - 3-8 hashtag relevan
- Hindari spam hashtag.
- Jangan pakai klaim berlebihan seperti `paling viral` kalau tidak ada dasar.

Contoh format description:

```text
Hook singkat yang bikin orang penasaran.

Cuplikan dari: {judul video original}

#shorts #podcast #clip #fyp
```

## Implementasi Yang Diminta

### 1. Tambahkan Dependency YouTube API

Gunakan Google API Client untuk PHP:

```bash
composer require google/apiclient:^2.0
```

Jangan implement upload YouTube dengan request manual kalau library resmi tersedia.

### 2. Tambahkan Konfigurasi ENV

Tambahkan ke `.env.example` jika file ada:

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/youtube/oauth/callback
YOUTUBE_DEFAULT_PRIVACY=private
```

Gunakan config Laravel, misalnya update `config/services.php`:

```php
'youtube' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    'default_privacy' => env('YOUTUBE_DEFAULT_PRIVACY', 'private'),
],
```

### 3. Database: Simpan Status Upload

Buat migration baru, jangan edit migration lama jika database sudah pernah jalan.

Tambahkan kolom ke table `clips`:

- `youtube_upload_status` string nullable/default `not_uploaded`
- `youtube_video_id` string nullable
- `youtube_url` string nullable
- `youtube_title` string nullable
- `youtube_description` text nullable
- `youtube_tags` json nullable
- `youtube_privacy_status` string nullable
- `youtube_error_message` text nullable
- `youtube_uploaded_at` timestamp nullable

Update `app/Models/Clip.php`:

- Tambahkan field di `$fillable`
- Cast:

```php
protected $casts = [
    'youtube_tags' => 'array',
    'youtube_uploaded_at' => 'datetime',
];
```

### 4. OAuth YouTube

Buat controller baru:

`app/Http/Controllers/YouTubeController.php`

Routes yang dibutuhkan di `routes/web.php`:

```php
Route::get('/youtube/oauth/redirect', [YouTubeController::class, 'redirect'])->name('youtube.oauth.redirect');
Route::get('/youtube/oauth/callback', [YouTubeController::class, 'callback'])->name('youtube.oauth.callback');
Route::post('/clips/{clip}/youtube/upload', [YouTubeController::class, 'upload'])->name('clips.youtube.upload');
```

OAuth scope:

```text
https://www.googleapis.com/auth/youtube.upload
```

Token storage:

- Untuk versi awal, simpan OAuth token di `storage/app/youtube_token.json`.
- Pastikan file tidak disimpan di `public/`.
- Jika token expired dan ada refresh token, refresh otomatis.
- Jika token tidak ada atau invalid, redirect ke OAuth.

Catatan keamanan:

- Jangan log access token/refresh token.
- Jangan expose token di halaman.
- Jangan upload tanpa user click.

### 5. Generate Caption Otomatis

Buat service baru:

`app/Services/YouTubeMetadataService.php`

Tanggung jawab:

1. Ambil transkrip dari `.txt`:

```php
$txtPath = storage_path('app/public/' . str_replace('.srt', '.txt', $clip->subtitle_path));
```

2. Generate metadata title/description/tags.
3. Gunakan LLM bila `GROQ_API_KEY` tersedia.
4. Jika LLM gagal/tidak tersedia, fallback rule-based.

Gunakan Groq API yang sudah dipakai project saat ini jika ingin konsisten. Model yang disarankan:

- `llama-3.1-8b-instant` untuk murah/cepat, atau
- model Groq lain yang tersedia di environment.

Prompt LLM harus meminta JSON valid:

```text
Buat metadata YouTube Shorts dari transkrip berikut.
Bahasa harus mengikuti transkrip.
Jangan mengarang fakta di luar transkrip.
Gaya catchy ala TikTok, ringkas, natural.

Return JSON only:
{
  "title": "max 90 chars",
  "description": "2-5 lines, include hashtags at end",
  "tags": ["tag1", "tag2"],
  "privacy_status": "private"
}

Original video title: ...
Clip time: ...
Transcript:
...
```

Fallback rule-based:

- Title: ambil 6-12 kata paling menarik dari awal transkrip atau gunakan `Clip dari {video.title}`.
- Description:

```text
Cuplikan singkat dari {video.title}.

{potongan transkrip 1-2 kalimat}

#shorts #clip #podcast
```

- Tags default: `["shorts", "clip", "podcast"]`
- Privacy default dari `config('services.youtube.default_privacy', 'private')`

### 6. Upload Video Ke YouTube

Di `YouTubeController@upload`:

1. Validasi clip:
   - `status === completed`
   - `video_path` ada
   - file video ada di `storage/app/public/{video_path}`
2. Pastikan OAuth token valid.
3. Update clip:
   - `youtube_upload_status = generating_metadata`
4. Generate metadata.
5. Update clip:
   - `youtube_upload_status = uploading`
   - simpan title/description/tags/privacy
6. Upload video final ke YouTube Data API.
7. Set metadata upload:

```php
$snippet = new Google_Service_YouTube_VideoSnippet();
$snippet->setTitle($metadata['title']);
$snippet->setDescription($metadata['description']);
$snippet->setTags($metadata['tags']);
$snippet->setCategoryId('22'); // People & Blogs, aman untuk default

$status = new Google_Service_YouTube_VideoStatus();
$status->setPrivacyStatus($metadata['privacy_status'] ?? config('services.youtube.default_privacy', 'private'));
```

8. Setelah sukses, update clip:
   - `youtube_upload_status = uploaded`
   - `youtube_video_id`
   - `youtube_url = https://www.youtube.com/watch?v={id}`
   - `youtube_uploaded_at = now()`
9. Redirect kembali ke halaman results.

Jika gagal:

- Update `youtube_upload_status = failed`
- Simpan `youtube_error_message`
- Redirect back dengan flash error.

### 7. UI di `results.blade.php`

Tambahkan tombol di bawah tombol download:

```blade
@if($clip->youtube_upload_status === 'uploaded' && $clip->youtube_url)
    <a href="{{ $clip->youtube_url }}" target="_blank" rel="noopener" class="btn-secondary">
        Lihat di YouTube
    </a>
@else
    <form method="POST" action="{{ route('clips.youtube.upload', $clip->id) }}">
        @csrf
        <button type="submit" class="btn-secondary">
            Upload ke YouTube
        </button>
    </form>
@endif
```

Tambahkan status upload:

```blade
@if($clip->youtube_upload_status)
    <p style="color: var(--text-muted); font-size: 0.85rem;">
        YouTube: {{ $clip->youtube_upload_status }}
    </p>
@endif
```

Jika metadata sudah dibuat, tampilkan ringkas:

- title
- privacy
- link YouTube jika sudah uploaded
- error message jika failed

Jangan pakai modal kompleks dulu kecuali mudah. Untuk MVP, tombol POST + status sudah cukup.

### 8. Optional: Job Queue Untuk Upload

Jika upload terasa lama, buat job:

`app/Jobs/UploadClipToYouTubeJob.php`

Alur:

- Tombol upload dispatch job.
- Status berubah `queued`.
- Halaman results bisa refresh manual.

Namun untuk MVP awal, upload langsung di controller boleh jika ukuran file kecil dan timeout server aman. Rekomendasi lebih baik: pakai queue job agar tidak timeout.

Jika menggunakan queue job:

- Controller hanya validasi + dispatch.
- Job generate metadata + upload.
- UI tampilkan status.

### 9. Acceptance Criteria

Fitur dianggap selesai jika:

1. Setiap clip completed punya tombol `Upload ke YouTube` di halaman results.
2. Jika OAuth belum tersedia, user diarahkan ke Google OAuth.
3. Setelah OAuth sukses, clip bisa diupload ke YouTube.
4. Metadata video otomatis dibuat dari transkrip:
   - title catchy
   - description/caption catchy
   - hashtag relevan
   - tidak mengarang fakta
5. Default privacy bukan public, gunakan `private` atau `unlisted`.
6. Status upload tersimpan di database.
7. Setelah sukses, link YouTube tampil di halaman results.
8. Jika upload gagal, error tersimpan dan terlihat di UI.
9. Video yang diupload adalah video final `clips/{clip_id}.mp4`, bukan raw/reframed intermediate.

### 10. Testing Manual

Jalankan:

```bash
cd /opt/lampp/htdocs/ai-clipper
composer require google/apiclient:^2.0
php artisan migrate
php artisan test
```

Pastikan env terisi:

```env
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/youtube/oauth/callback
YOUTUBE_DEFAULT_PRIVACY=private
GROQ_API_KEY=...
```

Manual:

1. Proses satu clip sampai completed.
2. Buka halaman results.
3. Klik `Upload ke YouTube`.
4. Login/authorize Google jika diminta.
5. Pastikan status berubah.
6. Pastikan video muncul di YouTube Studio.
7. Pastikan title/description/hashtag otomatis sesuai transkrip.
8. Pastikan privacy default bukan public.

## Catatan Penting

- Jangan publish public secara default. Pakai `private` untuk aman.
- Jangan menyimpan token OAuth di public path.
- Jangan upload ulang jika clip sudah punya `youtube_video_id`, kecuali nanti dibuat tombol `Upload ulang` secara eksplisit.
- Jangan mengganti pipeline pemrosesan clip. Fitur ini hanya tambahan setelah clip completed.
