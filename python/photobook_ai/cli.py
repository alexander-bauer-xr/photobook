#!/usr/bin/env python3
import argparse
import json
import sys
from pathlib import Path

try:
    import numpy as np
    from PIL import Image
    import cv2 as _cv2
    HAS_IMAGING = True
except Exception:
    HAS_IMAGING = False

from photobook_ai import faces, saliency, quality, crops, phash

ANALYSIS_VERSION = "v2.0"


def load_image(path: str, max_edge: int = 1200):
    if not HAS_IMAGING:
        raise RuntimeError("Pillow, numpy or opencv not installed")
    img = Image.open(path).convert("RGB")
    w, h = img.size
    s = min(1.0, max_edge / max(w, h))
    if s < 1.0:
        img = img.resize((int(w * s), int(h * s)), Image.BICUBIC)
    orig_w, orig_h = w, h
    arr = np.array(img)
    return arr, orig_w, orig_h


def analyze(input_path: str, output_path: str) -> None:
    arr, w, h = load_image(input_path)

    face_list  = faces.detect(arr)
    sal        = saliency.detect(arr)
    sharp      = quality.sharpness(arr)
    aest       = quality.aesthetic(arr)
    crop       = crops.suggest(face_list, sal, w, h)
    ph         = phash.compute(input_path)

    result = {
        "width":            w,
        "height":           h,
        "faces":            face_list,
        "saliency":         sal,
        "suggested_crop":   crop,
        "quality": {
            "sharpness":  sharp,
            "aesthetic":  aest,
        },
        "phash":            ph,
        "analysis_version": ANALYSIS_VERSION,
    }

    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(result, f, indent=2)


def main():
    ap = argparse.ArgumentParser(description="Photobook AI feature extractor")
    sub = ap.add_subparsers(dest="command")

    cmd = sub.add_parser("analyze")
    cmd.add_argument("--input",  required=True)
    cmd.add_argument("--output", required=True)

    args = ap.parse_args()

    if args.command == "analyze":
        analyze(args.input, args.output)
    else:
        ap.print_help()
        sys.exit(1)


if __name__ == "__main__":
    main()