"""בודק חיבור ל-WordPress REST API ומציג רשימת דפים."""
import os, requests
from dotenv import load_dotenv

load_dotenv()

BASE = os.getenv("WC_URL", "").rstrip("/")
USER = os.getenv("WP_ADMIN_USERNAME", "")
PASS = os.getenv("WP_ADMIN_PASSWORD", "")
auth = (USER, PASS)

HEADERS = {
    "Accept": "application/json",
    "Content-Type": "application/json",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
}

def wp_get(endpoint, params=None):
    r = requests.get(f"{BASE}/wp-json/wp/v2/{endpoint}", auth=auth, params=params,
                     headers=HEADERS, timeout=30)
    return r

# בדיקת חיבור
r = wp_get("users/me")
print(f"Status: {r.status_code}")
print(f"Response: {r.text[:500]}")
if r.status_code == 200:
    me = r.json()
    if "name" in me:
        print(f"✓ מחובר כ: {me['name']} ({me.get('slug', '')})\n")
    else:
        print(f"✗ תגובה לא צפויה: {me}")
        exit(1)
else:
    print(f"✗ שגיאת חיבור: {r.status_code} — {r.text[:200]}")
    exit(1)

# שלוף דפים
print("=" * 55)
print(f"{'ID':<6} {'סטטוס':<12} {'כתובת URL':<30} שם")
print("=" * 55)

pages, page = [], 1
while True:
    r = wp_get("pages", {"per_page": 100, "page": page})
    if r.status_code != 200:
        break
    batch = r.json()
    if not isinstance(batch, list) or not batch:
        break
    pages.extend(batch)
    page += 1

for p in sorted(pages, key=lambda x: x.get("menu_order", 0)):
    slug = p.get("slug", "")
    status = p.get("status", "")
    title = p.get("title", {}).get("rendered", "")
    pid = p["id"]
    print(f"{pid:<6} {status:<12} /{slug:<30} {title}")

print(f"\nסה\"כ: {len(pages)} דפים")
