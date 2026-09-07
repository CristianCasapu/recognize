#!/usr/bin/env python3
"""
Embedding for one manually drawn face box (Memories "People in this photo" → "Add face").

usage: face_at_box.py <image> <x> <y> <width> <height>   (relative 0..1 coordinates)

Crops the region with a margin, runs the InsightFace detector there (retrying on an
upscaled crop for small faces) and prints one JSON object with the detected face:
  {"x","y","width","height" (relative to the whole image), "vector", "score", "angle"}
An empty object means no face was found inside the box.
"""
import contextlib
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')
os.environ.setdefault('OMP_NUM_THREADS', os.environ.get('RECOGNIZE_CORES', '0') or str(os.cpu_count() or 1))

import cv2  # noqa: E402
import numpy as np  # noqa: E402
import onnxruntime  # noqa: E402
from insightface.app import FaceAnalysis  # noqa: E402

MODEL = os.environ.get('RECOGNIZE_INSIGHTFACE_MODEL', 'buffalo_l')
ROOT = os.environ.get('RECOGNIZE_INSIGHTFACE_ROOT') or os.path.expanduser('~/.insightface')
MARGIN = 0.6
MIN_CROP = 320
DET_SIZE = 640


def log(*args):
    print(*args, file=sys.stderr, flush=True)


def providers():
    available = onnxruntime.get_available_providers()
    if os.environ.get('RECOGNIZE_GPU') == 'true' and 'CUDAExecutionProvider' in available:
        return ['CUDAExecutionProvider', 'CPUExecutionProvider']
    return ['CPUExecutionProvider']


def iou(a, b):
    x0, y0 = max(a[0], b[0]), max(a[1], b[1])
    x1, y1 = min(a[0] + a[2], b[0] + b[2]), min(a[1] + a[3], b[1] + b[3])
    inter = max(0.0, x1 - x0) * max(0.0, y1 - y0)
    union = a[2] * a[3] + b[2] * b[3] - inter
    return inter / union if union > 0 else 0.0


def main():
    if len(sys.argv) < 6:
        raise SystemExit(__doc__)
    path = sys.argv[1]
    rx, ry, rw, rh = (float(v) for v in sys.argv[2:6])

    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        log('cannot decode image', path)
        print('{}', flush=True)
        return
    height, width = img.shape[:2]
    bx, by = rx * width, ry * height
    bw, bh = max(8.0, rw * width), max(8.0, rh * height)

    mx, my = bw * MARGIN, bh * MARGIN
    x0 = int(max(0, bx - mx))
    y0 = int(max(0, by - my))
    x1 = int(min(width, bx + bw + mx))
    y1 = int(min(height, by + bh + my))
    crop = img[y0:y1, x0:x1]
    if crop.size == 0:
        print('{}', flush=True)
        return

    with contextlib.redirect_stdout(sys.stderr):
        app = FaceAnalysis(name=MODEL, root=ROOT, providers=providers(),
                           allowed_modules=['detection', 'recognition', 'landmark_3d_68', 'genderage'])
        app.prepare(ctx_id=0, det_size=(DET_SIZE, DET_SIZE))

        best = None
        drawn = (bx - x0, by - y0, bw, bh)
        for scale in (1.0, 2.0, 4.0):
            work = crop
            if scale != 1.0 or max(crop.shape[:2]) < MIN_CROP:
                factor = max(scale, MIN_CROP / max(1, max(crop.shape[:2])))
                work = cv2.resize(crop, None, fx=factor, fy=factor, interpolation=cv2.INTER_CUBIC)
            else:
                factor = 1.0
            for face in app.get(work):
                fx0, fy0, fx1, fy1 = [float(v) / factor for v in face.bbox]
                box = (fx0, fy0, fx1 - fx0, fy1 - fy0)
                score = iou(box, drawn)
                if best is None or score > best[0]:
                    pose = getattr(face, 'pose', None)
                    best = (score, {
                        'x': (x0 + box[0]) / width,
                        'y': (y0 + box[1]) / height,
                        'width': box[2] / width,
                        'height': box[3] / height,
                        'vector': [round(float(v), 6) for v in face.normed_embedding],
                        'score': float(face.det_score),
                        'angle': {
                            'pitch': float(pose[0]) if pose is not None else 0.0,
                            'yaw': float(pose[1]) if pose is not None else 0.0,
                            'roll': float(pose[2]) if pose is not None else 0.0,
                        },
                    })
            if best is not None and best[0] > 0.1:
                break

    print(json.dumps(best[1] if best is not None else {}), flush=True)


if __name__ == '__main__':
    main()
