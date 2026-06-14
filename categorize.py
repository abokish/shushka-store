"""
categorize.py — Product Category Fixer for shushka.co.il

Steps:
  1. Rename / delete categories per config
  2. Fetch all products from WooCommerce
  3. Batch-classify each product with Claude Haiku (cheap + fast)
  4. Update product categories via WooCommerce API
  5. Write CSV report + checkpoint (resume-safe)

Usage:
    python categorize.py --dry-run        # preview, no writes
    python categorize.py --cleanup-only   # only rename/delete categories
    python categorize.py                  # full run
    python categorize.py --resume         # continue from last checkpoint
"""

import os, sys, re, json, time, csv, logging, argparse
from datetime import datetime
from pathlib import Path

import anthropic
from dotenv import load_dotenv
from woocommerce import API

load_dotenv()

# ── Logging ───────────────────────────────────────────────────────────────────
RUN_ID = datetime.now().strftime("%Y%m%d_%H%M%S")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(f"categorize_{RUN_ID}.log", encoding="utf-8"),
    ],
)
log = logging.getLogger(__name__)

CHECKPOINT_FILE = Path("checkpoint_categorize.json")

# ── Category Config ───────────────────────────────────────────────────────────

RENAME_CATEGORIES = {
    "הקפאה קירור וביצים":   "מקרר וקפוא",
    "תוספי תזונה וסופרפארם": "תוספי תזונה ובריאות",
    "תחליפי גבינה ובשר":    "תחליפי גבינה",
}

DELETE_CATEGORIES = [
    "פירות קפואים ואצות",
]

# Canonical tree: only leaf categories are used for product assignment.
# Parent categories are shown for context in the Claude prompt.
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

# Maps common AI abbreviations/mistakes → correct category name
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

# ── API Clients ───────────────────────────────────────────────────────────────

def _check_env():
    required = ("WC_URL", "WC_CONSUMER_KEY", "WC_CONSUMER_SECRET", "ANTHROPIC_API_KEY")
    missing = [k for k in required if not os.getenv(k)]
    if missing:
        log.error("Missing env vars: %s", missing)
        sys.exit(1)

wcapi = API(
    url=os.getenv("WC_URL", "").rstrip("/"),
    consumer_key=os.getenv("WC_CONSUMER_KEY", ""),
    consumer_secret=os.getenv("WC_CONSUMER_SECRET", ""),
    version="wc/v3",
    timeout=60,
)
claude_client = anthropic.Anthropic(api_key=os.getenv("ANTHROPIC_API_KEY", ""))

# ── WooCommerce helpers ───────────────────────────────────────────────────────

def wc_get_all(endpoint, **params):
    """Fetch all pages from a paginated WooCommerce endpoint."""
    items, page = [], 1
    while True:
        resp = wcapi.get(endpoint, params={**params, "per_page": 100, "page": page})
        resp.raise_for_status()
        batch = resp.json()
        if resp.status_code != 200:
            break
        if not isinstance(batch, list) or not batch:
            break
        items.extend(batch)
        page += 1
        time.sleep(0.3)
    return items

def wc_put(endpoint, data):
    resp = wcapi.put(endpoint, data)
    if resp.status_code not in (200, 201):
        raise RuntimeError(f"WC PUT {endpoint} → {resp.status_code}: {resp.text[:200]}")
    return resp.json()

def wc_delete(endpoint):
    resp = wcapi.delete(endpoint, params={"force": True})
    return resp.json()

# ── Step 1: Category cleanup ──────────────────────────────────────────────────

def get_category_map():
    """Return {name: {id, count}} for all WooCommerce categories."""
    cats = wc_get_all("products/categories")
    return {c["name"]: {"id": c["id"], "count": c["count"]} for c in cats}

