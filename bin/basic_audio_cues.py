#!/usr/bin/env python3
import argparse
import json
import re
import subprocess

def run(cmd, timeout=180):
    return subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)

def get_duration(ffmpeg, video):
    try:
        ffprobe = ffmpeg.replace("ffmpeg", "ffprobe")

        cmd = [
            ffprobe,
            "-v", "error",
            "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            video
        ]

        result = run(cmd, timeout=60)
        text = (result.stdout or "").strip()

        if not text:
            return 0

        return int(float(text))

    except Exception:
        return 0

def analyze_chunk(ffmpeg, video, start, duration):
    try:
        result = run([
            ffmpeg, "-hide_banner", "-ss", str(start), "-t", str(duration),
            "-i", video, "-af", "volumedetect", "-f", "null", "-"
        ], timeout=180)
        text = result.stderr or ""
        max_match = re.search(r"max_volume:\s*(-?[0-9.]+) dB", text)
        mean_match = re.search(r"mean_volume:\s*(-?[0-9.]+) dB", text)
        max_volume = float(max_match.group(1)) if max_match else None
        mean_volume = float(mean_match.group(1)) if mean_match else None

        if max_volume is None and mean_volume is None:
            return None

        if mean_volume is not None and mean_volume < -48:
            return "[silence]"

        # cinematic music beds
        if mean_volume is not None and mean_volume > -18:
            return "[music]"

        # dialogue-heavy sections
        if mean_volume is not None and mean_volume > -38:
            return "[dialogue]"

        # explosive peaks
        if (
            max_volume is not None
            and max_volume > -0.2
            and mean_volume is not None
            and mean_volume > -10
        ):
            return "[loud sound]"

        # ambience
        if mean_volume is not None and mean_volume > -42:
            return "[background noise]"

        return None

    except Exception:
        return None

def should_merge(label):
    return label in ["[silence]", "[background noise]", "[music]", "[dialogue]"]

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--video", required=True)
    parser.add_argument("--ffmpeg", default="/usr/bin/ffmpeg")
    parser.add_argument("--max-cues", type=int, default=300)
    parser.add_argument("--chunk-seconds", type=int, default=8)
    args = parser.parse_args()

    duration = get_duration(args.ffmpeg, args.video)
    if duration <= 0:
        print(json.dumps({"cues": []}, ensure_ascii=False))
        return

    chunk = max(5, int(args.chunk_seconds))
    max_cues = max(1, int(args.max_cues))
    cues, previous_label, previous_cue = [], None, None

    for start in range(0, duration, chunk):
        if len(cues) >= max_cues:
            break
        label = analyze_chunk(args.ffmpeg, args.video, start, chunk)
        if not label:
            previous_label, previous_cue = None, None
            continue
        end = min(start + chunk, duration)
        if previous_label == label and previous_cue is not None and should_merge(label):
            previous_cue["end"] = float(end)
            continue
        cue = {
            "start": float(start),
            "end": float(end),
            "cue": label,
            "confidence": 0.55,
            "source": "basic_chunk_detector_v287"
        }
        cues.append(cue)
        previous_label, previous_cue = label, cue

    print(json.dumps({"cues": cues[:max_cues]}, ensure_ascii=False))

if __name__ == "__main__":
    main()
