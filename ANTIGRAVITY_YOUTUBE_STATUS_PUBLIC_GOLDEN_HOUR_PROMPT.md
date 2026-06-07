# Prompt Antigravity: Fix Status Upload YouTube Queued + Public/Schedule Golden Hour

Analisis dan update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper`.

Masalah sekarang:

1. Setelah tombol upload YouTube diklik, job di terminal sudah selesai/done, tetapi tampilan web masih terlihat `queued`.
2. Upload YouTube saat ini masih default `private`.
3. User ingin video dipublish mengikuti rules golden hour: `07:00`, `12:00`, dan `17:00-21:00`, menyesuaikan waktu saat klik upload YouTube.

## Konteks Saat Ini

File terkait:

- `app/Http/Controllers/YouTubeController.php`
- `app/Jobs/UploadClipToYouTubeJob.php`
- `app/Services/YouTubeMetadataService.php`
- `resources/views/results.blade.php`
- `app/Models/Clip.php`
- `routes/web.php`
- `config/services.php`
- `.env.example`

Temuan penting: data DB bisa sudah berubah menjadi `uploaded`, tetapi halaman `results.blade.php` tidak melakukan polling status upload YouTube. Akibatnya halaman yang sedang terbuka tetap terlihat `queued` sampai user refresh manual.

## Target Perubahan

1. Fix UI agar status upload tidak stuck di `queued`.
2. Tambahkan endpoint JSON untuk polling status upload YouTube.
3. Saat upload diklik, tentukan publish plan berdasarkan golden hour.
4. Jika waktu klik masuk golden hour, upload langsung `public`.
5. Jika waktu klik di luar golden hour, upload sebagai scheduled publish di golden hour terdekat.
6. Default behavior tidak lagi private permanen.
7. Ikuti aturan YouTube API: scheduled publish wajib memakai `privacyStatus=private` + `publishAt` future time.

## Golden Hour Rules

Gunakan timezone `Asia/Jakarta`.

Rules:

- Fixed slot: `07:00`
- Fixed slot: `12:00`
- Prime window: `17:00-21:00`

Algoritma:

1. Ambil waktu sekarang dalam `YOUTUBE_PUBLISH_TIMEZONE`, default `Asia/Jakarta`.
2. Jika sekarang berada dalam `17:00 <= now < 21:00`, publish langsung public.
3. Untuk fixed slot, gunakan toleransi `YOUTUBE_GOLDEN_FIXED_WINDOW_MINUTES=30`:
   - 07:00-07:30 dianggap golden hour
   - 12:00-12:30 dianggap golden hour
4. Jika di luar golden hour, jadwalkan ke golden hour berikutnya:
   - 00:00-06:59 -> 07:00 hari ini
   - 07:31-11:59 -> 12:00 hari ini
   - 12:31-16:59 -> 17:00 hari ini
   - 21:00-23:59 -> 07:00 besok
5. Jika jadwal publish kurang dari `YOUTUBE_MIN_SCHEDULE_LEAD_MINUTES=15` menit dari sekarang, publish langsung public agar tidak ditolak YouTube.

Contoh:

- Klik 00:05 WIB -> scheduled publish 07:00 WIB hari itu.
- Klik 08:20 WIB -> scheduled publish 12:00 WIB.
- Klik 13:40 WIB -> scheduled publish 17:00 WIB.
- Klik 18:10 WIB -> upload langsung public.
- Klik 21:30 WIB -> scheduled publish 07:00 WIB besok.

## ENV dan Config

Update `.env.example` dan `.env`:

```env
YOUTUBE_DEFAULT_PRIVACY=public
YOUTUBE_PUBLISH_MODE=golden_hour
YOUTUBE_PUBLISH_TIMEZONE=Asia/Jakarta
YOUTUBE_GOLDEN_FIXED_WINDOW_MINUTES=30
YOUTUBE_MIN_SCHEDULE_LEAD_MINUTES=15
```

Update `config/services.php`:

```php
'youtube' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    'default_privacy' => env('YOUTUBE_DEFAULT_PRIVACY', 'public'),
    'publish_mode' => env('YOUTUBE_PUBLISH_MODE', 'golden_hour'),
    'publish_timezone' => env('YOUTUBE_PUBLISH_TIMEZONE', env('APP_TIMEZONE', 'Asia/Jakarta')),
    'golden_fixed_window_minutes' => (int) env('YOUTUBE_GOLDEN_FIXED_WINDOW_MINUTES', 30),
    'min_schedule_lead_minutes' => (int) env('YOUTUBE_MIN_SCHEDULE_LEAD_MINUTES', 15),
],
```

## Database Migration

Buat migration baru, jangan edit migration lama. Tambahkan kolom ke table `clips`:

- `youtube_publish_status` string nullable, value: `immediate_public` atau `scheduled_public`
- `youtube_publish_at` timestamp nullable
- `youtube_publish_timezone` string nullable
- `youtube_scheduled_for_local` string nullable

Update `app/Models/Clip.php`:

- Tambahkan field di `$fillable`.
- Tambahkan cast `youtube_publish_at => datetime`.

## Tambahkan Service Scheduler

Buat service baru:

`app/Services/YouTubePublishScheduler.php`

Method:

```php
public function resolvePublishPlan(?\Carbon\CarbonInterface $now = null): array
```

Return untuk immediate:

```php
[
    'mode' => 'immediate_public',
    'privacy_status' => 'public',
    'publish_at' => null,
    'publish_at_local' => null,
    'timezone' => 'Asia/Jakarta',
]
```

Return untuk scheduled:

```php
[
    'mode' => 'scheduled_public',
    'privacy_status' => 'private',
    'publish_at' => $publishAtUtc,
    'publish_at_local' => '2026-06-03 07:00:00 WIB',
    'timezone' => 'Asia/Jakarta',
]
```

Catatan: untuk scheduled publish, YouTube API harus menerima `privacyStatus=private` dan `publishAt` dalam RFC3339/UTC. Jangan set `privacyStatus=public` jika `publishAt` diset.

## Update `UploadClipToYouTubeJob.php`

Inject scheduler:

```php
public function handle(
    YouTubeMetadataService $metadataService,
    YouTubePublishScheduler $publishScheduler
): void
```

Perubahan wajib:

1. Ambil fresh model di awal job:

```php
$clip = Clip::query()->with('video')->findOrFail($this->clip->id);
```

2. Setelah update attribute yang akan dipakai lagi, panggil `$clip->refresh()`.

3. Hitung publish plan sebelum upload:

```php
$publishPlan = $publishScheduler->resolvePublishPlan();
$privacyStatus = $publishPlan['privacy_status'];
```

4. Metadata service boleh return privacy, tetapi job harus override privacy berdasarkan publish plan.

5. Simpan plan ke DB saat status `uploading`:

```php
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
```

6. Set YouTube video status:

```php
$status = new \Google_Service_YouTube_VideoStatus();
$status->setPrivacyStatus($privacyStatus);

