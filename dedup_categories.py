"""
dedup_categories.py — מאחד קטגוריות כפולות ב-WooCommerce

לכל שם כפול:
  1. מזהה איזו קטגוריה היא "הנכונה" (לפי הורה נכון ב-CATEGORY_TREE)
  2. מעביר מוצרים מהכפולה הלא-נכונה לנכונה
  3. מוחק את הכפולה הריקה

שימוש:
    python dedup_categories.py           # מציג תוכנית בלבד
    python dedup_categories.py --live    # מבצע בפועל
"""

import os, sys, time, argparse
from collections import defaultdict
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

# הורים נכונים לפי CATEGORY_TREE (שם תת-קטגוריה → שם קטגוריית אב)
CORRECT_PARENT = {
    "פירות יבשים":                    "פירות יבשים ואגוזים",
    "אגוזים וזרעים":                   "פירות יבשים ואגוזים",
    "דגנים":                           "דגנים קטניות ופסטות",
    "קטניות":                          "דגנים קטניות ופסטות",
    "פסטות, אטריות ופתיתים":          "דגנים קטניות ופסטות",
    "תבלינים":                         "בישול ואפייה",
    "ממתיקים ותחליפי סוכר":           "בישול ואפייה",
    "קמחים ועזרי אפייה":              "בישול ואפייה",
    "שמנים":                           "בישול ואפייה",
    "מחלקה אסייתית":                   "בישול ואפייה",
    "ממרחים מלוחים ורטבים":           "ממרחים, רטבים ושימורים",
    "ממרחי אגוזים":                    "ממרחים, רטבים ושימורים",
    "שימורים":                         "ממרחים, רטבים ושימורים",
    "ממרחים מתוקים":                   "ממרחים, רטבים ושימורים",
    "סירופים ורטבים":                  "ממרחים, רטבים ושימורים",
    "גרנולה ודגני בוקר":              "דגני בוקר, גרנולה שוקולד וחטיפים",
    "שוקולד ומוצריו":                  "דגני בוקר, גרנולה שוקולד וחטיפים",
    "סוכריות":                         "דגני בוקר, גרנולה שוקולד וחטיפים",
    "חלווה":                           "דגני בוקר, גרנולה שוקולד וחטיפים",
    "חטיפים מלוחים ומתוקים":          "דגני בוקר, גרנולה שוקולד וחטיפים",
    "לחמים ופיתות":                   "עוגיות מאפים וקרקרים",
    "עוגות":                           "עוגיות מאפים וקרקרים",
    "עוגיות":                          "עוגיות מאפים וקרקרים",
    "קרקרים, מצות ופריכיות":          "עוגיות מאפים וקרקרים",
    "תרכיזים ורכזים":                  "משקאות",
    "תחליפי חלב צמחיים":              "משקאות",
    "חליטות ומוצרי תה":               "משקאות",
    "קפה ותחליפים":                   "משקאות",
    "מיצים טבעיים לשתיה":             "משקאות",
    "טופו וביצים":                     "מקרר וקפוא",
    "תחליפי גבינה ובשר":              "מקרר וקפוא",
    "תחליפי גבינה":                   "מקרר וקפוא",
    "תחליפי בשר":                     "מקרר וקפוא",
    "פירות קפואים":                   "מקרר וקפוא",
    "גלידות":                          "מקרר וקפוא",
    "אוכל קפוא להכנה מהירה":          "מקרר וקפוא",
    "ויטמינים ותוספים":               "תוספי תזונה ובריאות",
    "מוצרים טיפוליים":                "תוספי תזונה ובריאות",
    "שמנים אתריים ובשמים":            "תוספי תזונה ובריאות",
    "הגנה מהשמש":                     "קוסמטיקה ורחצה טבעיים",
    "דאודורנט":                        "קוסמטיקה ורחצה טבעיים",
    "טיפוח הפנים והגוף":              "קוסמטיקה ורחצה טבעיים",
    "היגיינת הפה והשיניים":           "קוסמטיקה ורחצה טבעיים",
    "שמפו, סבון, מרכך ומסכות":        "קוסמטיקה ורחצה טבעיים",
    "הגיינה נשית":                    "קוסמטיקה ורחצה טבעיים",
    "לאם ולתינוק":                    "קוסמטיקה ורחצה טבעיים",
    "חד פעמי, ניירות אפייה וכסף":     "אקולוגי ומוצרים לבית",
    "ניקוי אקולוגי":                   "אקולוגי ומוצרים לבית",
    "כלי מטבח, תבניות ושקיות":        "אקולוגי ומוצרים לבית",
}

