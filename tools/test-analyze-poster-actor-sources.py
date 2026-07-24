#!/usr/bin/env python3
import importlib.util
import json
import tempfile
from pathlib import Path
from types import SimpleNamespace

from PIL import Image, ImageDraw


MODULE_PATH = Path(__file__).with_name("analyze-poster-actor-sources.py")
spec = importlib.util.spec_from_file_location("analyze_poster_actor_sources", MODULE_PATH)
analyzer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(analyzer)


SKIN = (170, 118, 78, 255)
BODY = (28, 24, 22, 255)


def actor_canvas(w=1024, h=897, subject=(0, 0, 1024, 897)):
    img = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    draw.rectangle(subject, fill=BODY)
    return img


def draw_skin(img, bbox):
    draw = ImageDraw.Draw(img)
    x, y, w, h = bbox
    draw.rectangle((x, y, x + w - 1, y + h - 1), fill=SKIN)
    return img


def test_giant_skin_component_rejected_for_body_source():
    img = actor_canvas()
    draw_skin(img, (118, 70, 790, 702))
    faces = analyzer.detect_faces(img, mode="body_source")
    assert faces, "expected the broad skin component to be reported diagnostically"
    giant = faces[0]
    assert not giant["plausible_face_component"]
    assert "face_component_exceeds_subject_geometry" in giant["face_component_rejection_reason"]


def test_closeup_identity_anchor_can_be_valid_but_not_body_source():
    img = actor_canvas(800, 900, (0, 0, 800, 900))
    draw_skin(img, (120, 20, 520, 620))
    faces = analyzer.detect_faces(img, mode="identity_anchor")
    selected, _, decision = analyzer.choose_identity_anchor_face(img, faces)
    assert selected is not None, decision
    assert decision["accepted"]
    geom = analyzer.geometry_for(img, selected["_raw_face"])
    assert geom["crop_classification"] in ("face_only", "head_and_neck")


def test_real_upper_bust_face_is_accepted():
    img = actor_canvas(600, 900, (40, 20, 520, 860))
    draw_skin(img, (230, 90, 150, 180))
    faces = analyzer.detect_faces(img, mode="body_source")
    assert any(face["plausible_face_component"] for face in faces)
    face = next(face for face in faces if face["plausible_face_component"])
    geom = analyzer.geometry_for(img, face)
    assert geom["crop_classification"] in ("bust", "half_body")
    assert geom["shoulder_width"] > 0
    assert geom["torso_bottom_y"] > 0


def test_tiny_skin_components_rejected():
    img = actor_canvas(500, 500, (50, 30, 400, 440))
    draw_skin(img, (240, 80, 18, 18))
    faces = analyzer.detect_faces(img, mode="body_source")
    assert not faces or all(not face.get("plausible_face_component", False) for face in faces)


def test_authoritative_anchor_projects_to_candidates():
    anchor = {
        "anchor_face_bbox": {"x": 200, "y": 100, "w": 100, "h": 120},
        "geometry": {"subject_alpha_bounds": {"x": 100, "y": 50, "w": 400, "h": 600}},
    }
    projector = analyzer.project_anchor_bbox_to_candidate(anchor)
    projected = projector({"x": 20, "y": 10, "w": 800, "h": 1200})
    assert projected == {"x": 220, "y": 110, "w": 200, "h": 240}


