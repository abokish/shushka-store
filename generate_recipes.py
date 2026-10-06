"""
generate_recipes.py
יוצר 10 מתכונים ב-WordPress דרך REST API, עם תוכן מ-Claude.
המתכונים נוצרים כטיוטות (draft) לסקירה לפני פרסום.
"""

import urllib.request, json, base64, os, time

# ── env ─────────────────────────────────────────────────────────
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

_ENV       = _load_env()
ANT_KEY    = _ENV['ANTHROPIC_API_KEY']
OAI_KEY    = _ENV.get('OPENAI_API_KEY', '')
WP_BASE    = _ENV.get('WC_URL', 'https://shushka.co.il') + '/wp-json'
WP_AUTH    = base64.b64encode(f"{_ENV['WP_ADMIN_USERNAME']}:{_ENV['WP_ADMIN_PASSWORD']}".encode()).decode()
WP_HEADERS = {'Authorization': f'Basic {WP_AUTH}', 'Content-Type': 'application/json'}
WC_AUTH    = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WC_HEADERS = {'Authorization': f'Basic {WC_AUTH}', 'Content-Type': 'application/json'}
WC_BASE    = _ENV.get('WC_URL', 'https://shushka.co.il') + '/wp-json/wc/v3'

# ── 10 מתכונים לייצור ───────────────────────────────────────────
RECIPES = [
    {
        'title':    'ברוניז שוקולד מקמח שקדים',
        'category': 'מאפים',
        'tags':     ['ללא גלוטן', 'ללא חלב', 'ללא סוכר', 'עם ביצים'],
        'prep':     40, 'servings': 16,
        'keywords': ['קמח שקדים', 'שוקולד מריר', 'שמן קוקוס', 'סירופ מייפל', 'ביצים', 'אבקת קקאו'],
    },
    {
        'title':    'עוגת בננה כוסמין',
        'category': 'מאפים',
        'tags':     ['עם ביצים'],
        'prep':     55, 'servings': 10,
        'keywords': ['קמח כוסמין', 'בננה', 'שמן קוקוס', 'סירופ מייפל', 'ביצים', 'אגוזי מלך'],
    },
    {
        'title':    'עוגת גזר עם קמח כוסמין',
        'category': 'מאפים',
        'tags':     ['עם ביצים', 'ללא חלב'],
        'prep':     60, 'servings': 12,
        'keywords': ['קמח כוסמין', 'גזר', 'שמן קוקוס', 'סירופ מייפל', 'ביצים', 'קינמון', 'אגוזי מלך'],
    },
    {
        'title':    'בצק פיצה כוסמין',
        'category': 'ארוחה עיקרית',
        'tags':     ['טבעוני', 'ללא חלב'],
        'prep':     20, 'servings': 4,
        'keywords': ['קמח כוסמין', 'שמן זית', 'שמרים'],
    },
    {
        'title':    'פנקייק כוסמין עם דבש',
        'category': 'ארוחה קלה',
        'tags':     ['עם ביצים', 'עד 30 דקות'],
        'prep':     20, 'servings': 3,
        'keywords': ['קמח כוסמין', 'ביצים', 'חלב שקדים', 'דבש', 'שמן קוקוס', 'וניל'],
    },
    {
        'title':    'מוקפץ טופו עם ירקות ורוטב סויה',
        'category': 'ארוחה עיקרית',
        'tags':     ['טבעוני', 'ללא חלב', 'עד 30 דקות'],
        'prep':     25, 'servings': 2,
        'keywords': ['טופו', 'רוטב סויה', 'שמן שומשום', 'שום', 'ג׳ינג׳ר', 'קינואה'],
    },
    {
        'title':    'גרנולה ביתית עם אגוזים ופירות יבשים',
        'category': 'ארוחה קלה',
        'tags':     ['טבעוני', 'ללא חלב', 'ללא גלוטן'],
        'prep':     30, 'servings': 8,
        'keywords': ['שיבולת שועל', 'שמן קוקוס', 'דבש', 'אגוזים', 'גרעיני חמנייה', 'פירות יבשים', 'קינמון'],
    },
    {
        'title':    'כדורי אנרגיה תמרים וקוקוס',
        'category': 'חטיפים',
        'tags':     ['טבעוני', 'ללא גלוטן', 'ללא סוכר', 'ללא חלב', 'עד 30 דקות'],
        'prep':     15, 'servings': 20,
        'keywords': ['תמרים', 'אגוזי קשיו', 'קוקוס טחון', 'אבקת קקאו', 'חמאת שקדים'],
    },
    {
        'title':    'מוס שוקולד טבעוני',
        'category': 'קינוחים',
        'tags':     ['טבעוני', 'ללא חלב', 'ללא גלוטן'],
        'prep':     15, 'servings': 4,
        'keywords': ['שוקולד מריר', 'חלב קוקוס', 'סירופ מייפל', 'וניל', 'אבקת קקאו'],
    },
    {
        'title':    'עוגת שוקולד ספוגית ללא חלב',
        'category': 'קינוחים',
        'tags':     ['ללא חלב', 'עם ביצים'],
        'prep':     50, 'servings': 10,
        'keywords': ['קמח כוסמין', 'אבקת קקאו', 'שוקולד מריר', 'שמן קוקוס', 'ביצים', 'סירופ מייפל'],
    },
]

