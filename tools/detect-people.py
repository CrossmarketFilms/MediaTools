#!/usr/bin/env python3
import json
import sys


def emit(payload):
    print(json.dumps(payload))


missing = []
try:
    import cv2
except Exception:
    cv2 = None
    missing.append("cv2")

try:
    import numpy as np
except Exception:
    np = None
    missing.append("numpy")


def main():
    if len(sys.argv) < 2:
        emit({"ok": False, "error": "missing_image_path", "person_count": 0, "silhouette_count": 0})
        return 2

    if missing:
        emit({"ok": False, "error": "missing_dependency", "missing": missing, "person_count": 0, "silhouette_count": 0})
        return 0

    image_path = sys.argv[1]
    image = cv2.imread(image_path)
    if image is None:
        emit({"ok": False, "error": "image_read_failed", "person_count": 0, "silhouette_count": 0})
        return 0

    max_dim = 960
    height, width = image.shape[:2]
    scale = min(1.0, max_dim / float(max(width, height)))
    if scale < 1.0:
        image = cv2.resize(image, (int(width * scale), int(height * scale)), interpolation=cv2.INTER_AREA)

    hog = cv2.HOGDescriptor()
    hog.setSVMDetector(cv2.HOGDescriptor_getDefaultPeopleDetector())
    rects, weights = hog.detectMultiScale(
        image,
        winStride=(8, 8),
        padding=(12, 12),
        scale=1.05,
        hitThreshold=0.0,
    )

    people = []
    for rect, weight in zip(rects, weights):
        x, y, w, h = [int(v) for v in rect]
        score = float(weight)
        if score < 0.45:
            continue
        if h < image.shape[0] * 0.12 or w < image.shape[1] * 0.025:
            continue
        people.append({"bbox": [x, y, w, h], "score": round(score, 4)})

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    edges = cv2.Canny(blurred, 48, 140)
    contours, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    silhouettes = []
    img_area = float(image.shape[0] * image.shape[1])
    for contour in contours:
        area = float(cv2.contourArea(contour))
        if area < img_area * 0.006 or area > img_area * 0.34:
            continue
        x, y, w, h = cv2.boundingRect(contour)
        if h <= 0 or w <= 0:
            continue
        aspect = h / float(w)
        if 1.45 <= aspect <= 4.8 and h > image.shape[0] * 0.16:
            silhouettes.append({"bbox": [int(x), int(y), int(w), int(h)], "area_ratio": round(area / img_area, 5)})

    emit({
        "ok": True,
        "method": "opencv_hog_contour",
        "person_count": len(people),
        "silhouette_count": len(silhouettes),
        "people": people,
        "silhouettes": silhouettes[:20],
    })
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
