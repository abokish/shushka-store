import urllib.request, json, base64, time, os

def _load_env():
    env = {}
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
    with open(p, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1); env[k.strip()] = v.strip()
    return env
_ENV = _load_env()
WC_AUTH = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WC_HEADERS = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}
ANTHROPIC_KEY = _ENV['ANTHROPIC_API_KEY']

CATEGORIES_PROMPT = """
קטגוריות הראשיות ותת-הקטגוריות שלהן (ID בסוגריים):

ירקניה (129): ירקות (48), פירות (58), עלים ירוקים (41)
דגנים קטניות ופסטות (130): דגנים (83) — אורז/קינואה/כוסמת/שיבולת שועל/גריסים, קטניות (84) — עדשים/חומוס/שעועית/אפונה, פסטות אטריות ופתיתים (85) — פסטה/ניוקי/אטריות/פתיתים/ספגטי
בישול ואפייה (131): תבלינים (87) — תבלינים/פלפל/כורכום/כמון, שמנים (1895) — שמן זית/קוקוס/שומשום, קמחים ועזרי אפייה (4932) — קמח/שמרים/אבקת אפייה/וניל, ממתיקים ותחליפי סוכר (88) — סטיביה/אגבה/סוכר קנה, מחלקה אסייתית (91) — רוטב סויה/מיסו/נורי/אצות/טום-יאם/וואסאבי/ג'ינג'ר/מוצרים יפניים-סיניים-תאילנדים
ממרחים רטבים ושימורים (132): ממרחי אגוזים (94) — טחינה/חמאת בוטנים/חלבה/ממרח שקדים, ממרחים מלוחים ורטבים (92) — הומוס/בבגנוש/פסטו/חרדל/מיונז/ממרח עגבניות, ממרחים מתוקים (1887) — ריבות/דבש/מעדן/ממרח שוקולד, סירופים ורטבים (86) — חומץ/רוטב עגבניות/רכז/רוטב סלט, שימורים (93) — שימורי ירקות/קטניות/דגים
דגני בוקר גרנולה שוקולד וחטיפים (133): גרנולה ודגני בוקר (96) — גרנולה/קורנפלקס/מוסלי, שוקולד ומוצריו (97) — שוקולד/ציפוי/כדורי שוקולד, חטיפים מלוחים ומתוקים (100) — בר/פופקורן/וופל/חטיפי בריאות/צ'יפס, סוכריות (98) — סוכריות/מסטיקים/ריקולה
עוגיות מאפים וקרקרים (134): עוגיות (103), קרקרים מצות ופריכיות (4929) — קרקר/מצה/פריכיות/גליל אורז, עוגות (102) — תערובת לעוגה/מרציפן/פאי, לחמים ופיתות (101) — לחם/פיתה/לחמניה/בגט
פירות יבשים ואגוזים (35): פירות יבשים (140) — תמרים/צימוקים/תאנים/משמש/שזיפים/קרנברי, אגוזים וזרעים (4920) — אגוזי מלך/שקדים/פיסטוק/גרעינים/זרעי צ'יה/פשתן/דלעת
משקאות (135): מיצים טבעיים לשתיה (109) — מיץ/נקטר/סמוצ'י/שייק ארוז, חליטות ומוצרי תה (107) — תה/חליטה/תה צמחים, קפה ותחליפים (108) — קפה/ציקוריה/אינסטנט, תחליפי חלב צמחיים (106) — חלב שקדים/שיבולת שועל/קוקוס/סויה/אורז, תרכיזים ורכזים (105) — רכז לימון/רימון/אלדרברי
מקרר וקפוא (136): תחליפי גבינה (111) — גבינה טבעונית/קשיו, תחליפי בשר (4930) — טמפה/המבורגר טבעוני/שניצל טבעוני, טופו וביצים (110) — טופו/ביצים/יוגורט סויה, אוכל קפוא להכנה מהירה (113) — פלאפל קפוא/פשטידה, גלידות (112), פירות קפואים (4922)
תוספי תזונה ובריאות (137): ויטמינים ותוספים (114) — ויטמין/מינרל/פרוביוטיקה/אומגה, מוצרים טיפוליים (4927) — CBD/תמצית/צמחי מרפא, שמנים אתריים ובשמים (116) — שמן אתרי/מפזר ארומה
קוסמטיקה ורחצה טבעיים (138): שמפו סבון מרכך ומסכות (121), טיפוח הפנים והגוף (119) — קרם/לוציון/שמן גוף, היגיינת הפה והשיניים (120) — משחת שיניים/מברשת/מי פה, דאודורנט (4931), הגנה מהשמש (117), הגיינה נשית (122) — תחתוניות/מגן תחתון/טמפון, לאם ולתינוק (123) — חיתול/מוצרי תינוקות
אקולוגי ומוצרים לבית (139): ניקוי אקולוגי (125) — אבקת כביסה/מרכך/נוזל כלים, חד פעמי ניירות אפייה וכסף (124) — כוסות/צלחות/נייר אפייה
כללי (15) — כשלא ברור לאיזו קטגוריה המוצר שייך
"""