# ── Claude API ───────────────────────────────────────────────────
def claude(prompt, max_tokens=2000, model='claude-haiku-4-5-20251001'):
    data = json.dumps({
        'model': model,
        'max_tokens': max_tokens,
        'messages': [{'role': 'user', 'content': prompt}]
    }).encode()
    req = urllib.request.Request(
        'https://api.anthropic.com/v1/messages', data=data,
        headers={'x-api-key': ANT_KEY, 'anthropic-version': '2023-06-01', 'content-type': 'application/json'},
        method='POST'
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())['content'][0]['text'].strip()

# ── ביקורת עברית עם Sonnet ───────────────────────────────────────
def review_hebrew(content_data, title):
    prompt = f"""תקן שגיאות עברית בהוראות ההכנה של המתכון "{title}".

{content_data['instructions_html']}

בדוק ותקן:
- מילים שאינן קיימות בעברית (כמו "חומצמים", "קורמט", "גששו")
- מילים עם רווח שגוי באמצע (כמו "הע וגה" → "העוגה")
- ניסוח לא טבעי בעברית
- שגיאות דקדוק

החזר JSON בלבד ללא markdown:
{{"instructions_html": "<ol><li>...</li></ol>"}}"""

    text = claude(prompt, max_tokens=2000, model='claude-sonnet-4-6')
    if '```' in text:
        text = text.split('```')[1]
        if text.startswith('json'): text = text[4:]
    reviewed = json.loads(text.strip())
    content_data['instructions_html'] = reviewed['instructions_html']
    return content_data

