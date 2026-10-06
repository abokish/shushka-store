"""
import_pos_customers.py
מייבא לקוחות מקובץ ייצוא הקופה ל-WooCommerce.
קובץ קלט: all_cosutmers.csv (מיוצא מהקופה)
עמודות: קוד לקוח, שם לקוח, יתרה, פעיל, תאריך ביצוע, תאריך תשלום, על-חשבון, יתרה על-חשבון, אמייל, טלפון

ריצות חוזרות בטוחות — מדלג על לקוחות שכבר יובאו.
"""

import urllib.request, urllib.parse, json, base64, os, csv, sys, re, secrets, string, time

# ── env ──────────────────────────────────────────────────────────────
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
WC_AUTH    = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WC_HEADERS = {"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json"}
WC_BASE    = _ENV.get('WC_BASE_URL', 'https://shushka.co.il/wp-json/wc/v3')

# ── WooCommerce API ───────────────────────────────────────────────────
def wc_request(path, method='GET', body=None):
    url  = f"{WC_BASE}/{path}"
    data = json.dumps(body, ensure_ascii=False).encode('utf-8') if body else None
    req  = urllib.request.Request(url, data=data, headers=WC_HEADERS, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        raise Exception(f"HTTP {e.code}: {e.read().decode('utf-8', errors='replace')}")

def find_customer_by_email(email):
    """Returns WC customer ID or 0."""
    try:
        results = wc_request(f"customers?email={urllib.parse.quote(email)}&per_page=1")
        return results[0]['id'] if results else 0
    except Exception:
        return 0

def cashier_code_set(wc_id, code):
    """Check if _cashier_code is already set on this WC customer."""
    try:
        cust = wc_request(f"customers/{wc_id}")
        return any(m['key'] == '_cashier_code' and m['value'] == str(code)
                   for m in cust.get('meta_data', []))
    except Exception:
        return False

def create_customer(code, first, last, email, phone):
    passwd = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(20))
    payload = {
        "email":      email,
        "username":   f"pos_{code}",
        "first_name": first,
        "last_name":  last,
        "password":   passwd,
        "billing": {"first_name": first, "last_name": last, "phone": phone},
        "meta_data":  [{"key": "_cashier_code", "value": str(code)}],
    }
    return wc_request("customers", "POST", payload)

def update_customer(wc_id, code, phone):
    payload = {"meta_data": [{"key": "_cashier_code", "value": str(code)}]}
    if phone:
        payload["billing"] = {"phone": phone}
    return wc_request(f"customers/{wc_id}", "PUT", payload)

# ── helpers ───────────────────────────────────────────────────────────
def normalize_phone(raw):
    digits = re.sub(r'[^\d]', '', raw)
    if not digits:
        return ''
    if len(digits) == 9 and digits.startswith('5'):
        digits = '0' + digits          # missing leading 0
    if digits.startswith('972') and len(digits) == 12:
        digits = '0' + digits[3:]      # strip country code
    return digits

def split_name(full):
    parts = full.strip().split(' ', 1)
    return parts[0], (parts[1] if len(parts) > 1 else '')

# ── main ──────────────────────────────────────────────────────────────
def main():
    csv_files = [f for f in os.listdir('.')
                 if 'customer' in f.lower() and f.endswith('.csv')]
    if not csv_files:
        print("לא נמצא קובץ *customer*.csv בתיקייה")
        sys.exit(1)
    csv_file = max(csv_files, key=os.path.getmtime)
    print(f"קורא: {csv_file}\n")

    with open(csv_file, encoding='utf-8-sig') as f:
        rows = list(csv.reader(f))

    headers   = rows[0] if rows else []
    data_rows = rows[1:]
    print(f"עמודות: {headers}")
    print(f"נמצאו {len(data_rows)} שורות\n")

    created = updated = skipped = failed = 0

    for i, row in enumerate(data_rows, 1):
        if len(row) < 2:
            skipped += 1
            continue

        code  = row[0].strip()
        name  = row[1].strip()
        email = row[8].strip() if len(row) > 8 else ''
        phone = normalize_phone(row[9].strip() if len(row) > 9 else '')

        # דלג על שורות ריקות ועל קוד 999 (כללי)
        if not code or not name or code == '999':
            skipped += 1
            continue

        first, last = split_name(name)
        # placeholder אם אין אמייל אמיתי
        use_email = email if email else f"pos_{code}@shushka-pos.local"

        print(f"[{i}/{len(data_rows)}] #{code} {name[:35]}", end=' ... ', flush=True)

        try:
            existing_id = find_customer_by_email(use_email)

            if existing_id:
                if cashier_code_set(existing_id, code):
                    print("קיים ✓, מדלג")
                    skipped += 1
                else:
                    update_customer(existing_id, code, phone)
                    print(f"עדכון WC#{existing_id} ✓")
                    updated += 1
            else:
                create_customer(code, first, last, use_email, phone)
                print("נוצר ✓")
                created += 1

            time.sleep(0.3)

        except Exception as e:
            print(f"✗ {e}")
            failed += 1
            time.sleep(1)

    print(f"\n{'='*50}")
    print(f"נוצרו: {created}  |  עודכנו: {updated}  |  דולגו: {skipped}  |  שגיאות: {failed}")

if __name__ == '__main__':
    main()
