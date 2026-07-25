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
    faces = actor_sources.detect_faces(img, mode="body_source")
    anchor_projector = actor_sources.project_anchor_bbox_to_candidate(anchor)
    evaluated, plausible = actor_sources.ranked_plausible_source_faces(img, faces, anchor_emb, anchor_projector)
    if not plausible:
        return {"verified": False, "score": 0.0, "margin": 0.0, "face": {}, "geometry": {}, "reason": "no_face_detected"}
    best = plausible[0]
    second = float(plausible[1]["_embedding_score_raw"]) if len(plausible) > 1 else 0.0
    score = float(best["_embedding_score_raw"])
    ok = score >= threshold
    return {
        "verified": bool(ok),
        "score": round(score, 4),
        "margin": round(float(score - second), 4),
        "face": best["bbox"],
        "geometry": actor_sources.geometry_for(img, best["_raw_face"]),
        "body_report": actor_sources.body_report_from_geometry(img, best["_raw_face"]),
        "detected_faces": [{k: v for k, v in item.items() if not k.startswith("_")} for item in evaluated],
        "plausible_faces": [{k: v for k, v in item.items() if not k.startswith("_")} for item in plausible],
        "reason": "" if ok else "identity_match_below_threshold",
    }

def load_plan(path):
    if not path or not Path(path).exists():
        return {"poster_generation_mode": "AUTO", "generate": [], "preserve": ["identity"]}
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except Exception:
        return {"poster_generation_mode": "AUTO", "generate": [], "preserve": ["identity"], "plan_parse_error": True}

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
    p.add_argument("--composition-plan", default="")
    p.add_argument("--report", required=True)
    p.add_argument("--threshold", type=float, default=0.62)
    p.add_argument("--margin", type=float, default=0.08)
    args = p.parse_args()
    plan = load_plan(args.composition_plan)
    src_cmp = compare_to_anchor(args.src, args.identity_reference, args.threshold, args.margin)
    generation_mode = str(plan.get("poster_generation_mode", "AUTO") or "AUTO")
    report = {
        "ok": False,
        "repair_mode": "identity_locked_layer_cleanup",
        "poster_generation_mode": generation_mode,
        "composition_plan_path": args.composition_plan,
        "composition_plan": plan,
        "input_derivative": args.src,
        "original_source_used": args.original_source,
        "identity_reference_type": "actor_identity_anchor_json",
        "actor_index": args.actor_index,
        "source_face_match_verified": src_cmp["verified"],
        "matched_face_score": src_cmp["score"],
        "match_margin": src_cmp["margin"],
        "matched_face_bbox": src_cmp["face"],
        "source_body_report": src_cmp.get("body_report", {}),
        "output_face_match_verified": False,
        "output_face_match_score": 0.0,
        "repair_actions": [],
        "failure_reason": "",
    }
    if not src_cmp["verified"]:
        report["failure_reason"] = src_cmp["reason"] or "source_identity_not_verified"
        write(args.report, report); return
    clean_alpha(args.src, args.dest)
    out_cmp = compare_to_anchor(args.dest, args.identity_reference, args.threshold, args.margin)
    report["output_face_match_verified"] = out_cmp["verified"]
    report["output_face_match_score"] = out_cmp["score"]
    report["output_match_margin"] = out_cmp["margin"]
    report["output_body_report"] = out_cmp.get("body_report", {})
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
    if not out_cmp["verified"]:
        report["failure_reason"] = out_cmp["reason"] or "output_identity_not_verified"
    else:
        report["ok"] = True
        report["repair_actions"] = [
            "conservative_alpha_cleanup",
            "body_geometry_recorded_as_advisory",
            "composition_plan_consumed",
        ]
    write(args.report, report)

if __name__ == "__main__":
    main()