if ($publishPlan['mode'] === 'scheduled_public' && $publishPlan['publish_at']) {
    $status->setPublishAt($publishPlan['publish_at']->toRfc3339String());
}
```

7. Setelah upload sukses:

- Jika immediate public, set `youtube_upload_status=uploaded`.
- Jika scheduled, set `youtube_upload_status=scheduled`.

Simpan juga `youtube_video_id`, `youtube_url`, dan `youtube_uploaded_at`.

## Fix UI Stuck Queued

Tambahkan route:

```php
Route::get('/clips/{clip}/youtube/status', [YouTubeController::class, 'status'])->name('clips.youtube.status');
```

Tambahkan method di `YouTubeController`:

```php
public function status(Clip $clip)
{
    $clip->refresh();

    return response()->json([
        'id' => $clip->id,
        'youtube_upload_status' => $clip->youtube_upload_status,
        'youtube_video_id' => $clip->youtube_video_id,
        'youtube_url' => $clip->youtube_url,
        'youtube_privacy_status' => $clip->youtube_privacy_status,
        'youtube_publish_status' => $clip->youtube_publish_status,
        'youtube_publish_at' => optional($clip->youtube_publish_at)->toIso8601String(),
        'youtube_scheduled_for_local' => $clip->youtube_scheduled_for_local,
        'youtube_error_message' => $clip->youtube_error_message,
    ]);
}
```

Update `results.blade.php`:

1. Beri wrapper YouTube upload section dengan `data-clip-id`, `data-status-url`, dan `data-current-status`.
2. Untuk status `queued`, `generating_metadata`, dan `uploading`, polling endpoint setiap 5 detik.
3. Stop polling saat final state: `uploaded`, `scheduled`, atau `failed`.
4. Jika ingin MVP cepat, boleh auto reload page tiap 10 detik selama ada active status. Lebih baik fetch JSON dan update DOM.

UI text:

- `queued`: Menunggu queue upload...
- `generating_metadata`: Membuat caption AI...
- `uploading`: Mengupload ke YouTube...
- `uploaded`: Berhasil dipublish public
- `scheduled`: Terjadwal public pada {youtube_scheduled_for_local}
- `failed`: tampilkan error dan tombol upload ulang

## Acceptance Criteria

1. Setelah job upload selesai, web tidak stuck di `queued`.
2. Results page polling status YouTube sampai final state.
3. Klik upload pada 17:00-21:00 WIB langsung upload sebagai public.
4. Klik upload di luar golden hour upload sebagai scheduled public di golden hour terdekat.
5. Scheduled publish memakai `privacyStatus=private` + `publishAt` sesuai aturan YouTube API.
6. UI membedakan `uploaded`, `scheduled`, dan `failed`.
7. DB menyimpan publish status dan schedule time.
8. Default env/config berubah dari private ke public untuk immediate publish.
9. OAuth dan caption generator yang sudah berhasil jangan dirusak.

## Testing Manual

Jalankan:

```bash
cd /opt/lampp/htdocs/ai-clipper
php artisan migrate
php artisan config:clear
php artisan test
php artisan queue:work --tries=1 --timeout=3600
```

Manual test:

1. Buka results page.
2. Klik Upload ke YouTube.
3. Jangan refresh halaman.
4. Pastikan status berubah dari queued/generating/uploading ke uploaded atau scheduled.
5. Cek DB dengan tinker:

```php
App\Models\Clip::latest()->first()->only([
    'youtube_upload_status',
    'youtube_privacy_status',
    'youtube_publish_status',
    'youtube_publish_at',
    'youtube_scheduled_for_local',
    'youtube_url',
]);
```

Expected:

- Dalam 17:00-21:00 WIB: `uploaded`, `public`, `immediate_public`.
- Di luar golden hour: `scheduled`, `private`, `scheduled_public`, publish time terisi.

## Catatan Penting

Permintaan user adalah langsung public dan otomatis dijadwalkan golden hour. Interpretasi aman:

- Kalau klik saat golden hour, langsung public.
- Kalau klik di luar golden hour, scheduled public di golden hour terdekat.

YouTube API tidak scheduled publish dengan `privacyStatus=public`; untuk scheduled harus `private + publishAt`. Jangan membuat video private permanen kecuali memang scheduled menunggu publish time.
