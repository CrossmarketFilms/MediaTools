#!/usr/bin/env python3
import argparse, json, shutil
from pathlib import Path
import numpy as np
from PIL import Image

# Import the same deterministic geometry/identity helpers used for source selection.
import importlib.util, sys
helper_path = Path(__file__).with_name("analyze-poster-actor-sources.py")
spec = importlib.util.spec_from_file_location("actor_sources", helper_path)
actor_sources = importlib.util.module_from_spec(spec)
spec.loader.exec_module(actor_sources)

def write(path, payload):
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    Path(path).write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
    print(json.dumps(payload, indent=2, sort_keys=True))

def compare_to_anchor(image_path, anchor_json, threshold, margin):
    anchor = json.loads(Path(anchor_json).read_text(encoding="utf-8")) if Path(anchor_json).exists() else {}
    emb_path = anchor.get("anchor_embedding_path", "")
    anchor_emb = json.loads(Path(emb_path).read_text(encoding="utf-8")).get("embedding", []) if emb_path and Path(emb_path).exists() else []
    if not anchor_emb:
        return {"verified": False, "score": 0.0, "margin": 0.0, "face": {}, "geometry": {}, "reason": "no_identity_comparison"}
    img = actor_sources.load_rgba(image_path)
    faces = actor_sources.detect_faces(img)
    scores = []
    for idx, face in enumerate(faces):
        scores.append((actor_sources.cosine(anchor_emb, actor_sources.embedding_for_face(img, face["bbox"])), idx, face))
    scores.sort(reverse=True, key=lambda x: x[0])
    if not scores:
        return {"verified": False, "score": 0.0, "margin": 0.0, "face": {}, "geometry": {}, "reason": "no_face_detected"}
    second = scores[1][0] if len(scores) > 1 else 0.0
    ok = scores[0][0] >= threshold and (len(scores) == 1 or scores[0][0] - second >= margin)
    return {"verified": bool(ok), "score": round(float(scores[0][0]), 4), "margin": round(float(scores[0][0] - second), 4), "face": scores[0][2]["bbox"], "geometry": actor_sources.geometry_for(img, scores[0][2]), "reason": "" if ok else "identity_match_below_threshold_or_margin"}

def clean_alpha(src, dest):
    img = Image.open(src).convert("RGBA")
    arr = np.array(img)
    a = arr[:,:,3]
    # Conservative cleanup only: preserve hair/hats/clothing contours.
    a = np.where(a < 18, 0, a)
    a = np.where(a > 170, 255, a)
    arr[:,:,3] = a.astype(np.uint8)
    Image.fromarray(arr, "RGBA").save(dest)

def main():
    p = argparse.ArgumentParser(description="Repair poster actor layer without redrawing faces.")
    p.add_argument("--src", required=True); p.add_argument("--dest", required=True)
    p.add_argument("--actor-index", type=int, default=0)
    p.add_argument("--original-source", default="")
    p.add_argument("--identity-reference", required=True)
    p.add_argument("--report", required=True)
    p.add_argument("--threshold", type=float, default=0.62)
    p.add_argument("--margin", type=float, default=0.08)
    args = p.parse_args()
    src_cmp = compare_to_anchor(args.src, args.identity_reference, args.threshold, args.margin)
    report = {"ok": False, "repair_mode": "derivative_cleanup", "input_derivative": args.src, "original_source_used": args.original_source, "identity_reference_type": "actor_identity_anchor_json", "actor_index": args.actor_index, "source_face_match_verified": src_cmp["verified"], "matched_face_score": src_cmp["score"], "match_margin": src_cmp["margin"], "matched_face_bbox": src_cmp["face"], "output_face_match_verified": False, "output_face_match_score": 0.0, "repair_actions": [], "failure_reason": ""}
    if not src_cmp["verified"]:
        report["failure_reason"] = src_cmp["reason"] or "source_identity_not_verified"
        write(args.report, report); return
    clean_alpha(args.src, args.dest)
    out_cmp = compare_to_anchor(args.dest, args.identity_reference, args.threshold, args.margin)
    report["output_face_match_verified"] = out_cmp["verified"]
    report["output_face_match_score"] = out_cmp["score"]
    report["output_match_margin"] = out_cmp["margin"]
    geom = out_cmp.get("geometry") or {}
    report.update({
        "face_bbox": geom.get("face_bbox", {}),
        "chin_y": geom.get("estimated_chin_y", 0),
        "shoulder_line_y": geom.get("shoulder_line_y", 0),
        "shoulder_width": geom.get("shoulder_width", 0),
        "torso_bottom_y": geom.get("torso_bottom_y", 0),
        "body_below_chin_ratio": geom.get("body_below_chin_ratio", 0),
        "face_to_subject_height_ratio": geom.get("face_to_subject_height_ratio", 0),
        "crop_classification": geom.get("crop_classification", ""),
        "geometry_confidence": geom.get("geometry_confidence", 0),
        "repair_actions": ["conservative_alpha_cleanup"],
    })
    required = [report["face_bbox"], report["chin_y"], report["shoulder_width"], report["torso_bottom_y"]]
    if not out_cmp["verified"]:
        report["failure_reason"] = out_cmp["reason"] or "output_identity_not_verified"
    elif any(v == 0 or v == {} for v in required):
        report["failure_reason"] = "recovery_actor_geometry_incomplete"
    elif report["crop_classification"] not in ("bust", "half_body"):
        report["failure_reason"] = "actor_layer_body_source_unavailable"
    else:
        report["ok"] = True
    write(args.report, report)

if __name__ == "__main__":
    main()
