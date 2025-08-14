#!/usr/bin/env python3
import argparse, json, sys
from pathlib import Path

# Optional deps
try:
    import cv2  # type: ignore
    HAS_CV2 = True
except Exception:
    cv2 = None
    HAS_CV2 = False

try:
    from PIL import Image  # type: ignore
    HAS_PIL = True
except Exception:
    Image = None
    HAS_PIL = False

try:
    import numpy as np  # type: ignore
    HAS_NP = True
except Exception:
    np = None
    HAS_NP = False

# onnxruntime is optional and not used by default in this script
try:
    import onnxruntime as ort  # type: ignore
    HAS_ONNX = True
except Exception:
    HAS_ONNX = False


def load_image(path, max_edge=1024):
    if not HAS_PIL:
        raise RuntimeError("Pillow not installed")
    im = Image.open(path).convert('RGB')
    w, h = im.size
    s = min(1.0, max_edge / max(w, h))
    if s < 1.0:
        im = im.resize((int(w * s), int(h * s)), Image.BICUBIC)
    return np.array(im) if HAS_NP else None


def detect_faces_haarcascade(img):
    if not HAS_CV2 or img is None:
        return []
    gray = cv2.cvtColor(img, cv2.COLOR_RGB2GRAY)
    cc = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    faces = cc.detectMultiScale(gray, 1.1, 4)
    H, W = gray.shape
    out = []
    for (x, y, w, h) in faces:
        out.append({"cx": (x + w / 2) / W, "cy": (y + h / 2) / H, "w": w / W, "h": h / H, "score": 1.0})
    return out


def saliency_spectral(img):
    if not HAS_CV2 or img is None:
        return None
    sal = cv2.saliency.StaticSaliencySpectralResidual_create()
    ok, m = sal.computeSaliency(cv2.cvtColor(img, cv2.COLOR_RGB2BGR))
    if not ok:
        return None
    y, x = (0, 0)
    try:
        import numpy as _np  # local to avoid hard dep if np missing
        y, x = _np.unravel_index(_np.argmax(m), m.shape)
        H, W = m.shape
        return {"cx": float(x / W), "cy": float(y / H)}
    except Exception:
        return None


def aesthetic_dummy(img):
    # placeholder (0..10). Plug NIMA ONNX later if desired.
    if not HAS_NP or img is None:
        return 5.0
    import numpy as _np
    return float(_np.clip(5.0 + _np.random.normal(0, 1.0), 0, 10))


def horizon_deg(img):
    if not HAS_CV2 or img is None:
        return 0.0
    g = cv2.cvtColor(img, cv2.COLOR_RGB2GRAY)
    g = cv2.GaussianBlur(g, (5, 5), 0)
    edges = cv2.Canny(g, 50, 150, apertureSize=3)
    lines = cv2.HoughLines(edges, 1, 3.14159 / 180, 160)
    if lines is None:
        return 0.0
    angles = []
    for line in lines[:, 0, :]:
        rho, theta = float(line[0]), float(line[1])
        deg = (theta * 180.0 / 3.14159)
        d = min(abs(deg - 0), abs(deg - 180))
        if d < 15:
            angles.append(deg)
    if not angles:
        return 0.0
    devs = []
    for a in angles:
        devs.append(a if a < 90 else 180 - a)
    import numpy as _np
    dev = float(_np.mean(devs))
    return float(max(-10.0, min(10.0, dev if dev <= 90 else dev - 180)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--list', required=True, help='Text file: path<TAB>relpath per line')
    ap.add_argument('--out', required=True, help='Output JSONL')
    args = ap.parse_args()

    out = open(args.out, 'w', encoding='utf-8')
    with open(args.list, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            local, rel = line.split('\t', 1)
            try:
                img = load_image(local, max_edge=1200)
                faces = detect_faces_haarcascade(img)
                sal = saliency_spectral(img)
                aest = aesthetic_dummy(img)
                hori = horizon_deg(img)
                out.write(json.dumps({
                    "path": rel,
                    "faces": faces,
                    "saliency": sal,
                    "aesthetic": aest,
                    "horizon_deg": hori
                }) + '\n')
            except Exception as e:
                out.write(json.dumps({"path": rel, "error": str(e)}) + '\n')
                continue
    out.close()


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
