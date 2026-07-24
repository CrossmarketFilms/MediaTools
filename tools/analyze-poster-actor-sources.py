#!/usr/bin/env python3
import argparse, json, math, os
from pathlib import Path
import numpy as np
from PIL import Image

FACE_COMPONENT_MIN_PIXELS = 80
FACE_COMPONENT_MIN_BODY_PIXELS = 160
FACE_COMPONENT_MIN_SUBJECT_AREA = 0.006
FACE_COMPONENT_MIN_ASPECT = 0.42
FACE_COMPONENT_MAX_ASPECT = 1.65
FACE_COMPONENT_MAX_BODY_SOURCE_AREA = 0.42
FACE_COMPONENT_MAX_BODY_SOURCE_WIDTH = 0.68
FACE_COMPONENT_MAX_BODY_SOURCE_HEIGHT = 0.68
FACE_COMPONENT_MAX_ANCHOR_NORMAL_AREA = 0.50
FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_AREA = 0.58
FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_WIDTH = 0.75
FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_HEIGHT = 0.74
FACE_COMPONENT_UPPER_SUBJECT_CENTER_MAX = 0.62
FACE_COMPONENT_UPPER_SUBJECT_TOP_MAX = 0.46
FACE_COMPONENT_CLOSEUP_TOP_MAX = 0.12

try:
    import cv2  # type: ignore
except Exception:
    cv2 = None

def emit(path, payload):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
    print(json.dumps(payload, indent=2, sort_keys=True))

def load_rgba(path):
    return Image.open(path).convert("RGBA")

def components(mask):
    h, w = mask.shape
    seen = np.zeros(mask.shape, dtype=bool)
    out = []
    for y in range(h):
        xs = np.where(mask[y] & ~seen[y])[0]
        for x0 in xs:
            if seen[y, x0] or not mask[y, x0]:
                continue
            stack = [(x0, y)]
            seen[y, x0] = True
            pts = []
            while stack:
                x, yy = stack.pop()
                pts.append((x, yy))
                for nx, ny in ((x+1, yy), (x-1, yy), (x, yy+1), (x, yy-1)):
                    if 0 <= nx < w and 0 <= ny < h and mask[ny, nx] and not seen[ny, nx]:
                        seen[ny, nx] = True
                        stack.append((nx, ny))
            if len(pts) >= 50:
                arr = np.array(pts)
                out.append({"x": int(arr[:,0].min()), "y": int(arr[:,1].min()), "w": int(arr[:,0].max()-arr[:,0].min()+1), "h": int(arr[:,1].max()-arr[:,1].min()+1), "area": int(len(pts))})
    return out

def face_component_subject_metrics(bbox, subject):
    if not bbox or not subject:
        return {
            "face_width_to_subject_width": 0.0,
            "face_height_to_subject_height": 0.0,
            "face_area_to_subject_area": 0.0,
            "component_aspect_ratio": 0.0,
            "component_top_to_subject": 0.0,
            "component_center_y_to_subject": 0.0,
            "component_bottom_to_subject": 0.0,
            "upper_subject_position_score": 0.0,
        }
    sw = max(1.0, float(subject.get("w", 1)))
    sh = max(1.0, float(subject.get("h", 1)))
    sx = float(subject.get("x", 0))
    sy = float(subject.get("y", 0))
    x = float(bbox.get("x", 0))
    y = float(bbox.get("y", 0))
    w = float(bbox.get("w", 0))
    h = float(bbox.get("h", 0))
    center_y_norm = ((y + h / 2.0) - sy) / sh
    top_norm = (y - sy) / sh
    bottom_norm = ((y + h) - sy) / sh
    upper_score = max(0.0, 1.0 - max(0.0, center_y_norm) / FACE_COMPONENT_UPPER_SUBJECT_CENTER_MAX)
    return {
        "face_width_to_subject_width": round(float(w / sw), 6),
        "face_height_to_subject_height": round(float(h / sh), 6),
        "face_area_to_subject_area": round(float((w * h) / max(1.0, sw * sh)), 6),
        "component_aspect_ratio": round(float(w / max(1.0, h)), 4),
        "component_top_to_subject": round(float(top_norm), 6),
        "component_center_y_to_subject": round(float(center_y_norm), 6),
        "component_bottom_to_subject": round(float(bottom_norm), 6),
        "upper_subject_position_score": round(float(upper_score), 4),
    }

