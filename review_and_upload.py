"""
שלב ב: סקירה ואישור תמונות + העלאה ל-WooCommerce ברקע.

שימוש: python review_and_upload.py
מקשים: [y] אשר  [n] דלג  [r] החלף תמונה
"""

import os, json, io, time, threading, subprocess
from pathlib import Path
from datetime import datetime

import requests
from PIL import Image
from dotenv import load_dotenv
from woocommerce import API

from image_utils import edit_image, compress_save

load_dotenv()

WC_URL    = os.getenv("WC_URL", "").rstrip("/")
WC_KEY    = os.getenv("WC_CONSUMER_KEY")
WC_SECRET = os.getenv("WC_CONSUMER_SECRET")
WP_USER   = os.getenv("WP_ADMIN_USERNAME")
WP_PASS   = os.getenv("WP_ADMIN_PASSWORD")

BASE_DIR         = Path(__file__).parent / "images"
PENDING_DIR      = BASE_DIR / "pending"
APPROVED_DIR     = BASE_DIR / "approved"
NEEDS_MANUAL_DIR = BASE_DIR / "needs_manual"
OUTPUT_DIR       = BASE_DIR / "output"
MANIFEST_FILE    = PENDING_DIR / "manifest.json"
LOG_FILE         = OUTPUT_DIR / "log.json"

wcapi = API(url=WC_URL, consumer_key=WC_KEY, consumer_secret=WC_SECRET,
            version="wc/v3", timeout=30)


# ── קבצי מצב ──────────────────────────────────────────────────────────────────

def load_manifest():
    if MANIFEST_FILE.exists():
        return json.loads(MANIFEST_FILE.read_text(encoding="utf-8"))
    return {}

def save_manifest(m):
    MANIFEST_FILE.write_text(json.dumps(m, ensure_ascii=False, indent=2), encoding="utf-8")

def log_event(**kw):
    log = []
    if LOG_FILE.exists():
        log = json.loads(LOG_FILE.read_text(encoding="utf-8"))
    log.append({"time": datetime.now().isoformat(), **kw})
    LOG_FILE.write_text(json.dumps(log, ensure_ascii=False, indent=2), encoding="utf-8")


# ── העלאה ל-WordPress + WooCommerce ───────────────────────────────────────────

def upload_product_image(filepath, product_id):
    img_data = Path(filepath).read_bytes()
    # שם קובץ ב-header חייב להיות ASCII בלבד
    ascii_fname = f"product_{product_id}.jpg"

    r = requests.post(
        f"{WC_URL}/wp-json/wp/v2/media",
        headers={
            "Content-Disposition": f'attachment; filename="{ascii_fname}"',
            "Content-Type": "image/jpeg",
        },
        data=img_data,
        auth=(WP_USER, WP_PASS),
        timeout=30
    )
    if not r.ok:
        return False, f"media {r.status_code}: {r.text[:120]}"

    media_id = r.json()["id"]
    resp = wcapi.put(f"products/{product_id}", {"images": [{"id": media_id}]})
    if resp.status_code not in (200, 201):
        return False, f"product {resp.status_code}"

    return True, media_id


# ── Thread העלאה ──────────────────────────────────────────────────────────────

def upload_worker(stop_event):
    while not stop_event.is_set():
        manifest = load_manifest()
        changed = False

        for pid, entry in manifest.items():
            if entry["status"] != "approved":
                continue
            approved_path = APPROVED_DIR / entry["filename"]
            if not approved_path.exists():
                continue

            success = False
            for attempt in range(3):
                ok, detail = upload_product_image(approved_path, entry["product_id"])
                if ok:
                    success = True
                    break
                if attempt < 2:
                    stop_event.wait(30)

            if success:
                approved_path.unlink(missing_ok=True)
                entry["status"] = "uploaded"
                entry["uploaded_at"] = datetime.now().isoformat()
                print(f"\n  ✓ הועלה: {entry['product_name'][:50]}", flush=True)
                log_event(event="uploaded", product_id=entry["product_id"],
                          name=entry["product_name"])
            else:
                entry["status"] = "upload_failed"
                print(f"\n  ✗ כשלון העלאה: {entry['product_name'][:50]}", flush=True)
                log_event(event="upload_failed", product_id=entry["product_id"],
                          error=str(detail))

            changed = True
            manifest[pid] = entry

        if changed:
            save_manifest(manifest)

        stop_event.wait(5)


# ── לולאת סקירה ───────────────────────────────────────────────────────────────

_viewer_proc = None

def open_image(path):
    global _viewer_proc
    if _viewer_proc is not None:
        try:
            _viewer_proc.terminate()
        except Exception:
            pass
    try:
        _viewer_proc = subprocess.Popen(["mspaint", str(path)])
    except Exception:
        try:
            os.startfile(str(path))
        except Exception:
            pass

def download_and_edit(url, min_px=400):
    try:
        r = requests.get(url, timeout=15)
        if r.ok:
            img = Image.open(io.BytesIO(r.content)).convert("RGB")
            if min(img.size) < min_px:
                return None
            return edit_image(img)
    except Exception:
        pass
    return None

