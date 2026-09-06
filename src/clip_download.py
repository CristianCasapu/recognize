#!/usr/bin/env python3
"""Download a CLIP ONNX model pack from Hugging Face:  clip_download.py <repo> <target dir>"""
import os
import shutil
import sys

from huggingface_hub import snapshot_download

repo, target = sys.argv[1], sys.argv[2]
path = snapshot_download(repo, local_dir=target, allow_patterns=['config.json', 'visual/*', 'textual/*'])
shutil.rmtree(os.path.join(path, '.cache'), ignore_errors=True)
total = sum(os.path.getsize(os.path.join(d, f)) for d, _, files in os.walk(path) for f in files)
print('downloaded %s (%.0f MB) to %s' % (repo, total / 1048576, path), flush=True)
