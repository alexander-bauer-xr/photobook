# Professional Print Production Guide

This guide covers how to prepare photobook PDFs for professional printing with your print shop.

## Print Shop Requirements (Your Specs)

Based on your print shop's specifications:

| Requirement | Setting | Notes |
|-------------|---------|-------|
| **Color Space** | CMYK | Convert from RGB after generation |
| **Bleed** | 3mm | All edges |
| **Format** | A4 Landscape | 297 × 210 mm (trim size) |
| **Paper** | Glossy | Glänzendes Papier |
| **Binding** | Perfect Binding | Klebebindung with softcover |

## Quick Start

### 1. Enable Print Mode

Add to your `.env` file:

```env
PHOTOBOOK_PRINT_ENABLED=true
PHOTOBOOK_PRINT_BLEED_MM=3.0
PHOTOBOOK_PRINT_CROP_MARKS=true
PHOTOBOOK_PRINT_SPINE_MM=10.0
```

### 2. Generate the PDF

```bash
php artisan photobook:build --folder="Photos/2025/MyAlbum"
```

### 3. Convert to CMYK

```bash
./scripts/convert-to-cmyk.sh storage/app/pdf-exports/book-*.pdf
```

This creates `book-*_cmyk.pdf` ready for the print shop.

---

## Detailed Settings

### Bleed (Beschnittzugabe)

Bleed is the area beyond the trim edge where content extends. It gets cut off during finishing but ensures no white edges appear if cutting is slightly off.

```env
# Standard 3mm bleed (your print shop's requirement)
PHOTOBOOK_PRINT_BLEED_MM=3.0
```

**Final PDF dimensions with bleed:**
- A4 Landscape: 303 × 216 mm (297+6 × 210+6)
- A4 Portrait: 216 × 303 mm

### Crop Marks (Schnittmarken)

Crop marks show the print operator where to cut the pages.

```env
PHOTOBOOK_PRINT_CROP_MARKS=true
```

### Spine/Binding Margin (Bundsteg)

For perfect binding (Klebebindung), content near the spine can get lost in the glue. The spine margin prevents this.

```env
# 10mm margin on spine side
PHOTOBOOK_PRINT_SPINE_MM=10.0
```

The margin automatically alternates:
- Odd pages (right side of spread): margin on right
- Even pages (left side of spread): margin on left

### Safety Zone

Keep important content (text, faces) away from the trim edge:

```env
PHOTOBOOK_PRINT_SAFE_ZONE_MM=5.0
```

---

## CMYK Color Conversion

Dompdf generates PDFs in RGB color space. Professional printing requires CMYK.

### Using Ghostscript

```bash
# Install Ghostscript
sudo apt-get install ghostscript  # Ubuntu/Debian
brew install ghostscript           # macOS

# Convert to CMYK
./scripts/convert-to-cmyk.sh input.pdf output_cmyk.pdf
```

### What the Script Does

1. Converts all colors from sRGB to CMYK
2. Embeds all fonts (subset)
3. Sets PDF/X compatibility for print workflows
4. Preserves image quality with prepress settings

### Alternative: Professional Tools

For critical color accuracy, consider:
- Adobe Acrobat Pro (Preflight → Convert to CMYK)
- Callas pdfToolbox
- PitStop Pro

---

## Preflight Checklist

Before sending to the print shop, verify:

- [ ] **PDF Page Size**: Should be trim size + bleed (303×216mm for A4 landscape)
- [ ] **Color Space**: CMYK (use `pdfinfo` or Acrobat to verify)
- [ ] **Bleed Content**: Images extend to bleed edge (no white edges)
- [ ] **Crop Marks**: Visible at corners
- [ ] **Fonts Embedded**: All fonts should be embedded
- [ ] **Resolution**: Images at least 200 DPI at print size

### Verify with Command Line

```bash
# Check PDF info
pdfinfo book_cmyk.pdf

# Check color space (requires poppler-utils)
pdfimages -list book_cmyk.pdf | head -20
```

---

## Page Layout Reference

```
┌─────────────────────────────────────────┐
│              BLEED (3mm)                │
│  ┌───────────────────────────────────┐  │
│  │         TRIM EDGE                 │  │
│  │  ┌─────────────────────────────┐  │  │
│  │  │      SAFE ZONE (5mm)        │  │  │
│  │  │  ┌───────────────────────┐  │  │  │
│  │  │  │                       │  │  │  │
│  │  │  │    CONTENT AREA       │  │  │  │
│  │  │  │                       │  │  │  │
│  │  │  │                       │  │  │  │
│  │  │  └───────────────────────┘  │  │  │
│  │  └─────────────────────────────┘  │  │
│  └───────────────────────────────────┘  │
└─────────────────────────────────────────┘
```

For spreads with binding margin:

```
LEFT PAGE                RIGHT PAGE
┌──────────┬────┐       ┌────┬──────────┐
│          │    │       │    │          │
│ CONTENT  │BIND│ SPINE │BIND│ CONTENT  │
│          │    │       │    │          │
└──────────┴────┘       └────┴──────────┘
              10mm       10mm
```

---

## Troubleshooting

### White Edges After Cutting

**Cause**: Images don't extend into bleed area.

**Fix**: Ensure `PHOTOBOOK_PRINT_ENABLED=true` and that cover/full-bleed images extend to page edge.

### Text Cut Off Near Spine

**Cause**: Spine margin not applied.

**Fix**: Increase `PHOTOBOOK_PRINT_SPINE_MM` (try 12-15mm for thick books).

### Colors Look Different When Printed

**Cause**: RGB to CMYK conversion can shift colors (especially bright blues, greens).

**Fix**: 
1. Use the CMYK conversion script
2. Consider soft-proofing in Photoshop/Acrobat before printing
3. Request a proof from print shop before full run

### PDF Too Large

**Cause**: High-resolution images embedded.

**Fix**: Adjust image optimization settings:
```env
PHOTOBOOK_TARGET_DPI=180  # Lower from 200
PHOTOBOOK_JPEG_QUALITY=82 # Lower from 88
```

---

## Communication with Print Shop

When submitting files, include:

1. **PDF File**: `book_cmyk.pdf`
2. **Specs Summary**:
   - Format: A4 Landscape (297×210mm)
   - Bleed: 3mm included
   - Binding: Softcover, Perfect Binding (Klebebindung)
   - Paper: Glossy (glänzend)
   - Color: CMYK

Sample email:

> Anbei die Druckdaten für das Fotobuch:
> - Format: A4 Querformat mit 3mm Beschnittzugabe
> - Farbprofil: CMYK
> - Bindung: Klebebindung mit Softcover
> - Papier: Glänzend
>
> Die PDF enthält Schnittmarken zur Orientierung.
