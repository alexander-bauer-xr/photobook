#!/bin/bash
# Convert RGB PDF to CMYK for professional printing
# Requires: Ghostscript (gs)
#
# Usage: ./scripts/convert-to-cmyk.sh input.pdf [output.pdf]
#
# This script converts an RGB PDF to CMYK color space using Ghostscript's
# pdfwrite device with appropriate settings for professional printing.

set -e

INPUT_FILE="${1:-}"
OUTPUT_FILE="${2:-}"

if [ -z "$INPUT_FILE" ]; then
    echo "Usage: $0 input.pdf [output.pdf]"
    echo ""
    echo "Convert RGB PDF to CMYK for professional printing."
    echo ""
    echo "Options:"
    echo "  input.pdf   - Source PDF file (RGB)"
    echo "  output.pdf  - Output file (default: input_cmyk.pdf)"
    exit 1
fi

if [ ! -f "$INPUT_FILE" ]; then
    echo "Error: Input file not found: $INPUT_FILE"
    exit 1
fi

# Check if Ghostscript is installed
if ! command -v gs &> /dev/null; then
    echo "Error: Ghostscript (gs) is not installed."
    echo ""
    echo "Install it with:"
    echo "  Ubuntu/Debian: sudo apt-get install ghostscript"
    echo "  macOS:         brew install ghostscript"
    echo "  RHEL/CentOS:   sudo yum install ghostscript"
    exit 1
fi

# Generate output filename if not provided
if [ -z "$OUTPUT_FILE" ]; then
    BASENAME=$(basename "$INPUT_FILE" .pdf)
    DIRNAME=$(dirname "$INPUT_FILE")
    OUTPUT_FILE="${DIRNAME}/${BASENAME}_cmyk.pdf"
fi

echo "Converting to CMYK..."
echo "  Input:  $INPUT_FILE"
echo "  Output: $OUTPUT_FILE"

# Ghostscript command for RGB to CMYK conversion
# - Uses pdfwrite device for PDF output
# - sColorConversionStrategy=CMYK forces CMYK color space
# - ProcessColorModel=DeviceCMYK sets output color model
# - UCRandBGInfo preserves black generation and undercolor removal
# - Compatible with PDF/X-3 print workflows
gs -dSAFER -dBATCH -dNOPAUSE -dNOCACHE \
   -sDEVICE=pdfwrite \
   -sColorConversionStrategy=CMYK \
   -dProcessColorModel=/DeviceCMYK \
   -dCompatibilityLevel=1.4 \
   -dPDFSETTINGS=/prepress \
   -dEmbedAllFonts=true \
   -dSubsetFonts=true \
   -dAutoRotatePages=/None \
   -dCompressPages=true \
   -dUCRandBGInfo=/Preserve \
   -sOutputFile="$OUTPUT_FILE" \
   "$INPUT_FILE"

echo ""
echo "✓ Conversion complete: $OUTPUT_FILE"
echo ""
echo "PDF info:"
if command -v pdfinfo &> /dev/null; then
    pdfinfo "$OUTPUT_FILE" 2>/dev/null | grep -E "(Pages|Page size|PDF version)" || true
fi
