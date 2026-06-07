# Prompt Antigravity: Inject Data Contoh Caption TikTok/Shorts Sebagai Style Reference AI

Analisis dan update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper`.

Masalah: generator title/description YouTube sudah lebih baik, tetapi style caption masih belum sesuai target. User ingin memasukkan contoh-contoh caption dari clipper TikTok atau YouTube Shorts agar AI menjadikan contoh tersebut sebagai referensi gaya saat membuat caption baru.

Target fitur: tambahkan **Caption Reference Style Library**. Sistem ini menyimpan kumpulan contoh caption/title/description yang user suka, lalu saat generate metadata YouTube, AI mengambil beberapa contoh paling relevan dan menggunakannya sebagai few-shot style reference.

## Prinsip Utama

1. Jangan scrape TikTok/YouTube otomatis tanpa kontrol user.
2. Gunakan data contoh yang user input sendiri atau file seed yang bisa diedit.
3. AI tidak boleh copy mentah caption referensi.
4. AI harus meniru style/pola/hook, bukan menjiplak kata-kata.
5. Contoh referensi dipilih berdasarkan kemiripan topik/intent/transkrip clip.
6. Jika belum ada contoh referensi, fallback ke prompt caption yang sudah ada.

## File Terkait Saat Ini

- `app/Services/YouTubeMetadataService.php`
- `app/Services/YouTubeContextService.php` jika sudah dibuat dari prompt sebelumnya
- `app/Jobs/UploadClipToYouTubeJob.php`
- `app/Http/Controllers/YouTubeController.php`
- `resources/views/results.blade.php`
- `routes/web.php`
- `config/services.php`

Jangan rusak flow upload YouTube yang sudah berhasil. Perubahan ini fokus ke kualitas caption generator.

## Struktur Data Reference Caption

Buat tabel baru:

`caption_references`

Kolom:

- `id` uuid primary
- `platform` string nullable: `tiktok`, `youtube_shorts`, `instagram_reels`, atau `other`
- `category` string nullable: `podcast`, `reaction`, `education`, `motivation`, `comedy`, `news`, dll
- `language` string default `id`
- `title_example` string nullable
- `description_example` text required
- `hook_example` string nullable
- `hashtags_example` json nullable
- `notes` text nullable
- `source_url` string nullable
- `is_active` boolean default true
- `created_at`, `updated_at`

Buat model:

`app/Models/CaptionReference.php`

Fillable:

- `platform`
- `category`
- `language`
- `title_example`
- `description_example`
- `hook_example`
- `hashtags_example`
- `notes`
- `source_url`
- `is_active`

Casts:

- `hashtags_example` => `array`
- `is_active` => `boolean`

## Seed File Yang Mudah Diedit

Buat file:

`storage/app/caption_references/examples.json`

Atau jika lebih enak untuk user edit:

`storage/app/caption_references/examples.md`

Rekomendasi pakai JSON agar mudah diimport:

```json
[
  {
    "platform": "tiktok",
    "category": "podcast",
    "language": "id",
    "title_example": "Jangan buru-buru setuju dulu",
    "description_example": "Jangan buru-buru setuju sebelum dengar bagian ini.\n\nArgumennya simpel, tapi lumayan nusuk kalau dipikir lagi.\n\n#shorts #podcast #fyp",
    "hook_example": "Jangan buru-buru setuju sebelum dengar bagian ini.",
    "hashtags_example": ["shorts", "podcast", "fyp"],
    "notes": "Hook menahan penonton, tone santai, cocok untuk diskusi/podcast."
  }
]
```

Tambahkan command artisan untuk import:

`php artisan caption-references:import storage/app/caption_references/examples.json`

Command:

`app/Console/Commands/ImportCaptionReferencesCommand.php`

Behavior:

- Baca JSON.
- Validasi minimal `description_example`.
- Insert/update berdasarkan hash `description_example` atau source_url.
- Jangan duplicate data.

## UI Sederhana Untuk Manage Reference

Tambahkan route dan controller optional tapi disarankan:

- `GET /caption-references`
- `POST /caption-references`
- `DELETE /caption-references/{captionReference}`
- `PATCH /caption-references/{captionReference}/toggle`

Controller:

`app/Http/Controllers/CaptionReferenceController.php`

View:

`resources/views/caption_references/index.blade.php`

UI minimal:

- Textarea untuk paste contoh caption/description.
- Input optional:
  - title example
  - platform
  - category
  - source URL
  - notes
- List reference aktif.
- Tombol aktif/nonaktif.

Jika ingin MVP lebih cepat, skip UI dulu dan cukup pakai JSON import. Tapi pastikan sistem siap membaca reference dari database.

## Service Pemilih Reference

Buat service:

`app/Services/CaptionReferenceService.php`

Method:

```php
public function pickReferencesForClip(Clip $clip, string $transcript, array $context = [], int $limit = 5): array
```

Tugas:

1. Deteksi bahasa sederhana dari transcript/context, default `id`.
2. Ambil reference aktif dengan language sama.
3. Skor relevansi reference berdasarkan:
   - category cocok dengan context/category/tags original
   - keyword overlap antara transcript/context dengan notes/title/description reference
   - platform preference: TikTok dan YouTube Shorts lebih tinggi
4. Return maksimal 3-5 contoh paling relevan.
5. Jika tidak ada yang relevan, return 3-5 contoh aktif terbaru/terbaik.

Tidak perlu embedding dulu untuk MVP. Keyword scoring cukup:

- tokenize lowercase
- buang stopwords umum Indonesia/English
- hitung overlap
- tambah bonus category/platform

Optional lanjutan:

- Jika ingin lebih pintar, nanti tambahkan embedding lokal/free. Tapi jangan wajib untuk MVP.

## Update YouTubeMetadataService

Inject service:

```php
public function __construct(
    private YouTubeContextService $contextService,
    private CaptionReferenceService $captionReferenceService
) {}
```

Di `generateMetadata(Clip $clip)`:

1. Ambil transcript.
2. Ambil context YouTube.
3. Ambil reference examples:

```php
$styleReferences = $this->captionReferenceService->pickReferencesForClip($clip, $transcript, $context, 5);
```

4. Kirim `$styleReferences` ke provider Gemini/Groq/Ollama.
5. Jika references kosong, tetap jalan dengan prompt lama.

## Format Reference Dalam Prompt AI

Tambahkan section ke user prompt:

```text
STYLE REFERENCES FROM USER:
Gunakan contoh berikut hanya sebagai referensi gaya, pola hook, panjang caption, dan cara menyusun hashtag.
JANGAN menyalin kalimat referensi secara mentah.
JANGAN memakai fakta dari referensi jika tidak ada di clip saat ini.

