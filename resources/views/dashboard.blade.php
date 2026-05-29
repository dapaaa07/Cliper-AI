@extends('layouts.app')

@section('title', 'Clipper.AI - Buat Klip Shorts Otomatis Bertenaga AI')

@section('content')
<div style="max-width: 650px; margin: 4rem auto; text-align: center;">
    <div style="margin-bottom: 3rem;">
        <h1 style="font-size: 3rem; line-height: 1.1; margin-bottom: 1rem;">
            Ubah Video Panjang Jadi <span style="background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Shorts Vertikal</span> AI
        </h1>
        <p style="color: var(--text-muted); font-size: 1.125rem;">
            Tempelkan link YouTube, pilih durasi klip, dan biarkan AI kami melacak wajah pembicara serta membuat transkripsi subtitle instan gratis.
        </p>
    </div>

    <!-- Success Alerts -->
    @if(session('success'))
        <div class="glass-panel" style="border-color: rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.05); padding: 1.25rem; border-radius: 12px; margin-bottom: 2rem; text-align: left;">
            <p style="color: #10b981; font-weight: 500; font-size: 0.95rem; margin: 0;">
                ✓ {{ session('success') }}
            </p>
        </div>
    @endif

    <!-- Error Alerts -->
    @if($errors->any())
        <div class="glass-panel" style="border-color: rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.05); padding: 1.25rem; border-radius: 12px; margin-bottom: 2rem; text-align: left;">
            <h4 style="color: #ef4444; margin-bottom: 0.5rem; font-size: 1rem;">Ada kesalahan pengisian:</h4>
            <ul style="color: var(--text-muted); padding-left: 1.25rem; font-size: 0.9rem; line-height: 1.5;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Input Form -->
    <div class="glass-panel">
        <form action="{{ route('videos.parse') }}" method="POST">
            @csrf
            <div style="display: flex; flex-direction: column; gap: 1.5rem; text-align: left;">
                <div>
                    <label for="youtube_url" style="font-family: var(--font-heading); font-weight: 500; display: block; margin-bottom: 0.75rem; color: var(--text-main);">
                        Masukkan Link Video YouTube
                    </label>
                    <input 
                        type="url" 
                        id="youtube_url" 
                        name="youtube_url" 
                        class="glass-input" 
                        placeholder="https://www.youtube.com/watch?v=..." 
                        required 
                        value="{{ old('youtube_url') }}"
                        autocomplete="off"
                    >
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;">
                    <span>Mulai Proses Video</span>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </div>
        </form>
    </div>

    <!-- Small Features Badges -->
    <div style="display: flex; justify-content: center; gap: 2rem; margin-top: 4rem; opacity: 0.8;">
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--secondary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                <circle cx="12" cy="13" r="4"></circle>
            </svg>
            <span style="font-size: 0.85rem; font-weight: 600;">MediaPipe Auto-Reframe</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--secondary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                <line x1="12" y1="19" x2="12" y2="23"></line>
                <line x1="8" y1="23" x2="16" y2="23"></line>
            </svg>
            <span style="font-size: 0.85rem; font-weight: 600;">Groq Whisper Subtitles</span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--secondary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span style="font-size: 0.85rem; font-weight: 600;">100% Gratis & Cepat</span>
        </div>
    </div>
</div>

<!-- History Section -->
@if(count($history) > 0)
    <div style="max-width: 800px; margin: 4rem auto 2rem auto; text-align: left;">
        <h2 style="font-size: 1.8rem; margin-bottom: 2rem; text-align: center;">Riwayat Pemrosesan Video</h2>
        
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            @foreach($history as $hist)
                <div class="glass-panel" style="padding: 1.25rem; border-color: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; gap: 1.25rem; align-items: center; width: 100%; flex-wrap: wrap;">
                        <!-- Video Thumbnail -->
                        <div style="width: 140px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                            <img src="{{ $hist->thumbnail }}" style="width: 100%; display: block;" alt="Thumbnail">
                        </div>

                        <!-- Details -->
                        <div style="flex-grow: 1; min-width: 200px;">
                            <h4 style="font-size: 1.05rem; line-height: 1.4; margin-bottom: 0.35rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                {{ $hist->title }}
                            </h4>
                            <p style="color: var(--text-muted); font-size: 0.8rem; font-weight: 500;">
                                ⏱️ Durasi: {{ sprintf('%02d:%02d', floor($hist->duration / 60), $hist->duration % 60) }} | 🎞️ Jumlah Klip: {{ $hist->clips_count }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display: flex; gap: 0.75rem; width: 100%; justify-content: flex-end; flex-wrap: wrap; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem;">
                        <a href="{{ route('videos.select', $hist->id) }}" class="btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.8rem; height: 36px; border-radius: 8px;">
                            ➕ Buat Klip Baru
                        </a>

                        @if($hist->clips_count > 0)
                            <a href="{{ route('videos.results', $hist->id) }}" class="btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.8rem; height: 36px; border-radius: 8px; box-shadow: none; background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);">
                                👁️ Lihat Hasil Klip
                            </a>
                        @endif

                        <form action="{{ route('videos.destroy', $hist->id) }}" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus riwayat video ini beserta seluruh klip kustom dan filenya? Tindakan ini tidak bisa dibatalkan.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger" style="padding: 0.5rem 1rem; font-size: 0.8rem; height: 36px; border-radius: 8px;">
                                🗑️ Hapus
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
