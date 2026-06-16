"""
import_missing_products.py
קורא CSV של מוצרים חסרים, יוצר תיאורים עם Claude, מסווג קטגוריות,
ומייבא ל-WooCommerce דרך REST API.

קובץ הקלט: missing-products-YYYYMMDD.csv (מיוצא מדף הסנכרון)
עמודות: ברקוד, שם, מחיר
"""

import urllib.request, json, base64, os, time, csv, sys

# ── env ─────────────────────────────────────────────────
def _load_env():
    env = {}
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
    with open(p, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                env[k.strip()] = v.strip()
    return env

_ENV = _load_env()
WC_AUTH    = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WC_HEADERS = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}
WC_BASE    = _ENV.get('WC_BASE_URL', 'https://shushka.co.il/wp-json/wc/v3')
ANT_KEY    = _ENV['ANTHROPIC_API_KEY']

# ── קטגוריות ────────────────────────────────────────────
CATEGORIES = """
ירקניה (129): ירקות (48), פירות (58), עלים ירוקים (41)
דגנים קטניות ופסטות (130): דגנים (83), קטניות (84), פסטות אטריות ופתיתים (85)
בישול ואפייה (131): תבלינים (87), שמנים (1895), קמחים ועזרי אפייה (4932), ממתיקים (88), מחלקה אסייתית (91)
ממרחים רטבים ושימורים (132): ממרחי אגוזים (94), ממרחים מלוחים ורטבים (92), ממרחים מתוקים (1887), סירופים (86), שימורים (93)
דגני בוקר גרנולה שוקולד וחטיפים (133): גרנולה ודגני בוקר (96), שוקולד (97), חטיפים (100), סוכריות (98)
עוגיות מאפים וקרקרים (134): עוגיות (103), קרקרים מצות ופריכיות (4929), עוגות (102), לחמים ופיתות (101)
פירות יבשים ואגוזים (35): פירות יבשים (140), אגוזים וזרעים (4920)
משקאות (135): מיצים (109), חליטות ותה (107), קפה (108), תחליפי חלב (106), תרכיזים (105)
מקרר וקפוא (136): תחליפי גבינה (111), תחליפי בשר (4930), טופו וביצים (110), אוכל קפוא (113), גלידות (112), פירות קפואים (4922)
תוספי תזונה ובריאות (137): ויטמינים ותוספים (114), מוצרים טיפוליים (4927), שמנים אתריים (116)
קוסמטיקה ורחצה טבעיים (138): שמפו סבון מרכך (121), טיפוח פנים וגוף (119), היגיינת פה ושיניים (120), דאודורנט (4931), הגנת שמש (117), היגיינה נשית (122), לאם ולתינוק (123)
אקולוגי ומוצרים לבית (139): ניקוי אקולוגי (125), חד פעמי וניירות (124)
כללי (15)
"""

# ── Claude API ───────────────────────────────────────────
def claude(prompt, max_tokens=1024):
    data = json.dumps({
        "model": "claude-haiku-4-5-20251001",
        "max_tokens": max_tokens,
        "messages": [{"role": "user", "content": prompt}]
    }).encode()
    req = urllib.request.Request(
        "https://api.anthropic.com/v1/messages", data=data,
        headers={"x-api-key": ANT_KEY, "anthropic-version": "2023-06-01",
                 "content-type": "application/json"},
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())['content'][0]['text'].strip()

def generate_descriptions(name):
    prompt = f"""אתה כותב תוכן לחנות טבע ישראלית בשם שושקה.
כתוב עבור המוצר: "{name}"

החזר JSON בלבד (ללא markdown):
{{
  "short_description": "תיאור קצר של 1-2 משפטים המדגיש יתרונות בריאותיים עיקריים",
  "description": "תיאור מפורט של 3-5 משפטים הכולל: מה המוצר, מהיכן מגיע, יתרונות תזונתיים, ואיך ניתן להשתמש בו",
  "category_id": <מספר קטגוריה מהרשימה הכי מתאימה>
}}

קטגוריות:
{CATEGORIES}"""
    text = claude(prompt)
    if '```' in text:
        text = text.split('```')[1]
        if text.startswith('json'):
            text = text[4:]
    return json.loads(text.strip())

# ── WooCommerce API ──────────────────────────────────────
def wc_request(path, method='GET', body=None):
    url  = f"{WC_BASE}/{path}"
    data = json.dumps(body).encode() if body else None
    req  = urllib.request.Request(url, data=data, headers=WC_HEADERS, method=method)
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())

def product_exists(sku):
    results = wc_request(f"products?sku={sku}&per_page=1")
    return len(results) > 0

def create_product(name, sku, price, short_desc, desc, cat_id):
    return wc_request('products', 'POST', {
        "name":              name,
        "sku":               sku,
        "regular_price":     str(price),
        "short_description": short_desc,
        "description":       desc,
        "categories":        [{"id": cat_id}],
        "status":            "publish",
        "manage_stock":      False,
    })

# ── main ─────────────────────────────────────────────────
def main():
    csv_files = [f for f in os.listdir('.') if f.startswith('missing-products') and f.endswith('.csv')]
    if not csv_files:
        print("לא נמצא קובץ missing-products-*.csv בתיקייה")
        sys.exit(1)
    csv_file = sorted(csv_files)[-1]
    print(f"קורא: {csv_file}")

    with open(csv_file, encoding='utf-8-sig') as f:
        rows = list(csv.DictReader(f))

    print(f"נמצאו {len(rows)} מוצרים לייבוא\n")

    done = skip = fail = 0
    for i, row in enumerate(rows, 1):
        barcode = row.get('ברקוד', '').strip()
        name    = row.get('שם', '').strip()
        price   = row.get('מחיר', '0').strip()

        if not barcode or not name:
            skip += 1
            continue

        print(f"[{i}/{len(rows)}] {name[:45]}", end=' ... ', flush=True)

        try:
            if product_exists(barcode):
                print("כבר קיים, מדלג")
                skip += 1
                continue

            d = generate_descriptions(name)
            create_product(name, barcode, price,
                           d['short_description'], d['description'],
                           d.get('category_id', 15))
            print(f"✓ (קטגוריה {d.get('category_id', 15)})")
            done += 1
            time.sleep(0.5)

        except Exception as e:
            print(f"✗ שגיאה: {e}")
            fail += 1
            time.sleep(2)

    print(f"\n{'='*50}")
    print(f"✓ יובאו: {done}  |  דולגו: {skip}  |  שגיאות: {fail}")

if __name__ == '__main__':
    main()
