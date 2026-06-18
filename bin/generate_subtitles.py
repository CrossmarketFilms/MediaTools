#!/usr/bin/env python3
import argparse
import os
import subprocess
import sys
import tempfile

os.environ["HF_HOME"] = "/var/www/html/wp-content/uploads/hf-cache"
os.environ["XDG_CACHE_HOME"] = "/var/www/html/wp-content/uploads/hf-cache"
os.environ["HUGGINGFACE_HUB_CACHE"] = "/var/www/html/wp-content/uploads/hf-cache"

def fmt(ts):
    ms = int(round(ts * 1000))
    h = ms // 3600000
    m = (ms % 3600000) // 60000
    s = (ms % 60000) // 1000
    z = ms % 1000
    return f"{h:02d}:{m:02d}:{s:02d},{z:03d}"

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--video", required=True)
    ap.add_argument("--language", default="auto")
    ap.add_argument("--model", default="small")
    ap.add_argument("--ffmpeg", default="ffmpeg")
    ap.add_argument("--mode", default="faster-whisper")
    args = ap.parse_args()

    video = os.path.abspath(args.video)
    if not os.path.exists(video):
        print("Video file not found.", file=sys.stderr)
        return 2

    tmpdir = tempfile.mkdtemp(prefix="cmsg_")
    wav = os.path.join(tmpdir, "audio.wav")

    ffmpeg_cmd = [args.ffmpeg, "-y", "-i", video, "-ac", "1", "-ar", "16000", wav]
    ff = subprocess.run(ffmpeg_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    if ff.returncode != 0:
        print(ff.stdout, file=sys.stderr)
        return ff.returncode

    if args.mode == "faster-whisper":
        try:
            from faster_whisper import WhisperModel
            model = WhisperModel(args.model, device="cpu", compute_type="int8")
            kwargs = {}
            if args.language != "auto":
                kwargs["language"] = args.language
            segments, info = model.transcribe(wav, vad_filter=True, **kwargs)
            segments = list(segments)
        except Exception as e:
            print(f"Failed to run faster-whisper: {e}", file=sys.stderr)
            return 3
    else:
        try:
            import whisper
            model = whisper.load_model(args.model)
            kwargs = {}
            if args.language != "auto":
                kwargs["language"] = args.language
            result = model.transcribe(wav, **kwargs)
            segments = result.get("segments", [])
        except Exception as e:
            print(f"Failed to run whisper: {e}", file=sys.stderr)
            return 4

    srt_path = os.path.splitext(video)[0] + ".srt"
    with open(srt_path, "w", encoding="utf-8") as f:
        for idx, seg in enumerate(segments, start=1):
            if hasattr(seg, "start"):
                start = seg.start
                end = seg.end
                text = (seg.text or "").strip()
            else:
                start = seg.get("start", 0)
                end = seg.get("end", 0)
                text = (seg.get("text", "") or "").strip()
            f.write(f"{idx}\n{fmt(float(start))} --> {fmt(float(end))}\n{text}\n\n")

    print("SRT_PATH=" + srt_path)
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
