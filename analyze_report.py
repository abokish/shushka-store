"""מנתח את קובץ הדוח של categorize.py."""
import csv, sys
from collections import Counter

fname = "categorize_report_20260517_191152.csv"

total = uncertain_not_updated = updated = uncertain_updated = not_updated = 0
uncertain_list = []

with open(fname, encoding="utf-8-sig") as f:
    reader = csv.DictReader(f)
    for row in reader:
        total += 1
        u = row["uncertain"] == "True"
        up = row["updated"] == "True"
        if up:
            updated += 1
            if u:
                uncertain_updated += 1
        else:
            not_updated += 1
            if u:
                uncertain_not_updated += 1
                uncertain_list.append(row)

print(f"סה\"כ מוצרים בדוח: {total}")
print(f"עודכנו:             {updated}")
print(f"  מתוכם uncertain:  {uncertain_updated}  (עודכנו אבל AI לא היה בטוח)")
print(f"לא עודכנו:          {not_updated}")
print(f"  מתוכם uncertain:  {uncertain_not_updated}  (צריך בדיקה ידנית)")
print()
if uncertain_list:
    print("══ מוצרים שלא עודכנו ודורשים בדיקה ══")
    for r in uncertain_list[:30]:
        print(f"  id={r['id']}  '{r['name']}'")
        print(f"         ישן: {r['old_category']}  →  מוצע: {r['new_category']}")
    if len(uncertain_list) > 30:
        print(f"  ... ועוד {len(uncertain_list)-30} מוצרים")
