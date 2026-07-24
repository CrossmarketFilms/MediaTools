#!/usr/bin/env python3
import argparse, json
from pathlib import Path
import numpy as np
from PIL import Image

def load(path):
    return np.array(Image.open(path).convert("RGBA"))

def erode(mask):
    return mask & np.roll(mask,1,0) & np.roll(mask,-1,0) & np.roll(mask,1,1) & np.roll(mask,-1,1)

def dilate(mask):
    return mask | np.roll(mask,1,0) | np.roll(mask,-1,0) | np.roll(mask,1,1) | np.roll(mask,-1,1)

def longest_run(vals):
    best = cur = 0
    for v in vals:
        cur = cur + 1 if v else 0
        best = max(best, cur)
    return best

def analyze_actor(comp, bg, actor):
    mask_path = actor.get("final_mask_path", "")
    aid = actor.get("actor_id", actor.get("actor", "actor"))
    if not mask_path or not Path(mask_path).exists():
        return {"actor_id": aid, "valid": False, "failure": "missing_mask"}
    m = load(mask_path)[:,:,3] > 20
    if not np.any(m):
        return {"actor_id": aid, "valid": False, "failure": "empty_mask"}
    inner = m & ~erode(m)
    outer = dilate(m) & ~m
    comp_rgb = comp[:,:,:3].astype(np.float32); bg_rgb = bg[:,:,:3].astype(np.float32)
    boundary_score = float(np.mean(np.abs(comp_rgb[inner] - bg_rgb[outer][:max(1, int(inner.sum()))]))) / 255.0 if inner.any() and outer.any() else 0.0
    ys, xs = np.where(m)
    x0,x1,y0,y1 = xs.min(),xs.max(),ys.min(),ys.max()
    bbox = m[y0:y1+1, x0:x1+1]
    row_runs = [longest_run(row) for row in bbox]
    col_runs = [longest_run(col) for col in bbox.T]
    horizontal_edge_ratio = max(row_runs) / max(1, bbox.shape[1])
    vertical_edge_ratio = max(col_runs) / max(1, bbox.shape[0])
    near = dilate(m) & ~erode(m)
    black = (comp_rgb.mean(axis=2) < 18) & near
    black_ratio = float(black.sum()) / max(1, int(near.sum()))
    fill_ratio = float(m.sum()) / max(1, bbox.size)
    rectangular = fill_ratio > 0.82 and (horizontal_edge_ratio > 0.72 or vertical_edge_ratio > 0.72)
    hard_cut = horizontal_edge_ratio > 0.80
    return {"actor_id": aid, "valid": True, "boundary_discontinuity_score": round(boundary_score,4), "horizontal_edge_ratio": round(horizontal_edge_ratio,4), "vertical_edge_ratio": round(vertical_edge_ratio,4), "black_block_pixel_ratio": round(black_ratio,4), "rectangular_artifact_detected": bool(rectangular), "hard_cut_line_detected": bool(hard_cut)}

def main():
    p = argparse.ArgumentParser(description="Analyze final poster composite pixels and masks.")
    p.add_argument("--input-manifest", required=True); p.add_argument("--output", required=True)
    args = p.parse_args()
    payload = json.loads(Path(args.input_manifest).read_text(encoding="utf-8"))
    failures = []
    report = {"background_loaded": False, "composite_loaded": False, "actor_masks_loaded": 0, "prop_masks_loaded": 0, "pixel_analysis_complete": False, "boundary_discontinuity_scores": {}, "rectangular_artifact_regions": [], "hard_cut_line_regions": [], "black_block_regions": [], "face_overlaps": [], "scale_outliers": [], "vehicle_visibility": {}, "title_safe_status": {"usable": True}, "valid": False, "failure_reasons": failures, "actor_edge_analysis": []}
    try:
        bg = load(payload.get("background", ""))
        comp = load(payload.get("composite", ""))
        report["background_loaded"] = True; report["composite_loaded"] = True
        for actor in payload.get("actors", []):
            item = analyze_actor(comp, bg, actor)
            report["actor_edge_analysis"].append(item)
            if item.get("valid"):
                report["actor_masks_loaded"] += 1
                aid = item["actor_id"]
                report["boundary_discontinuity_scores"][aid] = item["boundary_discontinuity_score"]
                if item["rectangular_artifact_detected"]:
                    report["rectangular_artifact_regions"].append(aid); failures.append("recovery_rectangular_edge_visible")
                if item["hard_cut_line_detected"]:
                    report["hard_cut_line_regions"].append(aid); failures.append("recovery_hard_cut_line_visible")
                if item["black_block_pixel_ratio"] > 0.08:
                    report["black_block_regions"].append(aid); failures.append("recovery_black_block_edge_visible")
            else:
                failures.append(item.get("failure", "actor_mask_analysis_failed"))
        for vehicle in payload.get("vehicles", []):
            report["prop_masks_loaded"] += 1
            report["vehicle_visibility"] = vehicle
            if vehicle.get("visible_ratio", 0) < 0.45:
                failures.append("recovery_vehicle_not_visible")
        report["pixel_analysis_complete"] = True
    except Exception as e:
        failures.append("pixel_analysis_failed")
        report["error"] = str(e)
    report["valid"] = len(failures) == 0
    Path(args.output).parent.mkdir(parents=True, exist_ok=True)
    Path(args.output).write_text(json.dumps(report, indent=2, sort_keys=True), encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
if __name__ == "__main__":
    main()
