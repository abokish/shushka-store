"""הצג את כל תתי-הקטגוריות עם מספר מוצרים, ממוין מהקטנה לגדולה."""
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

id_to_name = {c["id"]: c["name"] for c in cats}

# רק תתי-קטגוריות (יש להן הורה)
children = [c for c in cats if c["parent"] != 0]
children.sort(key=lambda c: c["count"])

lines = []
lines.append(f"{'מוצרים':>8}  {'קטגוריית אב':<35}  תת-קטגוריה")
lines.append("─" * 75)

for c in children:
    parent = id_to_name.get(c["parent"], "?")
    flag = "  ◄ מועמדת למחיקה" if c["count"] < 5 else ""
    lines.append(f"{c['count']:>8}  {parent:<35}  {c['name']}{flag}")

output = "\n".join(lines)
print(output)

# שמור לקובץ
with open("category_counts.txt", "w", encoding="utf-8") as f:
    f.write(output)
print(f"\n[נשמר ל-category_counts.txt]")
