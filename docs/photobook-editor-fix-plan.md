# Photobook Editor Stabilization Plan

## Summary

The editor now needs one canonical Inertia/React flow: load and save built albums through the hash-based `/api/photobook/...` API, keep `pages.json` as the immediate source of truth, and mirror editor decisions to `overrides.json` only so rebuilds can honor them.

## Implementation Checklist

- Replace active editor saves with `/api/photobook/pages/{hash}` patches.
- Normalize page IDs so the cover is always `id: "cover", n: 0` and interior pages are numbered from `1`.
- Autosave structural edits: cover photo replacement, photo replacement, image reorder, and template changes.
- Keep crop, zoom, rotation, captions, and cover text as explicit-save edits with a visible dirty state.
- Add layout preference feedback with `/api/photobook/feedback/{hash}` and persist it to `pages.json`, `overrides.json`, and `feedback.log`.
- Make the workspace stage and sidebar use fixed-height flex containers so the canvas fills the available space and the item list scrolls independently.
- Remove the redundant cover `Choose photo` button; cover replacement happens from the cover item just like normal page replacement.

## Acceptance Tests

- The editor canvas fills the available center workspace.
- Reordering page images persists after refresh.
- Replacing a page image persists after refresh.
- Replacing the cover photo persists after refresh and appears in PDF export.
- Changing a template persists after refresh.
- Toggling “Prefer this layout” persists after refresh and writes rebuild-readable feedback.
- Loading and save status indicators do not push the editor sideways.
- PDF export still renders print pages without editor overlays.
