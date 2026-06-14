"""מאחד חלווה → חטיפים מלוחים ומתוקים, ומוחק כלי מטבח (ריקה)."""
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

def get_cat_id(name):
    r = wcapi.get("products/categories", params={"search": name, "per_page": 20})
    for c in r.json():
        if c["name"] == name:
            return c["id"]
    return None

def get_products(cat_id):
    items, page = [], 1
    while True:
        r = wcapi.get("products", params={"category": cat_id, "per_page": 100, "page": page, "status": "any"})
        batch = r.json()
        if not batch:
            break
        items.extend(batch)
        page += 1
        time.sleep(0.2)
    return items

# חלווה → חטיפים מלוחים ומתוקים
chalva_id   = get_cat_id("חלווה")
snacks_id   = get_cat_id("חטיפים מלוחים ומתוקים")
kitchen_id  = get_cat_id("כלי מטבח, תבניות ושקיות")

print(f"חלווה id={chalva_id}, חטיפים id={snacks_id}, כלי מטבח id={kitchen_id}")

if chalva_id and snacks_id:
    products = get_products(chalva_id)
    print(f"מעביר {len(products)} מוצרים מחלווה → חטיפים...")
    for p in products:
        new_cats = [{"id": snacks_id if c["id"] == chalva_id else c["id"]} for c in p["categories"]]
        wcapi.put(f"products/{p['id']}", {"categories": new_cats})
        print(f"  ✓ {p['name']}")
        time.sleep(0.2)
    wcapi.delete(f"products/categories/{chalva_id}", params={"force": True})
    print("✓ קטגוריית חלווה נמחקה")

if kitchen_id:
    wcapi.delete(f"products/categories/{kitchen_id}", params={"force": True})
    print("✓ קטגוריית כלי מטבח, תבניות ושקיות נמחקה")
