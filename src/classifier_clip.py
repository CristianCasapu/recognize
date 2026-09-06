#!/usr/bin/env python3
"""
Image embeddings for natural-language photo search (CLIP, ONNX Runtime, GPU when available)
plus a 64-bit perceptual hash (dHash) for near-duplicate detection.

Reads image paths from stdin (one per line, started with "-"), prints one JSON object per image:
  {"vector": [512 floats, L2-normalised], "phash": "16 hex chars"}

Environment:
  RECOGNIZE_GPU=true            use the CUDA execution provider when available
  RECOGNIZE_CLIP_MODEL_DIR      directory with visual/model.onnx, visual/preprocess_cfg.json, config.json
  RECOGNIZE_CORES               limit ONNX Runtime CPU threads
"""
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')

import numpy as np  # noqa: E402
import onnxruntime  # noqa: E402
from PIL import Image, ImageOps  # noqa: E402

MODEL_DIR = os.environ.get('RECOGNIZE_CLIP_MODEL_DIR') or ''
BATCH = 1  # the ONNX export has a fixed batch dimension of 1


def log(*args):
    print(*args, file=sys.stderr, flush=True)


def providers():
    available = onnxruntime.get_available_providers()
    if os.environ.get('RECOGNIZE_GPU') == 'true' and 'CUDAExecutionProvider' in available:
        return ['CUDAExecutionProvider', 'CPUExecutionProvider']
    return ['CPUExecutionProvider']


def load_session(path):
    opts = onnxruntime.SessionOptions()
    cores = os.environ.get('RECOGNIZE_CORES')
    if cores and cores != '0':
        opts.intra_op_num_threads = int(cores)
    return onnxruntime.InferenceSession(path, opts, providers=providers())


def preprocess(img, cfg):
    """open_clip style: resize shortest side, center crop, normalise; returns CHW float32"""
    size = cfg.get('size', [224, 224])
    size = size if isinstance(size, list) else [size, size]
    img = ImageOps.exif_transpose(img).convert(cfg.get('mode', 'RGB'))
    w, h = img.size
    if cfg.get('resize_mode', 'shortest') == 'shortest':
        scale = max(size[0] / h, size[1] / w)
        img = img.resize((max(size[1], round(w * scale)), max(size[0], round(h * scale))), Image.BICUBIC)
        w, h = img.size
        left, top = (w - size[1]) // 2, (h - size[0]) // 2
        img = img.crop((left, top, left + size[1], top + size[0]))
    else:
        img = img.resize((size[1], size[0]), Image.BICUBIC)
    arr = np.asarray(img, dtype=np.float32) / 255.0
    mean = np.array(cfg.get('mean', [0.48145466, 0.4578275, 0.40821073]), dtype=np.float32)
    std = np.array(cfg.get('std', [0.26862954, 0.26130258, 0.27577711]), dtype=np.float32)
    arr = (arr - mean) / std
    return arr.transpose(2, 0, 1)


def dhash(img):
    """64-bit difference hash of the (exif-oriented) image as 16 hex chars"""
    g = ImageOps.exif_transpose(img).convert('L').resize((9, 8), Image.LANCZOS)
    px = np.asarray(g, dtype=np.int16)
    bits = (px[:, 1:] > px[:, :-1]).flatten()
    value = 0
    for bit in bits:
        value = (value << 1) | int(bit)
    return '%016x' % value


def main():
    if len(sys.argv) < 2:
        raise SystemExit('usage: classifier_clip.py - | classifier_clip.py <images...>')
    paths = sys.stdin.read().split('\n') if sys.argv[1] == '-' else sys.argv[1:]
    paths = [p for p in paths if p.strip()]
    if not MODEL_DIR or not os.path.isfile(os.path.join(MODEL_DIR, 'visual', 'model.onnx')):
        raise SystemExit('RECOGNIZE_CLIP_MODEL_DIR must contain visual/model.onnx (got %r)' % MODEL_DIR)
    cfg = json.load(open(os.path.join(MODEL_DIR, 'visual', 'preprocess_cfg.json')))
    session = load_session(os.path.join(MODEL_DIR, 'visual', 'model.onnx'))
    input_name = session.get_inputs()[0].name
    log('clip visual ready:', os.path.basename(MODEL_DIR), session.get_providers()[0])

    pending = []  # (index, tensor, phash)
    results = [None] * len(paths)

    def flush():
        if not pending:
            return
        batch = np.stack([t for _, t, _ in pending]).astype(np.float32)
        out = session.run(None, {input_name: batch})[0]
        out = out / np.maximum(np.linalg.norm(out, axis=1, keepdims=True), 1e-9)
        for (i, _, ph), vec in zip(pending, out):
            results[i] = {'vector': [round(float(v), 6) for v in vec], 'phash': ph}
        pending.clear()

    for i, path in enumerate(paths):
        try:
            with Image.open(path) as img:
                img.load()
                pending.append((i, preprocess(img, cfg), dhash(img)))
        except Exception as e:  # noqa: BLE001
            log('error processing', path, ':', repr(e))
            results[i] = {}
        if len(pending) >= BATCH:
            flush()
    flush()
    for r in results:
        print(json.dumps(r if r is not None else {}), flush=True)


if __name__ == '__main__':
    main()
