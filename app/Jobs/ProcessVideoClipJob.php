<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\Clip;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class ProcessVideoClipJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * Video processing (download + transcode + face tracking + transcription)
     * can take several minutes for longer clips.
     */
    public $timeout = 0; // No limit for queue worker (command line --timeout still applies)

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public $failOnTimeout = true;

    public $clip;

    /**
     * Create a new job instance.
     */
    public function __construct(Clip $clip)
    {
        $this->clip = $clip;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->clip->update([
            'status' => 'downloading',
            'progress' => 5,
        ]);

        $scriptPath = base_path('app/PythonScripts/clipper_bot.py');
        $videoUrl = $this->clip->video->youtube_url;

        // Use Python from the local virtual environment (ensures all packages are available)
        $pythonPath = base_path('python_venv/bin/python3');
        if (!file_exists($pythonPath)) {
            $pythonPath = base_path('python_venv/bin/python'); // fallback if named 'python'
        }
        if (!file_exists($pythonPath)) {
            $pythonPath = 'python3'; // global fallback as absolute last resort
        }

        // Run Python process
        $process = new Process([
            $pythonPath,
            $scriptPath,
            $this->clip->id,
            $videoUrl,
            $this->clip->start_time,
            $this->clip->end_time,
            $this->clip->aspect_ratio,
        ]);

        // Pass Groq API Key and other configuration via env
        $process->setEnv([
            'GROQ_API_KEY' => env('GROQ_API_KEY'),
            'PYTHONUNBUFFERED' => '1', // Ensure real-time output flushing
        ]);

        $process->setTimeout(null); // No timeout for Python processing

        Log::info("Starting Python AI Clipper pipeline for Clip: " . $this->clip->id);

        $errorOutput = "";

        $process->run(function ($type, $buffer) use (&$errorOutput) {
            if ($type === Process::ERR) {
                Log::warning("Python Script Warning/Err: " . $buffer);
                $errorOutput .= $buffer;
            } else {
                // Parse stdout lines
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    // Check if it is a protocol command
                    if (str_starts_with($line, 'PROGRESS:') || 
                        str_starts_with($line, 'STATUS:') || 
                        str_starts_with($line, 'VIDEO_PATH:') || 
                        str_starts_with($line, 'SUBTITLE_PATH:')) {
                        
                        if (str_starts_with($line, 'PROGRESS:')) {
                            $progress = intval(substr($line, 9));
                            $this->clip->update(['progress' => $progress]);
                        } elseif (str_starts_with($line, 'STATUS:')) {
                            $status = trim(substr($line, 7));
                            $this->clip->update(['status' => $status]);
                        } elseif (str_starts_with($line, 'VIDEO_PATH:')) {
                            $videoPath = trim(substr($line, 11));
                            $this->clip->update(['video_path' => $videoPath]);
                        } elseif (str_starts_with($line, 'SUBTITLE_PATH:')) {
                            $subPath = trim(substr($line, 14));
                            $this->clip->update(['subtitle_path' => $subPath]);
                        }
                    } else {
                        // Any non-protocol line is a debug message or Python traceback
                        if (str_starts_with($line, 'ERROR:')) {
                            $errorOutput .= substr($line, 6) . "\n";
                        } else {
                            $errorOutput .= $line . "\n";
                        }
                    }
                }
            }
        });

        if ($process->isSuccessful()) {
            $this->clip->update([
                'status' => 'completed',
                'progress' => 100,
            ]);
            Log::info("Successfully completed AI pipeline for Clip: " . $this->clip->id);
        } else {
            // Get detailed error context
            $fullErrorMsg = trim($errorOutput);
            if (empty($fullErrorMsg)) {
                $fullErrorMsg = trim($process->getErrorOutput());
            }
            if (empty($fullErrorMsg)) {
                $fullErrorMsg = "Terjadi kesalahan yang tidak diketahui (Exit Code: " . $process->getExitCode() . ")";
            }

            $this->clip->update([
                'status' => 'failed',
                'progress' => 0,
                'error_message' => $fullErrorMsg,
            ]);
            Log::error("AI pipeline failed for Clip " . $this->clip->id . ". Error: " . $fullErrorMsg);
        }
    }
}