def classify_batch(products):
    product_list = "\n".join([f'- ID {p["id"]}: {p["name"]}' for p in products])
    prompt = f"""אתה עוזר לסווג מוצרים לחנות טבע ישראלית בשם "שושקה".

{CATEGORIES_PROMPT}

סווג כל מוצר ברשימה לקטגוריה המתאימה ביותר.
כללים קריטיים:
- תת-קטגוריה ספציפית עדיפה על קטגוריה ראשית.
- אם לא ברור — "כללי" (15). עדיף כללי מאשר קטגוריה שגויה.
- ניוקי/פסטה/אטריות = פסטות (85), לא לחמים.
- פריכיות/קרקרים = קרקרים מצות ופריכיות (4929), לא חטיפים.
- אצות/טום-יאם/מיסו/נורי = מחלקה אסייתית (91).
- יוגורט סויה/ביוגורט סויה = טופו וביצים (110).
- מארזים/מתנות/מוצרים לא ברורים = כללי (15).
החזר JSON בלבד ללא טקסט נוסף:
[{{"id": <id>, "cat_id": <cat_id>, "cat_name": "<שם קטגוריה>"}}]

מוצרים לסיווג:
{product_list}"""

    data = json.dumps({
        "model": "claude-haiku-4-5-20251001",
        "max_tokens": 4096,
        "messages": [{"role": "user", "content": prompt}]
    }).encode()

    req = urllib.request.Request(
        "https://api.anthropic.com/v1/messages",
        data=data,
        headers={"x-api-key": ANTHROPIC_KEY, "anthropic-version": "2023-06-01", "content-type": "application/json"},
        method="POST"
    )
    with urllib.request.urlopen(req) as r:
        result = json.loads(r.read())

    text = result['content'][0]['text'].strip()
    if '```' in text:
        text = text.split('```')[1]
        if text.startswith('json'):
            text = text[4:]
    return json.loads(text)

def batch_update_wc(classifications):
    """Use WooCommerce batch API to update many products at once."""
    updates = [{"id": item['id'], "categories": [{"id": item['cat_id']}]} for item in classifications]
    data = json.dumps({"update": updates}).encode()
    req = urllib.request.Request(
        "https://shushka.co.il/wp-json/wc/v3/products/batch",
        data=data,
        headers=WC_HEADERS,
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())

# Load all products
with open('c:/Users/aboki/shushka-store/all_products.json', encoding='utf-8') as f:
    all_products = json.load(f)

# Load progress
progress_file = 'c:/Users/aboki/shushka-store/categorization_progress.json'
results_file = 'c:/Users/aboki/shushka-store/categorization_results.json'

if os.path.exists(progress_file):
    with open(progress_file, encoding='utf-8') as f:
        done_ids = set(json.load(f))
    print(f"ממשיך מהיכן שעצרנו: {len(done_ids)} מוצרים כבר עודכנו")
else:
    done_ids = set()

if os.path.exists(results_file):
    with open(results_file, encoding='utf-8') as f:
        all_results = json.load(f)
else:
    all_results = []

remaining = [p for p in all_products if p['id'] not in done_ids]
print(f"נשאר לסווג: {len(remaining)} מוצרים")

BATCH_SIZE = 50
WC_BATCH_SIZE = 20  # WooCommerce batch limit
errors = []
total_done = len(done_ids)

for i in range(0, len(remaining), BATCH_SIZE):
    batch = remaining[i:i+BATCH_SIZE]
    batch_num = i // BATCH_SIZE + 1
    total_batches = (len(remaining) + BATCH_SIZE - 1) // BATCH_SIZE

    print(f"\nקבוצה {batch_num}/{total_batches} — מסווג {len(batch)} מוצרים...", flush=True)

    try:
        classifications = classify_batch(batch)
        all_results.extend(classifications)

        # Save results to file
        with open(results_file, 'w', encoding='utf-8') as f:
            json.dump(all_results, f, ensure_ascii=False, indent=2)

        # Update WooCommerce in sub-batches of 20
        updated_count = 0
        for j in range(0, len(classifications), WC_BATCH_SIZE):
            sub = classifications[j:j+WC_BATCH_SIZE]
            try:
                batch_update_wc(sub)
                for item in sub:
                    done_ids.add(item['id'])
                updated_count += len(sub)
            except Exception as e:
                print(f"  WC batch error: {e}")
                errors.extend([{"id": x['id'], "error": str(e)} for x in sub])

        with open(progress_file, 'w', encoding='utf-8') as f:
            json.dump(list(done_ids), f)

        total_done += updated_count
        general_count = sum(1 for x in classifications if x['cat_id'] == 15)
        print(f"  עודכנו {updated_count}. כללי: {general_count}. סה\"כ: {total_done}/2088", flush=True)

        time.sleep(0.5)

    except Exception as e:
        print(f"  שגיאה בקבוצה {batch_num}: {e}", flush=True)
        errors.append({"batch": batch_num, "error": str(e)})
        time.sleep(3)

print(f"\n{'='*50}")
print(f"הושלם! עודכנו: {total_done} מוצרים")
if errors:
    print(f"שגיאות: {len(errors)}")
    with open('c:/Users/aboki/shushka-store/categorization_errors.json', 'w', encoding='utf-8') as f:
        json.dump(errors, f, ensure_ascii=False, indent=2)
