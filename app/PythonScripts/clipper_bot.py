#!/usr/bin/env python3
import sys
import os
import subprocess
from dotenv import load_dotenv

# Load env variables from Laravel .env
laravel_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../'))
load_dotenv(os.path.join(laravel_root, '.env'))

def process_clip(clip_id, youtube_url, start_time, end_time, aspect_ratio):
    """
    Main pipeline orchestrator. Downloads, reframes, and transcribes the clip.
    """
    print("STATUS: downloading")
    print("PROGRESS: 10")
    sys.stdout.flush()

    # Setup directories
    storage_dir = os.path.join(laravel_root, "storage/app/public/clips")
    os.makedirs(storage_dir, exist_ok=True)

    # File paths
    temp_raw_path = os.path.join(storage_dir, f"{clip_id}_raw.mp4")
    reframed_video_path = os.path.join(storage_dir, f"{clip_id}_reframed.mp4")
    final_video_path = os.path.join(storage_dir, f"{clip_id}.mp4")
    final_srt_path = os.path.join(storage_dir, f"{clip_id}.srt")

    # Step 1: Download segment using yt-dlp
    # IMPORTANT: Force H.264 (avc1) codec — AV1 is NOT supported by OpenCV on most systems.
    ytdlp_bin = "yt-dlp"
    venv_bin_dir = os.path.dirname(sys.executable)
    local_ytdlp = os.path.join(venv_bin_dir, "yt-dlp")
    if os.path.exists(local_ytdlp):
        ytdlp_bin = local_ytdlp
    else:
        rel_ytdlp = os.path.join(laravel_root, "python_venv/bin/yt-dlp")
        if os.path.exists(rel_ytdlp):
            ytdlp_bin = rel_ytdlp

    print(f"Downloading YouTube segment: {start_time} to {end_time} using {ytdlp_bin}...")
    sys.stdout.flush()

    cookies_args = []
    cookies_txt = os.path.join(laravel_root, "cookies.txt")
    if os.path.exists(cookies_txt):
        cookies_args = ["--cookies", cookies_txt]

    # Prefer H.264 (avc1) video codec, which is universally compatible with OpenCV
    yt_cmd = [
        ytdlp_bin,
        "--no-playlist",
        "--js-runtimes", "node",
        "--remote-components", "ejs:github",
    ] + cookies_args + [
        "--download-sections", f"*{start_time}-{end_time}",
        "-f", "bestvideo[vcodec^=avc1][ext=mp4]+bestaudio[ext=m4a]/bestvideo[vcodec^=avc1]+bestaudio/best[ext=mp4]/best",
        "--merge-output-format", "mp4",
        "--output", temp_raw_path,
        youtube_url
    ]

    result = subprocess.run(yt_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    
    if result.returncode != 0 or not os.path.exists(temp_raw_path) or os.path.getsize(temp_raw_path) == 0:
        print("Primary download (H.264 preferred) failed. Trying fallback...")
        if result.stderr:
            print(result.stderr.decode('utf-8', errors='replace'))
        sys.stdout.flush()
        
        # Fallback: download any format with sections
        fallback_cmd = [
            ytdlp_bin,
            "--no-playlist",
            "--js-runtimes", "node",
            "--remote-components", "ejs:github",
        ] + cookies_args + [
            "--download-sections", f"*{start_time}-{end_time}",
            "-f", "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best",
            "--merge-output-format", "mp4",
            "--output", temp_raw_path,
            youtube_url
        ]
        result_fallback = subprocess.run(fallback_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        
        if result_fallback.returncode != 0 or not os.path.exists(temp_raw_path):
            print("ERROR: All download attempts failed.")
            return False

    # Step 1b: Ensure the downloaded video is in H.264 format (transcode AV1/VP9 if needed)
    # This guarantees OpenCV can read every frame
    print("Verifying video codec compatibility...")
    sys.stdout.flush()
    
    import cv2
    test_cap = cv2.VideoCapture(temp_raw_path)
    test_ret, test_frame = test_cap.read()
    test_cap.release()
    
    if not test_ret:
        print("Downloaded video codec not readable by OpenCV. Transcoding to H.264...")
        sys.stdout.flush()
        transcoded_path = temp_raw_path.replace("_raw.mp4", "_h264.mp4")
        transcode_cmd = [
            "ffmpeg", "-y",
            "-i", temp_raw_path,
            "-c:v", "libx264",
            "-preset", "fast",
            "-crf", "23",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac",
            transcoded_path
        ]
        tc_result = subprocess.run(transcode_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        
        if tc_result.returncode == 0 and os.path.exists(transcoded_path) and os.path.getsize(transcoded_path) > 0:
            os.remove(temp_raw_path)
            os.rename(transcoded_path, temp_raw_path)
            print("Transcoding successful. Video is now in H.264.")
        else:
            print("ERROR: FFmpeg transcoding to H.264 failed.")
            if tc_result.stderr:
                print(tc_result.stderr.decode('utf-8', errors='replace'))
            return False
    else:
        print("Video codec OK (OpenCV can read frames).")

    print("PROGRESS: 20")
    sys.stdout.flush()

    # Step 2: Smart Face Tracking (Reframe to vertical 9:16)
    python_exec = sys.executable  # Use current python executable
    face_tracker_script = os.path.join(os.path.dirname(__file__), "face_tracker.py")

    print("Running AI face tracking and cropping...")
    sys.stdout.flush()

    tracker_cmd = [
        python_exec,
        face_tracker_script,
        temp_raw_path,
        reframed_video_path,
        aspect_ratio
    ]

    # Run face tracker and capture its stdout for real-time progress passing
    proc = subprocess.Popen(tracker_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    
    tracker_error_logs = []
    while True:
        line = proc.stdout.readline()
        if not line:
            break
        line = line.strip()
        if line:
            print(line)
            sys.stdout.flush()
            if not (line.startswith("PROGRESS:") or line.startswith("STATUS:")):
                tracker_error_logs.append(line)

    proc.wait()

    # Clean up raw download segment
    if os.path.exists(temp_raw_path):
        os.remove(temp_raw_path)

    if proc.returncode != 0 or not os.path.exists(reframed_video_path):
        error_details = "\n".join(tracker_error_logs) if tracker_error_logs else "No python traceback captured."
        print(f"ERROR: Face tracking & auto-reframe step failed. Details:\n{error_details}")
        return False

    print("PROGRESS: 55")
    sys.stdout.flush()

    # Step 3: Speech-to-Text & Subtitles via Groq API
    groq_api_key = os.getenv("GROQ_API_KEY")
    transcribe_script = os.path.join(os.path.dirname(__file__), "transcribe.py")

    print("Running speech-to-text transcriber...")
    sys.stdout.flush()

    transcribe_cmd = [
        python_exec,
        transcribe_script,
        reframed_video_path,
        final_srt_path,
        groq_api_key if groq_api_key else ""
    ]

    # Run transcriber and capture stdout
    proc_trans = subprocess.Popen(transcribe_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    
    trans_error_logs = []
    while True:
        line_trans = proc_trans.stdout.readline()
        if not line_trans:
            break
        line_trans = line_trans.strip()
        if line_trans:
            print(line_trans)
            sys.stdout.flush()
            if not (line_trans.startswith("PROGRESS:") or line_trans.startswith("STATUS:")):
                trans_error_logs.append(line_trans)

    proc_trans.wait()

    if proc_trans.returncode != 0 or not os.path.exists(final_srt_path):
        error_details = "\n".join(trans_error_logs) if trans_error_logs else "No python traceback captured."
        print(f"ERROR: Transcription & subtitles generation failed. Details:\n{error_details}")
        return False

    print("PROGRESS: 85")
    sys.stdout.flush()

    # Step 4: Subtitle Styling & Burn-in
    subtitle_styler_script = os.path.join(os.path.dirname(__file__), "subtitle_styler.py")
    final_ass_path = os.path.join(storage_dir, f"{clip_id}.ass")
    words_json_path = os.path.join(storage_dir, f"{clip_id}_words.json")
    
    print("Running subtitle styler and FFmpeg burn-in...")
    sys.stdout.flush()

    styler_cmd = [
        python_exec,
        subtitle_styler_script,
        reframed_video_path,
        final_srt_path,
        final_ass_path,
        final_video_path,
        words_json_path
    ]

    styler_proc = subprocess.run(styler_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    if styler_proc.returncode != 0 or not os.path.exists(final_video_path):
        print(f"WARNING: Subtitle styling failed. Fallback to unstyled video. Details:\n{styler_proc.stdout}")
        import shutil
        shutil.copy(reframed_video_path, final_video_path)
    else:
        print("Subtitle styling applied successfully.")

    # Clean up intermediate files
    if os.path.exists(reframed_video_path):
        os.remove(reframed_video_path)

    # Output paths for Laravel to pick up
    relative_video_path = f"clips/{clip_id}.mp4"
    relative_srt_path = f"clips/{clip_id}.srt"

    print(f"VIDEO_PATH: {relative_video_path}")
    print(f"SUBTITLE_PATH: {relative_srt_path}")
    print("PROGRESS: 100")
    print("STATUS: completed")
    sys.stdout.flush()
    return True

if __name__ == "__main__":
    if len(sys.argv) < 6:
        print("Usage: python3 clipper_bot.py <clip_id> <youtube_url> <start_time> <end_time> <aspect_ratio>")
        sys.exit(1)

    cid = sys.argv[1]
    url = sys.argv[2]
    start = sys.argv[3]
    end = sys.argv[4]
    ratio = sys.argv[5]

    success = process_clip(cid, url, start, end, ratio)
    if not success:
        sys.exit(1)
