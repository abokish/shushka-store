#!/usr/bin/env python3
"""
שושקה - כלי ניהול מוצרים
הרצה: python review_app.py
נפתח אוטומטית ב: http://localhost:5000
"""

from flask import Flask, jsonify, request
import urllib.request, urllib.parse, urllib.error
import json, base64, webbrowser, threading, time, os, traceback

app = Flask(__name__)

def _load_env():
    env = {}
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
    with open(p, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1); env[k.strip()] = v.strip()
    return env
_ENV = _load_env()
WC_AUTH = base64.b64encode(f"{_ENV['WC_CONSUMER_KEY']}:{_ENV['WC_CONSUMER_SECRET']}".encode()).decode()
WP_AUTH = base64.b64encode(f"{_ENV['WP_ADMIN_USERNAME']}:{_ENV['WP_ADMIN_PASSWORD']}".encode()).decode()
WC_BASE = f"{_ENV['WC_URL']}/wp-json/wc/v3"
WP_BASE = f"{_ENV['WC_URL']}/wp-json/wp/v2"

SHIPPING_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "shipping_config.json")


def wc_req(path, method="GET", data=None):
    req = urllib.request.Request(
        WC_BASE + path,
        data=json.dumps(data, ensure_ascii=False).encode("utf-8") if data else None,
        headers={"Authorization": f"Basic {WC_AUTH}", "Content-Type": "application/json; charset=utf-8"},
        method=method,
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        body = json.loads(r.read())
        hdrs = r.headers  # HTTPMessage — case-insensitive .get()
        return body, hdrs


def wp_req(path, method="GET", data=None):
    req = urllib.request.Request(
        WP_BASE + path,
        data=json.dumps(data, ensure_ascii=False).encode("utf-8") if data else None,
        headers={"Authorization": f"Basic {WP_AUTH}", "Content-Type": "application/json; charset=utf-8"},
        method=method,
    )
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read())


# ─── Global error handler — always returns JSON ───────────────────────────────
@app.errorhandler(Exception)
def handle_exception(e):
    traceback.print_exc()
    return jsonify({"success": False, "error": str(e)}), 500


# ─── Routes ───────────────────────────────────────────────────────────────────

@app.route("/")
def index():
    return HTML


@app.route("/api/categories")
def get_categories():
    all_cats, page = [], 1
    while True:
        data, hdrs = wc_req(f"/products/categories?per_page=100&page={page}&orderby=name")
        all_cats.extend(data)
        if page >= int(hdrs.get("X-WP-TotalPages", 1)):
            break
        page += 1
    return jsonify(all_cats)


@app.route("/api/products")
def get_products():
    page = request.args.get("page", 1)
    per_page = request.args.get("per_page", 50)
    category = request.args.get("category", "")
    search = request.args.get("search", "")

    path = f"/products?per_page={per_page}&page={page}&status=publish&orderby=id&order=asc"
    if category:
        path += f"&category={category}"
    if search:
        path += f"&search={urllib.parse.quote(search)}"

    data, hdrs = wc_req(path)
    return jsonify({
        "products": data,
        "total": hdrs.get("X-WP-Total", 0),
        "total_pages": hdrs.get("X-WP-TotalPages", 1),
    })


@app.route("/api/products/<int:pid>", methods=["PUT"])
def update_product(pid):
    body = request.json
    update = {}
    if "name" in body:
        update["name"] = body["name"]
    if "categories" in body:
        update["categories"] = body["categories"]
    wc_req(f"/products/{pid}", "PUT", update)
    return jsonify({"success": True})


# ─── Shipping notice ──────────────────────────────────────────────────────────

def load_shipping():
    if os.path.exists(SHIPPING_FILE):
        with open(SHIPPING_FILE, encoding="utf-8") as f:
            return json.load(f)
    return {"date": "", "extra": "", "widget_id": "block-26"}


def save_shipping_cfg(cfg):
    with open(SHIPPING_FILE, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)


