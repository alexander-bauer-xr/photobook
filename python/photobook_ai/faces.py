try:
    import cv2
    HAS_CV2 = True
except Exception:
    cv2 = None
    HAS_CV2 = False
    
def detect(img) -> list:
    if not HAS_CV2 or img is None:
        return []
    gray = cv2.cvtColor(img, cv2.COLOR_RGB2GRAY)
    cc = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    faces = cc.detectMultiScale(gray, 1.1, 4)
    H, W = gray.shape
    out = []
    for (x, y, w, h) in faces:
        out.append({
            "cx": round((x + w / 2) / W, 4),
            "cy": round((y + h / 2) / H, 4),
            "w":  round(w / W, 4),
            "h":  round(h / H, 4),
            "score": 1.0,
        })
    return out