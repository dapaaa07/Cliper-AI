<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'AI Short Video Clipper - Automatisasi Konten Pintar')</title>
    
    <!-- Meta SEO -->
    <meta name="description" content="Potong dan re-frame video YouTube menjadi format vertikal TikTok/Shorts secara otomatis menggunakan AI face tracking dan transkripsi subtitle instan.">
    <meta name="keywords" content="video clipper, ai reframe, face tracking, auto-subtitles, youtube clip, tik tok shorts">
    <meta name="author" content="Antigravity AI">
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    
    @yield('styles')
</head>
<body>
    <!-- Background glowing blobs -->
    <div class="bg-blobs">
        <div class="blob blob-primary"></div>
        <div class="blob blob-secondary"></div>
    </div>

    <!-- Navigation -->
    <nav>
        <div class="nav-container">
            <a href="{{ route('dashboard') }}" class="logo-text">⚡ CLIPPER.AI</a>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <span class="badge badge-success">🤖 AI-POWERED</span>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="container">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer>
        <p>&copy; {{ date('Y') }} Clipper.AI - Alat Automatisasi Video Pendek Cerdas. Dibuat dengan Laravel + MediaPipe + Groq.</p>
    </footer>

    @yield('scripts')
</body>
</html>
