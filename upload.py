"""
WooCommerce Product Uploader — shushka.co.il
דגנים וקטניות

Usage:
    python upload.py           # upload all products
    python upload.py --dry-run # preview only, no API calls
"""

import os
import sys
import time
import json
import logging
import argparse
from datetime import datetime

import requests
from dotenv import load_dotenv
from woocommerce import API

from products import PRODUCTS, CATEGORY_NAME

load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(f"upload_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log"),
    ],
)
log = logging.getLogger(__name__)

# ── WooCommerce client ────────────────────────────────────────────────────────

wcapi = API(
    url=os.getenv("WC_URL", "").rstrip("/"),
    consumer_key=os.getenv("WC_CONSUMER_KEY", ""),
    consumer_secret=os.getenv("WC_CONSUMER_SECRET", ""),
    version="wc/v3",
    timeout=60,
)

UNSPLASH_KEY = os.getenv("UNSPLASH_ACCESS_KEY", "")

# ── Helpers ───────────────────────────────────────────────────────────────────

def _check_env():
    missing = [k for k in ("WC_URL", "WC_CONSUMER_KEY", "WC_CONSUMER_SECRET")
               if not os.getenv(k)]
    if missing:
        log.error("Missing env vars: %s — copy .env.example to .env and fill in values.", missing)
        sys.exit(1)
    if not UNSPLASH_KEY:
        log.warning("UNSPLASH_ACCESS_KEY not set — products will be created without images.")


def _wc_get(endpoint, **params):
    """GET from WooCommerce, return parsed JSON."""
    resp = wcapi.get(endpoint, params=params)
    resp.raise_for_status()
    return resp.json()


def _wc_post(endpoint, data):
    """POST to WooCommerce, return parsed JSON."""
    resp = wcapi.post(endpoint, data)
    if resp.status_code not in (200, 201):
        raise RuntimeError(f"WC POST {endpoint} → {resp.status_code}: {resp.text[:300]}")
    return resp.json()


# ── Category ──────────────────────────────────────────────────────────────────

def get_or_create_category(name: str) -> int:
    """Return category ID, creating it if it doesn't exist."""
    existing = _wc_get("products/categories", search=name, per_page=100)
    for cat in existing:
        if cat["name"] == name:
            log.info("Category '%s' already exists (id=%s).", name, cat["id"])
            return cat["id"]

    cat = _wc_post("products/categories", {"name": name})
    log.info("Created category '%s' (id=%s).", name, cat["id"])
    return cat["id"]


# ── Image search ──────────────────────────────────────────────────────────────

def find_unsplash_image(search_term: str) -> str | None:
    """Return the URL of the first Unsplash result for search_term."""
    if not UNSPLASH_KEY:
        return None
    try:
        resp = requests.get(
            "https://api.unsplash.com/search/photos",
            params={
                "query": search_term,
                "per_page": 1,
                "orientation": "squarish",
                "client_id": UNSPLASH_KEY,
            },
            timeout=15,
        )
        resp.raise_for_status()
        results = resp.json().get("results", [])
        if results:
            url = results[0]["urls"]["regular"]
            log.info("  Image found: %s", url[:80])
            return url
    except Exception as exc:
        log.warning("  Unsplash search failed for '%s': %s", search_term, exc)
    return None


# ── Product creation ──────────────────────────────────────────────────────────

def product_exists(slug: str) -> bool:
    """Return True if a product with this slug already exists."""
    results = _wc_get("products", slug=slug, per_page=1)
    return bool(results)


def build_product_payload(p: dict, category_id: int) -> dict:
    """Build the WooCommerce product dict from our product definition."""
    payload = {
        "name": p["name"],
        "slug": p["slug"],
        "type": "simple",
        "status": "publish",
        "description": p["description"],
        "short_description": p["short_description"],
        "regular_price": p["price"],
        "sku": p.get("sku", ""),
        "categories": [{"id": category_id}],
        "manage_stock": True,
        "stock_quantity": 100,
        "weight": p.get("weight", ""),
        "attributes": [
            {
                "name": "משקל",
                "position": 0,
                "visible": True,
                "variation": False,
                "options": [f"{p.get('weight', '500')} גרם"],
            }
        ],
    }

    image_url = find_unsplash_image(p["search_term"])
    if image_url:
        payload["images"] = [{"src": image_url, "alt": p["name"]}]

    return payload


def upload_product(p: dict, category_id: int, dry_run: bool = False) -> dict | None:
    """Upload a single product; skip if it already exists."""
    log.info("─" * 60)
    log.info("Product: %s (%s)", p["name"], p["slug"])

    if product_exists(p["slug"]):
        log.info("  SKIP — product already exists.")
        return None

    payload = build_product_payload(p, category_id)

    if dry_run:
        log.info("  DRY RUN — would create:\n%s", json.dumps(payload, ensure_ascii=False, indent=2))
        return payload

    result = _wc_post("products", payload)
    log.info("  CREATED — id=%s, url=%s", result.get("id"), result.get("permalink"))
    return result


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Upload cereals & legumes to WooCommerce.")
    parser.add_argument("--dry-run", action="store_true", help="Preview only — no API writes.")
    args = parser.parse_args()

    _check_env()

    log.info("=" * 60)
    log.info("WooCommerce uploader — %s", os.getenv("WC_URL"))
    log.info("Mode: %s", "DRY RUN" if args.dry_run else "LIVE")
    log.info("Products to process: %d", len(PRODUCTS))
    log.info("=" * 60)

    if not args.dry_run:
        category_id = get_or_create_category(CATEGORY_NAME)
    else:
        category_id = 0  # placeholder for dry run

    results = {"created": [], "skipped": [], "failed": []}

    for p in PRODUCTS:
        try:
            result = upload_product(p, category_id, dry_run=args.dry_run)
            if result is None:
                results["skipped"].append(p["name"])
            else:
                results["created"].append(p["name"])
        except Exception as exc:
            log.error("  FAILED to upload '%s': %s", p["name"], exc)
            results["failed"].append(p["name"])

        # Be polite to the API — 1 second between requests
        time.sleep(1)

    log.info("=" * 60)
    log.info("Done.")
    log.info("  Created : %d — %s", len(results["created"]), results["created"])
    log.info("  Skipped : %d — %s", len(results["skipped"]), results["skipped"])
    log.info("  Failed  : %d — %s", len(results["failed"]), results["failed"])
    log.info("=" * 60)

    if results["failed"]:
        sys.exit(1)


if __name__ == "__main__":
    main()
