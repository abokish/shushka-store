"""List all WooCommerce categories with product counts."""
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

cats, page = [], 1
while True:
    resp = wcapi.get("products/categories", params={"per_page": 100, "page": page})
    batch = resp.json()
    if not batch:
        break
    cats.extend(batch)
    page += 1
    time.sleep(0.2)

# Build id→name map for parent lookup
id_to_name = {c["id"]: c["name"] for c in cats}

# Separate parents and children
parents = sorted([c for c in cats if c["parent"] == 0], key=lambda c: c["name"])
children = sorted([c for c in cats if c["parent"] != 0], key=lambda c: (c["parent"], c["name"]))

print(f"\nTotal categories: {len(cats)}")
print(f"{'='*60}")

print("\n── קטגוריות אב ──")
for c in parents:
    marker = "  [ריקה]" if c["count"] == 0 else ""
    print(f"  [{c['count']:>4} מוצרים]  {c['name']}{marker}")

print("\n── תתי-קטגוריות ──")
for c in children:
    parent_name = id_to_name.get(c["parent"], "?")
    marker = "  [ריקה]" if c["count"] == 0 else ""
    print(f"  [{c['count']:>4} מוצרים]  {parent_name}  →  {c['name']}{marker}")

# Duplicates check
from collections import Counter
name_counts = Counter(c["name"] for c in cats)
dupes = {name: count for name, count in name_counts.items() if count > 1}
if dupes:
    print(f"\n── כפילויות ──")
    for name, count in sorted(dupes.items()):
        print(f"  '{name}' מופיע {count} פעמים")
else:
    print("\nאין כפילויות.")
