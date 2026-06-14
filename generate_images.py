"""
יצירת תמונות מוצרים עם GPT-4o-mini + DALL-E 3
-------------------------------------------------
הפעלה:    python generate_images.py --test 5
מלא:      python generate_images.py
"""
import urllib.request, urllib.parse, json, base64, time, sys, os, argparse, re

# ── קריאת .env ────────────────────────────────────────────────────────────────
def load_env(path=".env"):
    env = {}
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env[k.strip()] = v.strip()
    return env

ENV = load_env("c:/Users/aboki/shushka-store/.env")

OPENAI_KEY  = ENV["OPENAI_API_KEY"]
WC_AUTH     = base64.b64encode(
    f"{ENV['WC_CONSUMER_KEY']}:{ENV['WC_CONSUMER_SECRET']}".encode()
).decode()
WP_AUTH     = base64.b64encode(
    f"{ENV['WP_ADMIN_USERNAME']}:{ENV['WP_ADMIN_PASSWORD']}".encode()
).decode()
WP_BASE     = ENV["WC_URL"]
WC_HEADERS  = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}
WP_HEADERS  = {"Authorization": f"Basic {WP_AUTH}", "Content-Type": "application/json"}

PROGRESS_FILE = "c:/Users/aboki/shushka-store/image_progress.json"

STYLE = (
    "Warm illustrated style for a natural health food store. "
    "Soft watercolor texture, cozy organic aesthetic, natural palette "
    "(sage green, warm cream, terracotta, honey yellow). "
    "Clean white background, simple centered composition, hand-drawn feel. "
    "No text, no labels, no brand names."
)

# ── helpers ───────────────────────────────────────────────────────────────────

def openai_post(endpoint, payload):
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req  = urllib.request.Request(
        f"https://api.openai.com/v1/{endpoint}",
        data=data,
        headers={"Authorization": f"Bearer {OPENAI_KEY}", "Content-Type": "application/json"},
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())

def wc_get(path):
    req = urllib.request.Request(f"{WP_BASE}/wp-json/wc/v3{path}", headers=WC_HEADERS)
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read()), r.headers

