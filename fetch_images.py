"""
שלב א: מביא ועורך תמונות ל-50 מוצרים עם מותג.
שומר ל-images/pending/ וממתין לאישור ב-review_and_upload.py.

מקורות לפי סדר עדיפות:
  1. Open Food Facts (לפי ברקוד)
  2. Brave Image Search (חיפוש תמונות מוצר)
  3. DALL-E 3 (fallback אם לא נמצא כלום)

שימוש:
  python fetch_images.py           ← המשך מהדף האחרון
  python fetch_images.py --reset   ← התחל מדף 1
"""

import os, sys, json, re, io
from pathlib import Path
from datetime import datetime

import requests
import anthropic
from PIL import Image
from dotenv import load_dotenv
from woocommerce import API

from image_utils import edit_image, compress_to_bytes, REMBG_AVAILABLE

load_dotenv()

WC_URL         = os.getenv("WC_URL", "").rstrip("/")
WC_KEY         = os.getenv("WC_CONSUMER_KEY")
WC_SECRET      = os.getenv("WC_CONSUMER_SECRET")
ANTHROPIC_KEY  = os.getenv("ANTHROPIC_API_KEY")
OPENAI_KEY     = os.getenv("OPENAI_API_KEY")
BRAVE_KEY      = os.getenv("BRAVE_API_KEY")

BASE_DIR         = Path(__file__).parent / "images"
PENDING_DIR      = BASE_DIR / "pending"
APPROVED_DIR     = BASE_DIR / "approved"
NEEDS_MANUAL_DIR = BASE_DIR / "needs_manual"
OUTPUT_DIR       = BASE_DIR / "output"
PROGRESS_FILE    = BASE_DIR / "progress.json"
MANIFEST_FILE    = PENDING_DIR / "manifest.json"
LOG_FILE         = OUTPUT_DIR / "log.json"

BATCH_SIZE = 20

wcapi = API(url=WC_URL, consumer_key=WC_KEY, consumer_secret=WC_SECRET,
            version="wc/v3", timeout=30)


# ── קבצי מצב ──────────────────────────────────────────────────────────────────

def load_json(path, default):
    p = Path(path)
    return json.loads(p.read_text(encoding="utf-8")) if p.exists() else default

def save_json(path, data):
    Path(path).write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")

def log_event(**kw):
    log = load_json(LOG_FILE, [])
    log.append({"time": datetime.now().isoformat(), **kw})
    save_json(LOG_FILE, log)


# ── עזרים למוצר ───────────────────────────────────────────────────────────────

def get_brand(product):
    for attr in product.get("attributes", []):
        if attr.get("name", "").strip().lower() in ("brand", "מותג"):
            vals = attr.get("options", [])
            return vals[0].strip() if vals else ""
    return ""

def safe_filename(pid, name, max_name=40):
    name = re.sub(r'[\\/*?:"<>|]', "_", name)[:max_name]
    return f"{pid}_{name}.jpg"


# ── מקורות תמונה ──────────────────────────────────────────────────────────────

def try_open_food_facts(sku):
    sku = (sku or "").strip()
    if sku.endswith(".0"):
        sku = sku[:-2]
    if len(sku) < 8:
        return None
    try:
        r = requests.get(
            f"https://world.openfoodfacts.org/api/v0/product/{sku}.json",
            timeout=12,
            headers={"User-Agent": "ShushkaStore-ImageFetcher/1.0 (abokish@gmail.com)"}
        )
        if not r.ok:
            return None
        data = r.json()
        if data.get("status") != 1:
            return None
        p = data["product"]
        return p.get("image_url") or p.get("image_front_url") or None
    except Exception:
        return None

def search_brave_images(query, count=10):
    """חיפוש תמונות מוצר דרך Brave Image Search."""
    if not BRAVE_KEY or not query:
        return []
    try:
        r = requests.get(
            "https://api.search.brave.com/res/v1/images/search",
            headers={"Accept": "application/json", "X-Subscription-Token": BRAVE_KEY},
            params={"q": query, "count": min(count, 20)},
            timeout=12
        )
        if not r.ok:
            print(f"  Brave שגיאה: {r.status_code}")
            return []
        urls = []
        for res in r.json().get("results", []):
            # properties.url = תמונת המוצר האמיתית, thumbnail.src = גרסת Brave CDN
            url = res.get("properties", {}).get("url") or res.get("thumbnail", {}).get("src")
            if url:
                urls.append(url)
        return urls
    except Exception as e:
        print(f"  Brave חריגה: {e}")
        return []

