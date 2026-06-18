#!/opt/cmsg-bgremove/bin/python

import sys
import cv2
import numpy as np

if len(sys.argv) < 4:
    print("Usage: blend-faces.py poster actor output [slot_index]")
    sys.exit(1)

poster_path = sys.argv[1]
actor_path = sys.argv[2]
output_path = sys.argv[3]
slot_index = int(sys.argv[4]) if len(sys.argv) >= 5 else 0

poster = cv2.imread(poster_path)
actor = cv2.imread(actor_path)

if poster is None:
    raise Exception("Could not read poster")
if actor is None:
    raise Exception("Could not read actor")

cascade_path = cv2.data.haarcascades + "haarcascade_frontalface_default.xml"
face_cascade = cv2.CascadeClassifier(cascade_path)

poster_gray = cv2.cvtColor(poster, cv2.COLOR_BGR2GRAY)
actor_gray = cv2.cvtColor(actor, cv2.COLOR_BGR2GRAY)

poster_faces = face_cascade.detectMultiScale(poster_gray, 1.08, 4, minSize=(40, 40))
actor_faces = face_cascade.detectMultiScale(actor_gray, 1.08, 4, minSize=(40, 40))

if len(poster_faces) == 0:
    print("No poster faces detected")
    cv2.imwrite(output_path, poster)
    sys.exit(0)

if len(actor_faces) == 0:
    print("No actor face detected")
    cv2.imwrite(output_path, poster)
    sys.exit(0)

# Sort poster faces left-to-right for deterministic actor mapping.
poster_faces = sorted(poster_faces, key=lambda r: r[0])

if slot_index >= len(poster_faces):
    print(f"Slot {slot_index} unavailable; poster has {len(poster_faces)} faces")
    cv2.imwrite(output_path, poster)
    sys.exit(0)

# Use largest detected face from actor image.
actor_faces = sorted(actor_faces, key=lambda r: r[2] * r[3], reverse=True)

px, py, pw, ph = [int(v) for v in poster_faces[slot_index]]
ax, ay, aw, ah = [int(v) for v in actor_faces[0]]

# Expand actor face crop slightly to include forehead/hairline/chin.
pad_x = int(aw * 0.18)
pad_y_top = int(ah * 0.28)
pad_y_bottom = int(ah * 0.20)

x1 = max(0, ax - pad_x)
y1 = max(0, ay - pad_y_top)
x2 = min(actor.shape[1], ax + aw + pad_x)
y2 = min(actor.shape[0], ay + ah + pad_y_bottom)

actor_face = actor[y1:y2, x1:x2]

# Expand poster target slightly.
target_w = int(pw * 1.05)
target_h = int(ph * 1.12)

target_x = int(px + pw / 2 - target_w / 2)
target_y = int(py + ph / 2 - target_h / 2)

target_x = max(0, min(target_x, poster.shape[1] - target_w))
target_y = max(0, min(target_y, poster.shape[0] - target_h))

actor_resized = cv2.resize(actor_face, (target_w, target_h), interpolation=cv2.INTER_LANCZOS4)

# Match basic brightness/contrast to target region.
target_roi = poster[target_y:target_y+target_h, target_x:target_x+target_w]

actor_lab = cv2.cvtColor(actor_resized, cv2.COLOR_BGR2LAB).astype(np.float32)
target_lab = cv2.cvtColor(target_roi, cv2.COLOR_BGR2LAB).astype(np.float32)

for c in range(3):
    a_mean, a_std = actor_lab[:, :, c].mean(), actor_lab[:, :, c].std()
    t_mean, t_std = target_lab[:, :, c].mean(), target_lab[:, :, c].std()
    if a_std > 1:
        actor_lab[:, :, c] = (actor_lab[:, :, c] - a_mean) * (t_std / a_std) + t_mean

actor_lab = np.clip(actor_lab, 0, 255).astype(np.uint8)
actor_matched = cv2.cvtColor(actor_lab, cv2.COLOR_LAB2BGR)

# Soft oval mask.
mask = np.zeros((target_h, target_w), dtype=np.uint8)
cv2.ellipse(
    mask,
    (target_w // 2, target_h // 2),
    (int(target_w * 0.42), int(target_h * 0.48)),
    0,
    0,
    360,
    255,
    -1
)
mask = cv2.GaussianBlur(mask, (41, 41), 0)

center = (target_x + target_w // 2, target_y + target_h // 2)

try:
    blended = cv2.seamlessClone(actor_matched, poster, mask, center, cv2.NORMAL_CLONE)
except Exception:
    blended = poster.copy()
    alpha = (mask.astype(np.float32) / 255.0)[:, :, None]
    blended[target_y:target_y+target_h, target_x:target_x+target_w] = (
        actor_matched * alpha + target_roi * (1 - alpha)
    ).astype(np.uint8)

cv2.imwrite(output_path, blended)
print(f"Blended actor face into poster slot {slot_index}: {output_path}")
