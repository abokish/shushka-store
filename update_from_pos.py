"""
update_from_pos.py — מעדכן מוצרי WooCommerce לפי ייצוא ה-POS

שימוש:
    python update_from_pos.py products17-2.csv               # dry-run
    python update_from_pos.py products17-2.csv --live        # עדכן שם/מחיר/מותג
    python update_from_pos.py products17-2.csv --recategorize           # dry-run + סיווג AI
    python update_from_pos.py products17-2.csv --recategorize --live    # הכל ביחד

מה מתעדכן:
    - שם מוצר (תאור פריט)    — רק אם שונה
    - מחיר מכירה             — רק אם שונה
    - מותג                   — רק אם השדה אינו ריק ב-CSV
    - קטגוריה               — רק עם --recategorize, ורק לשורות עם מותג

מה לא מתעדכן אף פעם:
    - שורות ללא ברקוד (SKU)
    - קטגוריה בלי --recategorize
"""

import os, sys, csv, time, re, json, argparse, logging
from datetime import datetime

import anthropic
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
claude_client = anthropic.Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY", ""))

# ── Category tree (identical to categorize.py) ───────────────────────────────
CATEGORY_TREE = {
    "פירות יבשים ואגוזים":                  ["פירות יבשים", "אגוזים וזרעים"],
    "דגנים קטניות ופסטות":                  ["דגנים", "קטניות", "פסטות, אטריות ופתיתים"],
    "בישול ואפייה":                          ["תבלינים", "ממתיקים ותחליפי סוכר",
                                              "קמחים ועזרי אפייה", "שמנים", "מחלקה אסייתית"],
    "ממרחים, רטבים ושימורים":               ["ממרחים מלוחים ורטבים", "ממרחי אגוזים",
                                              "שימורים", "ממרחים מתוקים", "סירופים ורטבים"],
    "דגני בוקר, גרנולה שוקולד וחטיפים":    ["גרנולה ודגני בוקר", "שוקולד ומוצריו",
                                              "סוכריות", "חטיפים מלוחים ומתוקים"],
    "עוגיות מאפים וקרקרים":                 ["לחמים ופיתות", "עוגות", "עוגיות",
                                              "קרקרים, מצות ופריכיות"],
    "משקאות":                                ["תרכיזים ורכזים", "תחליפי חלב צמחיים",
                                              "חליטות ומוצרי תה", "קפה ותחליפים",
                                              "מיצים טבעיים לשתיה"],
    "מקרר וקפוא":                            ["טופו וביצים", "תחליפי גבינה",
                                              "תחליפי בשר", "פירות קפואים",
                                              "גלידות", "אוכל קפוא להכנה מהירה"],
    "תוספי תזונה ובריאות":                  ["ויטמינים ותוספים", "מוצרים טיפוליים",
                                              "שמנים אתריים ובשמים"],
    "קוסמטיקה ורחצה טבעיים":               ["הגנה מהשמש", "דאודורנט",
                                              "טיפוח הפנים והגוף", "היגיינת הפה והשיניים",
                                              "שמפו, סבון, מרכך ומסכות",
                                              "הגיינה נשית", "לאם ולתינוק"],
    "אקולוגי ומוצרים לבית":                 ["חד פעמי, ניירות אפייה וכסף",
                                              "ניקוי אקולוגי", "כלי מטבח, תבניות ושקיות"],
}

CATEGORY_CONTEXT = "\n".join(
    f"  {parent}: {', '.join(children)}"
    for parent, children in CATEGORY_TREE.items()
)
ALL_LEAF_NAMES = {leaf for children in CATEGORY_TREE.values() for leaf in children}

CATEGORY_ALIASES = {
    "קרקרים":                  "קרקרים, מצות ופריכיות",
    "פריכיות":                 "קרקרים, מצות ופריכיות",
    "סבון":                    "שמפו, סבון, מרכך ומסכות",
    "שמפו":                    "שמפו, סבון, מרכך ומסכות",
    "פסטות":                   "פסטות, אטריות ופתיתים",
    "אטריות":                  "פסטות, אטריות ופתיתים",
    "פתיתים":                  "פסטות, אטריות ופתיתים",
    "פסטות, אטריות":           "פסטות, אטריות ופתיתים",
    "ממתיקים":                 "ממתיקים ותחליפי סוכר",
    "ממתיקים ותחליפי סוכר":   "ממתיקים ותחליפי סוכר",
}

