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
        "actor_index": int(actor_index),
        "image_width": int(width),
        "image_height": int(height),
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


class InsightDetector:
    def __init__(self):
        self.cv2 = None
        self.app = None
        try:
            import cv2  # type: ignore
            from insightface.app import FaceAnalysis  # type: ignore
            home = os.environ.get("HOME") or "/tmp"
            model_root = os.path.join(home, ".insightface")
            app = FaceAnalysis(name="buffalo_l", root=model_root, providers=["CPUExecutionProvider"])
            app.prepare(ctx_id=-1, det_size=(640, 640))
            self.cv2 = cv2
            self.app = app
        except Exception as exc:
            diag(f"insightface init failed: {exc}")

    def detect(self, image_path, actor_index):
        if self.cv2 is None or self.app is None:
            return None
        img = self.cv2.imread(image_path, self.cv2.IMREAD_COLOR)
        if img is None:
            return {"ok": False, "reason": "unreadable_image", "actor_index": actor_index}
        height, width = img.shape[:2]
        try:
            faces = self.app.get(img)
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
        return face_payload(
            actor_index,
            width,
            height,
            getattr(face, "bbox", [0, 0, 0, 0]),
            "insightface",
            len(faces),
            float(getattr(face, "det_score", 0.0)),
            getattr(face, "kps", None),
        )


class HaarDetector:
    def __init__(self):
        self.cv2 = None
        self.cascade = None
        try:
            import cv2  # type: ignore
            cascade_path = os.path.join(cv2.data.haarcascades, "haarcascade_frontalface_default.xml")
            self.cascade = cv2.CascadeClassifier(cascade_path)
            self.cv2 = cv2
        except Exception as exc:
            diag(f"opencv init failed: {exc}")

    def detect(self, image_path, actor_index):
        if self.cv2 is None or self.cascade is None:
            return None
        img = self.cv2.imread(image_path, self.cv2.IMREAD_UNCHANGED)
        if img is None:
            return {"ok": False, "reason": "unreadable_image", "actor_index": actor_index}
        height, width = img.shape[:2]
        gray = self.cv2.cvtColor(img[:, :, :3], self.cv2.COLOR_BGR2GRAY)
        faces = self.cascade.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=4, minSize=(32, 32))
        if len(faces) == 0:
            return None
        faces = sorted(faces, key=lambda f: int(f[2]) * int(f[3]), reverse=True)
        x, y, w, h = [int(v) for v in faces[0]]
        payload = face_payload(actor_index, width, height, [x, y, x + w, y + h], "opencv_haar", len(faces), min(0.75, 0.45 + (len(faces) * 0.04)), None)
        payload["fallback_from"] = "insightface"
        return payload


def main():
    parser = argparse.ArgumentParser(description="Batch detect face anchors for actor cutouts.")
    parser.add_argument("--input-manifest", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    try:
        with open(args.input_manifest, "r", encoding="utf-8") as fh:
            items = json.load(fh)
    except Exception as exc:
        return emit({"ok": False, "reason": "unreadable_input_manifest", "error": str(exc)}, 1)

    if not isinstance(items, list):
        return emit({"ok": False, "reason": "invalid_input_manifest"}, 1)

    insight = InsightDetector()
    haar = HaarDetector()
    actors = {}
    batch_method = "insightface" if insight.app is not None else "opencv_haar"

    for item in items:
        actor_index = int(item.get("actor_index", 0))
        image = str(item.get("image", ""))
        if not image or not os.path.exists(image):
            actors[str(actor_index)] = {"ok": False, "reason": "missing_image", "actor_index": actor_index}
            continue
        result = insight.detect(image, actor_index)
        if not (isinstance(result, dict) and result.get("ok")):
            result = haar.detect(image, actor_index)
        if not (isinstance(result, dict) and result.get("ok")):
            result = {"ok": False, "reason": "no_face_detected", "actor_index": actor_index}
        actors[str(actor_index)] = result

    payload = {
        "ok": True,
        "method": batch_method,
        "actors": actors,
    }
    try:
        os.makedirs(os.path.dirname(args.output), exist_ok=True)
        with open(args.output, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, indent=2)
    except Exception as exc:
        return emit({"ok": False, "reason": "output_write_failed", "error": str(exc), "actors": actors}, 1)

    return emit(payload, 0)


if __name__ == "__main__":
    sys.exit(main())
