"""
fix_categories.py — מתקן את מבנה הקטגוריות לפי verify_categories.py

שימוש:
    python fix_categories.py          # תצוגה בלבד (dry-run)
    python fix_categories.py --live   # ביצוע בפועל
"""

import os, sys, time, argparse
from dotenv import load_dotenv
from woocommerce import API

load_dotenv()

wcapi = API(
    url=os.getenv("WC_URL", "").rstrip("/"),
    consumer_key=os.getenv("WC_CONSUMER_KEY", ""),
    consumer_secret=os.getenv("WC_CONSUMER_SECRET", ""),
    version="wc/v3",
    timeout=60,
)

parser = argparse.ArgumentParser()
parser.add_argument("--live", action="store_true")
args = parser.parse_args()
LIVE = args.live


def fetch_categories():
    cats, page = [], 1
    while True:
        r = wcapi.get("products/categories", params={"per_page": 100, "page": page})
        if r.status_code != 200:
            break
        b = r.json()
        if not isinstance(b, list) or not b:
            break
        cats.extend(b)
        page += 1
        time.sleep(0.2)
    return cats


def create_category(name, parent_id=0):
    data = {"name": name, "parent": parent_id}
    if LIVE:
        r = wcapi.post("products/categories", data)
        time.sleep(0.3)
        if r.status_code in (200, 201):
            new_id = r.json().get("id")
            print(f"  ✓ נוצרה '{name}'  (id={new_id}, parent={parent_id})")
            return new_id
        else:
            print(f"  ✗ שגיאה ביצירת '{name}': {r.status_code} {r.text[:200]}")
            return None
    else:
        print(f"  [dry] תיווצר '{name}'  (parent={parent_id})")
        return None


def move_category(cat_id, new_parent_id, name):
    if LIVE:
        r = wcapi.put(f"products/categories/{cat_id}", {"parent": new_parent_id})
        time.sleep(0.3)
        if r.status_code == 200:
            print(f"  ✓ הוזז '{name}' (id={cat_id}) → parent={new_parent_id}")
        else:
            print(f"  ✗ שגיאה בהזזת '{name}': {r.status_code} {r.text[:200]}")
    else:
        print(f"  [dry] '{name}' (id={cat_id}) יוזז → parent={new_parent_id}")


def rename_category(cat_id, old_name, new_name):
    if LIVE:
        r = wcapi.put(f"products/categories/{cat_id}", {"name": new_name})
        time.sleep(0.3)
        if r.status_code == 200:
            print(f"  ✓ שונה שם: '{old_name}' → '{new_name}'  (id={cat_id})")
        else:
            print(f"  ✗ שגיאה בשינוי שם '{old_name}': {r.status_code} {r.text[:200]}")
    else:
        print(f"  [dry] שינוי שם: '{old_name}' → '{new_name}'  (id={cat_id})")