def rename_categories(dry_run):
    log.info("── Step 1a: Renaming categories ──")
    cat_map = get_category_map()
    for old, new in RENAME_CATEGORIES.items():
        if old in cat_map:
            cid = cat_map[old]["id"]
            log.info("  Rename '%s' → '%s' (id=%s)", old, new, cid)
            if not dry_run:
                wc_put(f"products/categories/{cid}", {"name": new})
        elif new in cat_map:
            log.info("  Skip rename '%s' — already renamed to '%s'", old, new)
        else:
            log.warning("  Category '%s' not found — skipping rename", old)

def delete_empty_categories(dry_run):
    log.info("── Step 1b: Deleting empty categories ──")
    cat_map = get_category_map()
    for name in DELETE_CATEGORIES:
        if name not in cat_map:
            log.info("  Skip delete '%s' — not found", name)
            continue
        info = cat_map[name]
        if info["count"] > 0:
            log.warning("  Skip delete '%s' — still has %d products", name, info["count"])
            continue
        log.info("  Delete '%s' (id=%s)", name, info["id"])
        if not dry_run:
            wc_delete(f"products/categories/{info['id']}")

# ── Step 2: Classify with Claude ─────────────────────────────────────────────

CLASSIFY_PROMPT = """\
אתה עוזר לסווג מוצרים לחנות טבע ישראלית בשם שושקה.
לכל מוצר מופיע: שם המוצר | תיאור קצר (אם קיים).
השתמש בשם ובתיאור יחד כדי לסווג נכון.

מבנה הקטגוריות (קטגוריית אב: תת-קטגוריות שאפשר לבחור מהן):
{category_context}

כללי סיווג:
- קרמאמה, פלנטי, מאמה-קיו (mamaQ) וכל ממרח/גבינה טבעוני → "תחליפי גבינה"
- שיוגורט, יוגורט שקדים/סויה/קשיו → "תחליפי גבינה"
- קשיו הולנדית, ריקוטה טבעונית, ברי טבעוני → "תחליפי גבינה"
- רוטב קשיו לפסטה (מק אנד קשיוציז, אלפרדו קשיו) → "תחליפי חלב צמחיים"
- נקניקיות צמחוניות, המבורגר צמחוני, שניצל טבעוני → "תחליפי בשר"
- טופו, טמפה → "טופו וביצים"
- מיצים, גרופר, שלוק-מיץ, מחיות פירות לשתייה, סמוצ'י → "מיצים טבעיים לשתיה"
- שלוק לקפוא / ארטיק בשקית / גלידון → "גלידות"
- גרנולה, קורנפלקס, קואלה קריפס, דגני בוקר → "גרנולה ודגני בוקר"
- אבקת קקאו, פולי קקאו, שוקולד, ניב קקאו → "שוקולד ומוצריו"
- תמצית וניל, אבקת אפייה, סודה לשתייה, שמרים, פסיליום → "קמחים ועזרי אפייה"
- אצות (נורי, וואקמה, קומבו, אצות קלויות, שיטאקה, פטריות מיובשות) → "מחלקה אסייתית"
- שמן קנולה/זית/קוקוס/שומשום לבישול → "שמנים"
- שמן ארומתרפי / שמן אתרי → "שמנים אתריים ובשמים"
- לחם, פיתה, טורטיה, לאפה → "לחמים ופיתות"
- וופל, ביסלי, במבה, חטיפים, מרציפן, חלבה, חלווה → "חטיפים מלוחים ומתוקים"
- ניקוי ביתי, נוזל כלים, אבקת כביסה, מרכך כביסה → "ניקוי אקולוגי"
- כוסות/צלחות חד-פעמי, נייר אפייה → "חד פעמי, ניירות אפייה וכסף"
- סבון גוף, סבון רחצה, פתיתי סבון, שמפו (לא לתינוק), מרכך שיער, מסכת שיער → "שמפו, סבון, מרכך ומסכות"
- קרקר, מצה, פריכית, אורז מתנפח → "קרקרים, מצות ופריכיות"
- ניוקי, פסטה, אטריות, פתיתים, לזניה, ספגטי, ריזוני → "פסטות, אטריות ופתיתים"
- ויטמין C/D/B12, אבץ, מגנזיום → "ויטמינים ותוספים"
- CBD, כורכומין, אומגה 3, מוצרים טיפוליים → "מוצרים טיפוליים"
- קרם פנים, שמן ארגן, קרם גוף → "טיפוח הפנים והגוף"
- ממרח חמאת שקדים/בוטנים/טחינה → "ממרחי אגוזים"
- ריבה, דבש, חרובה, סילאן → "ממרחים מתוקים"
- חומוס, פסטו, ממרח מלוח, רוטב → "ממרחים מלוחים ורטבים"
- שימורים, מלפפון כבוש, אננס בחתיכות, אפונה שימורים → "שימורים"
- חיתול, מגבוני תינוק, שמפו לתינוק → "לאם ולתינוק"
- שומר, ורד, כמון, זנגביל לחליטה → "חליטות ומוצרי תה"
- קפה, קקאו שתייה חמה → "קפה ותחליפים"

לכל מוצר, בחר את תת-הקטגוריה המתאימה ביותר מהרשימה לעיל.
כתוב בדיוק את שם תת-הקטגוריה כפי שהוא מופיע (כולל פסיקים ורווחים).
אל תחבר שמות של מספר תת-קטגוריות — בחר רק שם אחד בדיוק.
אל תשתמש בשם קטגוריית האב — בחר רק מתוך תת-הקטגוריות.
אם אינך בטוח — הוסף "uncertain": true.

מוצרים לסיווג:
{products_list}

החזר JSON בלבד, ללא טקסט נוסף:
[
  {{"id": 123, "category": "שם תת-קטגוריה", "uncertain": false}},
  ...
]\
"""

