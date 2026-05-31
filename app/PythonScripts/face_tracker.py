#!/usr/bin/env python3
"""
AI Face Tracker & Auto-Reframe (9:16 Vertical Crop)
Uses MediaPipe Face Detection with Haar Cascade fallback,
and a 2-pass approach for robust, anti-glitch tracking.
"""
import sys
import os
import cv2
import subprocess
import math

# --- CONFIGURATION CONSTANTS ---
DETECT_EVERY_N = 3
MIN_FACE_CONFIDENCE = 0.55
DEAD_ZONE_RATIO = 0.04
MAX_MOVE_RATIO_PER_FRAME = 0.012
LOST_HOLD_SECONDS = 1.25
SWITCH_HOLD_SECONDS = 0.75
SWITCH_SCORE_MARGIN = 0.25
SMOOTHING_ALPHA_STABLE = 0.055
SMOOTHING_ALPHA_DYNAMIC = 0.10

TRACKING_MODE = os.getenv("FACE_TRACKING_MODE", "stable")
DEBUG_MODE = os.getenv("FACE_TRACKING_DEBUG", "0") == "1"

def init_detectors():
    mp_face_detection = None
    mp_detector = None
    haar_cascade = None
    
    try:
        import mediapipe as mp
        mp_face_detection = mp.solutions.face_detection
        mp_detector = mp_face_detection.FaceDetection(
            model_selection=1, # 1 is for far faces, 0 for close
            min_detection_confidence=MIN_FACE_CONFIDENCE
        )
        print("Face detector loaded: MediaPipe Face Detection")
    except Exception as e:
        print(f"WARNING: MediaPipe unavailable or failed. Falling back to OpenCV Haar Cascade. ({e})")
        # Load Haar Cascade
        cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_alt2.xml")
        if not os.path.exists(cascade_path):
            cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
        haar_cascade = cv2.CascadeClassifier(cascade_path)
        if haar_cascade.empty():
            print("WARNING: Failed to load face cascade classifier. Using stable center crop fallback.")
            haar_cascade = None
            
    return mp_detector, haar_cascade

def detect_faces(frame, mp_detector, haar_cascade, width, height):
    faces = []
    
    if mp_detector:
        rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        results = mp_detector.process(rgb_frame)
        if results and results.detections:
            for detection in results.detections:
                bboxC = detection.location_data.relative_bounding_box
                x = int(bboxC.xmin * width)
                y = int(bboxC.ymin * height)
                w = int(bboxC.width * width)
                h = int(bboxC.height * height)
                faces.append({"x": x, "y": y, "w": w, "h": h})
    elif haar_cascade:
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        detected = haar_cascade.detectMultiScale(
            gray, scaleFactor=1.1, minNeighbors=5, 
            minSize=(int(width * 0.05), int(height * 0.05))
        )
        for (x, y, w, h) in detected:
            faces.append({"x": x, "y": y, "w": w, "h": h})
            
    return faces

def calculate_score(f_center_x, f_area, locked_target_x, frame_width, frame_height):
    # Normalize values
    area_norm = min(1.0, f_area / (frame_width * frame_height * 0.2)) # assume 20% of screen is huge
    center_dist = abs(f_center_x - (frame_width / 2.0))
    center_preference = max(0, 1.0 - (center_dist / (frame_width / 2.0)))
    
    if locked_target_x is not None:
        continuity_dist = abs(f_center_x - locked_target_x)
        continuity_score = max(0, 1.0 - (continuity_dist / (frame_width * 0.2)))
    else:
        continuity_score = 1.0
        
    score = (area_norm * 0.35) + (center_preference * 0.20) + (continuity_score * 0.45)
    return score

