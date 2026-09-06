#!/usr/bin/env python3
"""
Text embeddings for natural-language photo search (CLIP text tower, ONNX Runtime, CPU is fine).

  clip_text.py "apus la mare" "birthday cake" ...   →  one JSON array (512 floats, L2-normalised) per line

Environment: RECOGNIZE_CLIP_MODEL_DIR (textual/model.onnx + textual/tokenizer.json)
"""
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')

import numpy as np  # noqa: E402
import onnxruntime  # noqa: E402
from tokenizers import Tokenizer  # noqa: E402

MODEL_DIR = os.environ.get('RECOGNIZE_CLIP_MODEL_DIR') or ''
MAX_LEN = 77


def main():
    texts = sys.argv[1:]
    if not texts:
        raise SystemExit('usage: clip_text.py <text>...')
    textual = os.path.join(MODEL_DIR, 'textual')
    if not os.path.isfile(os.path.join(textual, 'model.onnx')):
        raise SystemExit('RECOGNIZE_CLIP_MODEL_DIR must contain textual/model.onnx (got %r)' % MODEL_DIR)
    tokenizer = Tokenizer.from_file(os.path.join(textual, 'tokenizer.json'))
    pad_id = tokenizer.token_to_id('<pad>')
    if pad_id is None:
        pad_id = tokenizer.token_to_id('<|endoftext|>') or 0
    tokenizer.enable_truncation(MAX_LEN)
    tokenizer.enable_padding(length=MAX_LEN, pad_id=pad_id, pad_token='<pad>' if tokenizer.token_to_id('<pad>') is not None else '<|endoftext|>')
    session = onnxruntime.InferenceSession(os.path.join(textual, 'model.onnx'), providers=['CPUExecutionProvider'])
    inputs = {i.name: i for i in session.get_inputs()}

    for text in texts:
        enc = tokenizer.encode(text)
        ids = np.array([enc.ids], dtype=np.int64)
        mask = np.array([enc.attention_mask], dtype=np.int64)
        feed = {}
        for name in inputs:
            lname = name.lower()
            if 'mask' in lname:
                feed[name] = mask
            elif inputs[name].type.startswith('tensor(int32'):
                feed[name] = ids.astype(np.int32)
            else:
                feed[name] = ids
        out = session.run(None, feed)[0][0]
        out = out / max(float(np.linalg.norm(out)), 1e-9)
        print(json.dumps([round(float(v), 6) for v in out]), flush=True)


if __name__ == '__main__':
    main()
