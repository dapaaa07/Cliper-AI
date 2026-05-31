# Prompt Antigravity: Penyempurnaan Face Tracking Anti-Glitch

Update project Laravel/Python di `/opt/lampp/htdocs/ai-clipper` untuk memperbaiki face tracking/reframe yang masih glitch, loncat antar wajah, dan framing terasa nyentak.

## Masalah Yang Terlihat

Hasil video face tracking terbaru masih suka glitch karena implementasi sekarang di `app/PythonScripts/face_tracker.py`:

- memakai OpenCV Haar Cascade saja;
- memilih wajah terbesar pada frame deteksi;
- tidak punya target lock/identity tracking;
- tidak punya hysteresis saat pindah target;
- smoothing hanya EMA sederhana `smoothing_factor=0.08`;
- ketika banyak orang dalam podcast, crop bisa loncat ke orang lain karena wajah terbesar berubah;
- saat wajah hilang beberapa frame, posisi bisa tertarik ke target salah atau bergerak tidak natural.

Goal update ini: framing harus jauh lebih stabil untuk video podcast/multi-person, dengan perpindahan target yang halus dan tidak agresif.

## Konteks Project Saat Ini

File yang relevan:

- `app/PythonScripts/face_tracker.py`
- `app/PythonScripts/clipper_bot.py`
- `app/PythonScripts/requirements.txt`

`clipper_bot.py` sudah menjalankan:

```bash
python3 app/PythonScripts/face_tracker.py <input_path> <output_path> <aspect_ratio>
```

Jangan ubah kontrak CLI ini kecuali menambahkan argumen opsional yang tetap backward compatible.

Penting: project juga sudah punya step subtitle styling/burn-in setelah face tracking. Jangan rusak flow subtitle yang sudah ada di `clipper_bot.py`.

## Target Perbaikan

1. Reframe video vertical 9:16 tetap otomatis.
2. Gerakan crop harus smooth, tidak jitter, tidak loncat mendadak.
3. Untuk video multi-person, tracker harus mempertahankan target yang sama kecuali ada alasan kuat untuk switch.
4. Jika target wajah hilang sementara, tahan posisi terakhir dan pelan-pelan fallback ke center, jangan langsung lompat.
5. Deteksi wajah harus lebih robust dari Haar Cascade saja.
6. Ada debug mode opsional untuk melihat bounding box, crop center, dan target yang dipilih.
7. Progress stdout tetap kompatibel dengan Laravel:

```text
STATUS: processing_crop
PROGRESS: <number>
STATUS: merging_audio
```

## Implementasi Yang Diminta

### 1. Refactor `face_tracker.py`

Ganti pendekatan sekarang menjadi pipeline 2-pass atau semi-2-pass:

#### Pass A: Analyze Tracking Path

Baca video dan buat daftar target crop center per frame/sample.

Gunakan deteksi wajah dengan urutan:

1. **MediaPipe Face Detection** sebagai primary detector, karena `mediapipe>=0.10.9` sudah ada di `requirements.txt`.
2. Fallback ke OpenCV Haar Cascade jika MediaPipe gagal import atau tidak mendeteksi wajah.

Jangan pilih wajah terbesar mentah-mentah. Buat scoring detection:

- prioritas tinggi untuk wajah yang dekat dengan target sebelumnya;
- bonus untuk wajah yang ukurannya masuk akal;
- bonus untuk wajah dekat center crop saat ini;
- penalti besar untuk pindah terlalu jauh dari target sebelumnya;
- penalti untuk bounding box terlalu kecil/false positive.

Contoh scoring:

```python
score = 0
score += area_norm * 0.35
score += center_preference * 0.20
score += continuity_score * 0.45
```

Continuity harus lebih penting daripada ukuran wajah agar crop tidak pindah target sembarangan.

#### Pass B: Smooth Crop Path + Render

Setelah target center ditemukan, buat crop path yang sudah distabilkan:

- apply median filter kecil untuk menghapus outlier;
- apply exponential smoothing atau spring smoothing;
- batasi kecepatan crop movement per frame, misalnya maksimal `width * 0.012` pixel/frame;
- clamp crop center agar crop tidak keluar frame;
- jangan gerakkan crop untuk perubahan kecil di bawah threshold, misalnya `dead_zone_px = crop_width * 0.04`.

Setelah path stabil, render frame ke output video silent, lalu merge audio seperti sekarang.

### 2. Tambahkan Target Lock dan Hysteresis

Implement target lock logic:

- Simpan `locked_target_center_x`, `locked_target_width`, dan timestamp/frame terakhir valid.
- Jika deteksi baru dekat dengan target terkunci, tetap gunakan target itu.
- Jika ada wajah lain yang lebih besar/lebih bagus, jangan langsung pindah.
- Switch target hanya jika kandidat baru:
  - konsisten muncul minimal `switch_hold_frames = int(fps * 0.75)`;
  - score lebih tinggi dari target lama dengan margin jelas, misalnya `switch_score_margin = 0.25`;
  - jarak perpindahan tidak membuat crop terlalu mendadak, atau transisinya di-smooth.

Jika tidak ada wajah:

- gunakan posisi terakhir selama `lost_hold_frames = int(fps * 1.25)`;
- setelah itu fallback pelan-pelan ke center frame, bukan langsung center.

### 3. Tambahkan Preset Mode