Reference 1:
Title style: ...
Caption style:
...
Notes: ...

Reference 2:
...
```

Tambahkan instruksi system prompt:

```text
User menyediakan style references dari caption TikTok/Shorts yang disukai.
Tiru pola hook, ritme, panjang, dan vibe-nya.
Jangan copy kalimat referensi.
Konten final harus tetap berdasarkan transcript/context clip saat ini.
```

## Prompt Style Yang Harus Diperkuat

AI harus mengikuti format:

```text
{hook pendek spesifik}

{1-2 baris konteks/kenapa ini menarik}

#{hashtag1} #{hashtag2} #{hashtag3}
```

Larangan:

- Jangan mulai dengan `Cuplikan singkat`.
- Jangan mulai dengan `Dalam video ini`.
- Jangan mulai dengan `Video ini menjelaskan`.
- Jangan membuat summary formal.
- Jangan copy paste reference.
- Jangan menambahkan fakta yang tidak ada di clip.

## Sanitasi dan Quality Gate

Tambahkan method quality gate:

`passesCaptionQualityGate(array $metadata, array $styleReferences): bool`

Rules:

1. Description harus punya 2-5 baris.
2. Baris pertama harus hook, panjang ideal 35-120 karakter.
3. Baris pertama tidak boleh:
   - `Cuplikan singkat`
   - `Dalam video ini`
   - `Video ini menjelaskan`
   - `Berikut adalah`
4. Description harus punya hashtag line.
5. Hashtag 3-6.
6. Description tidak boleh terlalu mirip dengan salah satu reference.
   - Simple check: similarity by exact sentence/substring.
   - Jika baris pertama sama persis dengan reference, reject.
7. Jika gagal quality gate:
   - regenerate sekali dengan prompt feedback.
   - Jika masih gagal, gunakan fallback rule-based catchy.

Prompt feedback regenerate:

```text
Output sebelumnya masih terlalu formal/generik.
Buat ulang dengan hook TikTok yang lebih kuat.
Jangan mulai dengan ringkasan formal.
Jangan copy style reference secara mentah.
Return JSON only.
```

## Fallback Rule-Based Catchy Dengan Reference

Jika AI gagal, gunakan reference untuk pola saja.

Fallback:

1. Ambil kalimat terbaik dari transcript.
2. Ubah jadi hook:
   - Jika ada pertanyaan: `Pertanyaan ini kelihatannya simpel, tapi jawabannya menarik.`
   - Jika ada kata `jangan`: pertahankan sebagai hook.
   - Jika ada kata `ternyata`: buat hook dimulai `Ternyata...`.
   - Jika topik diskusi/debat: `Jangan buru-buru setuju sebelum dengar bagian ini.`
3. Baris kedua:
   - jelaskan kenapa clip ini menarik berdasarkan transcript.
4. Hashtag dari context/tags/reference.

Jangan pakai fallback lama yang dimulai `Cuplikan singkat dari...`.

## Contoh Data Awal

Buat seed contoh minimal 10 reference berbahasa Indonesia. Jangan gunakan caption brand/person nyata kalau tidak punya datanya. Pakai contoh generik tapi realistis:

1. Podcast/debat:
```text
Jangan buru-buru setuju sebelum dengar bagian ini.

