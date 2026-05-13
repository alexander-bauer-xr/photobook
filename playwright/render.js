#!/usr/bin/env node
/**
 * playwright/render.js
 *
 * Renders a photobook preview URL to PDF using Playwright.
 *
 * Usage:
 *   node playwright/render.js \
 *     --url    http://localhost:8000/photobook/preview/abc123?print=1 \
 *     --output /tmp/book.pdf \
 *     --width  210 \
 *     --height 297
 *
 * --width / --height are in millimetres (default A4 portrait).
 * Exit code 0 on success, non-zero on failure.
 */

import { chromium } from 'playwright';

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
const args = process.argv.slice(2);
const get = (flag) => {
  const i = args.indexOf(flag);
  return i !== -1 ? args[i + 1] : null;
};

const url    = get('--url');
const output = get('--output');
const widthMm  = parseFloat(get('--width')  ?? '210');
const heightMm = parseFloat(get('--height') ?? '297');

if (!url || !output) {
  console.error('Usage: node playwright/render.js --url <url> --output <path> [--width mm] [--height mm]');
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
let browser;
try {
  browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  // Generous timeout — large photobooks can be slow to render
  page.setDefaultTimeout(120_000);

  // Match viewport to PDF page dimensions so that 100vh === one PDF page height.
  // CSS reference pixels at 96 DPI: 1 mm = 96/25.4 ≈ 3.7795 px
  const PX_PER_MM = 96 / 25.4;
  await page.setViewportSize({
    width:  Math.round(widthMm  * PX_PER_MM),
    height: Math.round(heightMm * PX_PER_MM),
  });

  // The print app has its own readiness signal; waiting for network idle can
  // stall forever when the regular editor is opened or some assets keep a
  // connection alive longer than expected.
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90_000 });

  // Wait for the React editor to signal it is print-ready
  // The page sets window.__printReady = true once all images are loaded.
  await page.waitForFunction(() => window.__printReady === true, { timeout: 60_000 })
    .catch(() => {
      // Graceful fallback: proceed even if signal never fires
      console.warn('render.js: __printReady timeout — printing anyway');
    });

  await page.pdf({
    path:   output,
    width:  `${widthMm}mm`,
    height: `${heightMm}mm`,
    printBackground: true,
    margin: { top: '0', right: '0', bottom: '0', left: '0' },
  });

  console.log(`render.js: PDF written to ${output}`);
} finally {
  await browser?.close();
}