Tambahkan opsi mode internal di `face_tracker.py`, tetap default tanpa perlu ubah Laravel.

Default:

```python
TRACKING_MODE = os.getenv("FACE_TRACKING_MODE", "stable")
```

Mode yang diharapkan:

- `stable`: paling aman, minim switch target, cocok untuk podcast.
- `dynamic`: boleh switch target lebih cepat, tapi tetap pakai hysteresis.
- `center`: crop center saja tanpa face tracking, fallback jika semua detector bermasalah.

Laravel tidak perlu diubah dulu. Mode bisa dikontrol dari `.env` kalau nanti diperlukan.

### 4. Tambahkan Debug Output Opsional

Jika env:

```env
FACE_TRACKING_DEBUG=1
```

Maka buat video debug opsional:

`storage/app/public/clips/{clip_id}_debug_tracking.mp4`

Karena `face_tracker.py` tidak tahu `clip_id` secara langsung, buat debug path dari `output_path`:

- output: `123_reframed.mp4`
- debug: `123_reframed_debug_tracking.mp4`

Debug overlay minimal:

- kotak wajah terdeteksi;
- titik target crop center;
- garis crop boundaries;
- label `locked`, `candidate`, `lost`, atau `center_fallback`.

Debug mode jangan aktif default karena akan memperlambat pipeline.

### 5. Output Video Quality

Pertahankan output final dari `face_tracker.py`:

- codec video: `libx264`
- pix fmt: `yuv420p`
- audio: `aac` atau copy audio yang kompatibel
- jangan hilangkan audio

Jika pakai OpenCV writer untuk silent video, pastikan dimensi genap dan FPS valid.

Rekomendasi:

- gunakan temp silent path seperti sekarang;
- render frame dengan OpenCV;
- merge audio via FFmpeg seperti sekarang.

### 6. Jangan Rusak Non-Vertical Mode

Jika `aspect_ratio != "vertical"`, behavior tetap copy input ke output seperti sekarang.

### 7. Logging dan Progress

Tetap print log yang mudah dibaca:

```text
Face detector loaded: MediaPipe Face Detection
Video info: 1920x1080 @ 30.0fps, 2310 frames
STATUS: processing_crop
PROGRESS: 25
...
STATUS: merging_audio
PROGRESS: 50
SUCCESS: Face tracking & cropping completed.
```

Jika fallback ke Haar Cascade:

```text
WARNING: MediaPipe unavailable or failed. Falling back to OpenCV Haar Cascade.
```

Jika fallback ke center crop:

```text
WARNING: No reliable face detections. Using stable center crop fallback.
```

## Parameter Awal Yang Disarankan

Gunakan konstanta yang mudah dituning di atas file:

```python
DETECT_EVERY_N = 3
MIN_FACE_CONFIDENCE = 0.55
DEAD_ZONE_RATIO = 0.04
MAX_MOVE_RATIO_PER_FRAME = 0.012
LOST_HOLD_SECONDS = 1.25
SWITCH_HOLD_SECONDS = 0.75
SWITCH_SCORE_MARGIN = 0.25
SMOOTHING_ALPHA_STABLE = 0.055
SMOOTHING_ALPHA_DYNAMIC = 0.10
```

Untuk mode `stable`, gunakan smoothing lebih lambat dan switch lebih sulit.

Untuk mode `dynamic`, gunakan smoothing sedikit lebih cepat dan switch hold lebih pendek, tapi tetap jangan langsung pindah target hanya karena wajah lain lebih besar satu frame.

## Acceptance Criteria

Fitur dianggap selesai kalau:

1. Pipeline Laravel tetap jalan tanpa perubahan cara memanggil job.
2. `face_tracker.py <input> <output> vertical` tetap menghasilkan MP4 vertical 9:16.
3. Crop tidak jitter saat wajah bergerak sedikit.
4. Crop tidak loncat mendadak antar orang dalam video podcast.
5. Saat wajah hilang sementara, crop tetap stabil.
6. Audio tetap ada setelah proses merge.
7. Subtitle pipeline setelah face tracking tetap berjalan.
8. Debug mode bisa dinyalakan dari env tanpa mengganggu mode normal.

## Testing Manual

Jalankan test langsung:

```bash
cd /opt/lampp/htdocs/ai-clipper

python_venv/bin/python3 app/PythonScripts/face_tracker.py \
  /path/to/input-podcast.mp4 \
  /tmp/reframed-test.mp4 \
  vertical
```

Cek output:

```bash
ffprobe -v error -show_entries stream=codec_type,width,height -of table /tmp/reframed-test.mp4
```

Test debug:

```bash
FACE_TRACKING_DEBUG=1 python_venv/bin/python3 app/PythonScripts/face_tracker.py \
  /path/to/input-podcast.mp4 \
  /tmp/reframed-test.mp4 \
  vertical
```

Cek manual dengan membuka video:

- framing smooth;
- tidak ada loncat target yang kasar;
- wajah utama tetap masuk frame;
- kalau target berpindah, transisi terasa pelan;
- audio tidak hilang.

## Catatan Penting

Jangan langsung implement active speaker detection berbasis audio diarization untuk update ini. Itu bisa jadi fitur lanjutan. Fokus update sekarang adalah stabilisasi tracking visual: MediaPipe detector, target lock, hysteresis, outlier rejection, dead-zone, movement limit, dan smooth crop path.