def generate_dalle(name, brand, category, en_name=""):
    """יצירת תמונה דרך DALL-E 3 כ-fallback אחרון."""
    if not OPENAI_KEY:
        return None, None
    try:
        from openai import OpenAI
        client = OpenAI(api_key=OPENAI_KEY)
        subject = en_name or f"{brand} {name}"
        prompt = (
            f"Professional product packaging photo of {subject}, "
            f"{category or 'natural health product'}, eco-friendly, "
            f"isolated on warm sandy beige background, soft studio lighting, "
            f"no text overlay, clean minimalist"
        )
        resp = client.images.generate(
            model="dall-e-3", prompt=prompt,
            size="1024x1024", quality="standard", n=1
        )
        url = resp.data[0].url
        img = download_image(url)
        return img, url
    except Exception as e:
        print(f"  DALL-E שגיאה: {e}")
        return None, None

def download_image(url, min_px=400):
    """מוריד תמונה ומחזיר None אם קטנה מדי."""
    try:
        r = requests.get(url, timeout=15, stream=True)
        if r.ok:
            img = Image.open(io.BytesIO(r.content)).convert("RGB")
            if min(img.size) < min_px:
                return None
            return img
    except Exception:
        pass
    return None

def batch_translate(products):
    """שמות מוצרים עברית → שאילתות חיפוש אנגלית דרך Claude Haiku."""
    if not ANTHROPIC_KEY:
        return {}
    lines = "\n".join(
        f'{i+1}. "{p["name"].strip()}" | brand: {get_brand(p)}'
        for i, p in enumerate(products)
    )
    try:
        client = anthropic.Anthropic(api_key=ANTHROPIC_KEY)
        resp = client.messages.create(
            model="claude-haiku-4-5-20251001",
            max_tokens=800,
            messages=[{"role": "user", "content":
                "For each Hebrew product below, write a short English product search query "
                "(2-4 words describing the product type and format, e.g. 'fabric softener bottle', "
                "'whole wheat spaghetti package', 'baby shampoo'). "
                "Focus on what the physical product looks like, not the brand. "
                "Format exactly: [number]. [query]\n\n" + lines
            }]
        )
        result = {}
        for line in resp.content[0].text.strip().split("\n"):
            m = re.match(r"(\d+)\.\s*(.+)", line.strip())
            if m:
                result[int(m.group(1)) - 1] = m.group(2).strip()
        return result
    except Exception:
        return {}


# ── לולאה הראשית ───────────────────────────────────────────────────────────────

