"""
עזרים משותפים לעריכת תמונות — משמש את fetch_images.py ואת review_and_upload.py.

אם rembg מותקן: מוחק רקע → מדביק על רקע חמים + צל.
אם לא: חימום גוונים בלבד (fallback).
"""

import io
import numpy as np
from PIL import Image, ImageFilter

IMG_SIZE     = 800
TARGET_KB    = 300
WARM_BG_RGB  = (248, 244, 236)   # קרם בהיר
SHADOW_COLOR = (100, 65, 30)

USE_REMBG = False  # מושבת — גורם לנזק על אריזות כסופות/בהירות

try:
    from rembg import remove as _rembg_remove
    REMBG_AVAILABLE = USE_REMBG
    print(f"[image_utils] rembg {'מופעל' if USE_REMBG else 'מושבת — fallback (חימום גוונים)'}.")
except ImportError:
    REMBG_AVAILABLE = False
    print("[image_utils] rembg לא מותקן — עובד במצב fallback (חימום גוונים).")


# ── הסרת רקע וצל ─────────────────────────────────────────────────────────────

def _remove_bg(img):
    """מחזיר תמונה RGBA ללא רקע."""
    return _rembg_remove(img)


def _add_shadow(bg_rgba, fg_rgba, offset_x=6, offset_y=10, blur=12):
    """מדביק fg_rgba על bg_rgba עם צל רך."""
    _, _, _, a = fg_rgba.split()
    shadow_mask = a.filter(ImageFilter.GaussianBlur(blur))
    shadow_layer = Image.new("RGBA", fg_rgba.size, (*SHADOW_COLOR, 150))
    shadow = Image.new("RGBA", fg_rgba.size, (0, 0, 0, 0))
    shadow.paste(shadow_layer, mask=shadow_mask)
    bg_rgba.paste(shadow, (offset_x, offset_y), shadow)
    bg_rgba.paste(fg_rgba, (0, 0), fg_rgba)
    return bg_rgba


# ── עריכת numpy ───────────────────────────────────────────────────────────────

def _warm_tones(arr):
    arr[:, :, 0] = np.clip(arr[:, :, 0] * 1.07, 0, 255)   # R ↑
    arr[:, :, 2] = np.clip(arr[:, :, 2] * 0.93, 0, 255)   # B ↓
    return arr

def _vignette(arr):
    Y, X = np.ogrid[:IMG_SIZE, :IMG_SIZE]
    cx, cy = IMG_SIZE / 2.0, IMG_SIZE / 2.0
    dist = np.sqrt(((X - cx) / cx) ** 2 + ((Y - cy) / cy) ** 2)
    factor = 1.0 - 0.35 * np.clip(dist, 0, 1) ** 1.5
    return arr * factor[:, :, np.newaxis]


# ── פונקציה ראשית ─────────────────────────────────────────────────────────────

def edit_image(img):
    """
    Pipeline מלא:
      crop → resize → rembg (אם זמין) → רקע חמים + צל → וינייט
      fallback (בלי rembg): crop → חימום גוונים → וינייט
    """
    # 1. חיתוך מרכז + resize
    w, h = img.size
    s = min(w, h)
    img = img.crop(((w - s) // 2, (h - s) // 2, (w + s) // 2, (h + s) // 2))
    img = img.resize((IMG_SIZE, IMG_SIZE), Image.LANCZOS)

    if REMBG_AVAILABLE:
        try:
            img_rgba = _remove_bg(img)
            bg = Image.new("RGBA", (IMG_SIZE, IMG_SIZE), (*WARM_BG_RGB, 255))
            composite = _add_shadow(bg, img_rgba)
            img = composite.convert("RGB")
        except Exception:
            # rembg נכשל על תמונה ספציפית → fallback
            arr = _warm_tones(np.array(img, dtype=np.float32))
            img = Image.fromarray(arr.astype(np.uint8))
    else:
        arr = np.array(img, dtype=np.float32)
        # רקע בהיר → שזף לרקע חמים
        corners = arr[
            [10, 10, IMG_SIZE - 11, IMG_SIZE - 11],
            [10, IMG_SIZE - 11, 10, IMG_SIZE - 11]
        ]
        if corners.mean() > 200:
            bg = np.full((IMG_SIZE, IMG_SIZE, 3), WARM_BG_RGB, dtype=np.float32)
            arr = arr * 0.88 + bg * 0.12
        arr = _warm_tones(arr)
        img = Image.fromarray(arr.astype(np.uint8))

    # וינייט (תמיד)
    arr = _vignette(np.array(img, dtype=np.float32))
    return Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8))


# ── דחיסה ─────────────────────────────────────────────────────────────────────

def compress_to_bytes(img, target_kb=TARGET_KB):
    for quality in [85, 80, 75, 70, 65]:
        buf = io.BytesIO()
        img.save(buf, "JPEG", quality=quality, optimize=True)
        if buf.tell() < target_kb * 1024:
            return buf.getvalue()
    return buf.getvalue()

def compress_save(img, path, target_kb=TARGET_KB):
    """שומר תמונה דחוסה ל-path. מחזיר גודל בKB."""
    from pathlib import Path
    data = compress_to_bytes(img, target_kb)
    Path(path).write_bytes(data)
    return len(data) / 1024
