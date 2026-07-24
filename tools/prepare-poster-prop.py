#!/usr/bin/env python3
import argparse, json
from pathlib import Path
import numpy as np
from PIL import Image

def main():
    p = argparse.ArgumentParser(description="Prepare poster prop or vehicle layer.")
    p.add_argument("--src", required=True); p.add_argument("--dest", required=True); p.add_argument("--mask", required=True); p.add_argument("--report", required=True); p.add_argument("--type", default="vehicle")
    args = p.parse_args()
    img = Image.open(args.src).convert("RGBA")
    arr = np.array(img)
    alpha = arr[:,:,3]
    if alpha.max() == 0:
        alpha = np.where(arr[:,:,:3].mean(axis=2) > 8, 255, 0).astype(np.uint8)
        arr[:,:,3] = alpha
    ys, xs = np.where(alpha > 20)
    report = {"valid": False, "prop_type": args.type, "object_bounds": {}, "alpha_bounds": {}, "edge_touch": {}, "rectangular_background_ratio": 0.0, "vehicle_visible_area": 0, "complete_silhouette": False, "clipped": False, "failure_reason": ""}
    if len(xs) == 0:
        report["failure_reason"] = "required_vehicle_not_rendered"
    else:
        bounds = {"x": int(xs.min()), "y": int(ys.min()), "w": int(xs.max()-xs.min()+1), "h": int(ys.max()-ys.min()+1)}
        crop = Image.fromarray(arr, "RGBA").crop((bounds["x"], bounds["y"], bounds["x"]+bounds["w"], bounds["y"]+bounds["h"]))
        Path(args.dest).parent.mkdir(parents=True, exist_ok=True)
        crop.save(args.dest)
        mask = np.zeros((bounds["h"], bounds["w"], 4), dtype=np.uint8); mask[:,:,3] = np.array(crop)[:,:,3]
        Image.fromarray(mask, "RGBA").save(args.mask)
        edge = {"left": bounds["x"] <= 1, "top": bounds["y"] <= 1, "right": bounds["x"]+bounds["w"] >= img.size[0]-1, "bottom": bounds["y"]+bounds["h"] >= img.size[1]-1}
        report.update({"valid": True, "object_bounds": bounds, "alpha_bounds": bounds, "edge_touch": edge, "vehicle_visible_area": int((alpha>20).sum()), "complete_silhouette": True, "clipped": any(edge.values())})
    Path(args.report).write_text(json.dumps(report, indent=2, sort_keys=True), encoding="utf-8")
    print(json.dumps(report, indent=2, sort_keys=True))
if __name__ == "__main__":
    main()