BRAND_ATTR_NAME = "מותג"

# ── Logging ───────────────────────────────────────────────────────────────────
timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
log_file = f"update_from_pos_{timestamp}.log"
logging.basicConfig(
    level=logging.INFO,
    format="%(message)s",
    handlers=[
        logging.FileHandler(log_file, encoding="utf-8"),
        logging.StreamHandler(sys.stdout),
    ],
)
log = logging.getLogger()

# ── Args ─────────────────────────────────────────────────────────────────────
parser = argparse.ArgumentParser()
parser.add_argument("csv_file", help="נתיב לקובץ ה-CSV של ה-POS")
parser.add_argument("--live", action="store_true", help="ביצוע בפועל (ללא זה: dry-run)")
parser.add_argument("--recategorize", action="store_true",
                    help="סווג מחדש עם AI את המוצרים שיש להם מותג")
args = parser.parse_args()

LIVE = args.live
CSV_PATH = args.csv_file

log.info(f"{'='*60}")
log.info(f"  update_from_pos — {'live' if LIVE else 'dry-run'}"
         f"{'  +recategorize' if args.recategorize else ''}")
log.info(f"  קובץ: {CSV_PATH}")
log.info(f"{'='*60}\n")


# ── CSV reading ───────────────────────────────────────────────────────────────
def stripped_row(row):
    return {k.strip(): v.strip() for k, v in row.items() if k}


def load_csv(path):
    rows = {}
    skipped = 0
    with open(path, encoding="utf-8-sig", newline="") as f:
        for raw in csv.DictReader(f):
            r = stripped_row(raw)
            sku = r.get("ברקוד", "")
            if not sku or sku == "0":
                skipped += 1
                continue
            name  = r.get("תאור פריט", "")
            price = r.get("מחיר מכירה", "").replace(",", ".")
            brand = r.get("מותג", "")
            dept  = r.get("שם מחלקה", "")
            rows[sku] = {"name": name, "price": price, "brand": brand, "dept": dept}
    log.info(f"CSV: {len(rows)} שורות עם SKU, {skipped} ללא SKU (דולגו)")
    return rows


# ── WooCommerce helpers ───────────────────────────────────────────────────────
def norm_sku(s):
    """WC stores barcodes as floats: '7290016306149.0' → '7290016306149'."""
    s = (s or "").strip()
    if s.endswith(".0"):
        s = s[:-2]
    return s


def fetch_all_products():
    """Returns (by_sku, by_name).
    by_sku: normalized-SKU → product
    by_name: name → product (None if name is ambiguous / appears in multiple products)
    """
    log.info("שולף מוצרים מ-WooCommerce...")
    all_products = []
    page = 1
    while True:
        r = wcapi.get("products", params={"per_page": 100, "page": page, "status": "any"})
        if r.status_code != 200:
            log.error(f"שגיאה: {r.status_code} {r.text[:200]}")
            break
        batch = r.json()
        if not isinstance(batch, list) or not batch:
            break
        all_products.extend(batch)
        log.info(f"  עמוד {page}: {len(batch)} ({len(all_products)} סה\"כ)")
        if len(batch) < 100:
            break
        page += 1
        time.sleep(0.3)

    by_sku  = {}
    by_name = {}
    name_count = {}
    for p in all_products:
        ns = norm_sku(p.get("sku", ""))
        if ns:
            by_sku[ns] = p
        name = (p.get("name") or "").strip()
        if name:
            name_count[name] = name_count.get(name, 0) + 1
            by_name[name] = p

    # Mark ambiguous names as None so we skip them
    for name, count in name_count.items():
        if count > 1:
            by_name[name] = None

    log.info(f"  {len(all_products)} מוצרים, {len(by_sku)} עם SKU, {sum(1 for v in by_name.values() if v)} שמות ייחודיים")
    return by_sku, by_name


