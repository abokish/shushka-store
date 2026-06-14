"""Quick diagnostic: how many products have descriptions?"""
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

products, page = [], 1
while True:
    resp = wcapi.get("products", params={"per_page": 100, "page": page, "status": "any"})
    batch = resp.json()
    if not batch:
        break
    products.extend(batch)
    print(f"  Fetched page {page} ({len(products)} total)...")
    page += 1
    time.sleep(0.3)

has_short = sum(1 for p in products if p.get("short_description", "").strip())
has_long  = sum(1 for p in products if p.get("description", "").strip())
has_any   = sum(1 for p in products if p.get("short_description", "").strip() or p.get("description", "").strip())
total     = len(products)

print(f"\nTotal products : {total}")
print(f"Has short desc : {has_short}  ({has_short*100//total}%)")
print(f"Has long desc  : {has_long}   ({has_long*100//total}%)")
print(f"Has any desc   : {has_any}    ({has_any*100//total}%)")
print(f"No description : {total - has_any}  ({(total-has_any)*100//total}%)")
