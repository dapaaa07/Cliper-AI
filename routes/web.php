<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\VideoController;

Route::get('/', [VideoController::class, 'index'])->name('dashboard');
Route::post('/videos/parse', [VideoController::class, 'parse'])->name('videos.parse');
Route::get('/videos/{video}/select', [VideoController::class, 'select'])->name('videos.select');
Route::post('/videos/{video}/clips', [VideoController::class, 'storeClips'])->name('videos.storeClips');
Route::get('/videos/{video}/processing', [VideoController::class, 'processing'])->name('videos.processing');
Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
Route::get('/videos/{video}/results', [VideoController::class, 'results'])->name('videos.results');
Route::delete('/videos/{video}', [VideoController::class, 'destroy'])->name('videos.destroy');
