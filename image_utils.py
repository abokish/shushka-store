"""
עזרים משותפים לעריכת תמונות — משמש את fetch_images.py ואת review_and_upload.py.

אם rembg מותקן: מוחק רקע → מדביק על רקע חמים + צל.
אם לא: חימום גוונים בלבד (fallback).
"""

import io
import random
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

IMG_SIZE     = 800
TARGET_KB    = 300
WARM_BG_RGB  = (248, 244, 236)   # קרם בהיר
SHADOW_COLOR = (100, 65, 30)

BACKGROUNDS_DIR = Path(__file__).parent / "backgrounds"

USE_REMBG = False  # מושבת — גורם לנזק על אריזות כסופות/בהירות

try:
    from rembg import remove as _rembg_remove
    _REMBG_IMPORTABLE = True
    REMBG_AVAILABLE = USE_REMBG
    print(f"[image_utils] rembg {'מופעל' if USE_REMBG else 'מושבת — fallback (חימום גוונים)'}.")
except ImportError:
    _REMBG_IMPORTABLE = False
    REMBG_AVAILABLE = False
    print("[image_utils] rembg לא מותקן — עובד במצב fallback (חימום גוונים).")


# ── הסרת רקע וצל ─────────────────────────────────────────────────────────────

def _remove_bg(img):
    """מחזיר תמונה RGBA ללא רקע."""
    return _rembg_remove(img)


def _center_cutout(img_rgba, canvas_size=IMG_SIZE, max_frac=0.86):
    """
    ממרכז את התוכן הגלוי בפועל של cutout (לפי bounding box של ערוץ ה-alpha)
    בתוך קנבס בגודל canvas_size — ולא רק מניח אותו ב-(0,0) כמו שה-rembg החזיר.
    חשוב במיוחד כש-rembg "אוכל" חלק מהמוצר (למשל בקבוק שקוף) ונשאר רק פריט
    קטן יותר (כמו המדבקה) שבלי זה היה נשאר תקוע באותו מיקום שהיה לו בתמונה
    המקורית, בלי מרכוז.
    """
    bbox = img_rgba.split()[3].getbbox()
    if not bbox:
        return img_rgba
    cropped = img_rgba.crop(bbox)
    cw, ch = cropped.size
    max_dim = int(canvas_size * max_frac)
    if max(cw, ch) > max_dim:
        scale = max_dim / max(cw, ch)
        cropped = cropped.resize((max(1, int(cw * scale)), max(1, int(ch * scale))), Image.LANCZOS)
        cw, ch = cropped.size
    centered = Image.new("RGBA", (canvas_size, canvas_size), (0, 0, 0, 0))
    centered.paste(cropped, ((canvas_size - cw) // 2, (canvas_size - ch) // 2), cropped)
    return centered


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

def edit_image(img, force_rembg=None):
    """
    Pipeline מלא:
      crop → resize → rembg (אם זמין) → רקע חמים + צל → וינייט
      fallback (בלי rembg): crop → חימום גוונים → וינייט

    force_rembg: None משתמש בדגל הגלובלי USE_REMBG (ברירת מחדל, ללא שינוי התנהגות
    לקוראים קיימים). True/False דורס אותו לקריאה הזו בלבד — דורש ש-rembg מותקן בפועל.
    """
    use_rembg = REMBG_AVAILABLE if force_rembg is None else (force_rembg and _REMBG_IMPORTABLE)

    # 1. חיתוך מרכז + resize
    w, h = img.size
    s = min(w, h)
    img = img.crop(((w - s) // 2, (h - s) // 2, (w + s) // 2, (h + s) // 2))
    img = img.resize((IMG_SIZE, IMG_SIZE), Image.LANCZOS)

    if use_rembg:
        try:
            img_rgba = _center_cutout(_remove_bg(img))
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


# ── רקע מצויר (AI) + מוצר אמיתי — לדף צילום המוצרים של העובדים ───────────────

def compose_on_painted_background(img):
    """
    חיתוך מרכז + resize, rembg cutout אמיתי של המוצר, הדבקה על אחד מרקעי ה-AI
    המצוירים הקבועים (backgrounds/*.png — נוצרו פעם אחת מראש, לא ליצור חדש
    לכל מוצר) + צל רך. המוצר עצמו תמיד נשאר תמונה אמיתית/מדויקת — ה-AI מצייר
    רק את הרקע, לא נוגע במוצר ולא עלול לסרס טקסט על התווית.

    לשימוש ע"י process_product_photos.py בלבד — לא משנה את edit_image() הרגיל
    (רקע קרם) ששאר הסקריפטים (fetch_images.py, review_and_upload.py) משתמשים בו.
    דורש ש-rembg יהיה מותקן בפועל; בלי זה, או בלי רקעים ב-backgrounds/, נופל
    חזרה ל-edit_image() הרגיל.
    """
    w, h = img.size
    s = min(w, h)
    img = img.crop(((w - s) // 2, (h - s) // 2, (w + s) // 2, (h + s) // 2))
    img = img.resize((IMG_SIZE, IMG_SIZE), Image.LANCZOS)

    if not _REMBG_IMPORTABLE:
        return edit_image(img)

    bg_files = sorted(BACKGROUNDS_DIR.glob("*.png"))
    if not bg_files:
        return edit_image(img, force_rembg=True)

    try:
        fg = _center_cutout(_remove_bg(img))
        bg = Image.open(random.choice(bg_files)).convert("RGBA").resize((IMG_SIZE, IMG_SIZE), Image.LANCZOS)
        composite = _add_shadow(bg, fg)
        return composite.convert("RGB")
    except Exception:
        return edit_image(img, force_rembg=True)


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
