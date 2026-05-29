#!/usr/bin/env python3
"""
AI Face Tracker & Auto-Reframe (9:16 Vertical Crop)
Uses OpenCV DNN + Haar Cascade for robust face detection with
smooth Exponential Moving Average (EMA) camera panning.
"""
import sys
import os
import cv2
import subprocess

def track_and_crop(input_path, output_path, aspect_ratio="vertical", smoothing_factor=0.08):
    """
    Tracks the speaker's face using OpenCV and crops the video to vertical (9:16)
    with smooth auto-framing panning, then merges original audio.
    """
    if not os.path.exists(input_path):
        print(f"ERROR: Input video not found: {input_path}")
        return False

    if aspect_ratio != "vertical":
        print("Aspect ratio is not vertical. Skipping face tracking, copying original...")
        cmd = ["ffmpeg", "-y", "-i", input_path, "-c", "copy", output_path]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return True

    # Initialize OpenCV Haar Cascade Face Detector (bundled with OpenCV, always available)
    cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_alt2.xml")
    if not os.path.exists(cascade_path):
        cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
    
    face_cascade = cv2.CascadeClassifier(cascade_path)
    if face_cascade.empty():
        print("ERROR: Failed to load face cascade classifier.")
        return False

    print("Face detector loaded: OpenCV Haar Cascade (haarcascade_frontalface_alt2)")
    sys.stdout.flush()

    # Open Video
    cap = cv2.VideoCapture(input_path)
    if not cap.isOpened():
        print(f"ERROR: Cannot open video: {input_path}")
        return False

    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = cap.get(cv2.CAP_PROP_FPS)
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))

    if width == 0 or height == 0 or fps == 0:
        print("ERROR: Invalid video properties.")
        cap.release()
        return False

    print(f"Video info: {width}x{height} @ {fps:.1f}fps, {total_frames} frames")
    sys.stdout.flush()

    # Verify that OpenCV can actually decode frames from this video
    test_ret, test_frame = cap.read()
    if not test_ret:
        print("ERROR: OpenCV cannot decode frames from this video (likely unsupported codec like AV1).")
        print("The video needs to be transcoded to H.264 before face tracking.")
        cap.release()
        return False
    # Reset to the beginning after the test read
    cap.set(cv2.CAP_PROP_POS_FRAMES, 0)

    # Calculate vertical crop width: height * 9 / 16
    crop_height = height
    crop_width = int(crop_height * 9 / 16)

    # Force crop_width to be even (FFmpeg requirement for mp4)
    if crop_width % 2 != 0:
        crop_width += 1

    # If original video is already vertical or narrower than 9:16
    if width <= crop_width:
        print("Original video is already vertical or narrower than 9:16. Skipping crop, copying...")
        cap.release()
        cmd = ["ffmpeg", "-y", "-i", input_path, "-c", "copy", output_path]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return True

    # Temporary silent cropped video path
    temp_silent_path = output_path.replace(".mp4", "_silent.mp4")

    # Setup Video Writer for cropped output
    fourcc = cv2.VideoWriter_fourcc(*'mp4v')
    out = cv2.VideoWriter(temp_silent_path, fourcc, fps, (crop_width, crop_height))

    # Face center tracking variables
    current_x_center = width / 2.0  # start at screen center
    last_valid_x = width / 2.0

    frame_count = 0
    detect_every_n = 3  # Detect face every N frames for performance
    print("STATUS: processing_crop")

    while cap.isOpened():
        ret, frame = cap.read()
        if not ret:
            break

        frame_count += 1
        if frame_count % 30 == 0:
            progress_pct = int(10 + (frame_count / total_frames) * 40)
            print(f"PROGRESS: {progress_pct}")
            sys.stdout.flush()

        target_x = current_x_center

        # Detect face every N frames (performance optimization)
        if frame_count % detect_every_n == 1:
            # Convert to grayscale for Haar cascade
            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            
            # Detect faces
            faces = face_cascade.detectMultiScale(
                gray,
                scaleFactor=1.1,
                minNeighbors=5,
                minSize=(int(width * 0.05), int(height * 0.05)),
                flags=cv2.CASCADE_SCALE_IMAGE
            )

            if len(faces) > 0:
                # Find the largest face
                largest_area = 0
                primary_face_x = None

                for (x, y, w, h) in faces:
                    area = w * h
                    if area > largest_area:
                        largest_area = area
                        primary_face_x = x + (w / 2.0)

                if primary_face_x is not None:
                    target_x = primary_face_x
                    last_valid_x = primary_face_x
            else:
                target_x = last_valid_x
        else:
            target_x = last_valid_x

        # Apply Exponential Moving Average (EMA) for smooth camera panning
        current_x_center = (smoothing_factor * target_x) + ((1.0 - smoothing_factor) * current_x_center)

        # Calculate crop boundaries
        left = int(current_x_center - (crop_width / 2.0))
        right = left + crop_width

        # Clamp boundaries to screen edges
        if left < 0:
            left = 0
            right = crop_width
        elif right > width:
            right = width
            left = width - crop_width

        # Crop frame and write
        cropped_frame = frame[0:crop_height, left:right]
        out.write(cropped_frame)

    cap.release()
    out.release()

    print(f"Cropping complete. Processed {frame_count} frames.")
    sys.stdout.flush()

    # Merge audio from the original input video with the cropped silent video
    print("STATUS: merging_audio")
    sys.stdout.flush()

    ffmpeg_cmd = [
        "ffmpeg", "-y",
        "-i", temp_silent_path,
        "-i", input_path,
        "-c:v", "libx264",
        "-pix_fmt", "yuv420p",
        "-c:a", "aac",
        "-map", "0:v:0",
        "-map", "1:a:0?",
        output_path
    ]

    result = subprocess.run(ffmpeg_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    # Clean up temp file
    if os.path.exists(temp_silent_path):
        os.remove(temp_silent_path)

    if result.returncode == 0:
        print("PROGRESS: 50")
        sys.stdout.flush()
        return True
    else:
        print("ERROR: FFmpeg audio merging failed.")
        print(result.stderr.decode('utf-8'))
        return False

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python3 face_tracker.py <input_path> <output_path> [aspect_ratio]")
        sys.exit(1)

    inp = sys.argv[1]
    outp = sys.argv[2]
    ratio = sys.argv[3] if len(sys.argv) > 3 else "vertical"

    success = track_and_crop(inp, outp, ratio)
    if success:
        print("SUCCESS: Face tracking & cropping completed.")
    else:
        print("ERROR: Processing failed.")
        sys.exit(1)
