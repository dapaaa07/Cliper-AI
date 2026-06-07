@extends('layouts.app')

@section('title', 'Klip AI Siap Diunduh - Clipper.AI')

@section('styles')
<style>
    .result-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 3rem;
    }
    .result-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 20px;
        padding: 2rem;
        display: grid;
        grid-template-columns: 1fr;
        gap: 2rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    @media (min-width: 900px) {
        .result-card {
            grid-template-columns: 1fr 1fr;
        }
    }
    .video-container {
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        background: #000;
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .video-container-vertical {
        aspect-ratio: 9/16;
        max-height: 500px;
        margin: 0 auto;
        width: 100%;
        max-width: 281px;
    }
    .video-container-horizontal {
        aspect-ratio: 16/9;
        width: 100%;
    }
    .transcript-box {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 10px;
        padding: 1.25rem;
        max-height: 200px;
        overflow-y: auto;
        font-size: 0.9rem;
        line-height: 1.6;
        color: var(--text-muted);
        position: relative;
    }
    .copy-btn {
        position: absolute;
        top: 8px;
        right: 8px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        color: var(--text-main);
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .copy-btn:hover {
        background: var(--secondary);
        border-color: var(--secondary);
    }
</style>
@endsection

@section('content')
<div style="max-width: 1000px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 4rem;">
        <h1 style="font-size: 2.8rem; margin-bottom: 0.75rem;">Klip AI Anda Telah Siap! 🎉</h1>
        <p style="color: var(--text-muted); font-size: 1.1rem; max-width: 600px; margin: 0 auto;">
            Berikut adalah klip video pendek hasil auto-reframe bertenaga AI beserta file subtitle lengkapnya.
        </p>
    </div>

    <div class="result-grid">
        @forelse($clips as $index => $clip)
            <div class="result-card">
                <!-- Left: Video Player -->
                <div style="display: flex; flex-direction: column; justify-content: center;">
                    <div class="video-container {{ $clip->aspect_ratio === 'vertical' ? 'video-container-vertical' : 'video-container-horizontal' }}">
                        <video controls style="width: 100%; height: 100%; object-fit: cover;">
                            <source src="{{ asset('storage/' . $clip->video_path) }}" type="video/mp4">
                            Browser Anda tidak mendukung tag video HTML5.
                        </video>
                    </div>
                </div>

                <!-- Right: Details, Subtitles, and Actions -->
                <div style="display: flex; flex-direction: column; justify-content: space-between; gap: 1.5rem;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <h2 style="font-size: 1.6rem;">Klip Pilihan #{{ $index + 1 }}</h2>
                            <span class="badge badge-success">Selesai</span>
                        </div>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem;">
                            ⏱️ Durasi: <strong>{{ $clip->start_time }}</strong> s/d <strong>{{ $clip->end_time }}</strong><br>
                            📐 Aspek Rasio: <strong>{{ $clip->aspect_ratio === 'vertical' ? 'Vertikal (9:16)' : 'Horizontal (16:9)' }}</strong>
                        </p>

                        <!-- Transkripsi Teks -->
                        <div style="position: relative;">
                            <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--text-main);">Transkripsi Teks (AI)</h4>
                            <div class="transcript-box">
                                @php
                                    $txtFile = str_replace('.srt', '.txt', storage_path('app/public/' . $clip->subtitle_path));
                                    $transcription = file_exists($txtFile) ? file_get_contents($txtFile) : 'Transkripsi tidak tersedia.';
                                @endphp
                                <span id="transcription-text-{{ $clip->id }}">{{ $transcription }}</span>
                                <button class="copy-btn" onclick="copyTranscription('{{ $clip->id }}')">Salin</button>
                            </div>
                        </div>
                    </div>

                    <!-- Action Downloads buttons -->
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <a href="{{ asset('storage/' . $clip->video_path) }}" download class="btn-primary" style="width: 100%;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                            <span>Unduh Video Klip (MP4)</span>
                        </a>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                            <a href="{{ asset('storage/' . $clip->subtitle_path) }}" download class="btn-secondary" style="padding: 0.75rem;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                <span style="font-size: 0.85rem;">Unduh SRT</span>
                            </a>
                            <a href="{{ asset('storage/' . str_replace('.srt', '.vtt', $clip->subtitle_path)) }}" download class="btn-secondary" style="padding: 0.75rem;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                <span style="font-size: 0.85rem;">Unduh VTT</span>
                            </a>
                        </div>

                        <!-- YouTube Upload Section -->
                        <div class="youtube-upload-section" 
                             data-clip-id="{{ $clip->id }}" 
                             data-status-url="{{ route('clips.youtube.status', $clip->id) }}"
                             data-current-status="{{ $clip->youtube_upload_status }}"
                             style="margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1rem;">
                            
                            <div class="youtube-status-container">
                                @if($clip->youtube_upload_status === 'uploaded' && $clip->youtube_url)
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                        <a href="{{ $clip->youtube_url }}" target="_blank" rel="noopener" class="btn-primary" style="background: #ef4444; border-color: #ef4444; width: 100%;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path>
                                                <polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon>
                                            </svg>
                                            <span>Lihat di YouTube</span>
                                        </a>
                                        <div style="font-size: 0.85rem; color: #10b981; text-align: center;">✓ Berhasil dipublish public</div>
                                    </div>
                                @elseif($clip->youtube_upload_status === 'scheduled')
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem; text-align: center;">
                                        <div style="padding: 0.75rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px;">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.5rem;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                            <div style="color: #3b82f6; font-weight: bold; font-size: 0.95rem;">Terjadwal Public</div>
                                            <div style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem;">Pada: {{ $clip->youtube_scheduled_for_local }}</div>
                                        </div>
                                    </div>
                                @elseif(in_array($clip->youtube_upload_status, ['queued', 'generating_metadata', 'uploading']))
                                    <button disabled class="btn-secondary" style="width: 100%; opacity: 0.7; cursor: not-allowed;">
                                        <span class="spinner" style="width:16px; height:16px; border-width:2px; margin-right: 8px; display:inline-block;"></span>
                                        @if($clip->youtube_upload_status === 'queued')
                                            <span>Menunggu queue upload...</span>
                                        @elseif($clip->youtube_upload_status === 'generating_metadata')
                                            <span>Membuat caption AI...</span>
                                        @elseif($clip->youtube_upload_status === 'uploading')
                                            <span>Mengupload ke YouTube...</span>
                                        @else
                                            <span>Memproses...</span>
                                        @endif
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('clips.youtube.upload', $clip->id) }}">
                                        @csrf
                                        <button type="submit" class="btn-secondary" style="width: 100%; border-color: #ef4444; color: #ef4444;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;">
                                                <path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path>
                                                <polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon>
                                            </svg>
                                            <span>Upload ke YouTube</span>
                                        </button>
                                    </form>
                                @endif

                                @if($clip->youtube_upload_status === 'failed')
                                    <div style="margin-top: 0.5rem; font-size: 0.85rem; color: #ef4444; background: rgba(239,68,68,0.1); padding: 0.5rem; border-radius: 4px;">
                                        Gagal: {{ $clip->youtube_error_message }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="glass-panel" style="text-align: center; padding: 4rem;">
                <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 2rem;">Tidak ada klip video yang berhasil diproses.</p>
                <a href="{{ route('dashboard') }}" class="btn-primary">Kembali ke Dashboard</a>
            </div>
        @endforelse
    </div>

    @if(count($clips) > 0)
        <div style="text-align: center; margin-top: 5rem;">
            <a href="{{ route('dashboard') }}" class="btn-secondary" style="padding: 1rem 3rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <span>Buat Klip Video Baru</span>
            </a>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    function copyTranscription(clipId) {
        const textElement = document.getElementById(`transcription-text-${clipId}`);
        if (!textElement) return;

        const text = textElement.textContent;
        navigator.clipboard.writeText(text).then(() => {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = 'Tersalin!';
            btn.style.background = '#10b981';
            btn.style.borderColor = '#10b981';

            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '';
                btn.style.borderColor = '';
            }, 2000);
        }).catch(err => {
            console.error('Gagal menyalin teks:', err);
        });
    }

    // YouTube Status Polling
    document.addEventListener('DOMContentLoaded', function() {
        const activeStatuses = ['queued', 'generating_metadata', 'uploading'];
        
        document.querySelectorAll('.youtube-upload-section').forEach(section => {
            const statusUrl = section.getAttribute('data-status-url');
            let currentStatus = section.getAttribute('data-current-status');
            
            if (activeStatuses.includes(currentStatus)) {
                pollYouTubeStatus(section, statusUrl, currentStatus);
            }
        });

        function pollYouTubeStatus(section, url, initialStatus) {
            const interval = setInterval(() => {
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.youtube_upload_status !== initialStatus) {
                            // If status changed, just reload the page for now to get fresh UI.
                            // A more robust way would be to rebuild DOM, but reload is safe and quick.
                            window.location.reload();
                        }
                        if (!activeStatuses.includes(data.youtube_upload_status)) {
                            clearInterval(interval);
                        }
                    })
                    .catch(err => console.error('Polling error:', err));
            }, 5000); // 5 seconds
        }
    });
</script>
@endsection
