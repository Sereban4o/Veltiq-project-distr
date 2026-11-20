# Veltiq brand assets

Source of truth for the Veltiq logo ("V-check" mark).

## Files
- `veltiq-icon.svg` — icon (blue rounded square + white check/V).
- `veltiq-icon-mono-white.svg` — white glyph on transparent (dark backgrounds).
- `veltiq-logo-full.svg` / `veltiq-logo-full-white.svg` — lockups for docs.
- `generate_assets.py` — regenerates all raster assets with Pillow.

## Design tokens
- Shape: circle (diameter 1000 in a 1024 canvas, transparent corners). Gradient 135° `#0052CC → #4C9AFF`.
- Gradient `#0052CC → #4C9AFF` (135°).
- Glyph `M 322,310 L 512,714 L 702,310`, stroke 132, round caps/joins, `#FFFFFF`.
- Colors: Blue `#0052CC`, Sky `#4C9AFF`, Ink `#172B4D`, Grey `#5E6C84`.

## Regenerate
```bash
python scripts/brand/generate_assets.py
```

Outputs:
- `frontend/public/` — favicons, apple-touch-icon, `veltiq-icon.svg`, 192/512 icons.
- `backend/public/` — `veltiq-icon.svg`, `favicon.ico`.
- `android/app/src/main/res/mipmap-*/` — launcher icons (+ round).
- `android/app/src/main/res/drawable-*/ic_notification.png` — notification glyph.
- `android/desktop/icons/veltiq.ico` — Windows installer icon.
- `android/desktop/src/main/resources/veltiq-icon.png` — window/tray icon.