def _clean_desc(html: str, limit: int = 160) -> str:
    """Strip HTML tags and truncate for use in classification prompt."""
    text = re.sub(r'<[^>]+>', ' ', html or '')
    text = re.sub(r'\s+', ' ', text).strip()
    return text[:limit]


def classify_batch(batch):
    """Send up to 30 products to Claude Haiku, get category assignments back."""
    def _product_line(i, p):
        desc = _clean_desc(p.get("short_description") or p.get("description", ""))
        line = f'{i+1}. id={p["id"]} | "{p["name"]}"'
        if desc:
            line += f' | {desc}'
        return line

    products_list = "\n".join(_product_line(i, p) for i, p in enumerate(batch))
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
            return json.loads(text)
        except Exception as exc:
            log.warning("  Claude attempt %d/3 failed: %s", attempt + 1, exc)
            time.sleep(2 ** attempt)

    # Fallback: mark all uncertain
    return [{"id": p["id"], "category": None, "uncertain": True} for p in batch]

# ── Step 3: Update + Report ───────────────────────────────────────────────────

def update_product_category(pid, cat_id, dry_run):
    if dry_run:
        return True
    try:
        wc_put(f"products/{pid}", {"categories": [{"id": cat_id}]})
        return True
    except Exception as exc:
        log.error("  Failed to update product %d: %s", pid, exc)
        return False

def load_checkpoint():
    if CHECKPOINT_FILE.exists():
        return json.loads(CHECKPOINT_FILE.read_text(encoding="utf-8"))
    return {"processed_ids": [], "results": []}

