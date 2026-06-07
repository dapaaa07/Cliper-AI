# Prompt Antigravity: Auto Collect Caption References dari YouTube Shorts/TikTok untuk Inject Style AI

Analisis dan update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper`.

User tidak ingin menambahkan caption references secara manual satu per satu. Buat fitur untuk mengumpulkan contoh caption dari YouTube Shorts dan TikTok, lalu otomatis inject hasilnya ke tabel `caption_references` agar dipakai sebagai style reference AI.

## Batasan Penting

Jangan scraping liar/massal yang agresif.

Implementasi harus:

1. Mengambil data dari URL publik yang user berikan, atau dari search YouTube API yang resmi.
2. Rate limit request.
3. Simpan hanya caption/title/description pendek sebagai style reference, bukan download video.
4. Simpan `source_url` agar data bisa dilacak.
5. Jangan bypass login, captcha, paywall, atau proteksi platform.
6. Jangan copy caption untuk dipublish ulang; caption reference hanya dipakai sebagai style guide.
7. AI tetap harus membuat caption baru berdasarkan clip/transkrip saat ini, bukan menjiplak reference.

## Target Fitur

Tambahkan modul **Auto Caption Reference Collector**.

Sumber data:

1. YouTube Shorts:
   - Dari daftar URL Shorts/video yang user paste.
   - Optional: dari keyword search memakai YouTube Data API jika `YOUTUBE_DATA_API_KEY` tersedia.
   - Fallback: `yt-dlp --dump-json --skip-download` untuk URL yang user berikan.

2. TikTok:
   - Dari daftar URL TikTok yang user paste.
   - Gunakan `yt-dlp --dump-json --skip-download` jika support di environment.
   - Jangan buat scraper browser yang bypass captcha/login.
   - Jika metadata TikTok gagal, tampilkan error dan minta user pakai URL lain/export manual.

Output:

- Data otomatis masuk ke tabel `caption_references`.
- Sistem deduplicate berdasarkan `source_url` dan hash description.
- User bisa review/aktif-nonaktif reference.

## File Terkait

Prompt sebelumnya sudah meminta:

- tabel `caption_references`
- model `app/Models/CaptionReference.php`
- service `app/Services/CaptionReferenceService.php`
- command import JSON

Lanjutkan dari fitur itu. Jangan duplikasi konsep.

File baru yang diminta:

- `app/Services/CaptionReferenceCollectorService.php`
- `app/Console/Commands/CollectCaptionReferencesCommand.php`
- `app/Http/Controllers/CaptionReferenceCollectorController.php` optional untuk UI
- view optional: `resources/views/caption_references/collector.blade.php`

## ENV

Tambahkan ke `.env.example`:

```env
YOUTUBE_DATA_API_KEY=
CAPTION_COLLECTOR_MAX_PER_RUN=30
CAPTION_COLLECTOR_SLEEP_MS=800
CAPTION_COLLECTOR_DEFAULT_LANGUAGE=id
```

YouTube OAuth upload yang sudah ada tidak perlu dipakai untuk search. Untuk search publik, gunakan `YOUTUBE_DATA_API_KEY` jika tersedia.

## Service: CaptionReferenceCollectorService

Buat service:

`app/Services/CaptionReferenceCollectorService.php`

Method utama:

```php
public function collectFromUrls(array $urls, array $options = []): array
public function collectFromYouTubeSearch(string $query, array $options = []): array
```

Return:

```php
[
    'imported' => 12,
    'skipped' => 3,
    'failed' => [
        ['url' => '...', 'error' => '...']
    ],
]
```

## Collect Dari URL

Input user berupa textarea:

```text
https://www.youtube.com/shorts/...
https://www.youtube.com/watch?v=...
https://www.tiktok.com/@user/video/...
```

Untuk setiap URL:

1. Deteksi platform:
   - domain mengandung `youtube.com`, `youtu.be` -> YouTube
   - domain mengandung `tiktok.com` -> TikTok
2. Jalankan `yt-dlp --dump-json --skip-download <url>`.
3. Ambil metadata:
   - `title`
   - `description`
   - `tags`
   - `categories`
   - `uploader` / `channel`
   - `webpage_url`
   - `duration`
4. Extract calon caption:
   - untuk YouTube: pakai `description` dan `title`
   - untuk TikTok: biasanya caption ada di `description` atau `title` dari yt-dlp
5. Normalize menjadi reference:
   - `platform`: `youtube_shorts` atau `tiktok`
   - `category`: infer dari tags/category/title, default `clip`
   - `language`: detect sederhana, default `id`
   - `title_example`: title pendek
   - `description_example`: caption/description
   - `hook_example`: baris pertama caption
   - `hashtags_example`: extract hashtag dari caption/tags
   - `notes`: auto note seperti `Collected from TikTok URL via yt-dlp`
   - `source_url`: original URL/webpage_url
   - `is_active`: true

## YouTube Search Collector

Jika `YOUTUBE_DATA_API_KEY` tersedia, tambahkan collect dari keyword:

```php
collectFromYouTubeSearch('podcast indonesia shorts mindset', [
    'maxResults' => 25,
    'order' => 'relevance',
    'videoDuration' => 'short',
]);
```

Gunakan endpoint:

```text
GET https://www.googleapis.com/youtube/v3/search
GET https://www.googleapis.com/youtube/v3/videos
```

Flow:

1. Search video berdasarkan query.
2. Ambil video ids.
3. Panggil `videos.list?part=snippet,contentDetails&id=...`.
4. Filter duration pendek jika bisa:
   - target <= 180 detik untuk Shorts-like content.
5. Simpan snippet title/description/tags sebagai reference.

Catatan:

- Search result YouTube tidak selalu Shorts murni. Itu tidak masalah selama durasi pendek dan caption style cocok.
- Jangan download video.

## TikTok Collector

Untuk TikTok, MVP cukup dari URL list.

Gunakan `yt-dlp --dump-json --skip-download`.

Jika gagal karena TikTok blocking/captcha:

- Jangan bypass.
- Return failed item dengan pesan jelas.
- User bisa pakai URL YouTube Shorts atau copy caption ke JSON import.

Jangan implement headless browser scraping TikTok kecuali user eksplisit meminta dan memahami risikonya.

## Command Artisan

Buat command:

`php artisan caption-references:collect`

Options:

```bash
php artisan caption-references:collect --urls=storage/app/caption_references/urls.txt
php artisan caption-references:collect --youtube-query="podcast indonesia shorts mindset" --max=25
php artisan caption-references:collect --platform=youtube --category=podcast --language=id
```

Behavior:

- Baca URL file jika `--urls` ada.
- Jalankan YouTube search jika `--youtube-query` ada.
- Batasi jumlah import berdasarkan `CAPTION_COLLECTOR_MAX_PER_RUN`.
- Sleep antar request berdasarkan `CAPTION_COLLECTOR_SLEEP_MS`.
- Print summary.

## UI Collector

Tambahkan UI sederhana di halaman caption references:

Route:

```php
Route::get('/caption-references/collect', [CaptionReferenceCollectorController::class, 'index'])->name('caption-references.collect.index');
Route::post('/caption-references/collect/urls', [CaptionReferenceCollectorController::class, 'collectUrls'])->name('caption-references.collect.urls');
Route::post('/caption-references/collect/youtube-search', [CaptionReferenceCollectorController::class, 'collectYouTubeSearch'])->name('caption-references.collect.youtubeSearch');
```

UI:

1. Textarea paste URL TikTok/YouTube Shorts.
2. Input keyword YouTube search.
3. Select category.
4. Select language.
5. Max results.
6. Submit.
7. Tampilkan summary imported/skipped/failed.
8. Link ke list caption references untuk review.

## Deduplication

Jangan insert duplicate.

Dedup criteria:

1. Jika `source_url` sama, skip/update.
2. Jika normalized `description_example` hash sama, skip.

Tambahkan kolom optional:

- `content_hash` string nullable/index

Jika belum mau migration tambahan, dedup dengan query:

```php
CaptionReference::where('source_url', $url)->exists()
```

dan normalized description comparison.

## Caption Extraction Rules

Buat helper:

`extractCaptionReferenceFromMetadata(array $metadata, string $platform, array $options): ?array`

Rules:

- Minimal caption length 20 karakter.
- Maksimal description_example 1000 karakter.
- Ambil hook dari baris non-empty pertama.
- Extract hashtags regex `/#([\p{L}\p{N}_]+)/u`.
- Bersihkan tracking text, URL panjang, repeated whitespace.
- Buang caption yang hanya berisi link/hashtag tanpa kalimat.
- Buang caption terlalu generic:
  - `subscribe`
  - `like and share`
  - `follow for more`
  - hanya promosi channel

