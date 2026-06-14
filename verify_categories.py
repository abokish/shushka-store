"""בודק שמבנה הקטגוריות ב-WooCommerce תואם ל-CATEGORY_TREE."""
import os, time
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

CATEGORY_TREE = {
    "פירות יבשים ואגוזים":               ["פירות יבשים", "אגוזים וזרעים"],
    "דגנים קטניות ופסטות":               ["דגנים", "קטניות", "פסטות, אטריות ופתיתים"],
    "בישול ואפייה":                       ["תבלינים", "ממתיקים ותחליפי סוכר",
                                           "קמחים ועזרי אפייה", "שמנים", "מחלקה אסייתית"],
    "ממרחים, רטבים ושימורים":            ["ממרחים מלוחים ורטבים", "ממרחי אגוזים",
                                           "שימורים", "ממרחים מתוקים", "סירופים ורטבים"],
    "דגני בוקר, גרנולה שוקולד וחטיפים": ["גרנולה ודגני בוקר", "שוקולד ומוצריו",
                                           "סוכריות", "חטיפים מלוחים ומתוקים"],
    "עוגיות מאפים וקרקרים":              ["לחמים ופיתות", "עוגות", "עוגיות",
                                           "קרקרים, מצות ופריכיות"],
    "משקאות":                             ["תרכיזים ורכזים", "תחליפי חלב צמחיים",
                                           "חליטות ומוצרי תה", "קפה ותחליפים",
                                           "מיצים טבעיים לשתיה"],
    "מקרר וקפוא":                         ["טופו וביצים", "תחליפי גבינה", "תחליפי בשר",
                                           "פירות קפואים", "גלידות",
                                           "אוכל קפוא להכנה מהירה"],
    "תוספי תזונה ובריאות":               ["ויטמינים ותוספים", "מוצרים טיפוליים",
                                           "שמנים אתריים ובשמים"],
    "קוסמטיקה ורחצה טבעיים":            ["הגנה מהשמש", "דאודורנט", "טיפוח הפנים והגוף",
                                           "היגיינת הפה והשיניים",
                                           "שמפו, סבון, מרכך ומסכות",
                                           "הגיינה נשית", "לאם ולתינוק"],
    "אקולוגי ומוצרים לבית":              ["חד פעמי, ניירות אפייה וכסף",
                                           "ניקוי אקולוגי"],
}

# שלוף קטגוריות
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

id_to_cat  = {c["id"]: c for c in cats}
name_to_cat = {}
for c in cats:
    name_to_cat.setdefault(c["name"], []).append(c)

ok = bad = missing = 0
issues = []
print("=" * 65)

for parent_name, children in CATEGORY_TREE.items():
    parent_matches = name_to_cat.get(parent_name, [])
    parent_top = [c for c in parent_matches if c["parent"] == 0]

    if not parent_top:
        print(f"✗ חסרה קטגוריית אב: '{parent_name}'")
        issues.append(f"  חסרה קטגוריית אב: '{parent_name}'")
        missing += 1
        continue

    parent_id = parent_top[0]["id"]
    print(f"\n  {parent_name}  (id={parent_id}, {parent_top[0]['count']} מוצרים)")

    for child_name in children:
        child_matches = name_to_cat.get(child_name, [])
        correct = [c for c in child_matches if c["parent"] == parent_id]
        wrong   = [c for c in child_matches if c["parent"] != parent_id]

        if correct:
            print(f"    ✓  {child_name}  ({correct[0]['count']} מוצרים)")
            ok += 1
        elif wrong:
            real_parent = id_to_cat.get(wrong[0]["parent"], {}).get("name", "ראשית")
            print(f"    ✗  {child_name}  — קיימת תחת '{real_parent}' במקום כאן")
            issues.append(f"  ✗ מיקום שגוי: '{child_name}' — תחת '{real_parent}' במקום '{parent_name}'  (id={wrong[0]['id']})")
            bad += 1
        else:
            print(f"    —  {child_name}  — לא קיימת בכלל")
            issues.append(f"  — חסרה: '{child_name}'  (תחת '{parent_name}')")
            missing += 1

print(f"\n{'='*65}")
print(f"  ✓ תקין: {ok}   ✗ מיקום שגוי: {bad}   — חסר: {missing}")
if issues:
    print(f"\n{'='*65}")
    print("  בעיות שדורשות טיפול:")
    for line in issues:
        print(line)
