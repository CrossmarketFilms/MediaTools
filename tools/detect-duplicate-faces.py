#!/opt/cmsg-bgremove/bin/python

import json
import os
import sys

missing_dependencies = []

try:
    import cv2
except Exception:
    cv2 = None
    missing_dependencies.append("cv2")

try:
    import numpy as np
except Exception:
    np = None
    missing_dependencies.append("numpy")

try:
    import insightface
except Exception:
    insightface = None

try:
    import onnxruntime
except Exception:
    onnxruntime = None


def dependency_report():
    missing = []
    if cv2 is None:
        missing.append("cv2")
    if np is None:
        missing.append("numpy")
    if insightface is None:
        missing.append("insightface")
    if onnxruntime is None:
        missing.append("onnxruntime")
    return missing


def normalize_bbox(bbox):
    return [int(round(float(v))) for v in bbox[:4]]


def cosine_similarity(a, b):
    a = np.asarray(a, dtype=np.float32)
    b = np.asarray(b, dtype=np.float32)
    denom = float(np.linalg.norm(a) * np.linalg.norm(b))
    if denom <= 0:
        return 0.0
    return float(np.dot(a, b) / denom)


def build_face_app():
    app = insightface.app.FaceAnalysis(
        name="buffalo_l",
        providers=["CPUExecutionProvider"],
    )
    app.prepare(ctx_id=-1, det_size=(640, 640))
    return app


def extract_face_records(app, image, max_faces=None):
    faces = app.get(image)

    face_records = []
    for index, face in enumerate(faces):
        embedding = getattr(face, "normed_embedding", None)
        if embedding is None:
            embedding = getattr(face, "embedding", None)
        if embedding is None:
            continue

        face_records.append({
            "index": index,
            "bbox": normalize_bbox(getattr(face, "bbox", [0, 0, 0, 0])),
            "embedding": np.asarray(embedding, dtype=np.float32),
            "area": float(max(0, face.bbox[2] - face.bbox[0]) * max(0, face.bbox[3] - face.bbox[1])) if hasattr(face, "bbox") else 0.0,
        })

    face_records = sorted(face_records, key=lambda row: row["area"], reverse=True)
    if max_faces is not None:
        face_records = face_records[:max_faces]

    for next_index, record in enumerate(face_records):
        record["index"] = next_index

    return face_records


def run_embedding_detector(image_path, threshold, reference_paths=None):
    reference_paths = reference_paths or []
    image = cv2.imread(image_path)
    if image is None:
        return {"ok": False, "error": "Could not read image"}

    app = build_face_app()
    face_records = extract_face_records(app, image)

    duplicate_pairs = []
    pair_scores = []
    for i in range(len(face_records)):
        for j in range(i + 1, len(face_records)):
            similarity = cosine_similarity(face_records[i]["embedding"], face_records[j]["embedding"])
            pair = {
                "a": face_records[i]["index"],
                "b": face_records[j]["index"],
                "similarity": round(similarity, 4),
                "box_a": face_records[i]["bbox"],
                "box_b": face_records[j]["bbox"],
            }
            pair_scores.append(pair)
            if similarity >= threshold:
                duplicate_pairs.append(pair)

    reference_pair_scores = []
    repeated_reference_matches = []
    for ref_index, reference_path in enumerate(reference_paths):
        ref_image = cv2.imread(reference_path)
        if ref_image is None:
            continue

        ref_faces = extract_face_records(app, ref_image, max_faces=1)
        if not ref_faces:
            continue

        ref_face = ref_faces[0]
        matches = []
        for face in face_records:
            similarity = cosine_similarity(ref_face["embedding"], face["embedding"])
            score = {
                "reference_index": ref_index,
                "reference_file": os.path.basename(reference_path),
                "face_index": face["index"],
                "similarity": round(similarity, 4),
                "reference_box": ref_face["bbox"],
                "face_box": face["bbox"],
            }
            reference_pair_scores.append(score)
            if similarity >= threshold:
                matches.append(score)

        if len(matches) > 1:
            repeated_reference_matches.append({
                "reference_index": ref_index,
                "reference_file": os.path.basename(reference_path),
                "matches": matches,
            })

    reasons = []
    if duplicate_pairs:
        reasons.append("similar_face_pairs")
    if repeated_reference_matches:
        reasons.append("reference_identity_repeated")

    likely_duplicate = bool(duplicate_pairs) or bool(repeated_reference_matches)
    return {
        "ok": True,
        "method": "embedding",
        "face_count": len(face_records),
        "detected_face_count": len(face_records),
        "duplicate_pairs": duplicate_pairs[:20],
        "pair_scores": pair_scores[:100],
        "reference_duplicate_pairs": repeated_reference_matches[:20],
        "reference_pair_scores": reference_pair_scores[:200],
        "reference_count": len(reference_paths),
        "threshold": threshold,
        "error": None,
        "likely_duplicate": likely_duplicate,
        "reasons": reasons,
        "warnings": [],
    }


def dhash(image, hash_size=8):
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    resized = cv2.resize(gray, (hash_size + 1, hash_size), interpolation=cv2.INTER_AREA)
    diff = resized[:, 1:] > resized[:, :-1]
    value = 0
    for bit in diff.flatten():
        value = (value << 1) | int(bit)
    return value


