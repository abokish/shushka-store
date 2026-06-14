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
ANTHROPIC_KEY = _ENV['ANTHROPIC_API_KEY']
WC_AUTH = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WC_HEADERS = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}

CATEGORIES_PROMPT = """
קטגוריות הראשיות ותת-הקטגוריות שלהן (ID בסוגריים):

ירקניה (129): ירקות (48), פירות (58), עלים ירוקים (41)
דגנים קטניות ופסטות (130): דגנים (83) — אורז/קינואה/כוסמת/שיבולת שועל/גריסים, קטניות (84) — עדשים/חומוס/שעועית/אפונה, פסטות אטריות ופתיתים (85) — פסטה/ניוקי/אטריות/פתיתים/ספגטי
בישול ואפייה (131): תבלינים (87), שמנים (1895), קמחים ועזרי אפייה (4932), ממתיקים ותחליפי סוכר (88), מחלקה אסייתית (91) — רוטב סויה/מיסו/נורי/אצות/טום-יאם/וואסאבי
ממרחים רטבים ושימורים (132): ממרחי אגוזים (94) — טחינה/חמאת בוטנים/חלבה, ממרחים מלוחים ורטבים (92) — הומוס/פסטו/חרדל/מיונז, ממרחים מתוקים (1887) — ריבות/דבש/מעדן, סירופים ורטבים (86), שימורים (93)
דגני בוקר גרנולה שוקולד וחטיפים (133): גרנולה ודגני בוקר (96), שוקולד ומוצריו (97), חטיפים מלוחים ומתוקים (100) — בר/פופקורן/וופל/צ'יפס, סוכריות (98)
עוגיות מאפים וקרקרים (134): עוגיות (103), קרקרים מצות ופריכיות (4929) — קרקר/מצה/פריכיות, עוגות (102) — מרציפן/פאי/תערובת, לחמים ופיתות (101) — לחם/פיתה/לחמניה
פירות יבשים ואגוזים (35): פירות יבשים (140) — תמרים/צימוקים/תאנים/משמש, אגוזים וזרעים (4920) — אגוזים/שקדים/גרעינים/זרעי צ'יה
משקאות (135): מיצים טבעיים לשתיה (109), חליטות ומוצרי תה (107), קפה ותחליפים (108), תחליפי חלב צמחיים (106), תרכיזים ורכזים (105)
מקרר וקפוא (136): תחליפי גבינה (111), תחליפי בשר (4930), טופו וביצים (110) — טופו/יוגורט סויה/ביצים, אוכל קפוא (113), גלידות (112), פירות קפואים (4922)
תוספי תזונה ובריאות (137): ויטמינים ותוספים (114), מוצרים טיפוליים (4927), שמנים אתריים ובשמים (116)
קוסמטיקה ורחצה טבעיים (138): שמפו סבון מרכך ומסכות (121), טיפוח הפנים והגוף (119), היגיינת הפה והשיניים (120), דאודורנט (4931), הגנה מהשמש (117), הגיינה נשית (122), לאם ולתינוק (123)
אקולוגי ומוצרים לבית (139): ניקוי אקולוגי (125), חד פעמי ניירות אפייה וכסף (124)
כללי (15)
"""

def review_batch(items):
    """Review a batch of (product, category) pairs for errors."""
    lines = "\n".join([f'- ID {x["id"]}: "{x["name"]}" → קטגוריה: "{x["cat_name"]}" (ID: {x["cat_id"]})' for x in items])

    prompt = f"""אתה בודק סיווגי מוצרים לחנות טבע ישראלית.

{CATEGORIES_PROMPT}

להלן רשימת מוצרים עם הקטגוריה שהוקצתה להם. זהה כל מוצר שהקטגוריה שלו שגויה.
החזר JSON בלבד — רק המוצרים השגויים:
[{{"id": <id>, "name": "<שם>", "wrong_cat": "<קטגוריה שגויה>", "correct_cat_id": <id נכון>, "correct_cat_name": "<שם נכון>", "reason": "<הסבר קצר>"}}]

אם אין שגיאות — החזר: []

מוצרים לבדיקה:
{lines}"""

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

# Load classification results
results_file = 'c:/Users/aboki/shushka-store/current_state.json'
if not os.path.exists(results_file):
    print("קובץ לא נמצא")
    exit(1)

with open(results_file, encoding='utf-8') as f:
    all_results = json.load(f)

print(f"בודק {len(all_results)} מוצרים...")

BATCH_SIZE = 60
all_errors = []

for i in range(0, len(all_results), BATCH_SIZE):
    batch = all_results[i:i+BATCH_SIZE]
    batch_num = i // BATCH_SIZE + 1
    total_batches = (len(all_results) + BATCH_SIZE - 1) // BATCH_SIZE
    print(f"  בדיקת קבוצה {batch_num}/{total_batches}...", flush=True)

    try:
        errors = review_batch(batch)
        if errors:
            all_errors.extend(errors)
            print(f"    נמצאו {len(errors)} שגיאות", flush=True)
        time.sleep(0.5)
    except Exception as e:
        print(f"  שגיאה: {e}", flush=True)
        time.sleep(2)

# Save errors
errors_file = 'c:/Users/aboki/shushka-store/categorization_review_errors.json'
with open(errors_file, 'w', encoding='utf-8') as f:
    json.dump(all_errors, f, ensure_ascii=False, indent=2)

print(f"\nסיכום: נמצאו {len(all_errors)} שגיאות מתוך {len(all_results)} מוצרים")
print(f"שמור ב: {errors_file}")

if all_errors:
    print("\nדוגמאות לשגיאות:")
    for e in all_errors[:20]:
        print(f"  {e['name'][:40]} | {e['wrong_cat']} → {e['correct_cat_name']} ({e['reason']})")

    # Generate correction script
    correction_script = "import urllib.request, json, base64, os\n\n"
    correction_script += "def _load_env():\n    env = {}\n    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env'), encoding='utf-8') as f:\n        for line in f:\n            line = line.strip()\n            if line and not line.startswith('#') and '=' in line:\n                k, v = line.split('=', 1); env[k.strip()] = v.strip()\n    return env\n_ENV = _load_env()\n"
    correction_script += 'WC_AUTH = base64.b64encode(f"{_ENV[\'WC_CONSUMER_KEY\']}:{_ENV[\'WC_CONSUMER_SECRET\']}".encode()).decode()\n'
    correction_script += 'WC_HEADERS = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}\n\n'
    correction_script += "corrections = [\n"
    for e in all_errors:
        correction_script += f'    {{"id": {e["id"]}, "name": "{e["name"].replace(chr(34), chr(39))}", "cat_id": {e["correct_cat_id"]}, "cat_name": "{e["correct_cat_name"]}"}},\n'
    correction_script += "]\n\n"
    correction_script += """updates = [{"id": c["id"], "categories": [{"id": c["cat_id"]}]} for c in corrections]
for i in range(0, len(updates), 20):
    batch = updates[i:i+20]
    data = json.dumps({"update": batch}).encode()
    req = urllib.request.Request(
        "https://shushka.co.il/wp-json/wc/v3/products/batch",
        data=data, headers=WC_HEADERS, method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        r.read()
    print(f"תוקנו {min(i+20, len(updates))}/{len(updates)}")
print("תיקון הושלם!")
"""
    with open('c:/Users/aboki/shushka-store/apply_corrections.py', 'w', encoding='utf-8') as f:
        f.write(correction_script)
    print(f"\nסקריפט תיקון נוצר: apply_corrections.py")
    print("הרץ אותו כדי לתקן את כל השגיאות.")
