#!/usr/bin/env python3
"""
Per-face image quality for the "prominence" sort in Memories.

For every face box the script measures on the pixels of the box:
  sharpness  0..1   log-scaled variance of the Laplacian on a 128px copy of the crop
                    (blurred / out of focus faces score low)
  brightness 0..1   mean luminance of the crop (dark faces score low)

usage: face_quality.py <image> x,y,w,h [x,y,w,h ...]   (relative 0..1 box coordinates)
       face_quality.py --batch <jobs.json>              [{"path": ..., "boxes": [[x,y,w,h], ...]}, ...]
prints a JSON list, one {"sharpness", "brightness"} object per box (null when the crop is empty);
in batch mode one such list per job (null when the image cannot be read).

The InsightFace classifier imports face_metrics() to compute the same numbers at detection time.
"""
import json
import sys

import cv2
import numpy as np

MAX_CROP = 256


def face_metrics(img, x, y, w, h):
    """img: BGR numpy image; x, y, w, h: relative box. Returns (sharpness, brightness) or None."""
    height, width = img.shape[:2]
    x0 = max(0, int(round(x * width)))
    y0 = max(0, int(round(y * height)))
    x1 = min(width, int(round((x + w) * width)))
    y1 = min(height, int(round((y + h) * height)))
    if x1 - x0 < 4 or y1 - y0 < 4:
        return None
    crop = img[y0:y1, x0:x1]
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    # big faces are reduced to 256px so the measure does not grow with the pixel size;
    # small faces are not upscaled (a far face has little detail, and should score low)
    side = min(MAX_CROP, max(x1 - x0, y1 - y0))
    gray = cv2.resize(gray, (side, side), interpolation=cv2.INTER_AREA)
    variance = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    # ~10 → 0.35, ~100 → 0.67, ≥1000 → 1
    sharpness = max(0.0, min(1.0, np.log10(variance + 1.0) / 3.0))
    brightness = float(gray.mean()) / 255.0
    return round(float(sharpness), 4), round(brightness, 4)


def measure_image(path, boxes):
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        return None
    out = []
    for x, y, w, h in boxes:
        m = face_metrics(img, float(x), float(y), float(w), float(h))
        out.append(None if m is None else {'sharpness': m[0], 'brightness': m[1]})
    return out


def main():
    if len(sys.argv) >= 3 and sys.argv[1] == '--batch':
        with open(sys.argv[2]) as f:
            jobs = json.load(f)
        print(json.dumps([measure_image(job['path'], job['boxes']) for job in jobs]))
        return
    if len(sys.argv) < 3:
        raise SystemExit('usage: face_quality.py <image> x,y,w,h [x,y,w,h ...] | --batch <jobs.json>')
    out = measure_image(sys.argv[1], [box.split(',') for box in sys.argv[2:]])
    if out is None:
        raise SystemExit('cannot decode image ' + sys.argv[1])
    print(json.dumps(out))


if __name__ == '__main__':
    main()