def component_face_plausibility(bbox, subject, mode="body_source", detector_method="skin_alpha_geometry"):
    metrics = face_component_subject_metrics(bbox, subject)
    reasons = []
    area = int(bbox.get("w", 0)) * int(bbox.get("h", 0)) if bbox else 0
    area_ratio = float(metrics["face_area_to_subject_area"])
    width_ratio = float(metrics["face_width_to_subject_width"])
    height_ratio = float(metrics["face_height_to_subject_height"])
    aspect = float(metrics["component_aspect_ratio"])
    center_y = float(metrics["component_center_y_to_subject"])
    top_y = float(metrics["component_top_to_subject"])
    closeup_identity_reference = False
    if area < FACE_COMPONENT_MIN_PIXELS:
        reasons.append("face_area_below_minimum_pixels")
    if aspect < FACE_COMPONENT_MIN_ASPECT or aspect > FACE_COMPONENT_MAX_ASPECT:
        reasons.append("implausible_face_proportions")
    if center_y > FACE_COMPONENT_UPPER_SUBJECT_CENTER_MAX and top_y > FACE_COMPONENT_UPPER_SUBJECT_TOP_MAX:
        reasons.append("face_component_not_in_upper_subject_region")
    if mode == "body_source":
        if area < FACE_COMPONENT_MIN_BODY_PIXELS:
            reasons.append("face_area_below_body_source_minimum_pixels")
        if area_ratio < FACE_COMPONENT_MIN_SUBJECT_AREA:
            reasons.append("face_area_below_subject_fraction")
        if (
            area_ratio > FACE_COMPONENT_MAX_BODY_SOURCE_AREA
            or width_ratio > FACE_COMPONENT_MAX_BODY_SOURCE_WIDTH
            or height_ratio > FACE_COMPONENT_MAX_BODY_SOURCE_HEIGHT
        ):
            reasons.append("face_component_exceeds_subject_geometry")
    else:
        if area_ratio > FACE_COMPONENT_MAX_ANCHOR_NORMAL_AREA:
            closeup_identity_reference = (
                area_ratio <= FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_AREA
                and width_ratio <= FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_WIDTH
                and height_ratio <= FACE_COMPONENT_MAX_ANCHOR_CLOSEUP_HEIGHT
                and top_y <= FACE_COMPONENT_CLOSEUP_TOP_MAX
                and center_y <= FACE_COMPONENT_UPPER_SUBJECT_CENTER_MAX
            )
            if not closeup_identity_reference:
                reasons.append("implausible_face_to_subject_geometry")
    plausible = len(reasons) == 0
    return {
        **metrics,
        "plausible_face_component": bool(plausible),
        "closeup_identity_reference": bool(closeup_identity_reference),
        "face_component_mode": mode,
        "face_component_detector": detector_method,
        "face_component_rejection_reason": ", ".join(reasons),
        "implausibly_large_face_component": bool(
            "face_component_exceeds_subject_geometry" in reasons
            or "implausible_face_to_subject_geometry" in reasons
        ),
    }

def opencv_detect_faces(img, subject, mode="body_source"):
    if cv2 is None:
        return []
    try:
        gray = cv2.cvtColor(np.array(img.convert("RGB")), cv2.COLOR_RGB2GRAY)
        cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
        cascade = cv2.CascadeClassifier(cascade_path)
        if cascade.empty():
            return []
        detections = cascade.detectMultiScale(gray, scaleFactor=1.08, minNeighbors=4, minSize=(28, 28))
    except Exception:
        return []
    faces = []
    for x, y, w, h in detections:
        bbox = {"x": int(x), "y": int(y), "w": int(w), "h": int(h)}
        plausibility = component_face_plausibility(bbox, subject, mode, "opencv_haar")
        faces.append({
            "bbox": bbox,
            "score": 0.82,
            "method": "opencv_haar",
            **plausibility,
        })
    return faces

def skin_component_face_candidates(img, subject, mode="body_source"):
    arr = np.array(img)
    rgb = arr[:, :, :3].astype(np.int16)
    alpha = arr[:, :, 3] > 20
    # Synthetic-friendly skin/face heuristic. InsightFace may run on VPS in future,
    # but this path is deterministic and does not guess identity from face size.
    skin = alpha & (rgb[:,:,0] > 80) & (rgb[:,:,1] > 45) & (rgb[:,:,2] > 25) & (rgb[:,:,0] >= rgb[:,:,1]) & (rgb[:,:,1] >= rgb[:,:,2] - 20)
    comps = components(skin)
    faces = []
    for c in comps:
        ratio = c["w"] / max(1, c["h"])
        if c["area"] < FACE_COMPONENT_MIN_PIXELS:
            continue
        bbox = {"x": c["x"], "y": c["y"], "w": c["w"], "h": c["h"]}
        plausibility = component_face_plausibility(bbox, subject, mode, "skin_alpha_geometry")
        faces.append({
            "bbox": bbox,
            "score": min(0.99, 0.55 + c["area"] / max(1, img.size[0] * img.size[1])),
            "method": "skin_alpha_geometry",
            "raw_component_area": int(c["area"]),
            "raw_component_aspect_ratio": round(float(ratio), 4),
            **plausibility,
        })
    faces.sort(key=lambda f: (f["score"], f["bbox"]["w"] * f["bbox"]["h"]), reverse=True)
    return faces

def detect_faces(img, mode="body_source"):
    subject = alpha_bounds(img)
    faces = opencv_detect_faces(img, subject, mode)
    if not any(face.get("plausible_face_component", True) for face in faces):
        faces = skin_component_face_candidates(img, subject, mode)
    faces.sort(key=lambda f: (bool(f.get("plausible_face_component", True)), f["score"], f["bbox"]["w"] * f["bbox"]["h"]), reverse=True)
    return faces

