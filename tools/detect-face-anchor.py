#!/usr/bin/env python3
import argparse
import json
import os
import sys


def emit(payload, code=0):
    print(json.dumps(payload, separators=(",", ":")))
    return code


def diag(message):
    print(str(message), file=sys.stderr)


def face_payload(actor_index, width, height, bbox, method, face_count, score=0.0, kps=None):
    x1, y1, x2, y2 = [float(v) for v in bbox]
    x = int(round(x1))
    y = int(round(y1))
    w = max(1, int(round(x2 - x1)))
    h = max(1, int(round(y2 - y1)))
    cx = x + (w / 2.0)
    cy = y + (h / 2.0)

    eye_line_y = y + (h * 0.38)
    if kps is not None:
        try:
            eye_line_y = float((float(kps[0][1]) + float(kps[1][1])) / 2.0)
        except Exception:
            pass

    return {
        "ok": True,
        "method": method,
        "actor_index": actor_index,
        "image_width": width,
        "image_height": height,
        "face_bbox": {"x": x, "y": y, "w": w, "h": h},
        "face_center": {"x": cx, "y": cy},
        "face_center_norm": {"x": cx / max(1, width), "y": cy / max(1, height)},
        "eye_line_y": eye_line_y,
        "forehead_top_y": y + (h * 0.08),
        "chin_y": y + h,
        "detection_score": float(score),
        "confidence": float(score),
        "face_count": int(face_count),
    }


def detect_with_insightface(image_path, actor_index):
    try:
        import cv2  # type: ignore
        from insightface.app import FaceAnalysis  # type: ignore
    except Exception as exc:
        diag(f"insightface import failed: {exc}")
        return None

    img = cv2.imread(image_path, cv2.IMREAD_COLOR)
    if img is None:
        return {"ok": False, "reason": "unreadable_image", "actor_index": actor_index}

    height, width = img.shape[:2]
    home = os.environ.get("HOME") or "/tmp"
    model_root = os.path.join(home, ".insightface")

    try:
        app = FaceAnalysis(name="buffalo_l", root=model_root, providers=["CPUExecutionProvider"])
        app.prepare(ctx_id=-1, det_size=(640, 640))
        faces = app.get(img)
    except Exception as exc:
        diag(f"insightface detection failed: {exc}")
        return None

    if not faces:
        return None

    def rank(face):
        bbox = getattr(face, "bbox", [0, 0, 0, 0])
        score = float(getattr(face, "det_score", 0.0))
        area = max(0.0, float(bbox[2] - bbox[0])) * max(0.0, float(bbox[3] - bbox[1]))
        return (score, area)

    face = sorted(faces, key=rank, reverse=True)[0]
    bbox = getattr(face, "bbox", [0, 0, 0, 0])
    score = float(getattr(face, "det_score", 0.0))
    kps = getattr(face, "kps", None)
    return face_payload(actor_index, width, height, bbox, "insightface", len(faces), score, kps)


def detect_with_opencv_haar(image_path, actor_index):
    try:
        import cv2  # type: ignore
    except Exception as exc:
        diag(f"opencv import failed: {exc}")
        return None

    img = cv2.imread(image_path, cv2.IMREAD_UNCHANGED)
    if img is None:
        return {"ok": False, "reason": "unreadable_image", "actor_index": actor_index}

    height, width = img.shape[:2]
    rgb = img[:, :, :3]
    gray = cv2.cvtColor(rgb, cv2.COLOR_BGR2GRAY)
    cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
    cascade = cv2.CascadeClassifier(cascade_path)
    faces = cascade.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=4, minSize=(32, 32))
    if len(faces) == 0:
        return None

    faces = sorted(faces, key=lambda f: int(f[2]) * int(f[3]), reverse=True)
    x, y, w, h = [int(v) for v in faces[0]]
    score = min(0.75, 0.45 + (len(faces) * 0.04))
    return face_payload(actor_index, width, height, [x, y, x + w, y + h], "opencv_haar", len(faces), score, None)


def main():
    parser = argparse.ArgumentParser(description="Detect the primary face anchor in an actor cutout.")
    parser.add_argument("--image", required=True)
    parser.add_argument("--actor-index", type=int, default=0)
    args = parser.parse_args()

    if not os.path.exists(args.image):
        return emit({"ok": False, "reason": "missing_image", "actor_index": args.actor_index}, 1)

    result = detect_with_insightface(args.image, args.actor_index)
    if isinstance(result, dict) and result.get("ok"):
        return emit(result, 0)

    result = detect_with_opencv_haar(args.image, args.actor_index)
    if isinstance(result, dict) and result.get("ok"):
        result["fallback_from"] = "insightface"
        return emit(result, 0)

    return emit({
        "ok": False,
        "reason": "no_face_detected",
        "actor_index": args.actor_index,
    }, 0)


if __name__ == "__main__":
    sys.exit(main())