# ── WooCommerce: חיפוש מוצרים ────────────────────────────────────
def search_wc_product(keyword):
    url = f"{WC_BASE}/products?search={urllib.parse.quote(keyword)}&per_page=3&status=publish"
    req = urllib.request.Request(url, headers=WC_HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            results = json.loads(r.read())
            return results[0] if results else None
    except Exception:
        return None

import urllib.parse

# ── WordPress REST API ───────────────────────────────────────────
def wp_request(path, method='GET', body=None):
    url  = f"{WP_BASE}/{path}"
    data = json.dumps(body, ensure_ascii=False).encode('utf-8') if body else None
    req  = urllib.request.Request(url, data=data, headers=WP_HEADERS, method=method)
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

def get_term_id(taxonomy, name):
    """מוצא ID של term לפי שם, או יוצר אם לא קיים."""
    terms = wp_request(f'wp/v2/{taxonomy}?search={urllib.parse.quote(name)}&per_page=5')
    for t in terms:
        if t['name'] == name:
            return t['id']
    # יצירה
    new_term = wp_request(f'wp/v2/{taxonomy}', 'POST', {'name': name})
    return new_term['id']

# ── יצירת תוכן מתכון עם Claude ──────────────────────────────────
def generate_recipe_content(recipe, matched_products):
    products_str = '\n'.join([
        f'  - {p["name"]} (ID: {p["id"]}, SKU: {p.get("sku","")}) — ₪{p.get("price","")}'
        for p in matched_products if p
    ]) or '  (לא נמצאו מוצרים מתאימים בחנות)'

    prompt = f"""אתה כותב מתכון לאתר חנות טבע ישראלית בשם שושקה. המתכונים בריאים, ללא מוצרי חלב ועדיפות לקמח מלא.

שם המתכון: {recipe['title']}
זמן הכנה: {recipe['prep']} דקות
מנות: {recipe['servings']}

מוצרים הנמכרים בחנות שעשויים להיכלל במתכון:
{products_str}

צור מתכון מפורט בעברית. החזר JSON בלבד (ללא markdown):
{{
  "ingredients": [
    {{"qty": "כמות + יחידה", "name": "שם מרכיב", "pid": <ID מוצר מהרשימה או null>}},
    ...
  ],
  "instructions_html": "<ol><li>שלב ראשון...</li><li>שלב שני...</li></ol>",
  "excerpt": "תיאור קצר ומפתה של המתכון במשפט אחד"
}}

חשוב:
- pid רק אם המרכיב מופיע ברשימת המוצרים שלמעלה — אחרת null
- כמויות מדויקות ומעשיות
- הוראות ברורות ב-HTML (ol/li בלבד)
- excerpt עד 120 תווים"""

    text = claude(prompt, max_tokens=2000)
    if '```' in text:
        text = text.split('```')[1]
        if text.startswith('json'): text = text[4:]
    return json.loads(text.strip())

# ── DALL-E: יצירת תמונה ─────────────────────────────────────────
def generate_recipe_image(title, keywords):
    kw_str = ', '.join(keywords[:4])
    prompt = (
        "Warm illustrated style for a natural health food store. Soft watercolor texture, "
        "cozy organic aesthetic, natural palette (sage green, warm cream, terracotta, honey yellow). "
        "Clean white background, simple centered composition, hand-drawn feel. No text, no labels. "
        f"The dish is: {title} ({kw_str})"
    )
    data = json.dumps({
        'model': 'gpt-image-1', 'prompt': prompt,
        'size': '1024x1024', 'quality': 'medium', 'n': 1,
    }).encode()
    req = urllib.request.Request(
        'https://api.openai.com/v1/images/generations', data=data,
        headers={'Authorization': f'Bearer {OAI_KEY}', 'Content-Type': 'application/json'},
        method='POST'
    )
    with urllib.request.urlopen(req, timeout=180) as r:
        result = json.loads(r.read())
    return base64.b64decode(result['data'][0]['b64_json'])

def upload_wp_media(image_bytes, filename, alt_text):
    req = urllib.request.Request(
        f'{WP_BASE}/wp/v2/media', data=image_bytes,
        headers={
            'Authorization': f'Basic {WP_AUTH}',
            'Content-Disposition': f'attachment; filename="{filename}"',
            'Content-Type': 'image/png',
        },
        method='POST'
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        media = json.loads(r.read())
    wp_request(f'wp/v2/media/{media["id"]}', 'POST', {'alt_text': alt_text})
    return media['id']

# ── יצירת פוסט WordPress ────────────────────────────────────────
def create_recipe_post(recipe, content_data, cat_id, tag_ids, media_id=0):
    ingredients_json = json.dumps(content_data['ingredients'], ensure_ascii=False)
    body = {
        'title':          recipe['title'],
        'content':        content_data['instructions_html'],
        'excerpt':        content_data['excerpt'],
        'status':         'draft',
        'featured_media': media_id,
        'shpk_rcat':      [cat_id],
        'shpk_rtag':      tag_ids,
        'meta': {
            '_shpk_prep_time':   recipe['prep'],
            '_shpk_servings':    recipe['servings'],
            '_shpk_ingredients': ingredients_json,
        },
    }
    return wp_request('wp/v2/shpk_recipe', 'POST', body)

# ── main ─────────────────────────────────────────────────────────
def main():
    print('📖 מייצר 10 מתכונים...\n')

    # טוען term IDs פעם אחת
    print('⏳ טוען קטגוריות ותגיות...')
    cat_cache = {}
    tag_cache = {}

    done = fail = 0
    for i, recipe in enumerate(RECIPES, 1):
        print(f'\n[{i}/10] {recipe["title"]}')

        try:
            # קטגוריה
            cat_name = recipe['category']
            if cat_name not in cat_cache:
                cat_cache[cat_name] = get_term_id('shpk_rcat', cat_name)
            cat_id = cat_cache[cat_name]

            # תגיות
            tag_ids = []
            for tag_name in recipe['tags']:
                if tag_name not in tag_cache:
                    tag_cache[tag_name] = get_term_id('shpk_rtag', tag_name)
                tag_ids.append(tag_cache[tag_name])

            # חיפוש מוצרים מתאימים
            print(f'  🔍 מחפש מוצרים...')
            matched = []
            for kw in recipe['keywords']:
                p = search_wc_product(kw)
                if p:
                    matched.append(p)
                    print(f'     ✓ {kw} → {p["name"][:40]} (ID:{p["id"]})')
                else:
                    print(f'     — {kw} לא נמצא')
                time.sleep(0.2)

            # יצירת תוכן עם Claude
            print(f'  🤖 מייצר תוכן (Haiku)...')
            content = generate_recipe_content(recipe, matched)
            ingr_count = len(content.get('ingredients', []))
            linked = sum(1 for x in content.get('ingredients', []) if x.get('pid'))
            print(f'     {ingr_count} מרכיבים ({linked} מקושרים למוצרים)')

            # ביקורת עברית עם Sonnet
            print(f'  🔍 ביקורת עברית (Sonnet)...')
            content = review_hebrew(content, recipe['title'])

            # יצירת תמונה עם DALL-E
            media_id = 0
            if OAI_KEY:
                print(f'  🎨 מייצר תמונה (DALL-E)...')
                img_bytes = generate_recipe_image(recipe['title'], recipe['keywords'])
                media_id  = upload_wp_media(img_bytes, f'recipe-{i}.png', recipe['title'])
                print(f'     ✓ תמונה הועלתה (ID: {media_id})')
            else:
                print(f'  ⚠ OPENAI_API_KEY חסר ב-.env — מדלג על תמונה')

            # יצירת פוסט
            print(f'  📝 מעלה ל-WordPress...')
            post = create_recipe_post(recipe, content, cat_id, tag_ids, media_id)
            print(f'     ✓ טיוטה נוצרה: {post["link"].replace("/wp-json","")}'
                  if 'link' in post else f'     ✓ ID: {post.get("id")}')
            done += 1
            time.sleep(1)

        except Exception as e:
            print(f'  ✗ שגיאה: {e}')
            fail += 1
            time.sleep(2)

    print(f'\n{"="*50}')
    print(f'✓ נוצרו: {done}  |  שגיאות: {fail}')
    print(f'\nהמתכונים ממתינים לסקירה ב: https://shushka.co.il/wp-admin/edit.php?post_type=shpk_recipe')

if __name__ == '__main__':
    main()