def face_sharpness(img, bbox):
    arr = crop_arr(img, bbox)
    alpha = arr[:, :, 3] > 20
    if arr.size == 0 or not np.any(alpha):
        return 0.0
    gray = (
        arr[:, :, 0].astype(np.float32) * 0.299
        + arr[:, :, 1].astype(np.float32) * 0.587
        + arr[:, :, 2].astype(np.float32) * 0.114
    )
    gray = np.where(alpha, gray, 0.0)
    if gray.shape[0] < 3 or gray.shape[1] < 3:
        return 0.0
    gx = np.diff(gray, axis=1)
    gy = np.diff(gray, axis=0)
    return float(np.var(gx) + np.var(gy))

def score_identity_anchor_faces(img, faces):
    width, height = img.size
    img_area = max(1, width * height)
    image_center_x = width / 2.0
    image_center_y = height / 2.0
    max_center_distance = max(1.0, math.hypot(image_center_x, image_center_y))
    candidates = []
    max_area = 1.0
    max_sharpness = 1.0
    for idx, face in enumerate(faces):
        bbox = face.get("bbox", {})
        area = float(max(1, int(bbox.get("w", 0)) * int(bbox.get("h", 0))))
        sharpness = face_sharpness(img, bbox) if bbox else 0.0
        max_area = max(max_area, area)
        max_sharpness = max(max_sharpness, sharpness)
        candidates.append({
            "candidate_index": int(idx),
            "bbox": bbox,
            "detector_confidence": round(float(face.get("score", 0.0)), 4),
            "detector_method": face.get("method", ""),
            "plausible_face": bool(face.get("plausible_face_component", True)),
            "rejection_reason": face.get("face_component_rejection_reason", ""),
            "closeup_identity_reference": bool(face.get("closeup_identity_reference", False)),
            "face_width_to_subject_width": face.get("face_width_to_subject_width", 0.0),
            "face_height_to_subject_height": face.get("face_height_to_subject_height", 0.0),
            "face_area_to_subject_area": face.get("face_area_to_subject_area", 0.0),
            "component_aspect_ratio": face.get("component_aspect_ratio", 0.0),
            "component_top_to_subject": face.get("component_top_to_subject", 0.0),
            "component_center_y_to_subject": face.get("component_center_y_to_subject", 0.0),
            "component_bottom_to_subject": face.get("component_bottom_to_subject", 0.0),
            "upper_subject_position_score": face.get("upper_subject_position_score", 0.0),
            "implausibly_large_face_component": bool(face.get("implausibly_large_face_component", False)),
            "area": int(area),
            "normalized_area": round(float(area / img_area), 6),
            "center_x": round(float(int(bbox.get("x", 0)) + int(bbox.get("w", 0)) / 2.0), 2),
            "center_y": round(float(int(bbox.get("y", 0)) + int(bbox.get("h", 0)) / 2.0), 2),
            "center_score": 0.0,
            "sharpness": round(float(sharpness), 4),
            "sharpness_score": 0.0,
            "combined_score": 0.0,
            "_raw_face": face,
        })
    for candidate in candidates:
        center_distance = math.hypot(float(candidate["center_x"]) - image_center_x, float(candidate["center_y"]) - image_center_y)
        center_score = max(0.0, 1.0 - center_distance / max_center_distance)
        area_score = float(candidate["area"]) / max_area
        sharpness_score = float(candidate["sharpness"]) / max_sharpness if max_sharpness > 0 else 0.0
        detector_confidence = float(candidate["detector_confidence"])
        plausible_weight = 1.0 if candidate["plausible_face"] else 0.0
        combined_score = (area_score * 0.46 + center_score * 0.24 + detector_confidence * 0.20 + sharpness_score * 0.10) * plausible_weight
        candidate["area_score"] = round(float(area_score), 4)
        candidate["center_score"] = round(float(center_score), 4)
        candidate["sharpness_score"] = round(float(sharpness_score), 4)
        candidate["combined_score"] = round(float(combined_score), 4)
    candidates.sort(key=lambda f: (f["plausible_face"], f["combined_score"], f["area"], f["detector_confidence"], f["sharpness"]), reverse=True)
    return candidates