TOP_LEVEL_NAMES = {
    "פירות יבשים ואגוזים", "דגנים קטניות ופסטות", "בישול ואפייה",
    "ממרחים, רטבים ושימורים", "דגני בוקר, גרנולה שוקולד וחטיפים",
    "עוגיות מאפים וקרקרים", "משקאות", "מקרר וקפוא",
    "תוספי תזונה ובריאות", "קוסמטיקה ורחצה טבעיים", "אקולוגי ומוצרים לבית",
    "תוספי תזונה ובריאות",  # before rename
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


def get_products_in_category(cat_id):
    products, page = [], 1
    while True:
        resp = wcapi.get("products", params={"category": cat_id, "per_page": 100, "page": page, "status": "any"})
        batch = resp.json()
        if resp.status_code != 200:
            break
        if not isinstance(batch, list) or not batch:
            break
        products.extend(batch)
        page += 1
        time.sleep(0.3)
    return products


def move_products(from_id, to_id, live):
    products = get_products_in_category(from_id)
    print(f"    מעביר {len(products)} מוצרים...")
    for p in products:
        new_cats = [{"id": to_id if c["id"] == from_id else c["id"]} for c in p["categories"]]
        if live:
            wcapi.put(f"products/{p['id']}", {"categories": new_cats})
            time.sleep(0.2)
    return len(products)


def delete_category(cat_id, live):
    if live:
        wcapi.delete(f"products/categories/{cat_id}", params={"force": True})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--live", action="store_true", help="בצע בפועל (ברירת מחדל: תצוגה בלבד)")
    args = parser.parse_args()
    live = args.live

    print("טוען קטגוריות...")
    cats = fetch_all_categories()
    id_to_cat = {c["id"]: c for c in cats}
    id_to_parent_name = {c["id"]: id_to_cat[c["parent"]]["name"] if c["parent"] else "" for c in cats}

    # קבץ לפי שם
    by_name = defaultdict(list)
    for c in cats:
        by_name[c["name"]].append(c)

    dupes = {name: group for name, group in by_name.items() if len(group) > 1}

    if not dupes:
        print("אין כפילויות!")
        return

    print(f"\nנמצאו {len(dupes)} שמות כפולים:\n{'='*60}")
    actions = []  # (keep_id, delete_id, name, n_products)

    for name, group in sorted(dupes.items()):
        correct_parent = CORRECT_PARENT.get(name, "")
        is_top = name in TOP_LEVEL_NAMES

        print(f"\n  '{name}'  ({len(group)} עותקים):")
        candidates = []
        for c in group:
            parent_name = id_to_parent_name[c["id"]]
            is_match = (is_top and c["parent"] == 0) or (correct_parent and parent_name == correct_parent)
            if is_match:
                candidates.append(c)

        # מכל המועמדים הנכונים — שמור זה עם הכי הרבה מוצרים
        keep = max(candidates, key=lambda c: c["count"]) if candidates else max(group, key=lambda c: c["count"])

        for c in group:
            parent_name = id_to_parent_name[c["id"]]
            marker = " ← נשמור" if c["id"] == keep["id"] else ""
            print(f"    id={c['id']}  הורה='{parent_name}'  מוצרים={c['count']}{marker}")
            print(f"    [אין הורה ברור — שומרים id={keep['id']} עם {keep['count']} מוצרים]")

        for c in group:
            if c["id"] != keep["id"]:
                actions.append((keep["id"], c["id"], name, c["count"]))
                print(f"    → ימחק: id={c['id']} (יעביר {c['count']} מוצרים לid={keep['id']})")

    print(f"\n{'='*60}")
    print(f"סה\"כ: {len(actions)} קטגוריות למחיקה")

    if not live:
        print("\n[תצוגה בלבד — הרץ עם --live לביצוע בפועל]")
        return

    print("\nמתחיל מיזוג...")
    for keep_id, del_id, name, count in actions:
        print(f"\n  מאחד '{name}': id={del_id} → id={keep_id}")
        moved = move_products(del_id, keep_id, live=True)
        delete_category(del_id, live=True)
        print(f"    ✓ הועברו {moved} מוצרים, קטגוריה נמחקה")

    print("\n✓ סיום — כל הכפילויות טופלו.")


if __name__ == "__main__":
    main()