Argumennya simpel, tapi lumayan nusuk kalau dipikir lagi.

#shorts #podcast #fyp
```

2. Mindset:
```text
Ternyata masalahnya bukan di idenya.

Kadang yang bikin gagal justru cara kita ngejelasinnya.

#shorts #mindset #belajar
```

3. Reaction:
```text
Gue kira jawabannya bakal biasa aja.

Tapi bagian akhirnya malah bikin konteksnya berubah total.

#shorts #reaction #fyp
```

4. Edukasi:
```text
Ini yang sering kelewat waktu orang bahas topik ini.

Sekali paham konteksnya, penjelasannya jadi jauh lebih masuk akal.

#shorts #edukasi #belajar
```

5. Warning:
```text
Jangan anggap sepele bagian kecil ini.

Efeknya bisa panjang kalau dari awal sudah salah paham.

#shorts #tips #fyp
```

Tambahkan 5 contoh lain dengan gaya serupa.

## Acceptance Criteria

Fitur dianggap selesai jika:

1. User bisa inject contoh caption TikTok/Shorts via JSON import atau UI.
2. Reference tersimpan di database dan bisa diaktif/nonaktifkan.
3. Saat generate metadata YouTube, service memilih 3-5 reference yang relevan.
4. Prompt AI menerima style references sebagai few-shot style guide.
5. AI tidak copy paste reference.
6. Caption final lebih mirip gaya TikTok/Shorts:
   - hook kuat di baris pertama
   - 2-4 baris caption
   - hashtag 3-6
   - tidak formal
7. Ada quality gate untuk menolak output generik/formal.
8. Jika AI gagal, fallback rule-based tetap catchy.
9. Flow upload YouTube, golden hour, dan OAuth tidak rusak.

## Testing Manual

Jalankan:

```bash
cd /opt/lampp/htdocs/ai-clipper
php artisan migrate
php artisan caption-references:import storage/app/caption_references/examples.json
php artisan test
```

Manual:

1. Tambahkan 10 contoh caption reference.
2. Generate/upload clip.
3. Cek `youtube_description`.
4. Pastikan baris pertama adalah hook.
5. Pastikan caption tidak mulai dengan `Cuplikan singkat` atau `Dalam video ini`.
6. Pastikan caption tidak sama persis dengan reference.
7. Tambahkan reference baru dengan gaya berbeda, lalu regenerate metadata dan cek perubahan style.

## Catatan Penting

- Reference adalah style guide, bukan sumber fakta.
- Fakta tetap harus berasal dari transcript/context clip saat ini.
- Jangan scrape otomatis dari TikTok/YouTube tanpa instruksi user.
- Jangan menyimpan data copyright-sensitive secara masif; cukup caption contoh yang user pilih sendiri.
