#!/usr/bin/env python3
import argparse
import json
import os
import sys


def fail(reason, **extra):
    payload = {"ok": False, "reason": reason}
    payload.update(extra)
    print(json.dumps(payload))
    return 1


def odd(value):
    value = int(round(value))
    if value % 2 == 0:
        value += 1
    return max(3, value)


def main():
    parser = argparse.ArgumentParser(description="Normalize, crop, and pad a transparent actor matte.")
    parser.add_argument("--src", required=True)
    parser.add_argument("--dest", required=True)
    parser.add_argument("--actor-index", type=int, default=0)
    parser.add_argument("--padding", type=float, default=0.08)
    args = parser.parse_args()

    try:
        from PIL import Image, ImageFilter
        import numpy as np
    except Exception as exc:
        return fail("missing_dependency", missing=str(exc))

    if not os.path.exists(args.src):
        return fail("missing_source", src=args.src)

    try:
        image = Image.open(args.src).convert("RGBA")
    except Exception as exc:
        return fail("unreadable_source", error=str(exc))

    arr = np.array(image)
    alpha = arr[:, :, 3].astype(np.uint8)
    visible = alpha > 20
    if not visible.any():
        return fail("no_nontransparent_bbox", src=args.src)

    ys, xs = np.where(visible)
    left, right = int(xs.min()), int(xs.max())
    top, bottom = int(ys.min()), int(ys.max())
    bbox_w = max(1, right - left + 1)
    bbox_h = max(1, bottom - top + 1)

    noise_kernel = None
    interior_kernel = None
    pixels_removed_by_cleanup = 0
    pixels_filled_by_close = 0
    overcleaned = False
    try:
        import cv2  # type: ignore
        mask = (visible.astype(np.uint8) * 255)
        min_dim = max(1, min(bbox_w, bbox_h))
        noise_kernel = max(3, min(5, odd(min_dim * 0.004)))
        interior_kernel = max(3, min(9, odd(min_dim * 0.008)))

        noise = np.ones((noise_kernel, noise_kernel), np.uint8)
        interior_k = np.ones((interior_kernel, interior_kernel), np.uint8)

        opened = cv2.morphologyEx(mask, cv2.MORPH_OPEN, noise) > 0
        closed = cv2.morphologyEx((opened.astype(np.uint8) * 255), cv2.MORPH_CLOSE, noise) > 0
        interior = cv2.erode((closed.astype(np.uint8) * 255), interior_k, iterations=1) > 0

        pixels_removed_by_cleanup = int((visible & ~opened).sum())
        pixels_filled_by_close = int((closed & ~opened).sum())
        visible_count = max(1, int(visible.sum()))
        overcleaned = (pixels_removed_by_cleanup / visible_count) > 0.03
        cleaned = closed
    except Exception:
        inset_x = max(2, int(round(bbox_w * 0.05)))
        inset_y = max(2, int(round(bbox_h * 0.05)))
        interior = np.zeros_like(visible, dtype=bool)
        interior[top + inset_y:bottom - inset_y + 1, left + inset_x:right - inset_x + 1] = True
        interior &= visible
        cleaned = visible

    if overcleaned:
        return fail(
            "actor_matte_overcleaned",
            actor_index=args.actor_index,
            src=args.src,
            noise_kernel=noise_kernel,
            interior_kernel=interior_kernel,
            pixels_removed_by_cleanup=pixels_removed_by_cleanup,
            pixels_filled_by_close=pixels_filled_by_close,
        )

    new_alpha = np.zeros_like(alpha)
    soft = cleaned & ~interior
    new_alpha[soft] = np.clip(alpha[soft], 0, 255)
    new_alpha[interior] = 255
    new_alpha[(alpha >= 110) & cleaned] = 255
    new_alpha[(alpha <= 20) & ~interior] = 0
    interior_pixels = int(interior.sum())
    interior_partial_before = 1.0
    if interior_pixels > 0:
        interior_partial_before = float(((alpha[interior] > 20) & (alpha[interior] < 245)).sum()) / float(interior_pixels)

    new_alpha_img = Image.fromarray(new_alpha, "L").filter(ImageFilter.GaussianBlur(radius=0.28))
    hardened = Image.fromarray(arr[:, :, :3], "RGB").convert("RGBA")
    hardened.putalpha(new_alpha_img)
    hardened_arr = np.array(hardened)
    alpha2 = hardened_arr[:, :, 3]

    visible2 = alpha2 > 20
    if not visible2.any():
        return fail("empty_after_harden", src=args.src)
    ys, xs = np.where(visible2)
    left, right = int(xs.min()), int(xs.max())
    top, bottom = int(ys.min()), int(ys.max())
    bbox_w = max(1, right - left + 1)
    bbox_h = max(1, bottom - top + 1)

    side_pad = max(18, int(round(max(bbox_w, bbox_h) * max(0.0, args.padding))))
    top_pad = max(side_pad, int(round(bbox_h * 0.18)))
    bottom_pad = max(side_pad, int(round(bbox_h * 0.08)))
    touches = {
        "left": left <= 0,
        "top": top <= 0,
        "right": right >= image.width - 1,
        "bottom": bottom >= image.height - 1,
    }
    if touches["top"]:
        top_pad = max(top_pad, int(round(bbox_h * 0.24)))

    crop = hardened.crop((left, top, right + 1, bottom + 1))
    out_w = crop.width + (side_pad * 2)
    out_h = crop.height + top_pad + bottom_pad
    out = Image.new("RGBA", (out_w, out_h), (0, 0, 0, 0))
    out.alpha_composite(crop, (side_pad, top_pad))
    os.makedirs(os.path.dirname(args.dest), exist_ok=True)
    out.save(args.dest)

    out_alpha = np.array(out)[:, :, 3]
    total = max(1, out_alpha.size)
    transparent = int((out_alpha <= 20).sum())
    partial = int(((out_alpha > 20) & (out_alpha < 245)).sum())
    opaque = int((out_alpha >= 245).sum())
    visible_out = out_alpha > 20
    ys, xs = np.where(visible_out)
    bbox = {
        "x": int(xs.min()),
        "y": int(ys.min()),
        "w": int(xs.max() - xs.min() + 1),
        "h": int(ys.max() - ys.min() + 1),
    }
    crop_alpha = np.array(crop)[:, :, 3]
    crop_visible = crop_alpha > 20
    crop_interior = np.zeros_like(crop_visible, dtype=bool)
    inset_x = max(1, int(round(crop.width * 0.08)))
    inset_y = max(1, int(round(crop.height * 0.08)))
    crop_interior[inset_y:max(inset_y + 1, crop.height - inset_y), inset_x:max(inset_x + 1, crop.width - inset_x)] = True
    crop_interior &= crop_visible
    crop_interior_pixels = int(crop_interior.sum())
    interior_partial = 0.0
    interior_opaque = 1.0
    if crop_interior_pixels > 0:
        interior_partial = float(((crop_alpha[crop_interior] > 20) & (crop_alpha[crop_interior] < 245)).sum()) / float(crop_interior_pixels)
        interior_opaque = float((crop_alpha[crop_interior] >= 245).sum()) / float(crop_interior_pixels)

    ok = partial / total <= 0.12 and opaque / total >= 0.20 and interior_opaque >= 0.95
    reason = "actor_matte_processed" if ok else "actor_matte_quality_failed"
    if interior_opaque < 0.95:
        reason = "translucent_subject_interior"

    report = {
        "ok": ok,
        "actor_index": args.actor_index,
        "src": args.src,
        "dest": args.dest,
        "width": out.width,
        "height": out.height,
        "alpha_bbox": bbox,
        "edge_touch": touches,
        "transparent_ratio": transparent / total,
        "partial_alpha_ratio": partial / total,
        "opaque_ratio": opaque / total,
        "interior_partial_ratio": interior_partial,
        "interior_opaque_ratio": interior_opaque,
        "interior_partial_before": interior_partial_before,
        "source_bbox": {"x": left, "y": top, "w": bbox_w, "h": bbox_h},
        "noise_kernel": noise_kernel,
        "interior_kernel": interior_kernel,
        "pixels_removed_by_cleanup": pixels_removed_by_cleanup,
        "pixels_filled_by_close": pixels_filled_by_close,
        "reason": reason,
    }
    print(json.dumps(report))
    return 0 if report["ok"] else 2


if __name__ == "__main__":
    sys.exit(main())