def make_banner(date, extra=""):
    extra_html = f'<br><span style="font-size:.85rem;opacity:.85">{extra}</span>' if extra else ""
    return f"""<!-- wp:html -->
<div id="sk-ship-bar" style="display:none;background:#2d5a27;color:#fff;padding:.9rem 8rem .9rem 1.5rem;text-align:center;font-family:Rubik,sans-serif;font-size:1.05rem;position:relative;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.25);line-height:1.5">
  &#x1F4E6; <strong>המשלוח הבא: {date}</strong>&nbsp;&nbsp;|&nbsp;&nbsp;&#x1F69A; משלוח רק 10&#8362;&nbsp;&nbsp;|&nbsp;&nbsp;חינם מהזמנה מעל 380&#8362;{extra_html}
  <button onclick="document.getElementById('sk-ship-bar').style.display='none';sessionStorage.setItem('sk_sb','1')" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);border-radius:6px;color:#fff;font-size:.85rem;font-family:Rubik,sans-serif;padding:.3rem .7rem;cursor:pointer;font-weight:600;white-space:nowrap" aria-label="סגור">הבנתי ✓</button>
</div>
<script>
(function(){{if(sessionStorage.getItem('sk_sb'))return;var b=document.getElementById('sk-ship-bar');if(!b)return;b.style.display='block';document.body.prepend?document.body.prepend(b):document.body.insertBefore(b,document.body.firstChild);}})();
</script>
<!-- /wp:html -->"""


def push_banner(content):
    """Update banner widget content via PUT. Uses ensure_ascii=False to handle emojis."""
    cfg = load_shipping()
    widget_id = cfg.get("widget_id", "block-26")
    wp_req(f"/widgets/{widget_id}", "PUT", {
        "sidebar": "footer1",
        "instance": {"raw": {"content": content}},
    })


@app.route("/api/shipping", methods=["GET"])
def get_shipping():
    return jsonify(load_shipping())


@app.route("/api/shipping", methods=["PUT"])
def update_shipping():
    try:
        data = request.json or {}
        date  = (data.get("date")  or "").strip()
        extra = (data.get("extra") or "").strip()

        if not date:
            return jsonify({"success": False, "error": "יש להזין תאריך"}), 400

        cfg = load_shipping()
        cfg.update({"date": date, "extra": extra})
        save_shipping_cfg(cfg)

        push_banner(make_banner(date, extra))
        return jsonify({"success": True})

    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="ignore")
        traceback.print_exc()
        return jsonify({"success": False, "error": f"HTTP {e.code}: {body[:200]}"}), 500
    except Exception as e:
        traceback.print_exc()
        return jsonify({"success": False, "error": str(e)}), 500


# ─── Auto-open browser ────────────────────────────────────────────────────────

def _open():
    time.sleep(1.3)
    webbrowser.open("http://localhost:5000")


# ─── HTML ─────────────────────────────────────────────────────────────────────

