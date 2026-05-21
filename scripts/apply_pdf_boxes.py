#!/usr/bin/env python3

import sys
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject


def mm_to_pt(mm: float) -> float:
    return mm * 72.0 / 25.4


def usage() -> None:
    print(
        "Usage: apply_pdf_boxes.py input.pdf output.pdf trim_width_mm trim_height_mm bleed_mm",
        file=sys.stderr,
    )


def main() -> int:
    if len(sys.argv) != 6:
        usage()
        return 1

    input_path = sys.argv[1]
    output_path = sys.argv[2]
    trim_width_mm = float(sys.argv[3])
    trim_height_mm = float(sys.argv[4])
    bleed_mm = float(sys.argv[5])

    trim_width = mm_to_pt(trim_width_mm)
    trim_height = mm_to_pt(trim_height_mm)
    bleed = mm_to_pt(bleed_mm)

    media_width = trim_width + 2 * bleed
    media_height = trim_height + 2 * bleed

    media_box = RectangleObject(
        [
            0,
            0,
            media_width,
            media_height,
        ]
    )

    trim_box = RectangleObject(
        [
            bleed,
            bleed,
            bleed + trim_width,
            bleed + trim_height,
        ]
    )

    bleed_box = RectangleObject(
        [
            0,
            0,
            media_width,
            media_height,
        ]
    )

    reader = PdfReader(input_path)
    writer = PdfWriter()

    for page in reader.pages:
        page.mediabox = media_box
        page.cropbox = media_box
        page.bleedbox = bleed_box
        page.trimbox = trim_box
        page.artbox = trim_box
        writer.add_page(page)

    with open(output_path, "wb") as f:
        writer.write(f)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