def fetch_wc_categories():
    cats, page = [], 1
    while True:
        r = wcapi.get("products/categories", params={"per_page": 100, "page": page})
        if r.status_code != 200:
            break
        batch = r.json()
        if not isinstance(batch, list) or not batch:
            break
        cats.extend(batch)
        if len(batch) < 100:
            break
        page += 1
        time.sleep(0.2)
    return {c["name"]: c["id"] for c in cats}


def get_current_price(p):
    return (p.get("regular_price") or p.get("price") or "").strip()


def get_current_brand(p):
    for attr in p.get("attributes", []):
        if attr.get("name") == BRAND_ATTR_NAME:
            opts = attr.get("options", [])
            return opts[0] if opts else ""
    return ""


# ── AI classification ─────────────────────────────────────────────────────────
CLASSIFY_PROMPT = """\
אתה עוזר לסווג מוצרים לחנות טבע ישראלית בשם שושקה.
לכל מוצר מופיע: שם המוצר | מותג | מחלקה ב-POS (מערכת הקופה). השתמש בכל שלושת השדות לסיווג נכון.

מבנה הקטגוריות (קטגוריית אב: תת-קטגוריות שאפשר לבחור מהן):
{category_context}

כללי סיווג:
- קרמאמה, פלנטי, מאמה-קיו וכל ממרח/גבינה טבעוני → "תחליפי גבינה"
- שיוגורט, יוגורט שקדים/סויה/קשיו → "תחליפי גבינה"
- נקניקיות צמחוניות, המבורגר צמחוני, שניצל טבעוני → "תחליפי בשר"
- טופו, טמפה, ביצים אורגניות → "טופו וביצים"
- מיצים, גרופר, שלוק-מיץ, מחיות פירות לשתייה, סמוצ'י → "מיצים טבעיים לשתיה"
- שלוק לקפוא / ארטיק / גלידה → "גלידות"
- גרנולה, קורנפלקס, דגני בוקר → "גרנולה ודגני בוקר"
- שוקולד, קקאו, ניב קקאו → "שוקולד ומוצריו"
- תמצית וניל, אבקת אפייה, סודה, שמרים, פסיליום, קמח → "קמחים ועזרי אפייה"
- אצות, וואקמה, מחית קארי, פטריות מיובשות, רוטב סויה, חומץ אורז → "מחלקה אסייתית"
- שמן קנולה/זית/קוקוס/שומשום לבישול → "שמנים"
- שמן ארומתרפי / שמן אתרי / שמן למבער → "שמנים אתריים ובשמים"
- לחם, פיתה, טורטיה → "לחמים ופיתות"
- עוגיות, חטיפים מלוחים/מתוקים, מרציפן, חלבה → "חטיפים מלוחים ומתוקים"
- עוגה, טירמיסו, פחזנית, קינוח → "עוגות"
- ניקוי ביתי, נוזל כלים, אבקת כביסה, מרכך כביסה, מסיר כתמים → "ניקוי אקולוגי"
- כוסות/צלחות חד-פעמי, נייר אפייה, נייר אלומיניום → "חד פעמי, ניירות אפייה וכסף"
- סבון גוף/רחצה, שמפו, מרכך שיער, מסכת שיער → "שמפו, סבון, מרכך ומסכות"
- קרקר, מצה, פריכית, אורז מתנפח → "קרקרים, מצות ופריכיות"
- פסטה, אטריות, פתיתים, לזניה, ספגטי, ריזוני, ניוקי → "פסטות, אטריות ופתיתים"
- ויטמין C/D/B12, אבץ, מגנזיום → "ויטמינים ותוספים"
- CBD, כורכומין, אומגה 3, שמן עץ התה, ארניקה → "מוצרים טיפוליים"
- קרם פנים, שמן ארגן, קרם גוף → "טיפוח הפנים והגוף"
- משחת שיניים, שטיפת פה → "היגיינת הפה והשיניים"
- ממרח חמאת שקדים/בוטנים/טחינה → "ממרחי אגוזים"
- ריבה, דבש, חרובה, סילאן → "ממרחים מתוקים"
- חומוס, פסטו, ממרח מלוח → "ממרחים מלוחים ורטבים"
- סירופ מייפל, אגבה, סירופ תמרים → "סירופים ורטבים"
- שימורים, זיתים, מלפפון כבוש, עגבניות שימורים → "שימורים"
- חיתול, מגבוני תינוק, שמפו לתינוק → "לאם ולתינוק"
- חליטה, תה, שומר, ורד → "חליטות ומוצרי תה"
- קפה, מקינטה → "קפה ותחליפים"
- דאודורנט, מבער → "דאודורנט"
- טמפון, תחבושת, מגן תחתון, מונקאפ → "הגיינה נשית"

לכל מוצר, בחר את תת-הקטגוריה המתאימה ביותר מהרשימה.
כתוב בדיוק את שם תת-הקטגוריה כפי שהוא מופיע (כולל פסיקים ורווחים).
אל תחבר שמות — בחר רק שם אחד בדיוק.
אל תשתמש בשם קטגוריית האב — בחר רק מתוך תת-הקטגוריות.
אם אינך בטוח — הוסף "uncertain": true.

מוצרים לסיווג:
{products_list}

החזר JSON בלבד, ללא טקסט נוסף:
[
  {{"id": "SKU", "category": "שם תת-קטגוריה", "uncertain": false}},
  ...
]"""


