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


def detect(img) -> dict | None:
    if not HAS_CV2 or not HAS_NP or img is None:
        return None
    sal = cv2.saliency.StaticSaliencySpectralResidual_create()
    ok, m = sal.computeSaliency(cv2.cvtColor(img, cv2.COLOR_RGB2BGR))
    if not ok:
        return None
    y, x = np.unravel_index(np.argmax(m), m.shape)
    H, W = m.shape
    return {"cx": round(float(x / W), 4), "cy": round(float(y / H), 4)}