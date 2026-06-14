"""
cleanup_categories.py — מוחק קטגוריות שאינן חלק מ-CATEGORY_TREE

שימוש:
    python cleanup_categories.py          # תצוגה בלבד
    python cleanup_categories.py --live   # מחיקה בפועל (רק ריקות)
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

# כל השמות המותרים — קטגוריות אב + תתי-קטגוריות
ALLOWED = {
    # קטגוריות אב
    "פירות יבשים ואגוזים", "דגנים קטניות ופסטות", "בישול ואפייה",
    "ממרחים, רטבים ושימורים", "דגני בוקר, גרנולה שוקולד וחטיפים",
    "עוגיות מאפים וקרקרים", "משקאות", "מקרר וקפוא",
    "תוספי תזונה ובריאות", "קוסמטיקה ורחצה טבעיים", "אקולוגי ומוצרים לבית",
    # תתי-קטגוריות
    "פירות יבשים", "אגוזים וזרעים",
    "דגנים", "קטניות", "פסטות, אטריות ופתיתים",
    "תבלינים", "ממתיקים ותחליפי סוכר", "קמחים ועזרי אפייה", "שמנים", "מחלקה אסייתית",
    "ממרחים מלוחים ורטבים", "ממרחי אגוזים", "שימורים", "ממרחים מתוקים", "סירופים ורטבים",
    "גרנולה ודגני בוקר", "שוקולד ומוצריו", "סוכריות", "חלווה", "חטיפים מלוחים ומתוקים",
    "לחמים ופיתות", "עוגות", "עוגיות", "קרקרים, מצות ופריכיות",
    "תרכיזים ורכזים", "תחליפי חלב צמחיים", "חליטות ומוצרי תה", "קפה ותחליפים", "מיצים טבעיים לשתיה",
    "טופו וביצים", "תחליפי גבינה", "תחליפי בשר", "פירות קפואים", "גלידות", "אוכל קפוא להכנה מהירה",
    "ויטמינים ותוספים", "מוצרים טיפוליים", "שמנים אתריים ובשמים",
    "הגנה מהשמש", "דאודורנט", "טיפוח הפנים והגוף", "היגיינת הפה והשיניים",
    "שמפו, סבון, מרכך ומסכות", "הגיינה נשית", "לאם ולתינוק",
    "חד פעמי, ניירות אפייה וכסף", "ניקוי אקולוגי", "כלי מטבח, תבניות ושקיות",
    # קטגוריה ישנה שעדיין תחת שמה הישן (לפני rename)
    "תחליפי גבינה ובשר",
}


def fetch_all_categories():
    cats, page = [], 1
    while True:
        resp = wcapi.get("products/categories", params={"per_page": 100, "page": page})
        batch = resp.json()
        if resp.status_code != 200:
            break
        if not isinstance(batch, list) or not batch:
            break
        cats.extend(batch)
        page += 1
        time.sleep(0.2)
    return cats


def delete_category(cat_id, live):
    if live:
        wcapi.delete(f"products/categories/{cat_id}", params={"force": True})
        time.sleep(0.3)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", action="store_true")
    args = parser.parse_args()

    print("טוען קטגוריות...")
    cats = fetch_all_categories()
    id_to_name = {c["id"]: c["name"] for c in cats}

    foreign = [c for c in cats if c["name"] not in ALLOWED]

    empty   = [c for c in foreign if c["count"] == 0]
    nonempty = [c for c in foreign if c["count"] > 0]

    print(f"\nסה\"כ קטגוריות: {len(cats)}")
    print(f"מותרות (ב-CATEGORY_TREE): {len(cats) - len(foreign)}")
    print(f"זרות — ריקות (ימחקו): {len(empty)}")
    print(f"זרות — עם מוצרים (לא ימחקו עכשיו): {len(nonempty)}")

    if empty:
        print(f"\n── ריקות — {'ימחקו' if args.live else 'יימחקו ב--live'} ──")
        for c in sorted(empty, key=lambda c: c["name"]):
            parent_name = id_to_name.get(c["parent"], "") if c["parent"] else ""
            parent_str = f"  (תחת: {parent_name})" if parent_name else ""
            print(f"  id={c['id']}  '{c['name']}'{parent_str}")
            if args.live:
                delete_category(c["id"], live=True)
                print(f"         ✓ נמחקה")

    if nonempty:
        print(f"\n── עם מוצרים — לא ימחקו (categorize.py יטפל בהן) ──")
        for c in sorted(nonempty, key=lambda c: -c["count"]):
            parent_name = id_to_name.get(c["parent"], "") if c["parent"] else ""
            parent_str = f"  (תחת: {parent_name})" if parent_name else ""
            print(f"  [{c['count']:>4} מוצרים]  '{c['name']}'{parent_str}")

    if not args.live:
        print(f"\n[תצוגה בלבד — הרץ עם --live למחיקת הריקות]")
    else:
        print(f"\n✓ סיום.")


if __name__ == "__main__":
    main()
