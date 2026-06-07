@extends('layouts.app')

@section('title', 'Auto Collect Caption References')

@section('content')
<div style="max-width: 1000px; margin: 0 auto;">
    <div style="margin-bottom: 3rem;">
        <h1 style="font-size: 2.2rem; margin-bottom: 0.5rem;">Caption Reference Collector</h1>
        <p style="color: var(--text-muted);">Kumpulkan gaya penulisan caption dari TikTok & YouTube Shorts agar AI Anda bisa belajar dan menirunya.</p>
    </div>

    @if(session('success'))
        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
            {{ session('error') }}
        </div>
    @endif

    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
        <!-- URLs Form -->
        <div class="glass-panel" style="padding: 2rem; border-radius: 12px; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.05);">
            <h2 style="font-size: 1.4rem; margin-bottom: 1rem;">1. Collect dari Daftar URL</h2>
            <form action="{{ route('caption-references.collect.urls') }}" method="POST">
                @csrf
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Paste URL YouTube Shorts / TikTok (Satu per baris)</label>
                    <textarea name="urls" rows="6" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 1rem; resize: vertical;" placeholder="https://www.youtube.com/shorts/...&#10;https://www.tiktok.com/@user/video/..."></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Kategori AI</label>
                        <input type="text" name="category" value="clip" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Bahasa</label>
                        <input type="text" name="language" value="id" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;">Mulai Collect dari URLs</button>
            </form>
        </div>

        <!-- YouTube Search Form -->
        <div class="glass-panel" style="padding: 2rem; border-radius: 12px; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.05);">
            <h2 style="font-size: 1.4rem; margin-bottom: 1rem;">2. Collect dari Pencarian YouTube</h2>
            <p style="color: var(--text-muted); margin-bottom: 1rem; font-size: 0.9rem;">Membutuhkan <code>YOUTUBE_DATA_API_KEY</code> di .env</p>
            <form action="{{ route('caption-references.collect.youtubeSearch') }}" method="POST">
                @csrf
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Kata Kunci Pencarian</label>
                    <input type="text" name="query" placeholder="Misal: podcast indonesia shorts lucu" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Max Results</label>
                        <input type="number" name="max" value="25" min="1" max="50" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Kategori AI</label>
                        <input type="text" name="category" value="clip" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Bahasa</label>
                        <input type="text" name="language" value="id" style="width: 100%; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-radius: 8px; padding: 0.75rem;">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;">Mulai Collect dari Pencarian YouTube</button>
            </form>
        </div>
    </div>

    <!-- Recent Collections -->
    <div style="margin-top: 4rem;">
        <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem;">Hasil Terakhir Terkumpul (Top 10)</h2>
        @if(count($recentReferences) > 0)
            <div style="display: grid; gap: 1rem;">
                @foreach($recentReferences as $ref)
                    <div style="padding: 1.5rem; border-radius: 12px; background: rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.05);">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="font-weight: bold; color: var(--primary);">{{ ucfirst(str_replace('_', ' ', $ref->platform)) }}</span>
                            <span style="color: #10b981;">Score: {{ $ref->quality_score }}</span>
                        </div>
                        <p style="font-style: italic; color: var(--text-muted); margin-bottom: 1rem;">"{{ $ref->hook_example }}"</p>
                        <div style="font-size: 0.85rem; color: rgba(255,255,255,0.5);">{{ $ref->hashtags_example }}</div>
                        <div style="font-size: 0.75rem; color: rgba(255,255,255,0.3); margin-top: 0.5rem;">Source: <a href="{{ $ref->source_url }}" target="_blank" style="color: var(--primary);">{{ Str::limit($ref->source_url, 50) }}</a></div>
                    </div>
                @endforeach
            </div>
        @else
            <div style="padding: 3rem; text-align: center; border-radius: 12px; background: rgba(0, 0, 0, 0.2); border: 1px dashed rgba(255, 255, 255, 0.1); color: var(--text-muted);">
                Belum ada referensi caption yang terkumpul.
            </div>
        @endif
    </div>
</div>
@endsection