def review_loop():
    manifest = load_manifest()
    pending = [(pid, e) for pid, e in manifest.items() if e["status"] == "pending"]
    total = len(pending)

    if total == 0:
        print("אין תמונות pending לסקירה. הפעל קודם: python fetch_images.py")
        return

    uploaded_so_far = sum(1 for e in manifest.values() if e["status"] == "uploaded")
    print(f"\nסטטוס: {uploaded_so_far} הועלו עד כה | {total} ממתינות לסקירה\n")

    stop_event = threading.Event()
    uploader = threading.Thread(target=upload_worker, args=(stop_event,), daemon=True)
    uploader.start()
    print("Thread העלאה ברקע הופעל.\n")

    approved_count = skipped = needs_manual_count = 0

    for i, (pid, entry) in enumerate(pending):
        name = entry["product_name"]
        brand = entry["brand"]
        source = entry.get("image_source", "—")
        kb = entry.get("file_size_kb", "?")
        fpath = PENDING_DIR / (entry["filename"] or "")

        if fpath.exists():
            open_image(fpath)

        while True:
            print(f"\n{'─' * 54}")
            print(f"[{i+1}/{total}] {name}")
            print(f"מותג: {brand} | קטגוריה: {entry.get('category','—')}")
            print(f"מקור: {source} | גודל: {kb}KB")
            if entry.get("license_note"):
                print(f"⚠️  {entry['license_note']}")
            print("[y] אשר   [n] דלג   [r] החלף תמונה")
            print("─" * 54)

            choice = input("> ").strip().lower()

            if choice == "y":
                approved_path = APPROVED_DIR / entry["filename"]
                if approved_path.exists():
                    approved_path.unlink()
                if fpath.exists():
                    fpath.rename(approved_path)
                manifest[pid]["status"] = "approved"
                save_manifest(manifest)
                approved_count += 1
                break

            elif choice == "n":
                manifest[pid]["status"] = "skipped"
                save_manifest(manifest)
                skipped += 1
                break

            elif choice == "r":
                available = entry.get("available_meta", [])
                tried = set(entry.get("tried_urls", []))
                remaining = [c for c in available if c["url"] not in tried]
                next_cand = remaining[0] if remaining else None

                if next_cand is None:
                    print("נגמרו כל האפשרויות → needs_manual")
                    if fpath.exists():
                        fpath.rename(NEEDS_MANUAL_DIR / entry["filename"])
                    manifest[pid]["status"] = "needs_manual"
                    save_manifest(manifest)
                    needs_manual_count += 1
                    break

                left_after = len(remaining) - 1
                print(f"  מוריד מ-{next_cand['source']} ({left_after} חלופות נוספות)...")
                new_img = download_and_edit(next_cand["url"])

                tried.add(next_cand["url"])
                manifest[pid]["tried_urls"] = list(tried)
                manifest[pid]["current_image_index"] += 1

                if new_img is None:
                    print(f"  ✗ ירידה כשלה ({left_after} חלופות נוספות). נסה [r] שוב או [n] לדלג.")
                    save_manifest(manifest)
                    entry = manifest[pid]
                    continue

                new_kb = compress_save(new_img, fpath)
                manifest[pid].update({
                    "image_source": next_cand["source"],
                    "source_url": next_cand["url"],
                    "file_size_kb": round(new_kb, 1),
                    "license_note": next_cand.get("note"),
                })
                save_manifest(manifest)
                entry = manifest[pid]
                source = next_cand["source"]
                kb = round(new_kb, 1)

                open_image(fpath)

    # המתן לסיום ה-thread
    print("\nממתין לסיום ההעלאות ברקע", end="", flush=True)
    for _ in range(60):
        manifest = load_manifest()
        if not any(e["status"] == "approved" for e in manifest.values()):
            break
        print(".", end="", flush=True)
        time.sleep(5)

    stop_event.set()
    uploader.join(timeout=10)
    print()

    manifest = load_manifest()
    uploaded_now = sum(1 for e in manifest.values() if e["status"] == "uploaded")
    still_pending = sum(1 for e in manifest.values() if e["status"] == "approved")
    failed = sum(1 for e in manifest.values() if e["status"] == "upload_failed")

    progress_file = BASE_DIR / "progress.json"
    next_page = "?"
    if progress_file.exists():
        prog = json.loads(progress_file.read_text(encoding="utf-8"))
        next_page = prog.get("next_page", "?")

    print(f"""
════════════════════════════════
סיכום הסשן:
  ✓ אושרו:         {approved_count}
  ✓ הועלו:         {uploaded_now}
  ⟳ בתהליך:        {still_pending}
  ↷ דולגו:         {skipped}
  ✋ needs_manual:  {needs_manual_count}
  ✗ כשל העלאה:     {failed}
  הדף הבא:         {next_page}
════════════════════════════════
להמשך: python fetch_images.py
""")


if __name__ == "__main__":
    review_loop()
