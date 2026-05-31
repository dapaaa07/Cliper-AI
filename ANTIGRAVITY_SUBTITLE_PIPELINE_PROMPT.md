# Prompt Antigravity: TikTok/CapCut Subtitle Pipeline

Update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper` agar hasil clip otomatis punya subtitle burned-in dengan style TikTok/CapCut: kata per kata menyala, highlight kuning, dan bounce animation.

## Konteks Project Saat Ini

Project ini sudah punya pipeline:

- Laravel job: `app/Jobs/ProcessVideoClipJob.php`
- Orchestrator Python: `app/PythonScripts/clipper_bot.py`
- Face tracking/reframe: `app/PythonScripts/face_tracker.py`
- Transkripsi SRT/VTT/TXT: `app/PythonScripts/transcribe.py`
- Output saat ini: `storage/app/public/clips/{clip_id}.mp4` dan `storage/app/public/clips/{clip_id}.srt`

Jangan ubah flow utama Laravel secara besar-besaran. Tambahkan step subtitle styling setelah transkripsi dan sebelum pipeline mengirim `VIDEO_PATH:`.

## Target Fitur

1. Setelah video selesai di-reframe dan transkripsi selesai, generate subtitle styled format `.ass`.
2. Burn subtitle `.ass` ke video menggunakan FFmpeg `subtitles` filter/libass.
3. Video final yang disimpan di `clips/{clip_id}.mp4` harus sudah berisi subtitle burned-in.
4. Tetap simpan file subtitle mentah:
   - `clips/{clip_id}.srt`
   - `clips/{clip_id}.vtt`
   - `clips/{clip_id}.txt`
   - tambahkan `clips/{clip_id}.ass`
   - tambahkan `clips/{clip_id}_words.json` jika word timestamp tersedia
5. Jika word-level timestamp gagal/tidak tersedia, pipeline tetap jalan memakai fallback timing berbasis segment SRT.

## Style Subtitle Yang Diinginkan

Buat default style untuk video vertical 9:16:

- Posisi: lower third, center horizontal.
- Font: sans bold, pakai `Arial`, `DejaVu Sans Bold`, atau font sistem yang tersedia.
- Ukuran: proporsional untuk video 576x1024 sampai 1080x1920.
- Teks normal: putih.
- Kata aktif: kuning terang.
- Outline: hitam tebal agar tetap kebaca di semua background.
- Shadow: hitam lembut.
- Layout: 2 sampai 5 kata per caption line/group, jangan satu kalimat panjang.
- Animasi: kata aktif terlihat bounce/pop, minimal dengan scale dari kecil ke besar memakai ASS override tag `\t()`, `\fscx`, dan `\fscy`.
- Efek kata per kata:
  - kata belum aktif: putih
  - kata aktif: kuning + scale lebih besar sebentar
  - kata yang sudah lewat: putih atau sedikit redup, pilih yang paling readable

Contoh rasa visual:

- normal word: white text, black outline
- active word: yellow text, black outline, scale 115-125% selama kata aktif
- jangan pakai box subtitle penuh seperti caption film

## Implementasi Yang Diminta

### 1. Update `transcribe.py`

Ubah transkripsi agar mencoba mengambil word-level timestamps dari Groq Whisper.

Saat request ke endpoint:

`https://api.groq.com/openai/v1/audio/transcriptions`

Gunakan:

- `model=whisper-large-v3`
- `response_format=verbose_json`
- coba tambahkan `timestamp_granularities[]=word`
- tetap simpan segment timestamps seperti sekarang

Jika response punya `words`, simpan ke:

`{clip_id}_words.json`

Format JSON yang diharapkan:

```json
[
  { "word": "contoh", "start": 0.12, "end": 0.42 },
  { "word": "subtitle", "start": 0.43, "end": 0.88 }
]
```

Jika Groq tidak mengembalikan `words`, jangan gagalkan pipeline. Tetap generate SRT/VTT/TXT seperti sekarang.

### 2. Tambahkan script baru `app/PythonScripts/subtitle_styler.py`

Script ini menerima argumen:

```bash
python3 app/PythonScripts/subtitle_styler.py \
  <input_video_path> \
  <input_srt_path> \
  <output_ass_path> \
  <output_video_path> \
  [words_json_path]
```

Tanggung jawab script:

1. Baca ukuran video dengan `ffprobe`.
2. Baca word timestamp dari `words_json_path` jika ada.
3. Jika word timestamp tidak ada, parse SRT dan buat fallback word timing dengan membagi durasi segment berdasarkan jumlah kata.
4. Group kata menjadi caption pendek:
   - maksimal 4 kata per group
   - maksimal sekitar 1.8 detik per group
   - break pada punctuation jika memungkinkan
5. Generate file `.ass` dengan:
   - `[Script Info]`
   - `[V4+ Styles]`
   - `[Events]`
6. Buat event ASS per rentang kata aktif supaya highlight berubah kata per kata.
7. Burn ASS ke video:

```bash
ffmpeg -y -i input.mp4 -vf "subtitles=output.ass" -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -c:a copy output.mp4
```

Catatan:

- Escape path subtitle dengan benar agar aman untuk FFmpeg filter.
- Escape teks ASS: `{ }`, `\\`, newline, dan karakter khusus lain yang bisa merusak ASS.
- Gunakan `force_style` hanya jika perlu. Lebih baik style didefinisikan di file ASS.
- Pastikan audio tetap ada di hasil final.

### 3. Update `clipper_bot.py`

Ubah output path agar ada video intermediate dan video final:

- `reframed_video_path = storage/app/public/clips/{clip_id}_reframed.mp4`
- `final_video_path = storage/app/public/clips/{clip_id}.mp4`
- `final_srt_path = storage/app/public/clips/{clip_id}.srt`
- `final_ass_path = storage/app/public/clips/{clip_id}.ass`
- `words_json_path = storage/app/public/clips/{clip_id}_words.json`

Flow baru:

1. Download segment ke raw.
2. Face tracking/reframe ke `reframed_video_path`.
3. Transcribe `reframed_video_path` ke SRT/VTT/TXT dan words JSON jika ada.
4. Jalankan `subtitle_styler.py`:
   - input video: `reframed_video_path`
   - input srt: `final_srt_path`
   - output ass: `final_ass_path`
   - output video: `final_video_path`
   - words json: `words_json_path`
5. Jika subtitle styling gagal, fallback:
   - copy `reframed_video_path` menjadi `final_video_path`
   - tetap return SRT path
   - log warning yang jelas
6. Hapus file intermediate `_raw.mp4`, `_reframed.mp4`, dan file temporary lain setelah sukses.
7. Progress:
   - download/codec: 10-20
   - face tracking: 20-55
   - transcription: 60-85
   - subtitle styling/burn-in: 85-98
   - done: 100

Tetap print protocol yang dibaca Laravel:

```text
VIDEO_PATH: clips/{clip_id}.mp4
SUBTITLE_PATH: clips/{clip_id}.srt
PROGRESS: 100
STATUS: completed
```

### 4. Update Laravel Job Bila Perlu

`ProcessVideoClipJob.php` saat ini sudah membaca:

- `PROGRESS:`
- `STATUS:`
- `VIDEO_PATH:`
- `SUBTITLE_PATH:`

Jangan wajib ubah database dulu. Cukup pastikan `video_path` menunjuk video final yang sudah burned-in. `subtitle_path` tetap menunjuk SRT.

Opsional: kalau ingin menampilkan/download ASS di UI nanti, tambahkan kolom baru. Untuk update ini tidak wajib.

### 5. Tambahkan Dependency Jika Dibutuhkan

Cek `app/PythonScripts/requirements.txt`.

Tambahkan dependency hanya jika benar-benar perlu. Untuk implementasi ini seharusnya cukup:

- `requests`
- `python-dotenv`
- `opencv-python`
- tools sistem: `ffmpeg` dengan `libass`

Jangan tambah library berat kalau parsing SRT dan ASS bisa dibuat sederhana di Python standar.

### 6. Acceptance Criteria

Fitur dianggap selesai kalau:

1. Pipeline lama tetap bisa jalan dari Laravel.
2. Output `storage/app/public/clips/{clip_id}.mp4` sudah punya subtitle burned-in.
3. Subtitle tampil gaya TikTok/CapCut:
   - word-by-word highlight
   - active word kuning
   - ada efek bounce/pop sederhana
   - readable di background terang/gelap
4. SRT tetap disimpan dan tetap bisa di-download/dipakai.
5. Kalau word timestamp tidak tersedia, fallback tetap menghasilkan subtitle styled berdasarkan segment SRT.
6. Tidak ada perubahan destruktif pada fitur face tracking/reframe.

## Testing Manual

Jalankan minimal smoke test dari terminal:

```bash
cd /opt/lampp/htdocs/ai-clipper

# pastikan ffmpeg mendukung libass
ffmpeg -filters | grep subtitles

# jalankan pipeline via Laravel queue seperti biasa
php artisan queue:work --tries=1 --timeout=1800
```

Setelah satu clip selesai:

```bash
ls -lh storage/app/public/clips/
ffprobe -v error -show_streams storage/app/public/clips/<clip_id>.mp4
```

Buka hasil video dan cek:

- subtitle sudah menyatu di video
- kata aktif berubah kuning mengikuti suara
- bounce/pop tidak terlalu berlebihan
- audio tetap ada
- SRT tetap ada

## Catatan Kualitas

- Buat implementasi defensif: jika subtitle styling error, jangan gagalkan seluruh video clip.
- Log error detail ke stdout agar `ProcessVideoClipJob.php` bisa menangkap error.
- Hindari hardcode resolusi 576x1024 saja. Hitung font size dan margin dari tinggi video.
- Target awal cukup bagus dan stabil, tidak perlu sempurna seperti CapCut 100%.