def track_and_crop(input_path, output_path, aspect_ratio="vertical"):
    if not os.path.exists(input_path):
        print(f"ERROR: Input video not found: {input_path}")
        return False

    if aspect_ratio != "vertical":
        print("Aspect ratio is not vertical. Skipping face tracking, copying original...")
        cmd = ["ffmpeg", "-y", "-i", input_path, "-c", "copy", output_path]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return True
        
    mp_detector, haar_cascade = init_detectors()
    
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
    
    crop_height = height
    crop_width = int(crop_height * 9 / 16)
    if crop_width % 2 != 0: crop_width += 1
    
    if width <= crop_width:
        print("Original video is already vertical or narrower than 9:16. Skipping crop, copying...")
        cap.release()
        cmd = ["ffmpeg", "-y", "-i", input_path, "-c", "copy", output_path]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return True
        
    # Variables for tracking
    lost_hold_frames = int(fps * LOST_HOLD_SECONDS)
    switch_hold_frames = int(fps * SWITCH_HOLD_SECONDS)
    
    locked_target_x = None
    last_valid_x = width / 2.0
    frames_since_last_face = 0
    candidate_x = None
    candidate_frames = 0
    
    print("STATUS: pass_a_analyzing")
    sys.stdout.flush()
    
    # PASS A: Analyze Tracking Path
    raw_targets = []
    debug_metadata = [] # stores list of faces and status per frame if debug is on
    
    frame_idx = 0
    while True:
        ret, frame = cap.read()
        if not ret:
            break
            
        frame_idx += 1
        
        # Report progress from 0-30% for pass A
        if frame_idx % max(1, int(fps)) == 0:
            progress_pct = int((frame_idx / total_frames) * 30)
            print(f"PROGRESS: {progress_pct}")
            sys.stdout.flush()
            
        if frame_idx % DETECT_EVERY_N == 1 or frame_idx == 1:
            faces = detect_faces(frame, mp_detector, haar_cascade, width, height)
            
            if not faces:
                frames_since_last_face += DETECT_EVERY_N
                if frames_since_last_face > lost_hold_frames:
                    # Slowly drift to center
                    target_x = last_valid_x + (width / 2.0 - last_valid_x) * 0.05
                    last_valid_x = target_x
                    locked_target_x = None
                    status_lbl = "center_fallback"
                else:
                    target_x = last_valid_x
                    status_lbl = "lost_hold"
            else:
                frames_since_last_face = 0
                
                best_face = None
                best_score = -1
                
                for f in faces:
                    f_cx = f['x'] + f['w']/2.0
                    f_area = f['w'] * f['h']
                    score = calculate_score(f_cx, f_area, locked_target_x, width, height)
                    f['score'] = score
                    if score > best_score:
                        best_score = score
                        best_face = f
                        
                # Lock logic
                if locked_target_x is None:
                    locked_target_x = best_face['x'] + best_face['w']/2.0
                    target_x = locked_target_x
                    status_lbl = "locked"
                else:
                    best_f_cx = best_face['x'] + best_face['w']/2.0
                    # Check if best face is significantly different from locked target
                    locked_score = calculate_score(locked_target_x, best_face['w']*best_face['h'], locked_target_x, width, height)
                    
                    if abs(best_f_cx - locked_target_x) > (width * 0.15) and best_score > locked_score + SWITCH_SCORE_MARGIN:
                        # Candidate for switch
                        if candidate_x is None or abs(candidate_x - best_f_cx) > (width * 0.05):
                            candidate_x = best_f_cx
                            candidate_frames = DETECT_EVERY_N
                        else:
                            candidate_frames += DETECT_EVERY_N
                            
                        if candidate_frames >= switch_hold_frames:
                            locked_target_x = best_f_cx
                            candidate_x = None
                            candidate_frames = 0
                            target_x = locked_target_x
                            status_lbl = "switched"
                        else:
                            target_x = locked_target_x
                            status_lbl = "candidate"
                    else:
                        # Continue with locked target (update position slightly)
                        locked_target_x = locked_target_x * 0.7 + best_f_cx * 0.3
                        target_x = locked_target_x
                        candidate_x = None
                        candidate_frames = 0
                        status_lbl = "locked"
                        
                last_valid_x = target_x
        else:
            target_x = last_valid_x
            status_lbl = "interpolated"
            
        raw_targets.append(target_x)
        if DEBUG_MODE:
            # Save metadata only, NO frames
            debug_metadata.append({
                "faces": faces if (frame_idx % DETECT_EVERY_N == 1 or frame_idx == 1) else [],
                "target_x": target_x,
                "status": status_lbl
            })
            
    # Release Pass A
    if len(raw_targets) == 0:
        print("ERROR: No frames read.")
        return False
        
    print("STATUS: pass_b_smoothing")
    sys.stdout.flush()
    
    # PASS B: Smooth the path
    smoothed_targets = []
    
    if TRACKING_MODE == "dynamic":
        alpha = SMOOTHING_ALPHA_DYNAMIC
    elif TRACKING_MODE == "center":
        alpha = 0.0
        # Force center
        raw_targets = [width / 2.0] * len(raw_targets)
    else: # stable
        alpha = SMOOTHING_ALPHA_STABLE
        
    dead_zone_px = crop_width * DEAD_ZONE_RATIO
    max_move_px = width * MAX_MOVE_RATIO_PER_FRAME
    
    current_smoothed_x = width / 2.0
    
    for i, tx in enumerate(raw_targets):
        # Dead zone
        if abs(tx - current_smoothed_x) < dead_zone_px:
            target = current_smoothed_x
        else:
            target = tx
            
        # EMA
        new_x = (alpha * target) + ((1.0 - alpha) * current_smoothed_x)
        
        # Max move speed
        move_dist = new_x - current_smoothed_x
        if abs(move_dist) > max_move_px:
            new_x = current_smoothed_x + math.copysign(max_move_px, move_dist)
            
        # Clamp to bounds
        min_x = crop_width / 2.0
        max_x = width - (crop_width / 2.0)
        new_x = max(min_x, min(new_x, max_x))
        
        smoothed_targets.append(new_x)
        current_smoothed_x = new_x
        
    print("STATUS: processing_crop")
    sys.stdout.flush()
    
    # PASS B: Render Video
    cap.set(cv2.CAP_PROP_POS_FRAMES, 0)
    
    temp_silent_path = output_path.replace(".mp4", "_silent.mp4")
    fourcc = cv2.VideoWriter_fourcc(*'mp4v')
    out = cv2.VideoWriter(temp_silent_path, fourcc, fps, (crop_width, crop_height))
    
    debug_out = None
    if DEBUG_MODE:
        debug_path = output_path.replace(".mp4", "_debug_tracking.mp4")
        debug_out = cv2.VideoWriter(debug_path, fourcc, fps, (width, height))
        
    frame_idx = 0
    while True:
        ret, frame = cap.read()
        if not ret:
            break
            
        if frame_idx >= len(smoothed_targets):
            break
            
        # Progress from 30% to 80%
        if frame_idx % max(1, int(fps)) == 0:
            progress_pct = int(30 + (frame_idx / total_frames) * 50)
            print(f"PROGRESS: {progress_pct}")
            sys.stdout.flush()
            
        cx = smoothed_targets[frame_idx]
        left = int(cx - (crop_width / 2.0))
        right = left + crop_width
        
        # Clamp bounds strictly
        if left < 0:
            left = 0
            right = crop_width
        elif right > width:
            right = width
            left = width - crop_width
            
        cropped_frame = frame[0:crop_height, left:right]
        out.write(cropped_frame)
        
        if DEBUG_MODE and debug_out:
            dbg_frame = frame.copy()
            meta = debug_metadata[frame_idx] if frame_idx < len(debug_metadata) else {}
            
            # Draw crop bounds
            cv2.rectangle(dbg_frame, (left, 0), (right, crop_height), (0, 255, 0), 3)
            # Draw center point
            cv2.circle(dbg_frame, (int(cx), int(crop_height/2)), 10, (0, 0, 255), -1)
            
            # Draw detected faces
            if "faces" in meta and meta["faces"]:
                for f in meta["faces"]:
                    cv2.rectangle(dbg_frame, (f['x'], f['y']), (f['x']+f['w'], f['y']+f['h']), (255, 0, 0), 2)
                    if 'score' in f:
                        cv2.putText(dbg_frame, f"{f['score']:.2f}", (f['x'], f['y']-10), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 0, 0), 2)
                        
            # Draw status
            status_text = meta.get("status", "unknown")
            cv2.putText(dbg_frame, f"Status: {status_text}", (50, 50), cv2.FONT_HERSHEY_SIMPLEX, 1.5, (0, 255, 255), 3)
            
            debug_out.write(dbg_frame)
            
        frame_idx += 1
        
    cap.release()
    out.release()
    if debug_out:
        debug_out.release()
        
    if mp_detector:
        mp_detector.close()
        
    print(f"Cropping complete. Processed {frame_idx} frames.")
    sys.stdout.flush()
    
    # Merge Audio
    print("STATUS: merging_audio")
    print("PROGRESS: 85")
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
    
    if os.path.exists(temp_silent_path):
        os.remove(temp_silent_path)
        
    if result.returncode == 0:
        print("PROGRESS: 100")
        print("SUCCESS: Face tracking & cropping completed.")
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
    if not success:
        print("ERROR: Processing failed.")
        sys.exit(1)