def classify_batch(items):
    """items = list of {sku, name, brand, dept}. Returns {sku: {category, uncertain}}."""
    lines = []
    for i, it in enumerate(items):
        line = f'{i+1}. id="{it["sku"]}" | "{it["name"]}"'
        if it["brand"]:
            line += f' | מותג: {it["brand"]}'
        if it["dept"]:
            line += f' | מחלקת POS: {it["dept"]}'
        lines.append(line)
    products_list = "\n".join(lines)
    prompt = CLASSIFY_PROMPT.format(
        category_context=CATEGORY_CONTEXT,
        products_list=products_list,
    )
    for attempt in range(3):
        try:
            resp = claude_client.messages.create(
                model="claude-haiku-4-5-20251001",
                max_tokens=2048,
                messages=[{"role": "user", "content": prompt}],
            )
            text = resp.content[0].text.strip()
            if "```" in text:
                text = text.split("```")[1]
                if text.lower().startswith("json"):
                    text = text[4:]
                text = text.strip()
            results = json.loads(text)
            return {str(r["id"]): r for r in results}
        except Exception as exc:
            log.warning(f"  Claude attempt {attempt+1}/3 failed: {exc}")
            time.sleep(2 ** attempt)
    return {it["sku"]: {"id": it["sku"], "category": None, "uncertain": True} for it in items}


def resolve_category(raw_name, cat_id_map):
    """Return (cat_id, uncertain) for a raw AI category name."""
    if not raw_name:
        return None, True
    name = CATEGORY_ALIASES.get(raw_name, raw_name)
    cid = cat_id_map.get(name)
    return cid, (cid is None)


# ── Payload builder ───────────────────────────────────────────────────────────
def build_payload(product, csv_row, cat_result, cat_id_map):
    payload = {}
    changes = []

    # Name
    wc_name = product.get("name", "").strip()
    new_name = csv_row["name"]
    if new_name and new_name != wc_name:
        payload["name"] = new_name
        changes.append(f"שם: '{wc_name}' → '{new_name}'")

    # Price
    wc_price = get_current_price(product)
    new_price = csv_row["price"]
    if new_price:
        try:
            wc_f  = float(wc_price)  if wc_price  else None
            new_f = float(new_price)
            if wc_f != new_f:
                payload["regular_price"] = f"{new_f:.2f}"
                changes.append(f"מחיר: {wc_price or '?'} → {new_f:.2f}")
        except ValueError:
            pass

    # Brand
    new_brand = csv_row["brand"]
    if new_brand:
        wc_brand = get_current_brand(product)
        if new_brand != wc_brand:
            attrs = [a for a in product.get("attributes", []) if a.get("name") != BRAND_ATTR_NAME]
            attrs.append({"name": BRAND_ATTR_NAME, "visible": True, "options": [new_brand]})
            payload["attributes"] = attrs
            changes.append(f"מותג: '{wc_brand or '?'}' → '{new_brand}'")

    # Category (only if recategorize is on AND we got a result)
    if cat_result and not cat_result.get("uncertain"):
        cid, uncertain = resolve_category(cat_result.get("category"), cat_id_map)
        if cid:
            old_cats = ", ".join(c["name"] for c in product.get("categories", [])) or "—"
            new_cat_name = cat_result["category"]
            payload["categories"] = [{"id": cid}]
            changes.append(f"קטגוריה: '{old_cats}' → '{new_cat_name}'")

    return payload, changes


# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    csv_data              = load_csv(CSV_PATH)
    wc_by_sku, wc_by_name = fetch_all_products()
    log.info(f"\nCSV: {len(csv_data)} שורות\n")

    cat_id_map  = {}
    cat_results = {}   # csv-sku → {category, uncertain}

    def find_wc_product(csv_sku, csv_name):
        """Try SKU first, then exact name. Returns (product, match_method) or (None, None)."""
        p = wc_by_sku.get(csv_sku)
        if p:
            return p, "sku"
        p = wc_by_name.get(csv_name)
        if p is None and csv_name in wc_by_name:
            return None, "ambiguous"  # name exists but not unique
        if p:
            return p, "name"
        return None, None

    if args.recategorize:
        log.info("שולף קטגוריות WooCommerce...")
        cat_id_map = fetch_wc_categories()
        log.info(f"  {len(cat_id_map)} קטגוריות")

        to_classify = []
        for sku, row in csv_data.items():
            if not row["brand"]:
                continue
            product, _ = find_wc_product(sku, row["name"])
            if product:
                to_classify.append({"sku": sku, "name": row["name"],
                                     "brand": row["brand"], "dept": row["dept"]})

        log.info(f"\nמסווג {len(to_classify)} מוצרים עם מותג...\n")
        BATCH = 30
        for start in range(0, len(to_classify), BATCH):
            batch = to_classify[start:start + BATCH]
            log.info(f"  batch {start+1}–{start+len(batch)} / {len(to_classify)}")
            cat_results.update(classify_batch(batch))
            time.sleep(0.5)
        log.info("")

    # Build and apply updates
    matched = not_found = updated = no_change = errors = 0
    match_by_sku = match_by_name = 0
    report  = []

    for sku, csv_row in csv_data.items():
        product, method = find_wc_product(sku, csv_row["name"])
        if not product:
            not_found += 1
            continue
        matched += 1
        if method == "sku":
            match_by_sku += 1
        elif method == "name":
            match_by_name += 1

        cat_result = cat_results.get(sku)
        payload, changes = build_payload(product, csv_row, cat_result, cat_id_map)

        if not payload:
            no_change += 1
            continue

        pid  = product["id"]
        wc_name = product.get("name", "")
        change_str = " | ".join(changes)
        log.info(f"  [{method}] SKU={sku}  id={pid}  '{wc_name[:45]}'")
        log.info(f"    → {change_str}")

        status = "dry"
        if LIVE:
            r = wcapi.put(f"products/{pid}", payload)
            time.sleep(0.3)
            if r.status_code == 200:
                updated += 1
                status = "ok"
            else:
                errors += 1
                status = "error"
                log.error(f"    ✗ {r.status_code} {r.text[:200]}")
        else:
            updated += 1

        report.append({"sku": sku, "id": pid, "name": wc_name,
                        "match_method": method, "changes": change_str, "status": status})

    # Summary
    log.info(f"\n{'='*60}")
    log.info(f"  תואמו:           {matched}  (לפי SKU: {match_by_sku}, לפי שם: {match_by_name})")
    log.info(f"  לא נמצאו ב-WC:   {not_found}")
    log.info(f"  ללא שינוי:       {no_change}")
    if LIVE:
        log.info(f"  עודכנו:          {updated}")
        log.info(f"  שגיאות:          {errors}")
    else:
        log.info(f"  יעודכנו (dry):   {updated}")
    log.info(f"{'='*60}")
    if not LIVE:
        log.info("  [dry-run] — הרץ עם --live לביצוע בפועל")

    report_file = f"update_from_pos_{timestamp}.json"
    with open(report_file, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)
    log.info(f"\n  דוח:  {report_file}")
    log.info(f"  לוג:  {log_file}")


if __name__ == "__main__":
    main()
