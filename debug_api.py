"""בודק מה ה-API מחזיר בפועל."""
import os
from dotenv import load_dotenv
from woocommerce import API

load_dotenv()

wcapi = API(
    url=os.getenv("WC_URL", "").rstrip("/"),
    consumer_key=os.getenv("WC_CONSUMER_KEY", ""),
    consumer_secret=os.getenv("WC_CONSUMER_SECRET", ""),
    version="wc/v3",
    timeout=30,
)

r = wcapi.get("products/categories", params={"per_page": 5, "page": 1})
print(f"Status: {r.status_code}")
print(f"URL: {r.url}")
print(f"Content-Type: {r.headers.get('content-type', '?')}")
print(f"Response (500 chars): {r.text[:500]}")
print(f"Type of parsed JSON: {type(r.json())}")