HTML = r"""<!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🌿 שושקה – ניהול מוצרים</title>
<link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Rubik',sans-serif;background:#f7f4f0;color:#2b2b2b;font-size:14px}

.hdr{background:#2d5a27;color:#fff;padding:.85rem 1.5rem;display:flex;align-items:center;gap:1rem;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.hdr h1{font-size:1.2rem;font-weight:700}
.hdr-sub{font-size:.8rem;opacity:.75;margin-top:.15rem}
.mod-badge{background:#f0a500;color:#fff;border-radius:12px;padding:.2rem .75rem;font-size:.78rem;font-weight:600;display:none}
.hdr-links{margin-right:auto;display:flex;gap:1rem;align-items:center}
.hdr-links a{color:#b8e0b5;font-size:.8rem;text-decoration:none}
.hdr-links a:hover{color:#fff}

.ship-section{background:#fffde7;border-bottom:2px solid #ffe082;padding:.7rem 1.5rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.ship-section label{font-weight:600;font-size:.85rem;white-space:nowrap;color:#5a4800}
.ship-section input[type=text]{border:1px solid #ccc;border-radius:6px;padding:.38rem .65rem;font-family:inherit;font-size:.85rem}
.ship-section input:focus{outline:none;border-color:#2d5a27}
.btn-ship{background:#2d5a27;color:#fff;border:none;border-radius:6px;padding:.4rem 1rem;cursor:pointer;font-family:inherit;font-weight:600;font-size:.83rem;white-space:nowrap}
.btn-ship:hover{background:#245020}
.ship-status{font-size:.82rem;min-width:160px}
.ship-hint{font-size:.75rem;color:#a08030;margin-right:auto}

.filters{background:#fff;padding:.7rem 1.5rem;border-bottom:1px solid #e8e3dc;display:flex;gap:.55rem;align-items:center;flex-wrap:wrap}
.filters select,.filters input[type=text]{border:1px solid #d0cbc4;border-radius:6px;padding:.38rem .6rem;font-family:inherit;font-size:.84rem;background:#fff}
.filters select:focus,.filters input:focus{outline:none;border-color:#2d5a27}
.btn{border:none;border-radius:6px;padding:.38rem 1rem;cursor:pointer;font-family:inherit;font-weight:600;font-size:.83rem}
.btn-green{background:#2d5a27;color:#fff}.btn-green:hover{background:#245020}
.btn-gray{background:#eae7e2;color:#555}.btn-gray:hover{background:#ddd}
.btn-save-all{background:#f0a500;color:#fff;font-size:.85rem}.btn-save-all:hover{background:#d48f00}

.stats{background:#fff;padding:.35rem 1.5rem;border-bottom:1px solid #ede8e1;font-size:.77rem;color:#888}

.tbl-wrap{padding:1rem 1.5rem;overflow-x:auto}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07)}
thead th{background:#2d5a27;color:#fff;padding:.65rem .75rem;text-align:right;font-size:.82rem;font-weight:600;white-space:nowrap}
tbody td{padding:.45rem .6rem;border-bottom:1px solid #f3ede6;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#faf7f3}
tbody tr.modified td{background:#fff9e6}
tbody tr.saved td{background:#f0faf0}
tbody tr.err td{background:#fff0f0}

.num{color:#aaa;font-size:.75rem;text-align:center}

/* Name input — visually looks like an editable field */
.inp-name{
  border:1px solid #d8d0c8;
  border-radius:5px;
  padding:.32rem .5rem;
  font-family:inherit;
  font-size:.84rem;
  width:100%;
  min-width:180px;
  background:#fafaf8;
  transition:.15s;
  color:#2b2b2b;
}
.inp-name:hover{border-color:#aaa;background:#fff}
.inp-name:focus{border-color:#2d5a27;background:#fff;outline:none;box-shadow:0 0 0 2px rgba(45,90,39,.12)}

.sku{color:#aaa;font-size:.76rem;font-family:monospace;white-space:nowrap}
select.sel{border:1px solid #ddd;border-radius:5px;padding:.3rem .45rem;font-family:inherit;font-size:.8rem;width:100%;min-width:120px;background:#fff;cursor:pointer}
select.sel:focus{border-color:#2d5a27;outline:none}
.btn-save{background:#e8f5e9;color:#2d5a27;border:1px solid #a5d6a7;border-radius:5px;padding:.3rem .8rem;cursor:pointer;font-family:inherit;font-size:.8rem;font-weight:600;transition:.15s;white-space:nowrap}
.btn-save:hover{background:#2d5a27;color:#fff;border-color:#2d5a27}
.btn-save.saving{background:#fff9c4;color:#e65100;border-color:#ffd54f;cursor:wait}
.btn-save.ok{background:#c8e6c9;color:#1b5e20;border-color:#66bb6a}
.btn-save.fail{background:#ffcdd2;color:#b71c1c;border-color:#ef9a9a}

.pager{display:flex;gap:.4rem;padding:.9rem 1.5rem;justify-content:center;align-items:center;flex-wrap:wrap}
.pager button{background:#fff;border:1px solid #d0cbc4;border-radius:5px;padding:.35rem .7rem;cursor:pointer;font-family:inherit;font-size:.82rem;min-width:34px}
.pager button:hover{background:#f0ebe4}
.pager button.cur{background:#2d5a27;color:#fff;border-color:#2d5a27}
.pager button:disabled{opacity:.35;cursor:default}
.pg-info{font-size:.78rem;color:#888;padding:0 .5rem}

.loading-msg{text-align:center;padding:3rem;color:#888;font-size:1rem}
.toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:.6rem 1.4rem;border-radius:8px;font-size:.87rem;z-index:9999;opacity:0;transition:.25s;pointer-events:none;white-space:nowrap}
.toast.show{opacity:1}
</style>
</head>
<body>

<div class="hdr">
  <div>
    <h1>🌿 שושקה – ניהול מוצרים</h1>
    <div class="hdr-sub" id="total-lbl">טוען...</div>
  </div>
  <span class="mod-badge" id="mod-badge">0 שינויים ממתינים</span>
  <div class="hdr-links">
    <a href="https://shushka.co.il/shop/" target="_blank">👁 חנות ↗</a>
    <a href="https://shushka.co.il/wp-admin/" target="_blank">⚙ WP Admin ↗</a>
  </div>
</div>

<!-- Shipping notice -->
<div class="ship-section">
  <label>📦 באנר משלוח:</label>
  <input id="ship-date" type="text" placeholder="תאריך — למשל: יום שלישי 24.6" style="width:215px">
  <input id="ship-extra" type="text" placeholder="הערה נוספת (אופציונלי)" style="width:255px">
  <button class="btn-ship" onclick="updateShipping()">עדכן באנר בחנות &#8593;</button>
  <span class="ship-status" id="ship-status"></span>
  <span class="ship-hint">לבדיקה: פתח חלון גלישה פרטי</span>
</div>

<!-- Filters -->
<div class="filters">
  <select id="f-cat" onchange="onParentChange()">
    <option value="">כל הקטגוריות</option>
  </select>
  <select id="f-sub">
    <option value="">כל תת-הקטגוריות</option>
  </select>
  <input id="f-search" type="text" placeholder="חיפוש לפי שם מוצר..." style="width:225px"
    onkeydown="if(event.key==='Enter')load(1)">
  <button class="btn btn-green" onclick="load(1)">&#x1F50D; חפש</button>
  <button class="btn btn-gray" onclick="clearFilters()">נקה</button>
  <button class="btn btn-save-all" id="btn-save-all" onclick="saveAll()" style="display:none">&#x2714; שמור את כל השינויים</button>
</div>
<div class="stats" id="stats-bar">–</div>

<div class="tbl-wrap">
  <div class="loading-msg" id="loading">⏳ טוען מוצרים...</div>
  <table id="tbl" style="display:none">
    <thead><tr>
      <th style="width:38px">#</th>
      <th style="min-width:220px">✏ שם מוצר (לחץ לעריכה)</th>
      <th style="width:85px">SKU</th>
      <th style="min-width:145px">קטגוריה</th>
      <th style="min-width:160px">תת-קטגוריה</th>
      <th style="width:72px"></th>
    </tr></thead>
    <tbody id="tbody"></tbody>
  </table>
</div>
<div class="pager" id="pager"></div>
<div class="toast" id="toast"></div>

<script>
let cats = [], parents = [], children = {};
let totalPages = 1, modified = new Set();

// ── Init ──────────────────────────────────────────────────────────────────────
async function init() {
  const res = await fetch('/api/categories');
  cats = await res.json();

  parents = cats.filter(c => c.parent === 0 && c.slug !== 'uncategorized');
  cats.filter(c => c.parent !== 0).forEach(c => {
    (children[c.parent] = children[c.parent] || []).push(c);
  });

  const sel = document.getElementById('f-cat');
  parents.forEach(c => sel.add(new Option(`${c.name} (${c.count})`, c.id)));

  load(1);
  loadShipping();
}

function onParentChange() {
  const pid = +document.getElementById('f-cat').value || 0;
  const sub = document.getElementById('f-sub');
  sub.innerHTML = '<option value="">כל תת-הקטגוריות</option>';
  (children[pid] || []).forEach(c => sub.add(new Option(`${c.name} (${c.count})`, c.id)));
  load(1);
}

// ── Load products ─────────────────────────────────────────────────────────────
async function load(page) {
  page = page || 1;
  document.getElementById('loading').style.display = 'block';
  document.getElementById('tbl').style.display = 'none';

  const cat = document.getElementById('f-sub').value || document.getElementById('f-cat').value;
  const q   = document.getElementById('f-search').value.trim();
  let url = `/api/products?page=${page}&per_page=50`;
  if (cat) url += `&category=${cat}`;
  if (q)   url += `&search=${encodeURIComponent(q)}`;

  const res  = await fetch(url);
  const data = await res.json();
  totalPages = +data.total_pages || 1;
  const total = +data.total || 0;

  document.getElementById('total-lbl').textContent = `${total} מוצרים בסך הכל`;
  document.getElementById('stats-bar').textContent =
    `עמוד ${page} מתוך ${totalPages} | ${data.products.length} מוצרים מוצגים מתוך ${total}`;

  render(data.products, page);
  renderPager(page, totalPages);
  document.getElementById('loading').style.display = 'none';
  document.getElementById('tbl').style.display = 'table';
}

// ── Render rows ───────────────────────────────────────────────────────────────
function render(prods, page) {
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';

  prods.forEach((p, i) => {
    let parentId = 0, subId = 0;
    (p.categories || []).forEach(pc => {
      const full = cats.find(c => c.id === pc.id);
      if (!full) return;
      if (full.parent === 0) { if (!subId) parentId = full.id; }
      else { subId = full.id; parentId = full.parent; }
    });

    const tr = document.createElement('tr');
    tr.dataset.pid = p.id;

    // Format SKU: remove trailing .0 or .00 from numeric SKUs
    const skuRaw = p.sku || '';
    const skuNum = parseFloat(skuRaw);
    const sku = skuRaw && !isNaN(skuNum) ? String(Math.round(skuNum)) : (skuRaw || '–');

    tr.innerHTML = `
      <td class="num">${(page-1)*50+i+1}</td>
      <td><input class="inp-name" type="text" value="${esc(p.name)}" oninput="mark(this)" title="לחץ לעריכת השם"></td>
      <td class="sku">${esc(sku)}</td>
      <td>${buildCatSel(parentId)}</td>
      <td>${buildSubSel(parentId, subId)}</td>
      <td><button class="btn-save" onclick="save(this)">שמור</button></td>
    `;
    tbody.appendChild(tr);

    tr.querySelector('select.parent-sel').addEventListener('change', function() {
      const pid2 = +this.value || 0;
      const sub  = tr.querySelector('select.sub-sel');
      sub.innerHTML = '<option value="">— תת-קטגוריה —</option>';
      (children[pid2] || []).forEach(c => sub.add(new Option(c.name, c.id)));
      mark(this);
    });
    tr.querySelector('select.sub-sel').addEventListener('change', function() { mark(this); });
  });
}

function buildCatSel(selected) {
  let h = '<select class="sel parent-sel"><option value="">— קטגוריה —</option>';
  parents.forEach(c => { h += `<option value="${c.id}"${c.id===selected?' selected':''}>${c.name}</option>`; });
  return h + '</select>';
}

function buildSubSel(parentId, selected) {
  let h = '<select class="sel sub-sel"><option value="">— תת-קטגוריה —</option>';
  (children[parentId] || []).forEach(c => { h += `<option value="${c.id}"${c.id===selected?' selected':''}>${c.name}</option>`; });
  return h + '</select>';
}

// ── Save row ──────────────────────────────────────────────────────────────────
async function save(btn) {
  const tr     = btn.closest('tr');
  const pid    = +tr.dataset.pid;
  const name   = tr.querySelector('.inp-name').value.trim();
  const parId  = +tr.querySelector('.parent-sel').value || 0;
  const subId  = +tr.querySelector('.sub-sel').value   || 0;
  const catId  = subId || parId;

  btn.textContent = '...'; btn.className = 'btn-save saving';
  tr.classList.remove('modified', 'saved', 'err');

  try {
    const r = await fetch(`/api/products/${pid}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, categories: catId ? [{ id: catId }] : [] })
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'שגיאה לא ידועה');

    tr.classList.add('saved');
    btn.textContent = '✓'; btn.className = 'btn-save ok';
    modified.delete(String(pid)); refreshBadge();
    setTimeout(() => { btn.textContent = 'שמור'; btn.className = 'btn-save'; tr.classList.remove('saved'); }, 2200);
  } catch(e) {
    tr.classList.add('err');
    btn.textContent = '✗'; btn.className = 'btn-save fail';
    toast('שגיאה בשמירה: ' + e.message);
    setTimeout(() => { btn.textContent = 'שמור'; btn.className = 'btn-save'; }, 3000);
  }
}

function mark(el) {
  const tr = el.closest('tr');
  tr.classList.add('modified');
  modified.add(tr.dataset.pid);
  refreshBadge();
}

function refreshBadge() {
  const b = document.getElementById('mod-badge');
  const btn = document.getElementById('btn-save-all');
  b.style.display = modified.size ? 'inline' : 'none';
  b.textContent = modified.size + ' שינויים ממתינים';
  btn.style.display = modified.size ? 'inline' : 'none';
  btn.textContent = '✔ שמור את כל השינויים (' + modified.size + ')';
}

async function saveAll() {
  const btn = document.getElementById('btn-save-all');
  const rows = [...document.querySelectorAll('tbody tr.modified')];
  if (!rows.length) return;
  btn.disabled = true;
  btn.textContent = 'שומר...';
  for (const tr of rows) {
    await save(tr.querySelector('.btn-save'));
  }
  btn.disabled = false;
  btn.style.display = 'none';
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPager(page, total) {
  const p = document.getElementById('pager');
  p.innerHTML = '';
  if (total <= 1) return;

  const btn = (txt, pg, cls) => {
    const b = document.createElement('button');
    b.textContent = txt; if (cls) b.className = cls;
    b.disabled = (pg < 1 || pg > total);
    b.onclick = () => load(pg);
    p.appendChild(b);
  };

  btn('→', page + 1);
  if (page > 3) { btn('1', 1); if (page > 4) p.innerHTML += '<span style="padding:0 .3rem">…</span>'; }
  for (let i = Math.max(1, page-2); i <= Math.min(total, page+2); i++)
    btn(i, i, i === page ? 'cur' : '');
  if (page < total-2) { if (page < total-3) p.innerHTML += '<span style="padding:0 .3rem">…</span>'; btn(total, total); }
  btn('←', page - 1);

  const info = document.createElement('span');
  info.className = 'pg-info';
  info.textContent = `עמוד ${page} / ${total}`;
  p.appendChild(info);
}

// ── Shipping ──────────────────────────────────────────────────────────────────
async function loadShipping() {
  try {
    const r = await fetch('/api/shipping');
    const d = await r.json();
    if (d.date)  document.getElementById('ship-date').value  = d.date;
    if (d.extra) document.getElementById('ship-extra').value = d.extra;
  } catch(e) {}
}

async function updateShipping() {
  const date  = document.getElementById('ship-date').value.trim();
  const extra = document.getElementById('ship-extra').value.trim();
  const st    = document.getElementById('ship-status');

  if (!date) { toast('יש להזין תאריך משלוח'); return; }

  st.textContent = 'מעדכן...'; st.style.color = '#888';
  try {
    const r = await fetch('/api/shipping', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ date, extra })
    });
    const d = await r.json();
    if (d.success) {
      st.textContent = '✓ הבאנר עודכן בחנות!'; st.style.color = '#2d5a27';
      setTimeout(() => st.textContent = '', 5000);
    } else {
      st.textContent = '✗ ' + (d.error || 'שגיאה'); st.style.color = '#c62828';
    }
  } catch(e) {
    st.textContent = '✗ ' + e.message; st.style.color = '#c62828';
  }
}

// ── Utils ─────────────────────────────────────────────────────────────────────
function clearFilters() {
  document.getElementById('f-cat').value = '';
  document.getElementById('f-sub').innerHTML = '<option value="">כל תת-הקטגוריות</option>';
  document.getElementById('f-search').value = '';
  load(1);
}

function esc(s) {
  return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3500);
}

init();
</script>
</body>
</html>"""


if __name__ == "__main__":
    print()
    print("🌿 שושקה – כלי ניהול מוצרים")
    print("=" * 45)
    print("✓ מפעיל שרת ב: http://localhost:5000")
    print("  לסגירה: Ctrl+C")
    print()
    threading.Thread(target=_open, daemon=True).start()
    app.run(debug=False, port=5000, host="127.0.0.1", threaded=True)