def test_identity_verified_face_only_candidate_is_not_selectable_geometry():
    img = Image.new("RGBA", (360, 420), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    draw.rectangle((100, 30, 259, 249), fill=SKIN)
    draw.rectangle((118, 250, 241, 295), fill=BODY)
    face = {"bbox": {"x": 100, "y": 30, "w": 160, "h": 220}}
    geom = analyzer.geometry_for(img, face)
    assert geom["crop_classification"] in ("face_only", "head_and_neck")
    assert geom["crop_classification"] not in ("bust", "half_body")


def test_valid_identity_verified_bust_candidate_can_be_selected():
    img = actor_canvas(600, 900, (40, 20, 520, 860))
    draw_skin(img, (225, 80, 160, 190))
    faces = analyzer.detect_faces(img, mode="identity_anchor")
    selected, _, decision = analyzer.choose_identity_anchor_face(img, faces)
    assert selected is not None, decision
    geom = analyzer.geometry_for(img, selected["_raw_face"])
    assert geom["crop_classification"] in ("bust", "half_body")


def test_missing_or_invalid_anchor_geometry_fails_closed():
    result = analyzer.anchor_geometry_correspondence({}, {})
    assert result["geometry_corresponds_to_authoritative_anchor"] is False
    assert result["authoritative_anchor_geometry_failure_reason"] == "missing_candidate_or_projected_anchor_bbox"


def test_no_reliable_face_fails_identity_anchor():
    img = actor_canvas(600, 900, (0, 0, 600, 900))
    draw_skin(img, (20, 30, 560, 780))
    faces = analyzer.detect_faces(img, mode="identity_anchor")
    selected, _, decision = analyzer.choose_identity_anchor_face(img, faces)
    assert selected is None
    assert decision["rejection_reason"] == "actor_identity_anchor_not_found"


def test_tiny_internal_detection_rejected_by_geometry_correspondence():
    result = analyzer.anchor_geometry_correspondence(
        {"x": 10, "y": 10, "w": 84, "h": 70},
        {"x": 0, "y": 0, "w": 790, "h": 702},
    )
    assert result["geometry_corresponds_to_authoritative_anchor"] is False


def test_identity_anchor_selects_highest_ranked_valid_face_with_close_runner_up():
    original_scorer = analyzer.score_identity_anchor_faces
    try:
        analyzer.score_identity_anchor_faces = lambda img, faces: [
            {
                "candidate_index": 1,
                "plausible_face": True,
                "implausibly_large_face_component": False,
                "rejection_reason": "",
                "combined_score": 0.3287,
                "area": 125000,
                "detector_confidence": 0.89,
                "sharpness": 0.72,
            },
            {
                "candidate_index": 0,
                "plausible_face": True,
                "implausibly_large_face_component": False,
                "rejection_reason": "",
                "combined_score": 0.3255,
                "area": 124000,
                "detector_confidence": 0.88,
                "sharpness": 0.70,
            },
            {
                "candidate_index": 2,
                "plausible_face": True,
                "implausibly_large_face_component": False,
                "rejection_reason": "",
                "combined_score": 0.3070,
                "area": 118000,
                "detector_confidence": 0.86,
                "sharpness": 0.69,
            },
        ]
        selected, _, decision = analyzer.choose_identity_anchor_face(None, [])
    finally:
        analyzer.score_identity_anchor_faces = original_scorer

    assert selected is not None
    assert selected["candidate_index"] == 1
    assert decision["accepted"] is True
    assert decision["selection_method"] == "highest_ranked_prominent_face"
    assert decision["acceptance_reason"] == "highest_ranked_valid_face_selected"
    assert decision["selected_candidate_index"] == 1
    assert decision["runner_up_score"] == 0.3255
    assert decision["score_margin"] == 0.0032
    assert decision["multiple_faces_detected"] is True


def test_identity_anchor_rejects_when_no_valid_plausible_candidate_exists():
    original_scorer = analyzer.score_identity_anchor_faces
    try:
        analyzer.score_identity_anchor_faces = lambda img, faces: [
            {
                "candidate_index": 0,
                "plausible_face": False,
                "implausibly_large_face_component": False,
                "rejection_reason": "tiny_false_positive",
                "combined_score": 0.0,
                "area": 400,
                "detector_confidence": 0.4,
                "sharpness": 0.2,
            },
            {
                "candidate_index": 1,
                "plausible_face": True,
                "implausibly_large_face_component": True,
                "rejection_reason": "",
                "combined_score": 0.44,
                "area": 980000,
                "detector_confidence": 0.7,
                "sharpness": 0.4,
            },
            {
                "candidate_index": 2,
                "plausible_face": True,
                "implausibly_large_face_component": False,
                "rejection_reason": "implausible_face_to_subject_geometry",
                "combined_score": 0.41,
                "area": 120000,
                "detector_confidence": 0.68,
                "sharpness": 0.4,
            },
        ]
        selected, _, decision = analyzer.choose_identity_anchor_face(None, [])
    finally:
        analyzer.score_identity_anchor_faces = original_scorer

    assert selected is None
    assert decision["accepted"] is False
    assert decision["rejection_reason"] == "actor_identity_anchor_not_found"


def run_select_source_with_plausible(plausible_faces, threshold=0.82, margin=0.08):
    original_load = analyzer.load_rgba
    original_detect = analyzer.detect_faces
    original_ranked = analyzer.ranked_plausible_source_faces
    original_geometry = analyzer.geometry_for
    original_alpha_bounds = analyzer.alpha_bounds
    original_emit = analyzer.emit
    try:
        img = actor_canvas(600, 900, (40, 20, 520, 860))
        analyzer.load_rgba = lambda path: img
        analyzer.detect_faces = lambda image, mode="body_source": [{"bbox": face["bbox"]} for face in plausible_faces]
        analyzer.ranked_plausible_source_faces = lambda image, faces, anchor_emb, anchor_projector=None: (plausible_faces, plausible_faces)
        analyzer.geometry_for = lambda image, face: {
            "crop_classification": "half_body",
            "geometry_confidence": 0.86,
            "face_bbox": face["bbox"],
            "estimated_chin_y": face["bbox"]["y"] + face["bbox"]["h"],
            "shoulder_line_y": face["bbox"]["y"] + face["bbox"]["h"] + 30,
            "shoulder_width": 220,
            "torso_bottom_y": 820,
            "body_below_chin_ratio": 1.1,
            "face_to_subject_height_ratio": 0.25,
            "clipping": {},
        }
        analyzer.alpha_bounds = lambda image: {"x": 40, "y": 20, "w": 520, "h": 860}
        analyzer.emit = lambda path, payload: Path(path).write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            source = root / "candidate.png"
            source.write_bytes(b"candidate")
            embedding = root / "anchor-embedding.json"
            embedding.write_text(json.dumps({"embedding": [1.0, 0.0, 0.0]}), encoding="utf-8")
            anchor = root / "anchor.json"
            anchor.write_text(json.dumps({
                "anchor_face_bbox": {"x": 220, "y": 90, "w": 150, "h": 180},
                "anchor_embedding_path": str(embedding),
                "geometry": {"subject_alpha_bounds": {"x": 40, "y": 20, "w": 520, "h": 860}},
            }), encoding="utf-8")
            manifest = root / "manifest.json"
            manifest.write_text(json.dumps({
                "actor_id": "actor_A",
                "actor_index": 0,
                "identity_anchor_path": str(anchor),
                "candidates": [{"candidate_type": "normalized_cutout", "path": str(source)}],
            }), encoding="utf-8")
            output = root / "source-selection.json"
            analyzer.select_source(SimpleNamespace(
                input_manifest=str(manifest),
                output=str(output),
                threshold=threshold,
                margin=margin,
            ))
            return json.loads(output.read_text(encoding="utf-8"))
    finally:
        analyzer.load_rgba = original_load
        analyzer.detect_faces = original_detect
        analyzer.ranked_plausible_source_faces = original_ranked
        analyzer.geometry_for = original_geometry
        analyzer.alpha_bounds = original_alpha_bounds
        analyzer.emit = original_emit


def plausible_source_face(index, score, bbox=None):
    if bbox is None:
        bbox = {"x": 220 + index * 8, "y": 90, "w": 150, "h": 180}
    return {
        "face_index": index,
        "bbox": bbox,
        "_raw_face": {"bbox": bbox},
        "_embedding_score_raw": score,
        "combined_source_face_score": round(score, 4),
        "face_area": bbox["w"] * bbox["h"],
        "plausible_face": True,
    }


def test_select_source_verifies_multiple_plausible_faces_small_margin_above_threshold():
    report = run_select_source_with_plausible([
        plausible_source_face(0, 0.9615),
        plausible_source_face(1, 0.9567),
    ])
    assert report["ok"] is True, report
    assert report["identity_verified"] is True
    assert report["identity_verification_decision"] == "identity_verified_highest_ranked_face"
    assert report["authoritative_anchor_verification_decision"] == "identity_verified_highest_ranked_face"
    assert report["matched_face_score"] == 0.9615
    assert report["second_best_score"] == 0.9567
    assert report["plausible_match_margin"] == 0.0048


def test_select_source_verifies_multiple_plausible_faces_large_margin_above_threshold():
    report = run_select_source_with_plausible([
        plausible_source_face(0, 0.94),
        plausible_source_face(1, 0.72),
    ])
    assert report["ok"] is True, report
    assert report["identity_verified"] is True
    assert report["identity_verification_decision"] == "identity_verified_highest_ranked_face"
    assert report["match_margin"] == 0.22


def test_select_source_rejects_best_score_below_threshold():
    report = run_select_source_with_plausible([
        plausible_source_face(0, 0.79),
        plausible_source_face(1, 0.20),
    ], threshold=0.82)
    assert report["ok"] is False
    assert report["failure_reason"] == "actor_layer_body_source_unavailable"
    candidate = report["candidates"][0]
    assert candidate["identity_verified"] is False
    assert candidate["identity_failure_reason"] == "identity_match_below_threshold"
    assert candidate["identity_verification_decision"] == "identity_rejected_below_threshold"


def test_select_source_rejects_when_no_plausible_faces():
    report = run_select_source_with_plausible([])
    assert report["ok"] is False
    assert report["failure_reason"] == "actor_layer_body_source_unavailable"
    candidate = report["candidates"][0]
    assert candidate["identity_verified"] is False
    assert candidate["identity_failure_reason"] == "no_plausible_face_detected"
    assert candidate["identity_verification_decision"] == "identity_rejected_no_plausible_face"


def run():
    tests = [
        test_giant_skin_component_rejected_for_body_source,
        test_closeup_identity_anchor_can_be_valid_but_not_body_source,
        test_real_upper_bust_face_is_accepted,
        test_tiny_skin_components_rejected,
        test_authoritative_anchor_projects_to_candidates,
        test_identity_verified_face_only_candidate_is_not_selectable_geometry,
        test_valid_identity_verified_bust_candidate_can_be_selected,
        test_missing_or_invalid_anchor_geometry_fails_closed,
        test_no_reliable_face_fails_identity_anchor,
        test_tiny_internal_detection_rejected_by_geometry_correspondence,
        test_identity_anchor_selects_highest_ranked_valid_face_with_close_runner_up,
        test_identity_anchor_rejects_when_no_valid_plausible_candidate_exists,
        test_select_source_verifies_multiple_plausible_faces_small_margin_above_threshold,
        test_select_source_verifies_multiple_plausible_faces_large_margin_above_threshold,
        test_select_source_rejects_best_score_below_threshold,
        test_select_source_rejects_when_no_plausible_faces,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    run()