def choose_identity_anchor_face(img, faces):
    candidates = score_identity_anchor_faces(img, faces)
    plausible_candidates = [
        candidate for candidate in candidates
        if candidate.get("plausible_face")
        and not candidate.get("implausibly_large_face_component")
        and not str(candidate.get("rejection_reason", "")).strip()
    ]
    if not plausible_candidates:
        return None, candidates, {
            "accepted": False,
            "acceptance_reason": "",
            "rejection_reason": "actor_identity_anchor_not_found",
            "selection_method": "no_valid_plausible_face",
            "explanation": "Detected components were rejected as unreliable face geometry." if candidates else "No usable face candidates were detected in the actor reference.",
        }
    if len(plausible_candidates) == 1:
        return plausible_candidates[0], candidates, {
            "accepted": True,
            "acceptance_reason": "single_valid_face" if not plausible_candidates[0].get("closeup_identity_reference") else "single_valid_closeup_identity_face",
            "rejection_reason": "",
            "selection_method": "single_valid_face" if not plausible_candidates[0].get("closeup_identity_reference") else "single_valid_closeup_identity_face",
            "explanation": "Exactly one usable face candidate was detected.",
            "selected_candidate_index": int(plausible_candidates[0]["candidate_index"]),
            "selected_score": float(plausible_candidates[0]["combined_score"]),
            "runner_up_score": 0.0,
            "score_margin": float(plausible_candidates[0]["combined_score"]),
        }
    top = plausible_candidates[0]
    runner_up = plausible_candidates[1]
    selected_score = float(top["combined_score"])
    runner_up_score = float(runner_up["combined_score"])
    score_margin = selected_score - runner_up_score
    area_ratio = float(top["area"]) / max(1.0, float(runner_up["area"]))
    confidence_delta = float(top["detector_confidence"]) - float(runner_up["detector_confidence"])
    top_candidates_similar = float(runner_up["area"]) >= float(top["area"]) * 0.75 and abs(confidence_delta) <= 0.12
    accepted = True
    method = "highest_ranked_prominent_face"
    explanation = (
        "Reference contains multiple detected faces, likely a poster/collage or environmental image. "
        f"Top score={selected_score:.4f}, runner-up score={runner_up_score:.4f}, "
        f"margin={score_margin:.4f}, area ratio={area_ratio:.4f}, confidence delta={confidence_delta:.4f}. "
        "The highest-ranked valid plausible face was selected as the identity anchor."
    )
    return top, candidates, {
        "accepted": bool(accepted),
        "acceptance_reason": "highest_ranked_valid_face_selected",
        "rejection_reason": "",
        "selection_method": method,
        "explanation": explanation,
        "selected_candidate_index": int(top["candidate_index"]),
        "selected_score": round(selected_score, 4),
        "runner_up_score": round(runner_up_score, 4),
        "score_margin": round(score_margin, 4),
        "multiple_faces_detected": True,
        "top_area_ratio_to_runner_up": round(float(area_ratio), 4),
        "top_confidence_delta_to_runner_up": round(float(confidence_delta), 4),
        "top_candidates_similar": bool(top_candidates_similar),
    }

def alpha_bounds(img):
    a = np.array(img)[:, :, 3]
    ys, xs = np.where(a > 20)
    if len(xs) == 0:
        return {}
    return {"x": int(xs.min()), "y": int(ys.min()), "w": int(xs.max()-xs.min()+1), "h": int(ys.max()-ys.min()+1)}

def crop_arr(img, bbox):
    x, y, w, h = [int(bbox[k]) for k in ("x","y","w","h")]
    return np.array(img.crop((x, y, x+w, y+h)).convert("RGBA"))

def embedding_for_face(img, bbox):
    arr = crop_arr(img, bbox)
    alpha = arr[:,:,3] > 20
    if not np.any(alpha):
        return []
    rgb = arr[:,:,:3][alpha].astype(np.float32)
    hist = []
    for ch in range(3):
        h, _ = np.histogram(rgb[:,ch], bins=8, range=(0,256), density=False)
        hist.extend(h.astype(np.float32).tolist())
    vec = np.array(hist, dtype=np.float32)
    norm = float(np.linalg.norm(vec))
    return (vec / norm).tolist() if norm else []

def cosine(a, b):
    if not a or not b or len(a) != len(b):
        return 0.0
    aa = np.array(a, dtype=np.float32); bb = np.array(b, dtype=np.float32)
    den = float(np.linalg.norm(aa) * np.linalg.norm(bb))
    return float(np.dot(aa, bb) / den) if den else 0.0

def geometry_for(img, face):
    bbox = face.get("bbox", {})
    bounds = alpha_bounds(img)
    if not bbox or not bounds:
        return {"crop_classification": "unusable", "geometry_confidence": 0.0, "failure_reason": "recovery_actor_geometry_incomplete"}
    arr = np.array(img)
    alpha = arr[:,:,3] > 20
    fx, fy, fw, fh = [int(bbox[k]) for k in ("x","y","w","h")]
    chin_y = fy + fh
    below = alpha[min(chin_y + 1, alpha.shape[0]-1):, :]
    body_pixels_below_chin = int(below.sum())
    face_area = max(1, fw * fh)
    body_ratio = body_pixels_below_chin / face_area
    shoulder_y = min(alpha.shape[0]-1, int(chin_y + fh * 0.35))
    row_band = alpha[max(0, shoulder_y-4):min(alpha.shape[0], shoulder_y+5), :]
    xs = np.where(row_band.any(axis=0))[0]
    shoulder_width = int(xs.max()-xs.min()+1) if len(xs) else 0
    torso_bottom_y = int(np.where(alpha)[0].max()) if np.any(alpha) else 0
    subject_h = max(1, bounds["h"])
    shoulder_ratio = shoulder_width / max(1, fw)
    torso_h = max(0, torso_bottom_y - shoulder_y)
    face_to_subject = fh / subject_h
    top_headroom = fy - bounds["y"]
    clipping = {
        "left": bounds["x"] <= 1,
        "right": bounds["x"] + bounds["w"] >= img.size[0] - 1,
        "top": bounds["y"] <= 1,
        "bottom": bounds["y"] + bounds["h"] >= img.size[1] - 1,
    }
    if body_ratio < 0.18 or shoulder_ratio < 1.20:
        cls = "face_only"
        conf = 0.78
    elif body_ratio < 0.55 or shoulder_ratio < 1.55 or torso_h < fh * 0.30:
        cls = "head_and_neck"
        conf = 0.76
    elif body_ratio > 1.60 and torso_h > fh * 1.10:
        cls = "half_body"
        conf = 0.86
    else:
        cls = "bust"
        conf = 0.84
    return {
        "face_bbox": bbox,
        "face_height": fh,
        "face_width": fw,
        "eye_line_y": int(fy + fh * 0.38),
        "estimated_chin_y": int(chin_y),
        "subject_alpha_bounds": bounds,
        "body_pixels_below_chin": body_pixels_below_chin,
        "body_below_chin_ratio": round(float(body_ratio), 4),
        "shoulder_line_y": int(shoulder_y),
        "shoulder_width": shoulder_width,
        "shoulder_width_to_face_width": round(float(shoulder_ratio), 4),
        "torso_bottom_y": int(torso_bottom_y),
        "torso_height_below_shoulders": int(torso_h),
        "face_to_subject_height_ratio": round(float(face_to_subject), 4),
        "top_headroom": int(top_headroom),
        "clipping": clipping,
        "crop_classification": cls,
        "geometry_confidence": conf,
    }

