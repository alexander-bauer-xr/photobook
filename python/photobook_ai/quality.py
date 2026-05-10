try:
    import cv2
    HAS_CV2 = True
except Exception:
    cv2 = None
    HAS_CV2 = False

try:
    import numpy as np
    HAS_NP = True
except Exception:
    np = None
    HAS_NP = False


def sharpness(img) -> float:
    if not HAS_CV2 or not HAS_NP or img is None:
        return 0.0
    gray = cv2.cvtColor(img, cv2.COLOR_RGB2GRAY)
    lap = cv2.Laplacian(gray, cv2.CV_64F).var()
    import math
    return round(min(1.0, math.log1p(lap) / 10.0), 4)


def aesthetic(img) -> float:
    if not HAS_NP or img is None:
        return 5.0
    return round(float(np.clip(5.0 + np.random.normal(0, 0.5), 0, 10)), 2)