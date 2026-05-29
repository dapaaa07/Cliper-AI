@extends('layouts.app')

@section('title', 'Sedang Memproses Klip - Clipper.AI')

@section('styles')
<style>
    .processing-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        transition: border-color 0.3s;
    }
    .processing-card.active {
        border-color: var(--primary);
        box-shadow: 0 0 15px rgba(139, 92, 246, 0.05);
    }
    .processing-grid {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    @media (min-width: 768px) {
        .processing-grid {
            display: grid;
            grid-template-columns: 200px 1fr 150px;
            align-items: center;
            gap: 2rem;
        }
    }
</style>
@endsection

@section('content')
<div style="max-width: 800px; margin: 2rem auto;">
    <div style="text-align: center; margin-bottom: 3rem;">
        <div style="display: inline-flex; justify-content: center; margin-bottom: 1.5rem;">
            <div class="spinner"></div>
        </div>
        <h1 style="font-size: 2.2rem; margin-bottom: 0.5rem;">AI Sedang Memproses Klip Anda</h1>
        <p style="color: var(--text-muted); font-size: 1.05rem;">
            Kami sedang mengunduh, melacak wajah (reframe), dan menulis transkripsi subtitle menggunakan AI. Halaman ini akan diperbarui otomatis.
        </p>
    </div>

    <!-- Processing Items Container -->
    <div id="clips-list">
        <!-- Javascript will populate this -->
        <div class="glass-panel" style="text-align: center; padding: 3rem;">
            <p style="color: var(--text-muted);">Memuat antrean pemrosesan...</p>
        </div>
    </div>

    <!-- Action Button (Hidden by default, shown when complete) -->
    <div id="action-container" style="text-align: center; margin-top: 3rem; display: none; animation: fadeIn 0.4s ease-out forwards;">
        <a href="{{ route('videos.results', $video->id) }}" class="btn-primary" style="padding: 1.25rem 3rem; font-size: 1.1rem; box-shadow: 0 4px 20px rgba(6, 182, 212, 0.3); background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);">
            <span>Lihat Hasil Klip AI Anda</span>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
        </a>
    </div>
</div>
@endsection

@section('scripts')
<script>
    const videoId = "{{ $video->id }}";
    const statusUrl = "{{ route('videos.status', $video->id) }}";
    const clipsList = document.getElementById('clips-list');
    const actionContainer = document.getElementById('action-container');

    function getStatusLabel(status) {
        switch (status) {
            case 'pending': return '<span class="badge badge-pending">Antrean</span>';
            case 'downloading': return '<span class="badge badge-processing">Mengunduh...</span>';
            case 'processing_crop': return '<span class="badge badge-processing">AI Face Crop</span>';
            case 'merging_audio': return '<span class="badge badge-processing">Audio Rendering</span>';
            case 'transcribing': return '<span class="badge badge-processing">AI Subtitling</span>';
            case 'completed': return '<span class="badge badge-success">Selesai 🎉</span>';
            case 'failed': return '<span class="badge badge-danger">Gagal ❌</span>';
            default: return `<span class="badge badge-pending">${status}</span>`;
        }
    }

    function getStatusDescription(status) {
        switch (status) {
            case 'pending': return 'Menunggu antrean server...';
            case 'downloading': return 'Mengunduh potongan video YouTube...';
            case 'processing_crop': return 'MediaPipe AI mendeteksi & melacak wajah (9:16)...';
            case 'merging_audio': return 'Menyatukan video re-framed dengan audio asli...';
            case 'transcribing': return 'Groq Whisper mentranskripsi percakapan ke teks...';
            case 'completed': return 'Klip video & subtitle berhasil diproduksi!';
            case 'failed': return 'Terjadi kesalahan saat memproses klip ini.';
            default: return 'Sedang memproses...';
        }
    }

    async function checkStatus() {
        try {
            const response = await fetch(statusUrl);
            const data = await response.json();
            
            if (data.clips && data.clips.length > 0) {
                let html = '';
                let allFinished = true;

                data.clips.forEach((clip, index) => {
                    const isActive = ['downloading', 'processing_crop', 'merging_audio', 'transcribing'].includes(clip.status);
                    const isFinished = ['completed', 'failed'].includes(clip.status);
                    
                    if (!isFinished) {
                        allFinished = false;
                    }

                    let errorHtml = '';
                    if (clip.status === 'failed' && clip.error_message) {
                        errorHtml = `
                            <div style="margin-top: 1rem; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 8px; padding: 0.75rem 1rem; font-family: monospace; font-size: 0.8rem; color: #f87171; white-space: pre-wrap; word-break: break-all; text-align: left; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);">
                                <strong>⚠️ Detail Eror (Debug Console):</strong><br>${clip.error_message}
                            </div>
                        `;
                    }

                    html += `
                        <div class="processing-card ${isActive ? 'active' : ''}">
                            <div class="processing-grid">
                                <div>
                                    <h4 style="font-size: 1.05rem; margin-bottom: 0.25rem;">Klip #${index + 1}</h4>
                                    <p style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">
                                        ⏱️ ${clip.start_time} - ${clip.end_time}<br>
                                        📐 Aspect: ${clip.aspect_ratio === 'vertical' ? 'Vertikal (9:16) 👤' : 'Horizontal (16:9) 📺'}
                                    </p>
                                </div>
                                <div style="margin: 1rem 0;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.5rem;">
                                        <span style="color: var(--text-muted); font-weight: 500;">${getStatusDescription(clip.status)}</span>
                                        <span style="font-weight: bold; color: ${clip.status === 'failed' ? '#ef4444' : 'var(--secondary)'}">${clip.progress}%</span>
                                    </div>
                                    <div class="progress-container">
                                        <div class="progress-bar" style="width: ${clip.progress}%; ${clip.status === 'failed' ? 'background: #ef4444; box-shadow: 0 0 10px rgba(239,68,68,0.5);' : ''}"></div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    ${getStatusLabel(clip.status)}
                                </div>
                            </div>
                            ${errorHtml}
                        </div>
                    `;
                });

                clipsList.innerHTML = html;

                if (allFinished) {
                    actionContainer.style.display = 'block';
                    // Stop polling
                    clearInterval(pollInterval);
                }
            }
        } catch (error) {
            console.error("Gagal melakukan polling status:", error);
        }
    }

    // Poll status immediately and then every 2 seconds
    checkStatus();
    const pollInterval = setInterval(checkStatus, 2000);
</script>
@endsection