def save_checkpoint(processed_ids, results):
    CHECKPOINT_FILE.write_text(
        json.dumps({"processed_ids": list(processed_ids), "results": results},
                   ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

def write_report(results, run_id):
    path = f"categorize_report_{run_id}.csv"
    with open(path, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(
            f, fieldnames=["id", "name", "old_category", "new_category", "uncertain", "updated"]
        )
        writer.writeheader()
        writer.writerows(results)
    log.info("Report saved → %s", path)
    return path

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Categorize WooCommerce products for shushka.co.il")
    parser.add_argument("--dry-run",        action="store_true", help="No writes to WooCommerce")
    parser.add_argument("--cleanup-only",   action="store_true", help="Only rename/delete categories")
    parser.add_argument("--resume",         action="store_true", help="Resume from last checkpoint")
    parser.add_argument("--uncertain-only", action="store_true", help="Re-classify only uncertain products from checkpoint")
    args = parser.parse_args()

    _check_env()
    log.info("=" * 62)
    log.info("  Product Categorizer — shushka.co.il   [%s]", RUN_ID)
    log.info("  Mode: %s", "DRY RUN" if args.dry_run else "LIVE")
    log.info("=" * 62)

    # Step 1 — cleanup
    rename_categories(args.dry_run)
    delete_empty_categories(args.dry_run)

    if args.cleanup_only:
        log.info("Cleanup-only mode — finished.")
        return

    # Step 2 — build category name→id map (after renames)
    cat_map = get_category_map()          # {name: {id, count}}
    cat_id = {n: v["id"] for n, v in cat_map.items()}

    # Step 3 — fetch all products
    log.info("── Fetching all products ──")
    all_products = wc_get_all("products", status="any")
    log.info("  Total products found: %d", len(all_products))

    # Resume / uncertain-only support
    if args.uncertain_only:
        cp = load_checkpoint()
        uncertain_ids = {r["id"] for r in cp["results"] if r.get("uncertain")}
        # Keep certain results as-is; re-classify the uncertain ones
        kept_results = [r for r in cp["results"] if not r.get("uncertain")]
        processed_ids = {r["id"] for r in kept_results}
        results = kept_results
        todo = [p for p in all_products if p["id"] in uncertain_ids]
        log.info("  Uncertain-only mode: re-classifying %d products", len(todo))
    elif args.resume:
        cp = load_checkpoint()
        processed_ids = set(cp["processed_ids"])
        results = cp["results"]
        todo = [p for p in all_products if p["id"] not in processed_ids]
        log.info("  To process: %d  |  Already done: %d", len(todo), len(processed_ids))
    else:
        processed_ids = set()
        results = []
        todo = all_products
        log.info("  To process: %d", len(todo))

    # Step 4 — classify + update in batches of 30
    BATCH = 30
    for start in range(0, len(todo), BATCH):
        batch = todo[start: start + BATCH]
        log.info("── Batch %d–%d / %d ──", start + 1, start + len(batch), len(todo))

        classifications = classify_batch(batch)
        class_by_id = {c["id"]: c for c in classifications}

        for product in batch:
            pid   = product["id"]
            pname = product["name"]
            old_cat = product["categories"][0]["name"] if product["categories"] else "—"

            cls       = class_by_id.get(pid, {"category": None, "uncertain": True})
            new_cat   = cls.get("category")
            uncertain = bool(cls.get("uncertain", False))
            updated   = False

            # Normalize common AI abbreviations before matching
            if new_cat and new_cat not in cat_id:
                new_cat = CATEGORY_ALIASES.get(new_cat, new_cat)

            if new_cat and new_cat in cat_id:
                updated = update_product_category(pid, cat_id[new_cat], args.dry_run)
                status  = "DRY" if args.dry_run else "✓"
                log.info("  [%s] %-40s  %s → %s", status, pname[:40], old_cat, new_cat)
            else:
                uncertain = True
                new_cat   = new_cat or "לא זוהה"
                log.warning("  [?] %-40s  → %s (uncertain)", pname[:40], new_cat)

            results.append({
                "id": pid, "name": pname,
                "old_category": old_cat, "new_category": new_cat,
                "uncertain": uncertain, "updated": updated,
            })
            processed_ids.add(pid)

        save_checkpoint(processed_ids, results)
        time.sleep(1)   # stay polite to both APIs

    # Step 5 — report
    report_path = write_report(results, RUN_ID)

    updated_n   = sum(1 for r in results if r["updated"])
    uncertain_n = sum(1 for r in results if r["uncertain"])
    log.info("=" * 62)
    log.info("  Updated  : %d products", updated_n)
    log.info("  Uncertain: %d products — review %s", uncertain_n, report_path)
    log.info("=" * 62)

if __name__ == "__main__":
    main()
