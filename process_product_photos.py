"""
שלב ב של צילום מוצרים: מעבד תמונות שעובדים צילמו בטלפון (דף "📷 צילום מוצרים" בתוסף).

התוסף מעלה את התמונה הגולמית לוורדפרס ומסמן את המוצר ב-meta 'shpk_photo_pending'=1.
הסקריפט הזה מריצים ידנית מתי שנוח — מוצא את המוצרים הממתינים, מריץ עליהם cutout אמיתי
(rembg) ומדביק על אחד מרקעי ה-AI המצוירים הקבועים (backgrounds/*.png — נוצרו מראש,
לא נוצר רקע חדש לכל מוצר), מעלה את התוצאה כתמונה ראשית ומנקה את הדגל.

שימוש: python process_product_photos.py
"""

import os
import requests
from dotenv import load_dotenv
from woocommerce import API

from image_utils import compose_on_painted_background, edit_image, compress_to_bytes
from fetch_images import download_image

load_dotenv()

WC_URL    = os.getenv("WC_URL", "").rstrip("/")
WC_KEY    = os.getenv("WC_CONSUMER_KEY")
WC_SECRET = os.getenv("WC_CONSUMER_SECRET")
WP_USER   = os.getenv("WP_ADMIN_USERNAME")
WP_PASS   = os.getenv("WP_ADMIN_PASSWORD")

wcapi = API(url=WC_URL, consumer_key=WC_KEY, consumer_secret=WC_SECRET,
            version="wc/v3", timeout=30)


def _photo_scan():
    """
    קורא לנתיב shushka/v1/photo-scan (SQL גולמי בתוסף) — מחזיר את סטטוס הצילום
    של כל הקטלוג בבקשה אחת מהירה, במקום 27+ בקשות wc/v3/products שאיטיות מאוד
    באתר הזה (כל עמוד שם בונה אובייקטי WC_Product מלאים גם כש-_fields מבוקש).
    """
    r = requests.get(f"{WC_URL}/wp-json/shushka/v1/photo-scan",
                      auth=(WP_USER, WP_PASS), timeout=30)
    r.raise_for_status()
    return r.json()


def find_pending_products(log=print):
    """
    סריקה מהירה לאיתור המוצרים הממתינים, ואז הבאת פרטים מלאים (שם + תמונה)
    רק עבור אלה — לא לכל הקטלוג.
    """
    pending_ids = [row["id"] for row in _photo_scan() if row["status"] == "pending"]
    pending = []
    for pid in pending_ids:
        resp = wcapi.get(f"products/{pid}", params={"_fields": "id,name,images"})
        if resp.status_code == 200:
            pending.append(resp.json())
        else:
            log(f"  שגיאה בטעינת מוצר #{pid}: {resp.status_code}")
    return pending


def get_photo_stats():
    """סורק את כל המוצרים ומחזיר {total, pending, gallery_pending, photographed}."""
    total = pending = photographed = 0
    for row in _photo_scan():
        total += 1
        if row["status"] == "pending":
            pending += 1
        elif row["status"] == "done":
            photographed += 1
    gallery_pending = sum(len(e["attachment_ids"]) for e in find_pending_gallery())
    return {"total": total, "pending": pending, "gallery_pending": gallery_pending, "photographed": photographed}


def find_pending_gallery():
    """תמונות גלריה (רכיבים/תזונה) שהועלו ע"י עובדים וממתינות לעיבוד רקע קרם."""
    r = requests.get(f"{WC_URL}/wp-json/shushka/v1/gallery-pending",
                      auth=(WP_USER, WP_PASS), timeout=30)
    r.raise_for_status()
    return r.json()  # [{product_id, attachment_ids: [...]}]


def _get_media_url(media_id):
    r = requests.get(f"{WC_URL}/wp-json/wp/v2/media/{media_id}", auth=(WP_USER, WP_PASS), timeout=20)
    r.raise_for_status()
    return r.json()["source_url"]


def upload_media(img_bytes, product_id):
    ascii_fname = f"product_{product_id}_processed.jpg"
    r = requests.post(
        f"{WC_URL}/wp-json/wp/v2/media",
        headers={
            "Content-Disposition": f'attachment; filename="{ascii_fname}"',
            "Content-Type": "image/jpeg",
        },
        data=img_bytes,
        auth=(WP_USER, WP_PASS),
        timeout=30,
    )
    if not r.ok:
        raise RuntimeError(f"media {r.status_code}: {r.text[:120]}")
    return r.json()["id"]


def process_product(p, log=print):
    pid = p["id"]
    name = p["name"]
    images = p.get("images") or []
    if not images:
        log(f"  ⚠ {name} (#{pid}) — אין תמונה להוריד, מדלג")
        return False

    img = download_image(images[0]["src"])
    if img is None:
        log(f"  ⚠ {name} (#{pid}) — הורדת התמונה נכשלה, מדלג")
        return False

    edited = compose_on_painted_background(img)
    img_bytes = compress_to_bytes(edited)
    media_id = upload_media(img_bytes, pid)

    # PUT על 'images' מחליף את כל המערך (לא רק את הראשית!) — חייבים לכלול גם את
    # שאר תמונות הגלריה הקיימות (רכיבים/תזונה) כדי לא למחוק אותן בטעות.
    gallery_images = [{"id": img_obj["id"]} for img_obj in images[1:]]
    new_images = [{"id": media_id}] + gallery_images

    resp = wcapi.put(f"products/{pid}", {
        "images": new_images,
        "meta_data": [{"key": "shpk_photo_pending", "value": ""}],
    })
    if resp.status_code not in (200, 201):
        log(f"  ✗ {name} (#{pid}) — עדכון המוצר נכשל: {resp.status_code}")
        return False

    log(f"  ✓ {name} (#{pid})")
    return True