def bbox_overlap_ratio(a, b):
    if not a or not b:
        return 0.0
    ax1, ay1 = int(a.get("x", 0)), int(a.get("y", 0))
    ax2, ay2 = ax1 + int(a.get("w", 0)), ay1 + int(a.get("h", 0))
    bx1, by1 = int(b.get("x", 0)), int(b.get("y", 0))
    bx2, by2 = bx1 + int(b.get("w", 0)), by1 + int(b.get("h", 0))
    ix1, iy1 = max(ax1, bx1), max(ay1, by1)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    if ix2 <= ix1 or iy2 <= iy1:
        return 0.0
    intersection = float((ix2 - ix1) * (iy2 - iy1))
    area = float(max(1, int(a.get("w", 0)) * int(a.get("h", 0))))
    return intersection / area

def expanded_bbox(bbox, ratio=0.18):
    if not bbox:
        return {}
    pad_x = int(round(int(bbox.get("w", 0)) * ratio))
    pad_y = int(round(int(bbox.get("h", 0)) * ratio))
    return {
        "x": int(bbox.get("x", 0)) - pad_x,
        "y": int(bbox.get("y", 0)) - pad_y,
        "w": int(bbox.get("w", 0)) + pad_x * 2,
        "h": int(bbox.get("h", 0)) + pad_y * 2,
    }

def project_anchor_bbox_to_candidate(anchor):
    anchor_bbox = anchor.get("anchor_face_bbox", {}) if isinstance(anchor, dict) else {}
    geometry = anchor.get("geometry", {}) if isinstance(anchor, dict) else {}
    anchor_subject = geometry.get("subject_alpha_bounds", {}) if isinstance(geometry, dict) else {}
    if not anchor_bbox or not anchor_subject:
        return None
    def project(candidate_subject):
        if not candidate_subject:
            return {}
        aw = max(1.0, float(anchor_subject.get("w", 1)))
        ah = max(1.0, float(anchor_subject.get("h", 1)))
        rel_x = (float(anchor_bbox.get("x", 0)) - float(anchor_subject.get("x", 0))) / aw
        rel_y = (float(anchor_bbox.get("y", 0)) - float(anchor_subject.get("y", 0))) / ah
        rel_w = float(anchor_bbox.get("w", 0)) / aw
        rel_h = float(anchor_bbox.get("h", 0)) / ah
        return {
            "x": int(round(float(candidate_subject.get("x", 0)) + rel_x * float(candidate_subject.get("w", 0)))),
            "y": int(round(float(candidate_subject.get("y", 0)) + rel_y * float(candidate_subject.get("h", 0)))),
            "w": int(round(rel_w * float(candidate_subject.get("w", 0)))),
            "h": int(round(rel_h * float(candidate_subject.get("h", 0)))),
        }
    return project

def anchor_geometry_correspondence(bbox, projected_anchor_bbox):
    if not bbox or not projected_anchor_bbox:
        return {
            "area_ratio_to_projected_anchor": 0.0,
            "width_ratio_to_projected_anchor": 0.0,
            "height_ratio_to_projected_anchor": 0.0,
            "geometry_corresponds_to_authoritative_anchor": False,
            "authoritative_anchor_geometry_failure_reason": "missing_candidate_or_projected_anchor_bbox",
        }
    face_area = float(max(1, int(bbox.get("w", 0)) * int(bbox.get("h", 0))))
    anchor_area = float(max(1, int(projected_anchor_bbox.get("w", 0)) * int(projected_anchor_bbox.get("h", 0))))
    width_ratio = float(int(bbox.get("w", 0))) / max(1.0, float(int(projected_anchor_bbox.get("w", 0))))
    height_ratio = float(int(bbox.get("h", 0))) / max(1.0, float(int(projected_anchor_bbox.get("h", 0))))
    area_ratio = face_area / anchor_area
    corresponds = area_ratio >= 0.25 and width_ratio >= 0.35 and height_ratio >= 0.35
    return {
        "area_ratio_to_projected_anchor": round(float(area_ratio), 6),
        "width_ratio_to_projected_anchor": round(float(width_ratio), 4),
        "height_ratio_to_projected_anchor": round(float(height_ratio), 4),
        "geometry_corresponds_to_authoritative_anchor": bool(corresponds),
        "authoritative_anchor_geometry_failure_reason": "" if corresponds else "face_geometry_mismatch_authoritative_identity_anchor",
    }

