try:
    import imagehash
    from PIL import Image as PILImage
    HAS_PHASH = True
except Exception:
    HAS_PHASH = False


def compute(img_path: str) -> str | None:
    if not HAS_PHASH:
        return None
    try:
        h = imagehash.phash(PILImage.open(img_path))
        return str(h)
    except Exception:
        return None