def hamming(a, b):
    return int((a ^ b).bit_count())


def face_descriptor(face):
    face = cv2.resize(face, (96, 96), interpolation=cv2.INTER_AREA)
    lab = cv2.cvtColor(face, cv2.COLOR_BGR2LAB)
    hist = cv2.calcHist([lab], [0, 1, 2], None, [16, 12, 12], [0, 256, 0, 256, 0, 256])
    cv2.normalize(hist, hist)
    return {
        "hash": dhash(face),
        "hist": hist.flatten(),
    }


def hist_similarity(a, b):
    return float(cv2.compareHist(a.astype("float32"), b.astype("float32"), cv2.HISTCMP_CORREL))


def run_heuristic_detector(image_path, expected_cast_count, embedding_missing):
    if cv2 is None or np is None:
        return {
            "ok": False,
            "error": "missing_dependency",
            "missing": dependency_report(),
        }

    image = cv2.imread(image_path)
    if image is None:
        return {"ok": False, "error": "Could not read image"}

    cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
    face_cascade = cv2.CascadeClassifier(cascade_path)
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    faces = face_cascade.detectMultiScale(
        gray,
        scaleFactor=1.06,
        minNeighbors=4,
        minSize=(38, 38),
    )

    h, w = image.shape[:2]
    min_area = max(900, int(w * h * 0.0012))
    normalized_faces = []
    for x, y, fw, fh in faces:
        area = int(fw * fh)
        if area < min_area:
            continue
        normalized_faces.append((int(x), int(y), int(fw), int(fh), area))

    normalized_faces = sorted(normalized_faces, key=lambda r: r[4], reverse=True)

    descriptors = []
    face_boxes = []
    for x, y, fw, fh, _area in normalized_faces:
        pad_x = int(fw * 0.12)
        pad_y = int(fh * 0.16)
        x1 = max(0, x - pad_x)
        y1 = max(0, y - pad_y)
        x2 = min(w, x + fw + pad_x)
        y2 = min(h, y + fh + pad_y)
        crop = image[y1:y2, x1:x2]
        if crop.size == 0:
            continue
        descriptors.append(face_descriptor(crop))
        face_boxes.append([x1, y1, x2, y2])

    duplicate_pairs = []
    pair_scores = []
    for i in range(len(descriptors)):
        for j in range(i + 1, len(descriptors)):
            ham = hamming(descriptors[i]["hash"], descriptors[j]["hash"])
            hist = hist_similarity(descriptors[i]["hist"], descriptors[j]["hist"])
            pair = {
                "a": i,
                "b": j,
                "hash_distance": ham,
                "hist_similarity": round(hist, 4),
                "box_a": face_boxes[i],
                "box_b": face_boxes[j],
            }
            pair_scores.append(pair)
            if ham <= 8 and hist >= 0.82:
                duplicate_pairs.append(pair)

    face_count = len(descriptors)
    excess_faces = expected_cast_count > 0 and face_count > expected_cast_count + 1
    likely_duplicate = bool(duplicate_pairs)

    warnings = []
    if excess_faces:
        warnings.append("face_count_exceeds_cast_count")
    if embedding_missing:
        warnings.append("embedding_detector_unavailable_using_heuristic")

    return {
        "ok": True,
        "method": "heuristic",
        "face_count": face_count,
        "detected_face_count": face_count,
        "duplicate_pairs": duplicate_pairs[:20],
        "pair_scores": pair_scores[:100],
        "threshold": None,
        "error": None,
        "likely_duplicate": likely_duplicate,
        "reasons": ["similar_face_pairs"] if likely_duplicate else [],
        "warnings": warnings,
        "embedding_missing_dependencies": embedding_missing,
    }


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"ok": False, "error": "Usage: detect-duplicate-faces.py image expected_cast_count [similarity_threshold] [reference_image ...]"}))
        return 2

    image_path = sys.argv[1]
    try:
        expected_cast_count = max(0, int(sys.argv[2]))
    except Exception:
        expected_cast_count = 0

    try:
        threshold = float(sys.argv[3]) if len(sys.argv) >= 4 else 0.62
    except Exception:
        threshold = 0.62

    threshold = max(0.05, min(0.99, threshold))
    reference_paths = sys.argv[4:] if len(sys.argv) > 4 else []

    embedding_missing = []
    if insightface is None:
        embedding_missing.append("insightface")
    if onnxruntime is None:
        embedding_missing.append("onnxruntime")

    if cv2 is not None and np is not None and not embedding_missing:
        try:
            print(json.dumps(run_embedding_detector(image_path, threshold, reference_paths)))
            return 0
        except Exception as exc:
            fallback = run_heuristic_detector(image_path, expected_cast_count, ["embedding_runtime_error"])
            fallback["embedding_error"] = str(exc)
            print(json.dumps(fallback))
            return 1 if fallback.get("likely_duplicate") else 0

    result = run_heuristic_detector(image_path, expected_cast_count, embedding_missing)
    print(json.dumps(result))
    return 1 if result.get("likely_duplicate") else 0


if __name__ == "__main__":
    sys.exit(main())