def fetch_batch(reset=False):
    if reset:
        if PROGRESS_FILE.exists():
            PROGRESS_FILE.unlink()
        if MANIFEST_FILE.exists():
            MANIFEST_FILE.unlink()
        for f in PENDING_DIR.glob("*.jpg"):
            f.unlink()
        print("אופס מלא: progress, manifest ותמונות pending נמחקו — מתחיל מדף 1\n")

    for d in [PENDING_DIR, APPROVED_DIR, NEEDS_MANUAL_DIR, OUTPUT_DIR]:
        d.mkdir(parents=True, exist_ok=True)

    progress = load_json(PROGRESS_FILE, {"next_page": 1, "total_processed": 0})
    manifest = load_json(MANIFEST_FILE, {})

    page = progress["next_page"]
    per_page = 100
    collected = []

    print(f"סטטוס: עובדנו על {progress['total_processed']} מוצרים עד כה")
    print(f"rembg: {'פעיל' if REMBG_AVAILABLE else 'fallback'} | "
          f"Brave: {'פעיל' if BRAVE_KEY else 'לא מוגדר'} | "
          f"DALL-E: {'פעיל' if OPENAI_KEY else 'לא מוגדר'}")
    print(f"שולף מוצרים עם מותג החל מדף {page}...\n")

    while len(collected) < BATCH_SIZE:
        resp = wcapi.get("products", params={
            "page": page, "per_page": per_page, "status": "publish"
        })
        products = resp.json()
        if not isinstance(products, list) or not products:
            print("הגענו לסוף הקטלוג.")
            break

        for p in products:
            if len(collected) >= BATCH_SIZE:
                break
            if not get_brand(p):
                continue
            pid = str(p["id"])
            if pid in manifest and manifest[pid].get("status") in ("pending", "approved", "uploaded"):
                continue
            collected.append(p)

        if len(products) < per_page:
            page += 1
            break
        page += 1

    progress["next_page"] = page
    save_json(PROGRESS_FILE, progress)

    if not collected:
        print("אין מוצרים חדשים לעיבוד.")
        return

    print(f"נמצאו {len(collected)} מוצרים. מתרגם שמות לאנגלית...")
    en_queries = batch_translate(collected)
    print("תרגום הושלם. מחפש תמונות...\n")

    stats = {"open_food_facts": 0, "brave_he": 0, "brave_en": 0, "dall-e-3": 0, "none": 0}
    total_kb = []

    for i, product in enumerate(collected):
        pid = str(product["id"])
        name = product["name"].strip()
        brand = get_brand(product)
        sku = product.get("sku", "").strip()
        category = product["categories"][0]["name"] if product.get("categories") else ""
        en_q = en_queries.get(i, brand)

        print(f"[{i+1}/{len(collected)}] {name} | {brand}")

        available_meta = []

        # 1. Open Food Facts (לפי ברקוד — ללא עלות API)
        off_url = try_open_food_facts(sku)
        if off_url:
            available_meta.append({
                "url": off_url, "source": "open_food_facts",
                "note": "CC-BY-SA — בדוק רישיון"
            })

        # 2. Brave עברית — "אריזה" מסמן כוונת מוצר ולא תמונת אווירה
        q_he = f"{name} {brand} אריזה"
        brave_he = search_brave_images(q_he, 8)
        for u in brave_he:
            available_meta.append({"url": u, "source": "brave_he", "query": q_he})

        # 3. Brave אנגלית — רק אם עברית לא הניבה תוצאות
        if not brave_he:
            q_en = f"{brand} {en_q}"
            for u in search_brave_images(q_en, 8):
                if not any(x["url"] == u for x in available_meta):
                    available_meta.append({"url": u, "source": "brave_en", "query": q_en})

        # הורד את הראשונה שעובדת
        img_raw = None
        downloaded_idx = None
        for idx, candidate in enumerate(available_meta):
            img_raw = download_image(candidate["url"])
            if img_raw:
                downloaded_idx = idx
                break

        # DALL-E 3 — fallback אחרון
        if img_raw is None and OPENAI_KEY:
            print(f"  DALL-E 3 מייצר תמונה...")
            dalle_img, dalle_url = generate_dalle(name, brand, category, en_q)
            if dalle_img:
                available_meta.append({"url": dalle_url, "source": "dall-e-3",
                                        "note": "AI Generated"})
                img_raw = dalle_img
                downloaded_idx = len(available_meta) - 1

        entry = {
            "product_id": product["id"],
            "product_name": name,
            "brand": brand,
            "category": category,
            "sku": sku,
            "status": "needs_manual",
            "filename": None,
            "current_image_index": (downloaded_idx + 1) if downloaded_idx is not None else 0,
            "tried_urls": ([available_meta[downloaded_idx]["url"]] if downloaded_idx is not None else []),
            "available_meta": available_meta,
            "image_source": None,
            "source_url": None,
            "file_size_kb": None,
            "license_note": None,
        }

        if img_raw is None:
            print(f"  ✗ לא נמצאה תמונה")
            stats["none"] += 1
            log_event(event="no_image", product_id=product["id"], name=name)
        else:
            source = available_meta[downloaded_idx]["source"]
            source_url = available_meta[downloaded_idx]["url"]
            try:
                edited = edit_image(img_raw)
                img_bytes = compress_to_bytes(edited)
                kb = len(img_bytes) / 1024
            except Exception as e:
                print(f"  ✗ שגיאה בעריכה: {e}")
                stats["none"] += 1
                log_event(event="edit_error", product_id=product["id"], error=str(e))
                manifest[pid] = entry
                save_json(MANIFEST_FILE, manifest)
                continue

            fname = safe_filename(pid, name)
            (PENDING_DIR / fname).write_bytes(img_bytes)

            entry.update({
                "status": "pending",
                "filename": fname,
                "image_source": source,
                "source_url": source_url,
                "file_size_kb": round(kb, 1),
                "license_note": available_meta[downloaded_idx].get("note"),
            })
            stats[source] = stats.get(source, 0) + 1
            total_kb.append(kb)

            warn = f"  ⚠️  {entry['license_note']}" if entry["license_note"] else ""
            print(f"  ✓ {source} | {kb:.0f}KB{warn}")

        manifest[pid] = entry
        save_json(MANIFEST_FILE, manifest)

    progress["total_processed"] += len(collected)
    save_json(PROGRESS_FILE, progress)

    avg_kb = f"{sum(total_kb)/len(total_kb):.0f}KB" if total_kb else "—"
    print(f"""
════════════════════════════════
סיכום:
  Open Food Facts: {stats.get('open_food_facts', 0)}
  Brave עברית:     {stats.get('brave_he', 0)}
  Brave אנגלית:    {stats.get('brave_en', 0)}
  DALL-E 3:        {stats.get('dall-e-3', 0)}
  לא נמצא:         {stats.get('none', 0)}
  גודל ממוצע:      {avg_kb}
  הדף הבא:         {progress['next_page']}
════════════════════════════════
כעת הפעל: python review_and_upload.py
""")


if __name__ == "__main__":
    fetch_batch(reset="--reset" in sys.argv)
