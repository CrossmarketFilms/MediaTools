#!/usr/bin/env python3
import argparse
import math
import os
import shutil
import subprocess
import sys
import tempfile
import time
import wave

os.environ["HF_HOME"] = "/var/www/html/wp-content/uploads/hf-cache"
os.environ["XDG_CACHE_HOME"] = "/var/www/html/wp-content/uploads/hf-cache"
os.environ["HUGGINGFACE_HUB_CACHE"] = "/var/www/html/wp-content/uploads/hf-cache"

TARGET_SAMPLE_RATE = 16000
DEFAULT_CHUNK_SECONDS = 300


def fmt(ts):
    ms = int(round(max(0.0, ts) * 1000))
    h = ms // 3600000
    m = (ms % 3600000) // 60000
    s = (ms % 60000) // 1000
    z = ms % 1000
    return f"{h:02d}:{m:02d}:{s:02d},{z:03d}"


def emit_progress(percent, message):
    print(f"CMSG_PROGRESS={int(percent)}|{message}", flush=True)


def emit_diag(message):
    print(f"CMSG_DIAG={message}", flush=True)


def ffprobe_path(ffmpeg_path):
    dirname = os.path.dirname(ffmpeg_path)
    candidate = os.path.join(dirname, "ffprobe") if dirname else "ffprobe"
    return candidate if shutil.which(candidate) or os.path.exists(candidate) else "ffprobe"


def run_command(cmd, label):
    emit_diag(f"{label} start: {' '.join(cmd)}")
    started = time.time()
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    elapsed = time.time() - started

    if proc.stdout:
        for line in proc.stdout.splitlines():
            emit_diag(f"{label} output: {line}")

    emit_diag(f"{label} end: return_code={proc.returncode} runtime={elapsed:.2f}s")
    return proc


def audio_duration(path, ffmpeg):
    probe = ffprobe_path(ffmpeg)
    cmd = [
        probe,
        "-v",
        "error",
        "-show_entries",
        "format=duration",
        "-of",
        "default=noprint_wrappers=1:nokey=1",
        path,
    ]
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)

    if proc.returncode == 0:
        try:
            return max(0.0, float(proc.stdout.strip()))
        except ValueError:
            pass

    with wave.open(path, "rb") as wav:
        frames = wav.getnframes()
        rate = wav.getframerate()
        return frames / float(rate) if rate else 0.0


def cached_wav_path(video):
    base = os.path.splitext(video)[0]
    return f"{base}.cmsg-16k-mono.wav"


def is_valid_cached_wav(path):
    if not os.path.exists(path) or os.path.getsize(path) <= 44:
        return False

    try:
        with wave.open(path, "rb") as wav:
            return (
                wav.getnchannels() == 1
                and wav.getframerate() == TARGET_SAMPLE_RATE
                and wav.getsampwidth() == 2
                and wav.getnframes() > 0
            )
    except Exception:
        return False


def extract_audio(video, wav_path, ffmpeg):
    if is_valid_cached_wav(wav_path):
        emit_diag(f"Audio extraction skipped: valid cached WAV found at {wav_path}")
        return 0

    emit_progress(30, "Extracting 16kHz mono audio for speech recognition.")
    cmd = [
        ffmpeg,
        "-y",
        "-i",
        video,
        "-vn",
        "-ac",
        "1",
        "-ar",
        str(TARGET_SAMPLE_RATE),
        "-acodec",
        "pcm_s16le",
        wav_path,
    ]
    proc = run_command(cmd, "Audio extraction")
    return proc.returncode


def create_chunks(wav_path, duration, chunk_seconds, ffmpeg, tmpdir):
    if duration <= chunk_seconds:
        return [
            {
                "index": 1,
                "path": wav_path,
                "offset": 0.0,
                "duration": duration,
                "temporary": False,
            }
        ]

    emit_progress(35, "Processing long-form audio. This may take several minutes depending on length and model size.")
    emit_diag(f"Chunk creation start: duration={duration:.2f}s chunk_seconds={chunk_seconds}")
    chunks = []
    total = int(math.ceil(duration / float(chunk_seconds)))

    for index in range(total):
        offset = float(index * chunk_seconds)
        remaining = max(0.0, duration - offset)
        length = min(float(chunk_seconds), remaining)
        chunk_path = os.path.join(tmpdir, f"chunk_{index + 1:04d}.wav")
        cmd = [
            ffmpeg,
            "-y",
            "-ss",
            f"{offset:.3f}",
            "-t",
            f"{length:.3f}",
            "-i",
            wav_path,
            "-ac",
            "1",
            "-ar",
            str(TARGET_SAMPLE_RATE),
            "-acodec",
            "pcm_s16le",
            chunk_path,
        ]
        proc = run_command(cmd, f"Chunk creation {index + 1}/{total}")

        if proc.returncode != 0:
            return None

        chunks.append(
            {
                "index": index + 1,
                "path": chunk_path,
                "offset": offset,
                "duration": length,
                "temporary": True,
            }
        )

    emit_diag(f"Chunk creation end: chunks={len(chunks)}")
    return chunks


def normalize_segment(seg, offset):
    if hasattr(seg, "start"):
        start = float(seg.start or 0.0)
        end = float(seg.end or start)
        text = (seg.text or "").strip()
    else:
        start = float(seg.get("start", 0.0) or 0.0)
        end = float(seg.get("end", start) or start)
        text = (seg.get("text", "") or "").strip()

    return {
        "start": start + offset,
        "end": end + offset,
        "text": text,
    }


