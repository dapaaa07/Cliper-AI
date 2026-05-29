<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Video;
use App\Models\Clip;
use App\Jobs\ProcessVideoClipJob;
use Symfony\Component\Process\Process;

class VideoController extends Controller
{
    /**
     * Show the landing page / dashboard.
     */
    public function index()
    {
        $history = Video::withCount('clips')->latest()->get();
        return view('dashboard', compact('history'));
    }

    /**
     * Parse YouTube link and save video info.
     */
    public function parse(Request $request)
    {
        $request->validate([
            'youtube_url' => 'required|url',
        ]);

        $url = $request->input('youtube_url');

        // Extract YouTube Video ID
        preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match);
        $youtubeId = $match[1] ?? null;

        if (!$youtubeId) {
            return back()->withErrors(['youtube_url' => 'Link YouTube tidak valid. Harap masukkan link video YouTube yang benar.']);
        }

        // Fetch Metadata via yt-dlp (with local fallback if yt-dlp is not installed or fails)
        $title = "Video YouTube";
        $duration = 600; // default 10 minutes
        $thumbnail = "https://img.youtube.com/vi/{$youtubeId}/maxresdefault.jpg";

        try {
            // Run yt-dlp --dump-json --skip-download
            $process = new Process(['yt-dlp', '--dump-json', '--skip-download', $url]);
            $process->run();

            if ($process->isSuccessful()) {
                $metadata = json_decode($process->getOutput(), true);
                $title = $metadata['title'] ?? $title;
                $duration = isset($metadata['duration']) ? intval($metadata['duration']) : $duration;
                $thumbnail = $metadata['thumbnail'] ?? $thumbnail;
            }
        } catch (\Exception $e) {
            // Fallback to defaults on error
        }

        // Save or update video record
        $video = Video::updateOrCreate(
            ['youtube_url' => $url],
            [
                'title' => $title,
                'thumbnail' => $thumbnail,
                'duration' => $duration,
                'status' => 'ready',
            ]
        );

        return redirect()->route('videos.select', $video->id);
    }

    /**
     * Show clip selector screen.
     */
    public function select(Video $video)
    {
        return view('clip_selector', compact('video'));
    }

    /**
     * Store requested clips and dispatch processing jobs.
     */
    public function storeClips(Request $request, Video $video)
    {
        $request->validate([
            'clips' => 'required|array|min:1',
            'clips.*.start_time' => 'required|string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'clips.*.end_time' => 'required|string|regex:/^\d{2}:\d{2}:\d{2}$/',
            'clips.*.aspect_ratio' => 'required|string|in:vertical,horizontal',
        ]);

        $createdClips = [];

        foreach ($request->input('clips') as $clipData) {
            $clip = Clip::create([
                'video_id' => $video->id,
                'start_time' => $clipData['start_time'],
                'end_time' => $clipData['end_time'],
                'aspect_ratio' => $clipData['aspect_ratio'],
                'status' => 'pending',
                'progress' => 0,
            ]);

            $createdClips[] = $clip;

            // Dispatch Laravel Job to process clip asynchronously
            ProcessVideoClipJob::dispatch($clip);
        }

        return redirect()->route('videos.processing', $video->id);
    }

    /**
     * Show real-time progress of clips processing.
     */
    public function processing(Video $video)
    {
        return view('processing', compact('video'));
    }

    /**
     * Get JSON status for polling.
     */
    public function status(Video $video)
    {
        $clips = $video->clips()->get();
        return response()->json([
            'video' => $video,
            'clips' => $clips,
        ]);
    }

    /**
     * Show results of finished clips.
     */
    public function results(Video $video)
    {
        $clips = $video->clips()->where('status', 'completed')->get();
        return view('results', compact('video', 'clips'));
    }

    /**
     * Delete video and all associated clips and their files.
     */
    public function destroy(Video $video)
    {
        foreach ($video->clips as $clip) {
            // Delete physical video file
            if ($clip->video_path) {
                $fullVideoPath = storage_path('app/public/' . $clip->video_path);
                if (file_exists($fullVideoPath)) {
                    unlink($fullVideoPath);
                }
            }

            // Delete physical subtitle files (SRT, VTT, TXT)
            if ($clip->subtitle_path) {
                $fullSrtPath = storage_path('app/public/' . $clip->subtitle_path);
                if (file_exists($fullSrtPath)) {
                    unlink($fullSrtPath);
                }

                $fullVttPath = str_replace('.srt', '.vtt', $fullSrtPath);
                if (file_exists($fullVttPath)) {
                    unlink($fullVttPath);
                }

                $fullTxtPath = str_replace('.srt', '.txt', $fullSrtPath);
                if (file_exists($fullTxtPath)) {
                    unlink($fullTxtPath);
                }
            }
        }

        // Delete records
        $video->clips()->delete();
        $video->delete();

        return redirect()->route('dashboard')->with('success', 'Riwayat video dan seluruh klip terkait berhasil dihapus dari sistem.');
    }
}
