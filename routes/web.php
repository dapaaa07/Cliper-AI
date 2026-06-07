<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\VideoController;

Route::get('/', [VideoController::class, 'index'])->name('dashboard');
Route::post('/videos/parse', [VideoController::class, 'parse'])->name('videos.parse');
Route::get('/videos/{video}/select', [VideoController::class, 'select'])->name('videos.select');
Route::post('/videos/{video}/auto-analyze', [VideoController::class, 'autoAnalyze'])->name('videos.autoAnalyze');
Route::post('/videos/{video}/clips', [VideoController::class, 'storeClips'])->name('videos.storeClips');
Route::get('/videos/{video}/processing', [VideoController::class, 'processing'])->name('videos.processing');
Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
Route::get('/videos/{video}/results', [VideoController::class, 'results'])->name('videos.results');
Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');

use App\Http\Controllers\YouTubeController;
Route::get('/youtube/oauth/redirect', [YouTubeController::class, 'redirect'])->name('youtube.oauth.redirect');
Route::get('/youtube/oauth/callback', [YouTubeController::class, 'callback'])->name('youtube.oauth.callback');
Route::post('/clips/{clip}/youtube/upload', [YouTubeController::class, 'upload'])->name('clips.youtube.upload');
Route::get('/clips/{clip}/youtube/status', [YouTubeController::class, 'status'])->name('clips.youtube.status');

use App\Http\Controllers\CaptionReferenceCollectorController;
Route::get('/caption-references/collect', [CaptionReferenceCollectorController::class, 'index'])->name('caption-references.collect.index');
Route::post('/caption-references/collect/urls', [CaptionReferenceCollectorController::class, 'collectUrls'])->name('caption-references.collect.urls');
Route::post('/caption-references/collect/youtube-search', [CaptionReferenceCollectorController::class, 'collectYouTubeSearch'])->name('caption-references.collect.youtubeSearch');