def process_gallery_pending(log=print):
    """
    מעבד תמונות גלריה (רכיבים/תזונה) שהועלו ע"י עובדים — רקע קרם ניטרלי בלבד
    (לא מצויר, לא AI), כדי לשמור על קריאות מלאה של הטקסט. מחליף כל תמונה ישנה
    באחת חדשה באותו מיקום בדיוק במערך התמונות של המוצר, ומנקה את ה-pending.
    """
    items = find_pending_gallery()
    log(f"נמצאו {len(items)} מוצרים עם תמונות גלריה ממתינות.")

    ok = fail = 0
    for entry in items:
        pid = entry["product_id"]
        old_ids = entry["attachment_ids"]
        resp = wcapi.get(f"products/{pid}", params={"_fields": "id,name,images"})
        if resp.status_code != 200:
            log(f"  שגיאה בטעינת מוצר #{pid}: {resp.status_code}")
            fail += len(old_ids)
            continue
        p = resp.json()
        name = p.get("name", "")
        images = p.get("images") or []
        id_to_pos = {im["id"]: i for i, im in enumerate(images)}

        replaced_any = False
        for old_id in old_ids:
            if old_id not in id_to_pos:
                log(f"  ⚠ {name} (#{pid}) תמונה #{old_id} כבר לא בגלריה, מדלג")
                continue
            try:
                img = download_image(_get_media_url(old_id))
                if img is None:
                    log(f"  ⚠ {name} (#{pid}) — הורדת תמונת גלריה #{old_id} נכשלה")
                    continue
                edited = edit_image(img, force_rembg=True)
                img_bytes = compress_to_bytes(edited)
                new_id = upload_media(img_bytes, pid)
                images[id_to_pos[old_id]] = {"id": new_id}
                replaced_any = True
            except Exception as e:
                log(f"  ✗ {name} (#{pid}) תמונה #{old_id} — שגיאה: {e}")

        if not replaced_any:
            fail += len(old_ids)
            continue

        resp2 = wcapi.put(f"products/{pid}", {
            "images": [{"id": im["id"]} for im in images],
            "meta_data": [{"key": "shpk_gallery_pending", "value": []}],
        })
        if resp2.status_code not in (200, 201):
            log(f"  ✗ {name} (#{pid}) — עדכון המוצר נכשל: {resp2.status_code}")
            fail += 1
        else:
            log(f"  ✓ {name} (#{pid}) — תמונת גלריה עודכנה לרקע קרם")
            ok += 1

    return ok, fail


def reprocess_products(ids, log=print):
    """
    מעבד מחדש מוצרים ספציפיים לפי ID, גם אם כבר עובדו בעבר (למשל אחרי שינוי
    בפייפליין עצמו — כמו המעבר לרקעים המצוירים). לא סורק pending — מקבל רשימת
    ID-ים מפורשת, כדי לא לדרוס בטעות תמונות אמיתיות של מוצרים שלא עברו בכלל
    דרך דף הצילום של העובדים.
    """
    ok = fail = 0
    for pid in ids:
        resp = wcapi.get(f"products/{pid}", params={"_fields": "id,name,images"})
        if resp.status_code != 200:
            log(f"  שגיאה בטעינת מוצר #{pid}: {resp.status_code}")
            fail += 1
            continue
        p = resp.json()
        try:
            if process_product(p, log=log):
                ok += 1
            else:
                fail += 1
        except Exception as e:
            log(f"  ✗ {p.get('name')} (#{p.get('id')}) — שגיאה: {e}")
            fail += 1
    log(f"סיום עיבוד מחדש: {ok} הצליחו, {fail} נכשלו.")
    return ok, fail


def run(log=print):
    """
    מריץ את כל התהליך ומחזיר (ok, fail). `log` מאפשר לקרוא מ-CLI (print) או מ-review_app.py
    (אוסף שורות להצגה בדף, למשל log=some_list.append).
    """
    log("🌿 עיבוד תמונות מוצרים שצולמו ע\"י עובדים")
    pending = find_pending_products(log=log)
    log(f"נמצאו {len(pending)} מוצרים ממתינים לעיבוד.")

    ok = fail = 0
    for p in pending:
        try:
            if process_product(p, log=log):
                ok += 1
            else:
                fail += 1
        except Exception as e:
            log(f"  ✗ {p.get('name')} (#{p.get('id')}) — שגיאה: {e}")
            fail += 1

    gok, gfail = process_gallery_pending(log=log)
    ok += gok
    fail += gfail

    log(f"סיום: {ok} עובדו בהצלחה, {fail} נכשלו/דולגו.")
    return ok, fail


def main():
    import sys
    print()
    print("=" * 45)
    if len(sys.argv) > 1 and sys.argv[1] == "--reprocess":
        ids = [int(x) for x in sys.argv[2:]]
        if not ids:
            print("שימוש: python process_product_photos.py --reprocess <id1> <id2> ...")
            return
        reprocess_products(ids, log=print)
    else:
        run(log=print)


if __name__ == "__main__":
    main()
