def suggest(faces: list, saliency: dict | None, img_w: int, img_h: int) -> dict:
    if faces:
        # Use centroid of all faces
        cx = sum(f["cx"] for f in faces) / len(faces)
        cy = sum(f["cy"] for f in faces) / len(faces)
    elif saliency:
        cx = saliency["cx"]
        cy = saliency["cy"]
    else:
        return {"align": {"x": 0.0, "y": 0.0}, "zoom": 1.0}

    align_x = round((cx - 0.5) * 2, 4)
    align_y = round((cy - 0.5) * 2, 4)

    offset = (align_x ** 2 + align_y ** 2) ** 0.5
    zoom = round(1.0 + min(0.15, offset * 0.2), 3)

    return {"align": {"x": align_x, "y": align_y}, "zoom": zoom}