def source_face_plausibility(img, face, anchor_emb, anchor_projector=None):
    bbox = face.get("bbox", {})
    subject = alpha_bounds(img)
    width, height = img.size
    subject_area = float(max(1, int(subject.get("w", width)) * int(subject.get("h", height))))
    face_area = float(max(1, int(bbox.get("w", 0)) * int(bbox.get("h", 0))))
    normalized_area = face_area / subject_area
    ratio = float(int(bbox.get("w", 0))) / max(1.0, float(int(bbox.get("h", 0))))
    face_center_x = float(int(bbox.get("x", 0)) + int(bbox.get("w", 0)) / 2.0)
    face_center_y = float(int(bbox.get("y", 0)) + int(bbox.get("h", 0)) / 2.0)
    subject_center_x = float(int(subject.get("x", 0)) + int(subject.get("w", width)) / 2.0)
    subject_center_y = float(int(subject.get("y", 0)) + int(subject.get("h", height)) / 2.0)
    max_dist = max(1.0, math.hypot(max(1, int(subject.get("w", width))) / 2.0, max(1, int(subject.get("h", height))) / 2.0))
    center_score = max(0.0, 1.0 - math.hypot(face_center_x - subject_center_x, face_center_y - subject_center_y) / max_dist)
    subject_overlap = bbox_overlap_ratio(bbox, subject)
    projected_anchor_bbox = anchor_projector(subject) if anchor_projector else {}
    expanded_anchor_bbox = expanded_bbox(projected_anchor_bbox, 0.22) if projected_anchor_bbox else {}
    anchor_region_overlap = bbox_overlap_ratio(bbox, expanded_anchor_bbox) if expanded_anchor_bbox else 0.0
    anchor_region_consistent = True
    if projected_anchor_bbox:
        anchor_region_consistent = anchor_region_overlap >= 0.10
    anchor_geometry = anchor_geometry_correspondence(bbox, projected_anchor_bbox)
    embedding_score = cosine(anchor_emb, embedding_for_face(img, bbox))
    rejection_reasons = []
    if not face.get("plausible_face_component", True):
        rejection_reasons.append(face.get("face_component_rejection_reason") or "implausible_face_component")
    if face_area < 160:
        rejection_reasons.append("face_area_below_minimum_pixels")
    if normalized_area < 0.006:
        rejection_reasons.append("face_area_below_subject_fraction")
    if ratio < 0.42 or ratio > 1.65:
        rejection_reasons.append("implausible_face_proportions")
    if subject_overlap < 0.90:
        rejection_reasons.append("outside_dominant_subject_region")
    if center_score < 0.08:
        rejection_reasons.append("far_from_subject_region")
    if not anchor_region_consistent:
        rejection_reasons.append("outside_authoritative_identity_anchor_region")
    if not anchor_geometry["geometry_corresponds_to_authoritative_anchor"]:
        rejection_reasons.append(anchor_geometry.get("authoritative_anchor_geometry_failure_reason") or "face_geometry_mismatch_authoritative_identity_anchor")
    plausible = len(rejection_reasons) == 0
    combined = embedding_score * 0.70 + min(1.0, normalized_area * 20.0) * 0.14 + center_score * 0.10 + subject_overlap * 0.06
    return {
        "bbox": bbox,
        "detector_confidence": round(float(face.get("score", 0.0)), 4),
        "detector_method": face.get("method", ""),
        "component_plausible_face": bool(face.get("plausible_face_component", True)),
        "face_component_rejection_reason": face.get("face_component_rejection_reason", ""),
        "face_width_to_subject_width": face.get("face_width_to_subject_width", 0.0),
        "face_height_to_subject_height": face.get("face_height_to_subject_height", 0.0),
        "face_area_to_subject_area": face.get("face_area_to_subject_area", 0.0),
        "component_aspect_ratio": face.get("component_aspect_ratio", 0.0),
        "component_top_to_subject": face.get("component_top_to_subject", 0.0),
        "component_center_y_to_subject": face.get("component_center_y_to_subject", 0.0),
        "component_bottom_to_subject": face.get("component_bottom_to_subject", 0.0),
        "upper_subject_position_score": face.get("upper_subject_position_score", 0.0),
        "implausibly_large_face_component": bool(face.get("implausibly_large_face_component", False)),
        "face_area": int(face_area),
        "normalized_area_to_subject_bounds": round(float(normalized_area), 6),
        "face_aspect_ratio": round(float(ratio), 4),
        "embedding_score": round(float(embedding_score), 4),
        "subject_region_overlap": round(float(subject_overlap), 4),
        "center_score": round(float(center_score), 4),
        "projected_anchor_bbox": projected_anchor_bbox,
        "anchor_region_overlap": round(float(anchor_region_overlap), 4),
        "authoritative_anchor_region_consistent": bool(anchor_region_consistent),
        **anchor_geometry,
        "combined_source_face_score": round(float(combined), 4),
        "plausible_face": bool(plausible),
        "rejection_reason": ", ".join(rejection_reasons),
        "_raw_face": face,
        "_embedding_score_raw": float(embedding_score),
    }