def wc_put(path, body):
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req  = urllib.request.Request(
        f"{WP_BASE}/wp-json/wc/v3{path}", data=data, headers=WC_HEADERS, method="PUT"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.loads(r.read())

def load_progress():
    if os.path.exists(PROGRESS_FILE):
        with open(PROGRESS_FILE, encoding="utf-8") as f:
            return set(json.load(f))
    return set()

def save_progress(done_ids):
    with open(PROGRESS_FILE, "w", encoding="utf-8") as f:
        json.dump(list(done_ids), f)

# ── core logic ────────────────────────────────────────────────────────────────

def describe_product(name, category):
    resp = openai_post("chat/completions", {
        "model": "gpt-4o-mini",
        "max_tokens": 150,
        "messages": [{
            "role": "user",
            "content": (
                f"You are describing a product sold in a natural health food store for an illustrator.\n"
                f"Product: {name}\nCategory: {category}\n\n"
                f"First decide: is this a packaged product (bottle, bag, jar, box) or a prepared food/drink item (a dish, a drink served in a glass/cup, a slice of something)?\n"
                f"Then describe its visual appearance in 2 sentences: shape, colors, key visual elements. "
                f"Do NOT mention any brand names. Be specific and accurate."
            )
        }]
    })
    return resp["choices"][0]["message"]["content"].strip()

def generate_image(description):
    prompt = f"{STYLE} The product is: {description}"
    resp = openai_post("images/generations", {
        "model": "gpt-image-1",
        "prompt": prompt,
        "size": "1024x1024",
        "quality": "medium",
        "n": 1
    })
    return base64.b64decode(resp["data"][0]["b64_json"])

def upload_image_to_wp(image_data, filename, alt_text):
    """העלאת bytes ישירות ל-WordPress Media Library"""

    # העלאה ל-WP
    upload_headers = {
        "Authorization": f"Basic {WP_AUTH}",
        "Content-Disposition": f'attachment; filename="{filename}"',
        "Content-Type": "image/png",
    }
    req = urllib.request.Request(
        f"{WP_BASE}/wp-json/wp/v2/media",
        data=image_data,
        headers=upload_headers,
        method="POST"
    )
    with urllib.request.urlopen(req, timeout=60) as r:
        media = json.loads(r.read())

    media_id = media["id"]

    # קביעת alt text
    upd_data = json.dumps({"alt_text": alt_text}, ensure_ascii=False).encode("utf-8")
    upd_req  = urllib.request.Request(
        f"{WP_BASE}/wp-json/wp/v2/media/{media_id}",
        data=upd_data,
        headers={**WP_HEADERS},
        method="POST"
    )
    with urllib.request.urlopen(upd_req, timeout=30) as r:
        r.read()

    return media_id

def set_product_image(product_id, media_id):
    return wc_put(f"/products/{product_id}", {"images": [{"id": media_id}]})

def fetch_products_without_images(limit=None):
    products, page = [], 1
    while True:
        batch, hdrs = wc_get(f"/products?per_page=100&page={page}&status=publish&orderby=id&order=asc")
        products.extend(batch)
        total_pages = int(hdrs.get("X-WP-TotalPages") or 1)
        if page >= total_pages:
            break
        page += 1
    no_img = [p for p in products if not p.get("images")]
    return no_img[:limit] if limit else no_img

# ── main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--test", type=int, default=0,
                        help="כמה מוצרים לבדיקה (0 = כל המוצרים)")
    parser.add_argument("--redo", type=str, default="",
                        help="IDs לציור מחדש, מופרדים בפסיק: --redo 5136,5138")
    args = parser.parse_args()
    test_mode = args.test > 0
    redo_ids  = set(args.redo.split(",")) - {""} if args.redo else set()

    if redo_ids:
        print(f"🔄 ציור מחדש ל-IDs: {', '.join(redo_ids)}")
        products = []
        for pid in redo_ids:
            req = urllib.request.Request(f"{WP_BASE}/wp-json/wc/v3/products/{pid}", headers=WC_HEADERS)
            with urllib.request.urlopen(req) as r:
                products.append(json.loads(r.read()))
        done = load_progress() - redo_ids
    else:
        print(f"{'🧪 מצב בדיקה — ' + str(args.test) + ' מוצרים' if test_mode else '🚀 מצב מלא'}")
        print("טוען מוצרים ללא תמונה...")
        products = fetch_products_without_images(limit=args.test if test_mode else None)
        print(f"נמצאו {len(products)} מוצרים ללא תמונה")
        done = load_progress()

    products = [p for p in products if str(p["id"]) not in done]
    if not products:
        print("✓ כל המוצרים כבר עודכנו!")
        return

    print(f"נשאר לעבד: {len(products)}\n")
    errors = []

    for i, p in enumerate(products, 1):
        pid   = p["id"]
        name  = p["name"]
        cats  = [c["name"] for c in p.get("categories", [])]
        cat   = cats[-1] if cats else "natural food product"
        slug  = f"product-{pid}.png"

        print(f"[{i}/{len(products)}] {name[:50]}", end=" ", flush=True)
        try:
            print("→ מתאר...", end=" ", flush=True)
            desc = describe_product(name, cat)

            print("→ מצייר...", end=" ", flush=True)
            img_bytes = generate_image(desc)

            print("→ מעלה...", end=" ", flush=True)
            media_id = upload_image_to_wp(img_bytes, slug, name)

            print("→ מגדיר...", end=" ", flush=True)
            set_product_image(pid, media_id)

            done.add(str(pid))
            save_progress(done)
            print("✓")
        except Exception as e:
            print(f"✗ {e}")
            errors.append({"id": pid, "name": name, "error": str(e)})
            time.sleep(3)

        # DALL-E 3: ~5 תמונות/דקה → המתנה 13 שניות
        if i < len(products):
            time.sleep(13)

    print(f"\n{'='*50}")
    print(f"הושלם! עודכנו {len(done)} מוצרים")
    if errors:
        print(f"\nשגיאות ({len(errors)}):")
        for e in errors[:10]:
            print(f"  {e['id']}: {e['name'][:40]} — {e['error']}")

if __name__ == "__main__":
    main()
