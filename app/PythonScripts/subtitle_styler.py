import sys
import os
import json
import subprocess
import math
import re

def format_ass_time(seconds):
    """Formats seconds into ASS timestamp format: H:MM:SS.cs"""
    hours = int(seconds // 3600)
    minutes = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    centiseconds = int(round((seconds - int(seconds)) * 100))
    if centiseconds == 100:
        centiseconds = 0
        secs += 1
        if secs == 60:
            secs = 0
            minutes += 1
            if minutes == 60:
                minutes = 0
                hours += 1
    return f"{hours}:{minutes:02d}:{secs:02d}.{centiseconds:02d}"

def srt_time_to_seconds(time_str):
    """Converts SRT time HH:MM:SS,mmm to seconds"""
    parts = time_str.replace(',', '.').split(':')
    if len(parts) == 3:
        return int(parts[0]) * 3600 + int(parts[1]) * 60 + float(parts[2])
    return 0.0

def parse_srt_to_words(srt_path):
    with open(srt_path, "r", encoding="utf-8") as f:
        content = f.read().strip()
    blocks = re.split(r'\n\s*\n', content)
    words = []
    for block in blocks:
        lines = block.strip().split('\n')
        if len(lines) >= 3:
            times = lines[1].split('-->')
            if len(times) == 2:
                start = srt_time_to_seconds(times[0].strip())
                end = srt_time_to_seconds(times[1].strip())
                text = " ".join(lines[2:]).strip()
                w_list = text.split()
                if not w_list: continue
                duration_per_word = (end - start) / len(w_list)
                for i, w in enumerate(w_list):
                    words.append({
                        "word": w,
                        "start": start + i * duration_per_word,
                        "end": start + (i + 1) * duration_per_word
                    })
    return words

def get_video_dimensions(video_path):
    cmd = [
        "ffprobe", "-v", "error", "-select_streams", "v:0",
        "-show_entries", "stream=width,height",
        "-of", "csv=s=x:p=0", video_path
    ]
    try:
        output = subprocess.check_output(cmd).decode("utf-8").strip()
        width, height = map(int, output.split("x"))
        return width, height
    except Exception as e:
        print(f"Warning: Could not read video dimensions. Assuming 1080x1920. Error: {e}")
        return 1080, 1920

def generate_ass(words, width, height, output_ass_path):
    font_size = int(height * 0.045)
    margin_v = int(height * 0.15)
    outline = max(2, int(height * 0.003))
    shadow = max(1, int(height * 0.002))
    
    ass_content = f"""[Script Info]
ScriptType: v4.00+
PlayResX: {width}
PlayResY: {height}
WrapStyle: 1

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,Arial,{font_size},&H00FFFFFF,&H000000FF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,{outline},{shadow},2,20,20,{margin_v},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""
    
    # Group words
    groups = []
    current_group = []
    for w in words:
        if not current_group:
            current_group.append(w)
        else:
            duration = w['end'] - current_group[0]['start']
            if len(current_group) >= 4 or duration > 1.8 or re.search(r'[,.!?;]$', current_group[-1]['word']):
                groups.append(current_group)
                current_group = [w]
            else:
                current_group.append(w)
    if current_group:
        groups.append(current_group)
        
    for group in groups:
        caption_start = group[0]['start']
        caption_end = group[-1]['end']
        
        for k in range(len(group)):
            start_time = caption_start if k == 0 else group[k]['start']
            end_time = caption_end if k == len(group) - 1 else group[k+1]['start']
            
            # Avoid overlapping or zero-duration events
            if start_time >= end_time:
                end_time = start_time + 0.1
                
            line_words = []
            for j, w in enumerate(group):
                word_text = w['word'].strip().replace('{', '').replace('}', '')
                if j == k:
                    # Active word: Yellow color, popup animation
                    line_words.append(f"{{\\fscx80\\fscy80\\t(0,100,\\fscx115\\fscy115)\\c&H00FFFF&}}{word_text}{{\\r}}")
                else:
                    # Inactive word
                    line_words.append(word_text)
            
            text = " ".join(line_words)
            ass_start = format_ass_time(start_time)
            ass_end = format_ass_time(end_time)
            
            ass_content += f"Dialogue: 0,{ass_start},{ass_end},Default,,0,0,0,,{text}\n"

    with open(output_ass_path, "w", encoding="utf-8") as f:
        f.write(ass_content)

def main():
    if len(sys.argv) < 5:
        print("Usage: python3 subtitle_styler.py <input_video_path> <input_srt_path> <output_ass_path> <output_video_path> [words_json_path]")
        sys.exit(1)

    input_video = sys.argv[1]
    input_srt = sys.argv[2]
    output_ass = sys.argv[3]
    output_video = sys.argv[4]
    words_json = sys.argv[5] if len(sys.argv) > 5 else None

    print(f"Generating ASS styling for {input_video}...")

    # Load words
    words = []
    if words_json and os.path.exists(words_json):
        try:
            with open(words_json, "r", encoding="utf-8") as f:
                words = json.load(f)
        except Exception as e:
            print(f"Warning: Could not read words.json, falling back to SRT. Error: {e}")
    
    if not words:
        words = parse_srt_to_words(input_srt)

    if not words:
        print("ERROR: No words could be parsed from JSON or SRT.")
        sys.exit(1)

    width, height = get_video_dimensions(input_video)
    generate_ass(words, width, height, output_ass)
    
    print(f"Generated ASS file: {output_ass}")
    print(f"Burning subtitles to {output_video}...")

    # Escape the ASS path for FFmpeg filter
    # FFmpeg requires escaping colon and backslash in Windows, but we're on Linux. 
    # Just replacing single quote and colon to be safe, or just wrap in single quotes.
    ass_path_escaped = output_ass.replace('\\', '\\\\').replace(':', '\\:').replace("'", "\\'")

    ffmpeg_cmd = [
        "ffmpeg", "-y",
        "-i", input_video,
        "-vf", f"subtitles='{ass_path_escaped}'",
        "-c:v", "libx264",
        "-preset", "veryfast",
        "-crf", "20",
        "-pix_fmt", "yuv420p",
        "-c:a", "copy",
        output_video
    ]
    
    try:
        subprocess.run(ffmpeg_cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        print("Subtitle burning completed successfully.")
    except subprocess.CalledProcessError as e:
        print(f"ERROR: Subtitle burning failed. FFmpeg output:\n{e.stderr.decode('utf-8', errors='replace')}")
        sys.exit(1)

if __name__ == "__main__":
    main()