def ranked_plausible_source_faces(img, faces, anchor_emb, anchor_projector=None):
    evaluated = []
    for idx, face in enumerate(faces):
        item = source_face_plausibility(img, face, anchor_emb, anchor_projector)
        item["face_index"] = int(idx)
        evaluated.append(item)
    plausible = [item for item in evaluated if item["plausible_face"]]
    plausible.sort(key=lambda f: (f["_embedding_score_raw"], f["combined_source_face_score"], f["face_area"]), reverse=True)
    return evaluated, plausible

def source_candidate_preference(item):
    candidate_type = str(item.get("candidate_type", ""))
    clipping = item.get("clipping", {})
    clipped = any(bool(v) for v in clipping.values()) if isinstance(clipping, dict) else False
    if candidate_type == "normalized_cutout" and not clipped:
        return 0.05
    if candidate_type == "raw_cutout" and not clipped:
        return 0.02
    return 0.0

def identity_anchor(args):
    img = load_rgba(args.source)
    faces = detect_faces(img, mode="identity_anchor")
    selected, face_candidates, decision = choose_identity_anchor_face(img, faces)
    public_candidates = [{k: v for k, v in candidate.items() if k != "_raw_face"} for candidate in face_candidates]
    report = {
        "ok": False,
        "mode": "identity-anchor",
        "actor_id": args.actor_id,
        "actor_index": int(args.actor_index),
        "source_faces_detected": len(faces),
        "source_analysis": "multi_face_reference_likely_collage_or_poster" if len(faces) > 1 else "single_face_or_simple_portrait",
        "detected_faces": public_candidates,
        "identity_anchor_candidates": public_candidates,
        "selected_candidate": {k: v for k, v in selected.items() if k != "_raw_face"} if selected else {},
        "selected_candidate_index": int(decision.get("selected_candidate_index", -1)),
        "selected_score": float(decision.get("selected_score", 0.0)),
        "runner_up_score": float(decision.get("runner_up_score", 0.0)),
        "score_margin": float(decision.get("score_margin", 0.0)),
        "selection_decision": decision,
        "anchor_selection_method": "",
        "identity_reference_actor_id": args.actor_id,
        "anchor_face_bbox": {},
        "anchor_embedding_path": "",
        "identity_anchor_valid": False,
        "failure_reason": "",
    }
    if len(faces) == 0:
        report["failure_reason"] = "actor_identity_anchor_unavailable"
        emit(args.output, report); return
    if not selected:
        report["failure_reason"] = decision.get("rejection_reason", "actor_identity_anchor_ambiguous")
        report["anchor_selection_method"] = decision.get("selection_method", "actor_identity_anchor_ambiguous")
        emit(args.output, report); return
    face = selected.get("_raw_face", faces[0])
    emb = embedding_for_face(img, face["bbox"])
    if not emb:
        report["failure_reason"] = "actor_identity_anchor_unavailable"
        emit(args.output, report); return
    emb_path = str(Path(args.output).with_suffix(".embedding.json"))
    Path(emb_path).write_text(json.dumps({"embedding": emb, "bbox": face["bbox"], "actor_id": args.actor_id}, indent=2), encoding="utf-8")
    if args.crop_output:
        x,y,w,h = [face["bbox"][k] for k in ("x","y","w","h")]
        img.crop((x,y,x+w,y+h)).save(args.crop_output)
    report.update({
        "ok": True,
        "anchor_selection_method": decision.get("selection_method", "dominant_face_selected"),
        "anchor_face_bbox": face["bbox"],
        "anchor_embedding_path": emb_path,
        "identity_anchor_valid": True,
        "geometry": geometry_for(img, face),
    })
    emit(args.output, report)

