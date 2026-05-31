#!/usr/bin/env python3
import sys
import os
import requests
import subprocess
import json

def format_time_srt(seconds):
    """Formats seconds into SRT timestamp format: HH:MM:SS,mmm"""
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    milliseconds = int(round((seconds - int(seconds)) * 1000))
    if milliseconds == 1000:
        milliseconds = 0
        secs += 1
        if secs == 60:
            secs = 0
            minutes += 1
            if minutes == 60:
                minutes = 0
                hours += 1
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{milliseconds:03d}"

def format_time_vtt(seconds):
    """Formats seconds into VTT timestamp format: HH:MM:SS.mmm"""
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    milliseconds = int(round((seconds - int(seconds)) * 1000))
    if milliseconds == 1000:
        milliseconds = 0
        secs += 1
        if secs == 60:
            secs = 0
            minutes += 1
            if minutes == 60:
                minutes = 0
                hours += 1
    return f"{hours:02d}:{minutes:02d}:{secs:02d}.{milliseconds:03d}"

def transcribe_clip(video_path, srt_path, groq_api_key):
    """
    Extracts audio from video clip, transcribes it using Groq API (Whisper),
    and saves subtitles in SRT and VTT formats.
    """
    if not groq_api_key:
        print("ERROR: GROQ_API_KEY is not configured in .env file.")
        return False

    print("STATUS: transcribing")
    print("PROGRESS: 60")
    sys.stdout.flush()

    temp_audio_path = video_path.replace(".mp4", "_audio.mp3")

    # Extract audio using FFmpeg (convert to lightweight 16kHz mono MP3)
    ffmpeg_cmd = [
        "ffmpeg", "-y",
        "-i", video_path,
        "-vn",
        "-acodec", "libmp3lame",
        "-ar", "16000",
        "-ac", "1",
        temp_audio_path
    ]
    subprocess.run(ffmpeg_cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    if not os.path.exists(temp_audio_path) or os.path.getsize(temp_audio_path) == 0:
        print("ERROR: Audio extraction failed.")
        return False

    print("PROGRESS: 70")
    sys.stdout.flush()

    # Call Groq API
    url = "https://api.groq.com/openai/v1/audio/transcriptions"
    headers = {
        "Authorization": f"Bearer {groq_api_key}"
    }
    
    try:
        with open(temp_audio_path, "rb") as f:
            files = {
                "file": (os.path.basename(temp_audio_path), f, "audio/mp3")
            }
            data = {
                "model": "whisper-large-v3",
                "response_format": "verbose_json",
                "timestamp_granularities[]": ["word", "segment"]
            }
            
            response = requests.post(url, headers=headers, files=files, data=data)
            
        # Clean up temp audio file
        if os.path.exists(temp_audio_path):
            os.remove(temp_audio_path)

        if response.status_code != 200:
            print(f"ERROR: Groq API request failed with status {response.status_code}")
            print(response.text)
            return False

        print("PROGRESS: 85")
        sys.stdout.flush()

        res_json = response.json()
        segments = res_json.get("segments") or []

        # Generate SRT Content
        srt_content = ""
        vtt_content = "WEBVTT\n\n"
        
        for idx, segment in enumerate(segments, 1):
            start = segment["start"]
            end = segment["end"]
            text = segment["text"].strip()

            # SRT format
            srt_content += f"{idx}\n"
            srt_content += f"{format_time_srt(start)} --> {format_time_srt(end)}\n"
            srt_content += f"{text}\n\n"

            # VTT format
            vtt_content += f"{format_time_vtt(start)} --> {format_time_vtt(end)}\n"
            vtt_content += f"{text}\n\n"

        # Save SRT
        with open(srt_path, "w", encoding="utf-8") as f:
            f.write(srt_content)

        # Save VTT (in the same directory)
        vtt_path = srt_path.replace(".srt", ".vtt")
        with open(vtt_path, "w", encoding="utf-8") as f:
            f.write(vtt_content)

        # Also write the raw text transcription to a text file for Laravel to read if needed
        text_path = srt_path.replace(".srt", ".txt")
        with open(text_path, "w", encoding="utf-8") as f:
            f.write(res_json.get("text", "").strip())

        # Save words JSON if available
        words = res_json.get("words") or []
        if words:
            words_path = srt_path.replace(".srt", "_words.json")
            with open(words_path, "w", encoding="utf-8") as f:
                json.dump(words, f, ensure_ascii=False, indent=2)

        print("PROGRESS: 90")
        sys.stdout.flush()
        return True

    except Exception as e:
        print(f"ERROR: Groq API transaction failed: {str(e)}")
        if os.path.exists(temp_audio_path):
            os.remove(temp_audio_path)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Usage: python3 transcribe.py <video_path> <srt_path> <groq_api_key>")
        sys.exit(1)

    video = sys.argv[1]
    srt = sys.argv[2]
    api_key = sys.argv[3]

    success = transcribe_clip(video, srt, api_key)
    if success:
        print("SUCCESS: Transcription & subtitles generated.")
    else:
        print("ERROR: Transcription failed.")
        sys.exit(1)