## Quality Score Reference

Sebelum insert, hitung score 0-100:

- +25 punya hook line 30-140 char
- +20 punya 2-5 baris
- +15 punya 2-8 hashtags
- +15 ada kata/struktur hook seperti:
  - `ternyata`
  - `jangan`
  - `gue kira`
  - `ini yang`
  - `plot twist`
  - `sering banget`
  - `baru sadar`
- +10 durasi video <= 180 detik
- -20 jika terlalu formal
- -30 jika promosi murni

Simpan hanya jika score >= 40, kecuali user set `--include-low-quality`.

Tambahkan field optional:

- `quality_score` integer nullable

Kalau tidak mau migration tambahan, simpan score di `notes`.

## Integrasi Dengan Generator Caption

Pastikan `CaptionReferenceService::pickReferencesForClip()` hanya mengambil `is_active=true` dan prefer `quality_score` tinggi jika ada.

Saat AI generate caption:

- Pakai maksimal 5 references.
- Jangan kirim terlalu banyak agar prompt tidak penuh.
- Sertakan `source_url` hanya untuk debugging/internal, bukan untuk AI jika tidak perlu.

## Acceptance Criteria

Fitur selesai jika:

1. User bisa paste URL TikTok/YouTube Shorts dan sistem otomatis membuat caption references.
2. User bisa collect dari YouTube search jika `YOUTUBE_DATA_API_KEY` tersedia.
3. TikTok URL collector memakai metadata publik dari `yt-dlp`, tanpa bypass captcha/login.
4. Sistem deduplicate reference.
5. Sistem filter caption yang jelek/promosi murni.
6. Reference baru langsung bisa dipakai generator caption AI.
7. Caption generator memakai reference hasil collect sebagai style guide.
8. Ada summary imported/skipped/failed.
9. Flow upload YouTube tidak rusak.

## Testing Manual

Jalankan:

```bash
cd /opt/lampp/htdocs/ai-clipper
php artisan migrate
```

Test URL list:

```bash
mkdir -p storage/app/caption_references
nano storage/app/caption_references/urls.txt
php artisan caption-references:collect --urls=storage/app/caption_references/urls.txt --category=podcast --language=id
```

Test YouTube search:

```bash
php artisan caption-references:collect --youtube-query="podcast indonesia shorts" --max=20 --category=podcast --language=id
```

Verifikasi:

```bash
php artisan tinker
>>> App\Models\CaptionReference::latest()->take(10)->get(['platform','category','hook_example','source_url'])->toArray();
```

Manual UI:

1. Buka collector page.
2. Paste 5-10 URL Shorts/TikTok.
3. Klik collect.
4. Review list references.
5. Generate/upload clip baru.
6. Pastikan caption AI lebih mengikuti style reference.

## Catatan Penting

- Untuk TikTok, scraping bisa sering gagal karena proteksi platform. Jangan paksa bypass.
- Untuk YouTube, prefer YouTube Data API atau `yt-dlp` pada URL yang user berikan.
- Data reference adalah style input, bukan konten untuk dipublish ulang.
- Jangan menyimpan atau mendownload video dari platform.