def select_source(args):
    payload = json.loads(Path(args.input_manifest).read_text(encoding="utf-8"))
    anchor_path = payload.get("identity_anchor_path", "")
    anchor = json.loads(Path(anchor_path).read_text(encoding="utf-8")) if anchor_path and Path(anchor_path).exists() else {}
    emb_path = anchor.get("anchor_embedding_path", "")
    anchor_emb = json.loads(Path(emb_path).read_text(encoding="utf-8")).get("embedding", []) if emb_path and Path(emb_path).exists() else []
    anchor_projector = project_anchor_bbox_to_candidate(anchor)
    report = {"ok": False, "mode": "select-source", "actor_id": payload.get("actor_id", ""), "actor_index": payload.get("actor_index", 0), "identity_anchor_authoritative": bool(anchor_projector), "anchor_face_bbox": anchor.get("anchor_face_bbox", {}) if isinstance(anchor, dict) else {}, "candidates": [], "selected_source": "", "failure_reason": ""}
    if not anchor_emb:
        report["failure_reason"] = "actor_identity_anchor_unavailable"
        emit(args.output, report); return
    best = None
    for cand in payload.get("candidates", []):
        path = cand.get("path", "")
        item = {"candidate_type": cand.get("candidate_type", ""), "path": path, "source_faces_detected": 0, "plausible_faces_detected": 0, "detected_faces": [], "matched_face_index": -1, "matched_face_bbox": {}, "matched_face_score": 0.0, "second_best_score": 0.0, "match_margin": 0.0, "plausible_match_margin": 0.0, "best_plausible_face": {}, "second_best_plausible_face": {}, "identity_verified": False, "identity_verification_decision": "", "identity_failure_reason": ""}
        if not path or not Path(path).exists():
            item["identity_failure_reason"] = "candidate_missing"
            report["candidates"].append(item); continue
        img = load_rgba(path)
        faces = detect_faces(img, mode="body_source")
        item["source_faces_detected"] = len(faces)
        candidate_subject = alpha_bounds(img)
        item["candidate_subject_alpha_bounds"] = candidate_subject
        item["projected_authoritative_anchor_bbox"] = anchor_projector(candidate_subject) if anchor_projector else {}
        evaluated, plausible = ranked_plausible_source_faces(img, faces, anchor_emb, anchor_projector)
        item["detected_faces"] = [{k: v for k, v in face.items() if not k.startswith("_")} for face in evaluated]
        item["plausible_faces_detected"] = len(plausible)
        item["authoritative_anchor_candidates"] = [{k: v for k, v in face.items() if not k.startswith("_")} for face in plausible]
        if plausible:
            best_face = plausible[0]
            second_face = plausible[1] if len(plausible) > 1 else None
            best_score = float(best_face["_embedding_score_raw"])
            second_score = float(second_face["_embedding_score_raw"]) if second_face else 0.0
            margin = best_score - second_score
            item["matched_face_score"] = round(best_score, 4)
            item["matched_face_index"] = int(best_face["face_index"])
            item["matched_face_bbox"] = best_face["bbox"]
            item["second_best_score"] = round(second_score, 4)
            item["match_margin"] = round(margin, 4)
            item["plausible_match_margin"] = round(margin, 4)
            item["authoritative_anchor_match_margin"] = round(margin, 4)
            item["best_plausible_face"] = {k: v for k, v in best_face.items() if not k.startswith("_")}
            item["second_best_plausible_face"] = {k: v for k, v in second_face.items() if not k.startswith("_")} if second_face else {}
            item.update(geometry_for(img, best_face["_raw_face"]))
            if best_score >= float(args.threshold):
                item["identity_verified"] = True
                item["identity_verification_decision"] = "identity_verified_highest_ranked_face"
                item["authoritative_anchor_verification_decision"] = item["identity_verification_decision"]
            else:
                item["identity_verification_decision"] = "identity_rejected_below_threshold"
                item["authoritative_anchor_verification_decision"] = item["identity_verification_decision"]
                item["identity_failure_reason"] = "identity_match_below_threshold"
        else:
            item["identity_failure_reason"] = "no_plausible_face_detected"
            item["identity_verification_decision"] = "identity_rejected_no_plausible_face"
            item["authoritative_anchor_verification_decision"] = "identity_rejected_no_authoritative_anchor_candidate"
        if item["identity_verified"]:
            crop_ok = item.get("crop_classification") in ("bust", "half_body")
            geom_ok = item.get("shoulder_width", 0) > 0 and item.get("torso_bottom_y", 0) > 0
            if crop_ok and geom_ok:
                item["candidate_preference_score"] = round(float(source_candidate_preference(item)), 4)
                score = item["matched_face_score"] + item.get("geometry_confidence", 0) + item["candidate_preference_score"]
                if best is None or score > best[0]:
                    best = (score, item, path)
            else:
                item["identity_failure_reason"] = "actor_layer_body_source_unavailable"
        report["candidates"].append(item)
    if best:
        _, item, path = best
        report.update({"ok": True, "selected_source": path, "selected_candidate_type": item["candidate_type"], "selected_score": round(float(best[0]), 4), "selection_reason": "identity-verified candidate with usable face/body geometry"})
        for k in ("matched_face_index","matched_face_bbox","matched_face_score","second_best_score","match_margin","plausible_match_margin","authoritative_anchor_match_margin","plausible_faces_detected","best_plausible_face","second_best_plausible_face","authoritative_anchor_candidates","identity_verified","identity_verification_decision","authoritative_anchor_verification_decision","crop_classification","geometry_confidence","face_bbox","estimated_chin_y","shoulder_line_y","shoulder_width","torso_bottom_y","body_below_chin_ratio","face_to_subject_height_ratio","candidate_preference_score"):
            if k in item: report[k] = item[k]
    else:
        report["failure_reason"] = "actor_layer_body_source_unavailable"
    emit(args.output, report)

def main():
    p = argparse.ArgumentParser(description="Analyze poster actor identity anchors and body-aware source candidates.")
    p.add_argument("--mode", required=True, choices=["identity-anchor","select-source"])
    p.add_argument("--actor-id", default="")
    p.add_argument("--actor-index", default=0, type=int)
    p.add_argument("--source", default="")
    p.add_argument("--output", required=True)
    p.add_argument("--crop-output", default="")
    p.add_argument("--input-manifest", default="")
    p.add_argument("--threshold", default=0.62, type=float)
    p.add_argument("--margin", default=0.08, type=float)
    args = p.parse_args()
    if args.mode == "identity-anchor": identity_anchor(args)
    else: select_source(args)

if __name__ == "__main__":
    main()
