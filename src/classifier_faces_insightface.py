#!/usr/bin/env python3
"""
Face detection + embedding with InsightFace (RetinaFace detector + ArcFace recognizer,
model pack "buffalo_l" by default) through ONNX Runtime, optionally on the GPU.

Drop-in alternative to classifier_faces.js: reads image paths from stdin (one per line,
started with "-") and prints one JSON array per image with the same structure:
  [{"angle": {"roll", "yaw", "pitch"}, "vector": [...512 floats...],
    "x", "y", "width", "height" (relative), "score", "gender", "age"}]

Environment:
  RECOGNIZE_GPU=true              use the CUDA execution provider when available
  RECOGNIZE_FACES_TILING=true     additionally scan overlapping tiles (small faces)
  RECOGNIZE_INSIGHTFACE_ROOT      directory holding models/<pack>/*.onnx (default ~/.insightface)
  RECOGNIZE_INSIGHTFACE_MODEL     model pack name (default buffalo_l)
  RECOGNIZE_CORES                 limit ONNX Runtime CPU threads
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

TILING = os.environ.get('RECOGNIZE_FACES_TILING') == 'true'
TILE_GRID = 2
TILE_OVERLAP = 0.2
TILE_MIN_IMAGE_SIZE = 1024
DUPLICATE_IOU = 0.4
DET_SIZE = 640
MODEL = os.environ.get('RECOGNIZE_INSIGHTFACE_MODEL', 'buffalo_l')
ROOT = os.environ.get('RECOGNIZE_INSIGHTFACE_ROOT') or os.path.expanduser('~/.insightface')


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


def detect(app, img, offset_x, offset_y):
    faces = []
    for face in app.get(img):
        x0, y0, x1, y1 = [float(v) for v in face.bbox]
        pose = getattr(face, 'pose', None)
        faces.append({
            'box': (x0 + offset_x, y0 + offset_y, x1 - x0, y1 - y0),
            'vector': [round(float(v), 6) for v in face.normed_embedding],
            'score': float(face.det_score),
            'angle': {
                'pitch': float(pose[0]) if pose is not None else 0.0,
                'yaw': float(pose[1]) if pose is not None else 0.0,
                'roll': float(pose[2]) if pose is not None else 0.0,
            },
            'gender': int(face.gender) if getattr(face, 'gender', None) is not None else None,
            'age': int(face.age) if getattr(face, 'age', None) is not None else None,
        })
    return faces


def detect_faces(app, img):
    height, width = img.shape[:2]
    faces = detect(app, img, 0, 0)
    if not TILING or max(width, height) < TILE_MIN_IMAGE_SIZE:
        return faces
    tile_w, tile_h = -(-width // TILE_GRID), -(-height // TILE_GRID)
    ov_x, ov_y = int(round(tile_w * TILE_OVERLAP)), int(round(tile_h * TILE_OVERLAP))
    for row in range(TILE_GRID):
        for col in range(TILE_GRID):
            x0, y0 = max(0, col * tile_w - ov_x), max(0, row * tile_h - ov_y)
            x1, y1 = min(width, (col + 1) * tile_w + ov_x), min(height, (row + 1) * tile_h + ov_y)
            for face in detect(app, img[y0:y1, x0:x1], x0, y0):
                # keep the full-image detection when both passes found the same face
                if not any(iou(existing['box'], face['box']) > DUPLICATE_IOU for existing in faces):
                    faces.append(face)
    return faces


def main():
    if len(sys.argv) < 2:
        raise SystemExit('usage: classifier_faces_insightface.py - | classifier_faces_insightface.py <images...>')
    paths = sys.stdin.read().split('\n') if sys.argv[1] == '-' else sys.argv[1:]
    paths = [p for p in paths if p.strip()]

    # insightface prints model loading info to stdout, which must stay reserved for the JSON results
    with contextlib.redirect_stdout(sys.stderr):
        app = FaceAnalysis(name=MODEL, root=ROOT, providers=providers(),
                           allowed_modules=['detection', 'recognition', 'landmark_3d_68', 'genderage'])
        app.prepare(ctx_id=0, det_size=(DET_SIZE, DET_SIZE))
    log('insightface ready:', MODEL, app.models['recognition'].session.get_providers()[0])

    for path in paths:
        try:
            img = cv2.imread(path, cv2.IMREAD_COLOR)
            if img is None:
                raise ValueError('cannot decode image ' + path)
            height, width = img.shape[:2]
            out = []
            with contextlib.redirect_stdout(sys.stderr):
                faces = detect_faces(app, img)
            for face in faces:
                x, y, w, h = face['box']
                out.append({
                    'angle': face['angle'],
                    'vector': face['vector'],
                    'x': x / width,
                    'y': y / height,
                    'width': w / width,
                    'height': h / height,
                    'score': face['score'],
                    'gender': face['gender'],
                    'age': face['age'],
                })
            print(json.dumps(out), flush=True)
        except Exception as e:  # noqa: BLE001
            log('error processing', path, ':', repr(e))
            print('[]', flush=True)


if __name__ == '__main__':
    main()