def chunk_percent(index, total):
    if total <= 1 or index >= total:
        return 95

    return min(94, int(math.ceil((index * 102.0) / total)))


def transcribe_chunks(args, chunks):
    kwargs = {}

    if args.language != "auto":
        kwargs["language"] = args.language
    elif args.auto_detect_reason:
        emit_diag("Unsupported source language selected. Using Faster-Whisper auto-detection.")

    started = time.time()
    all_segments = []
    total = len(chunks)
    emit_diag(f"Total transcription start: chunks={total} model={args.model} mode={args.mode}")

    if args.mode == "faster-whisper":
        try:
            from faster_whisper import WhisperModel

            model = WhisperModel(args.model, device="cpu", compute_type="int8")

            for chunk in chunks:
                index = chunk["index"]
                emit_diag(f"Chunk transcription start: {index}/{total} path={chunk['path']} offset={chunk['offset']:.3f}s")
                segments, _info = model.transcribe(chunk["path"], vad_filter=True, **kwargs)
                all_segments.extend(normalize_segment(seg, chunk["offset"]) for seg in list(segments))
                percent = chunk_percent(index, total)
                emit_diag(f"Chunk transcription end: {index}/{total} merged_segments={len(all_segments)}")
                emit_progress(percent, f"Chunk {index}/{total} complete ({percent}%)")
        except Exception as exc:
            print(f"Failed to run faster-whisper: {exc}", file=sys.stderr, flush=True)
            return None
    else:
        try:
            import whisper

            model = whisper.load_model(args.model)

            for chunk in chunks:
                index = chunk["index"]
                emit_diag(f"Chunk transcription start: {index}/{total} path={chunk['path']} offset={chunk['offset']:.3f}s")
                result = model.transcribe(chunk["path"], **kwargs)
                all_segments.extend(normalize_segment(seg, chunk["offset"]) for seg in result.get("segments", []))
                percent = chunk_percent(index, total)
                emit_diag(f"Chunk transcription end: {index}/{total} merged_segments={len(all_segments)}")
                emit_progress(percent, f"Chunk {index}/{total} complete ({percent}%)")
        except Exception as exc:
            print(f"Failed to run whisper: {exc}", file=sys.stderr, flush=True)
            return None

    elapsed = time.time() - started
    emit_diag(f"Total transcription end: runtime={elapsed:.2f}s segments={len(all_segments)}")
    return all_segments


def write_srt(video, segments):
    emit_progress(96, "Generating SRT...")
    srt_path = os.path.splitext(video)[0] + ".srt"

    with open(srt_path, "w", encoding="utf-8") as handle:
        idx = 1

        for seg in segments:
            text = (seg.get("text", "") or "").strip()

            if not text:
                continue

            handle.write(f"{idx}\n{fmt(float(seg['start']))} --> {fmt(float(seg['end']))}\n{text}\n\n")
            idx += 1

    return srt_path


def cleanup_chunks(chunks):
    for chunk in chunks:
        if chunk.get("temporary"):
            try:
                os.remove(chunk["path"])
            except OSError as exc:
                emit_diag(f"Temporary chunk cleanup skipped: {chunk['path']} error={exc}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--video", required=True)
    ap.add_argument("--language", default="auto")
    ap.add_argument("--model", default="small")
    ap.add_argument("--ffmpeg", default="ffmpeg")
    ap.add_argument("--mode", default="faster-whisper")
    ap.add_argument("--chunk-seconds", type=int, default=DEFAULT_CHUNK_SECONDS)
    ap.add_argument("--auto-detect-reason", default="")
    args = ap.parse_args()

    video = os.path.abspath(args.video)
    if not os.path.exists(video):
        print("Video file not found.", file=sys.stderr, flush=True)
        return 2

    chunk_seconds = max(60, int(args.chunk_seconds or DEFAULT_CHUNK_SECONDS))
    tmpdir = tempfile.mkdtemp(prefix="cmsg_chunks_")
    wav = cached_wav_path(video)

    try:
        extract_code = extract_audio(video, wav, args.ffmpeg)
        if extract_code != 0:
            return extract_code

        duration = audio_duration(wav, args.ffmpeg)
        emit_diag(f"Audio extraction end: wav={wav} duration={duration:.2f}s sample_rate={TARGET_SAMPLE_RATE} channels=1")

        chunks = create_chunks(wav, duration, chunk_seconds, args.ffmpeg, tmpdir)
        if not chunks:
            print("Failed to create audio chunks.", file=sys.stderr, flush=True)
            return 5

        emit_diag(
            "Chunk metadata: "
            + "; ".join(
                f"{chunk['index']}|offset={chunk['offset']:.3f}|duration={chunk['duration']:.3f}|temporary={int(chunk['temporary'])}"
                for chunk in chunks
            )
        )

        segments = transcribe_chunks(args, chunks)
        if segments is None:
            return 3 if args.mode == "faster-whisper" else 4

        srt_path = write_srt(video, segments)
        cleanup_chunks(chunks)
        print("SRT_PATH=" + srt_path, flush=True)
        return 0
    finally:
        try:
            shutil.rmtree(tmpdir, ignore_errors=True)
        except Exception as exc:
            emit_diag(f"Temporary directory cleanup skipped: {tmpdir} error={exc}")


if __name__ == "__main__":
    raise SystemExit(main())
