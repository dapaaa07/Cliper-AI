@extends('layouts.app')

@section('title', 'Pilih Durasi Klip - Clipper.AI')

@section('styles')
<style>
    .selector-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
    }
    @media (min-width: 900px) {
        .selector-grid {
            grid-template-columns: 350px 1fr;
        }
    }
    .clip-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        position: relative;
        animation: fadeIn 0.3s ease-out forwards;
    }
    .clip-row-ratio {
        grid-column: span 2;
    }
    @media (min-width: 600px) {
        .clip-row {
            grid-template-columns: 1fr 1fr 1fr auto;
            align-items: flex-end;
        }
        .clip-row-ratio {
            grid-column: span 1;
        }
    }
    .remove-btn {
        margin-top: 1rem;
        width: 100%;
    }
    @media (min-width: 600px) {
        .remove-btn {
            margin-top: 0;
            width: auto;
        }
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>
@endsection

@section('content')
<div style="margin-bottom: 2rem;">
    <a href="{{ route('dashboard') }}" style="color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500; transition: color 0.2s;" onmouseover="this.style.color='#f3f4f6'" onmouseout="this.style.color='#9ca3af'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
        <span>Kembali ke Input URL</span>
    </a>
</div>

<div class="selector-grid">
    <!-- Left Column: Video Metadata Card -->
    <div>
        <div class="glass-panel" style="position: sticky; top: 120px; padding: 1.5rem;">
            <div style="position: relative; border-radius: 10px; overflow: hidden; margin-bottom: 1.25rem; border: 1px solid rgba(255,255,255,0.05); box-shadow: 0 4px 15px rgba(0,0,0,0.5);">
                <img src="{{ $video->thumbnail }}" alt="Thumbnail" style="width: 100%; display: block; object-fit: cover;">
                <div style="position: absolute; bottom: 8px; right: 8px; background: rgba(0,0,0,0.85); color: #fff; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; border: 1px solid rgba(255,255,255,0.1);">
                    {{ sprintf('%02d:%02d', floor($video->duration / 60), $video->duration % 60) }}
                </div>
            </div>
            
            <h3 style="font-size: 1.2rem; line-height: 1.4; margin-bottom: 0.75rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                {{ $video->title }}
            </h3>
            
            <p style="color: var(--text-muted); font-size: 0.85rem; word-break: break-all; opacity: 0.7; margin-bottom: 1.5rem;">
                {{ $video->youtube_url }}
            </p>

            <span class="badge badge-success" style="width: 100%; justify-content: center; font-size: 0.8rem; padding: 0.5rem 1rem;">Video Siap Diproses</span>
        </div>
    </div>

    <!-- Right Column: Clip Selection Form -->
    <div>
        <div class="glass-panel">
            <h2 style="font-size: 1.8rem; margin-bottom: 0.5rem;">Tentukan Rentang Durasi Klip</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
                Anda bisa membuat lebih dari 1 hasil klip dari video di samping. Format waktu adalah **Jam:Menit:Detik** (contoh: `00:01:30`).
            </p>

            <button type="button" id="auto-analyze-btn" class="btn-primary" style="width: 100%; margin-bottom: 2rem; background: linear-gradient(135deg, #8b5cf6, #3b82f6); border: none; font-weight: bold;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
                <span>✨ Analisa Momen Viral Otomatis dengan AI</span>
            </button>
            <div id="ai-loading" style="display: none; text-align: center; margin-bottom: 2rem; color: #a78bfa;">
                <svg class="spinner" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite; display: inline-block; vertical-align: middle;">
                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" stroke-opacity="0.75"></path>
                </svg>
                <span style="display: inline-block; vertical-align: middle; margin-left: 0.5rem; font-weight: 500;">AI sedang menganalisa video, mohon tunggu beberapa saat...</span>
                <style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>
            </div>

            <form action="{{ route('videos.storeClips', $video->id) }}" method="POST">
                @csrf
                
                <div id="clips-container">
                    <!-- Dynamic Clip Row (At least 1 by default) -->
                    <div class="clip-row" id="clip-row-0">
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Waktu Mulai (Start)
                            </label>
                            <input 
                                type="text" 
                                name="clips[0][start_time]" 
                                class="glass-input" 
                                placeholder="00:00:00" 
                                required
                                pattern="\d{2}:\d{2}:\d{2}"
                                value="00:00:00"
                            >
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Waktu Selesai (End)
                            </label>
                            <input 
                                type="text" 
                                name="clips[0][end_time]" 
                                class="glass-input" 
                                placeholder="00:00:30" 
                                required
                                pattern="\d{2}:\d{2}:\d{2}"
                                value="00:00:30"
                            >
                        </div>
                        <div class="clip-row-ratio">
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Aspek Rasio (AI Reframe)
                            </label>
                            <select name="clips[0][aspect_ratio]" class="glass-input" style="height: 52px; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%239ca3af%22 stroke-width=%222.5%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cpolyline points=%226 9 12 15 18 9%22%3E%3C/polyline%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.5rem;">
                                <option value="vertical">Vertikal (9:16) - AI Face Tracking 👤</option>
                                <option value="horizontal">Horizontal (16:9) - Standar 📺</option>
                            </select>
                        </div>
                        <div>
                            <!-- Placeholder button so structure aligns perfectly, hidden on first item -->
                            <button type="button" class="btn-danger remove-btn" style="opacity: 0; pointer-events: none; height: 52px; padding: 0 1.25rem;">Hapus</button>
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 2rem;">
                    <button type="button" id="add-clip-btn" class="btn-secondary" style="flex-grow: 1;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        <span>Tambah Klip Lainnya</span>
                    </button>
                    
                    <button type="submit" class="btn-primary" style="flex-grow: 1;">
                        <span>Ekspor Klip AI Sekarang</span>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    let clipIndex = 1;
    const clipsContainer = document.getElementById('clips-container');
    const addClipBtn = document.getElementById('add-clip-btn');

    addClipBtn.addEventListener('click', () => {
        const newRow = document.createElement('div');
        newRow.className = 'clip-row';
        newRow.id = `clip-row-${clipIndex}`;

        newRow.innerHTML = `
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                    Waktu Mulai (Start)
                </label>
                <input 
                    type="text" 
                    name="clips[${clipIndex}][start_time]" 
                    class="glass-input" 
                    placeholder="00:00:00" 
                    required
                    pattern="\\d{2}:\\d{2}:\\d{2}"
                    value="00:00:00"
                >
            </div>
            <div>
                <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                    Waktu Selesai (End)
                </label>
                <input 
                    type="text" 
                    name="clips[${clipIndex}][end_time]" 
                    class="glass-input" 
                    placeholder="00:00:30" 
                    required
                    pattern="\\d{2}:\\d{2}:\\d{2}"
                    value="00:00:30"
                >
            </div>
            <div class="clip-row-ratio">
                <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                    Aspek Rasio (AI Reframe)
                </label>
                <select name="clips[${clipIndex}][aspect_ratio]" class="glass-input" style="height: 52px; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%239ca3af%22 stroke-width=%222.5%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cpolyline points=%226 9 12 15 18 9%22%3E%3C/polyline%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.5rem;">
                    <option value="vertical">Vertikal (9:16) - AI Face Tracking 👤</option>
                    <option value="horizontal">Horizontal (16:9) - Standar 📺</option>
                </select>
            </div>
            <div>
                <button type="button" class="btn-danger remove-btn" onclick="removeRow(${clipIndex})" style="height: 52px; padding: 0 1.25rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                </button>
            </div>
        `;

        clipsContainer.appendChild(newRow);
        clipIndex++;
    });

    function removeRow(index) {
        const rowToRemove = document.getElementById(`clip-row-${index}`);
        if (rowToRemove) {
            rowToRemove.style.animation = 'fadeIn 0.2s ease-in reverse';
            setTimeout(() => {
                rowToRemove.remove();
            }, 180);
        }
    }

    const autoAnalyzeBtn = document.getElementById('auto-analyze-btn');
    const aiLoading = document.getElementById('ai-loading');

    autoAnalyzeBtn.addEventListener('click', async () => {
        autoAnalyzeBtn.style.display = 'none';
        aiLoading.style.display = 'block';

        try {
            const response = await fetch(`{{ route('videos.autoAnalyze', $video->id) }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });

            const result = await response.json();

            if (result.success && result.data && result.data.length > 0) {
                // Clear existing rows except the first one
                clipsContainer.innerHTML = '';
                clipIndex = 0;

                result.data.forEach((clipData, i) => {
                    const newRow = document.createElement('div');
                    newRow.className = 'clip-row';
                    newRow.id = `clip-row-${clipIndex}`;

                    newRow.innerHTML = `
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Waktu Mulai (Start)
                            </label>
                            <input 
                                type="text" 
                                name="clips[${clipIndex}][start_time]" 
                                class="glass-input" 
                                placeholder="00:00:00" 
                                required
                                pattern="\\d{2}:\\d{2}:\\d{2}"
                                value="${clipData.start_time}"
                            >
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Waktu Selesai (End)
                            </label>
                            <input 
                                type="text" 
                                name="clips[${clipIndex}][end_time]" 
                                class="glass-input" 
                                placeholder="00:00:30" 
                                required
                                pattern="\\d{2}:\\d{2}:\\d{2}"
                                value="${clipData.end_time}"
                            >
                        </div>
                        <div class="clip-row-ratio">
                            <label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 0.5rem; color: var(--text-muted);">
                                Aspek Rasio (AI Reframe)
                            </label>
                            <select name="clips[${clipIndex}][aspect_ratio]" class="glass-input" style="height: 52px; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%239ca3af%22 stroke-width=%222.5%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E%3Cpolyline points=%226 9 12 15 18 9%22%3E%3C/polyline%3E%3C/svg%3E'); background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.5rem;">
                                <option value="vertical">Vertikal (9:16) - AI Face Tracking 👤</option>
                                <option value="horizontal">Horizontal (16:9) - Standar 📺</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" class="btn-danger remove-btn" onclick="removeRow(${clipIndex})" style="${clipIndex === 0 ? 'opacity: 0; pointer-events: none;' : ''} height: 52px; padding: 0 1.25rem;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                </svg>
                            </button>
                        </div>
                        <div style="grid-column: 1 / -1; background: rgba(167, 139, 250, 0.1); border: 1px dashed rgba(167, 139, 250, 0.3); border-radius: 8px; padding: 0.75rem; font-size: 0.85rem; color: #d8b4fe; margin-top: 0.5rem;">
                            <strong>✨ AI Score: ${clipData.score}/10</strong><br>
                            ${clipData.reasoning}
                        </div>
                    `;

                    clipsContainer.appendChild(newRow);
                    clipIndex++;
                });
            } else {
                alert('Gagal menganalisa video atau tidak ada momen yang ditemukan. ' + (result.message || ''));
            }
        } catch (error) {
            console.error(error);
            alert('Terjadi kesalahan saat memproses analisa otomatis AI.');
        } finally {
            autoAnalyzeBtn.style.display = 'flex';
            aiLoading.style.display = 'none';
        }
    });
</script>
@endsection