def main():
    print("טוען קטגוריות...")
    cats = fetch_categories()
    name_to_id = {}
    for c in cats:
        if c["parent"] == 0:
            name_to_id[("root", c["name"])] = c["id"]
        name_to_id[(c["parent"], c["name"])] = c["id"]

    # עוזר: מוצא ID של קטגוריית-אב לפי שם
    def parent_id(name):
        return name_to_id.get(("root", name))

    mode = "live" if LIVE else "dry-run"
    print(f"\n{'='*60}")
    print(f"  מצב: {mode}")
    print(f"{'='*60}\n")

    # ── 1. שנה שם 'בישול ואפיה' → 'בישול ואפייה' ───────────────
    if parent_id("בישול ואפייה"):
        print(f"✓ 'בישול ואפייה' כבר קיימת (id={parent_id('בישול ואפייה')})")
    else:
        old_id = parent_id("בישול ואפיה")
        if old_id:
            print(f"→ שינוי שם: 'בישול ואפיה' → 'בישול ואפייה'  (id={old_id})")
            rename_category(old_id, "בישול ואפיה", "בישול ואפייה")
            if LIVE:
                name_to_id[("root", "בישול ואפייה")] = old_id
        else:
            print("→ לא נמצאה 'בישול ואפיה' — יוצרת חדשה")
            new_id = create_category("בישול ואפייה", parent_id=0)
            if new_id:
                name_to_id[("root", "בישול ואפייה")] = new_id

    # ── 2. שנה שם 'ממרחים , רטבים ושימורים' → 'ממרחים, רטבים ושימורים' ──
    if parent_id("ממרחים, רטבים ושימורים"):
        print(f"✓ 'ממרחים, רטבים ושימורים' כבר קיימת (id={parent_id('ממרחים, רטבים ושימורים')})")
    else:
        old_id = parent_id("ממרחים , רטבים ושימורים")
        if old_id:
            print(f"→ שינוי שם: 'ממרחים , רטבים ושימורים' → 'ממרחים, רטבים ושימורים'  (id={old_id})")
            rename_category(old_id, "ממרחים , רטבים ושימורים", "ממרחים, רטבים ושימורים")
            if LIVE:
                name_to_id[("root", "ממרחים, רטבים ושימורים")] = old_id
        else:
            print("→ לא נמצאה 'ממרחים , רטבים ושימורים' — יוצרת חדשה")
            new_id = create_category("ממרחים, רטבים ושימורים", parent_id=0)
            if new_id:
                name_to_id[("root", "ממרחים, רטבים ושימורים")] = new_id

    # ── 3. צור 'קרקרים, מצות ופריכיות' תחת 'עוגיות מאפים וקרקרים' ──
    parent = parent_id("עוגיות מאפים וקרקרים")
    if not parent:
        print("✗ לא נמצאה קטגוריית האב 'עוגיות מאפים וקרקרים' — דלג")
    elif name_to_id.get((parent, "קרקרים, מצות ופריכיות")):
        print(f"✓ 'קרקרים, מצות ופריכיות' כבר קיימת")
    else:
        print("→ חסרה: 'קרקרים, מצות ופריכיות'")
        create_category("קרקרים, מצות ופריכיות", parent_id=parent)

    # ── 4. צור 'תחליפי בשר' תחת 'מקרר וקפוא' ───────────────────
    parent = parent_id("מקרר וקפוא")
    if not parent:
        print("✗ לא נמצאה קטגוריית האב 'מקרר וקפוא' — דלג")
    elif name_to_id.get((parent, "תחליפי בשר")):
        print(f"✓ 'תחליפי בשר' כבר קיימת")
    else:
        print("→ חסרה: 'תחליפי בשר'")
        create_category("תחליפי בשר", parent_id=parent)

    # ── 5. הזז 'מוצרים טיפוליים' (id=4927) לתוך 'תוספי תזונה ובריאות' ──
    parent = parent_id("תוספי תזונה ובריאות")
    if not parent:
        print("✗ לא נמצאה קטגוריית האב 'תוספי תזונה ובריאות' — דלג")
    else:
        print("→ מיקום שגוי: 'מוצרים טיפוליים' (id=4927) — צריך להיות תחת 'תוספי תזונה ובריאות'")
        move_category(4927, parent, "מוצרים טיפוליים")

    # ── 6. צור 'דאודורנט' תחת 'קוסמטיקה ורחצה טבעיים' ─────────
    parent = parent_id("קוסמטיקה ורחצה טבעיים")
    if not parent:
        print("✗ לא נמצאה קטגוריית האב 'קוסמטיקה ורחצה טבעיים' — דלג")
    elif name_to_id.get((parent, "דאודורנט")):
        print(f"✓ 'דאודורנט' כבר קיימת")
    else:
        print("→ חסרה: 'דאודורנט'")
        create_category("דאודורנט", parent_id=parent)

    # ── 7. צור 'קמחים ועזרי אפייה' תחת 'בישול ואפייה' ──────────
    parent = parent_id("בישול ואפייה") or name_to_id.get(("root", "בישול ואפייה"))
    if not parent:
        print("✗ לא נמצאה 'בישול ואפייה' — דלג")
    elif name_to_id.get((parent, "קמחים ועזרי אפייה")):
        print(f"✓ 'קמחים ועזרי אפייה' כבר קיימת")
    else:
        print("→ חסרה: 'קמחים ועזרי אפייה'")
        create_category("קמחים ועזרי אפייה", parent_id=parent)

    # ── 8. הזז 'שמנים' (id=1895) לתוך 'בישול ואפייה' ───────────
    parent = parent_id("בישול ואפייה") or name_to_id.get(("root", "בישול ואפייה"))
    if not parent:
        print("✗ לא נמצאה 'בישול ואפייה' — דלג")
    else:
        print("→ מיקום שגוי: 'שמנים' (id=1895) — צריך להיות תחת 'בישול ואפייה'")
        move_category(1895, parent, "שמנים")

    # ── 9. הזז 'ממרחים מתוקים' (id=1887) לתוך 'ממרחים, רטבים ושימורים' ──
    parent = parent_id("ממרחים, רטבים ושימורים") or name_to_id.get(("root", "ממרחים, רטבים ושימורים"))
    if not parent:
        print("✗ לא נמצאה 'ממרחים, רטבים ושימורים' — דלג")
    else:
        print("→ מיקום שגוי: 'ממרחים מתוקים' (id=1887) — צריך להיות תחת 'ממרחים, רטבים ושימורים'")
        move_category(1887, parent, "ממרחים מתוקים")

    # ── 10. הזז 'סירופים ורטבים' (id=86) לתוך 'ממרחים, רטבים ושימורים' ──
    parent = parent_id("ממרחים, רטבים ושימורים") or name_to_id.get(("root", "ממרחים, רטבים ושימורים"))
    if not parent:
        print("✗ לא נמצאה 'ממרחים, רטבים ושימורים' — דלג")
    else:
        print("→ מיקום שגוי: 'סירופים ורטבים' (id=86) — צריך להיות תחת 'ממרחים, רטבים ושימורים'")
        move_category(86, parent, "סירופים ורטבים")

    print(f"\n{'='*60}")
    if not LIVE:
        print("  [dry-run] — הרץ עם --live לביצוע בפועל")
    else:
        print("  ✓ סיום.")


if __name__ == "__main__":
    main()
