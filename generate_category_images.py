"""
מייצר תמונות וואטרקולור לקטגוריות ב-DALL-E 3,
מעלה לספריית המדיה של וורדפרס ומקשר לקטגוריה.
"""

import os, io, base64, requests
from dotenv import load_dotenv
from woocommerce import API
from openai import OpenAI
from PIL import Image

load_dotenv()

WC_URL     = os.getenv("WC_URL", "").rstrip("/")
WC_KEY     = os.getenv("WC_CONSUMER_KEY")
WC_SECRET  = os.getenv("WC_CONSUMER_SECRET")
WP_USER    = os.getenv("WP_ADMIN_USERNAME")
WP_PASS    = os.getenv("WP_ADMIN_PASSWORD")
OPENAI_KEY = os.getenv("OPENAI_API_KEY")

wcapi  = API(url=WC_URL, consumer_key=WC_KEY, consumer_secret=WC_SECRET,
             version="wc/v3", timeout=30)
client = OpenAI(api_key=OPENAI_KEY)

STYLE = (
    "soft watercolor and colored-pencil illustration, warm natural earth tones, "
    "cream white background, hand-painted artistic style, no text, no labels, "
    "square composition, cozy artisan Israeli health-food store aesthetic"
)

# תיאור לכל קטגוריה — מה להציג בתמונה
PROMPTS = {
    "אקולוגי ומוצרים לבית":
        "eco-friendly home products: handmade natural soap bars, glass spray bottle, "
        "wooden brush, lavender sprigs, beeswax candle, folded linen cloth on wooden surface",

    "בישול ואפייה":
        "cooking and baking: small flour sack, olive oil bottle, wooden spoon, "
        "fresh rosemary and thyme, whole spices in small bowls, rustic arrangement",

    "דגני בוקר, גרנולה שוקולד וחטיפים":
        "granola bowl with fresh berries, dark chocolate shards, "
        "scattered almonds and dried cranberries, morning warmth",

    "דגנים קטניות ופסטות":
        "colorful assortment of whole grains, lentils, chickpeas, and pasta "
        "in small tied cloth bags arranged on a wooden shelf",

    "ירקניה":
        "fresh organic vegetables: cherry tomatoes, cucumbers, leafy herbs, "
        "colorful bell peppers arranged naturally on linen",

    "ממרחים, רטבים ושימורים":
        "artisan spreads and preserves: tahini jar, strawberry jam jar, "
        "pickled olives, olive oil bottle, arranged warmly on wood",

    "מקרר וקפוא":
        "fresh dairy and frozen foods: ceramic yogurt jar, artisan cheese wedge, "
        "frozen mixed berries with soft frost, fresh herbs",

    "משקאות":
        "natural drinks: herbal tea with dried chamomile flowers, "
        "bottle of fresh juice, kombucha bottle with botanicals",

    "עוגיות מאפים וקרקרים":
        "artisan cookies, whole-grain crackers and small pastries "
        "arranged on a rustic wooden plate with a linen napkin",

    "פירות יבשים ואגוזים":
        "wicker basket overflowing with mixed nuts and dried fruits: "
        "figs, dates, apricots, walnuts, almonds, cashews, golden raisins",

    "קוסמטיקה ורחצה טבעיים":
        "natural bath and cosmetics: handmade soap bars, essential oil bottles, "
        "dried rose petals, loofah, green herbs on marble",

    "תוספי תזונה ובריאות":
        "natural health supplements: glass jars with seeds and dried herbs, "
        "honey dipper, capsules on natural linen with botanical elements",
}

SUB_PROMPTS = {
    # אקולוגי ומוצרים לבית
    "חד פעמי, ניירות אפייה וכסף":
        "eco disposables: baking paper roll, parchment sheets, wooden cutlery, paper cups arranged on linen",
    "ניקוי אקולוגי":
        "eco cleaning supplies: natural soap bar, glass spray bottle, wooden scrub brush, "
        "baking soda in jar, lemon and vinegar bottle",

    # בישול ואפייה
    "מחלקה אסייתית":
        "asian pantry ingredients: miso paste jar, soy sauce bottle, rice noodles, "
        "sesame seeds, dried seaweed, bamboo chopsticks",
    "ממתיקים ותחליפי סוכר":
        "natural sweeteners: honey jar with honey dipper, coconut sugar in bowl, "
        "medjool dates, agave bottle, stevia leaves",
    "קמחים ועזרי אפייה":
        "baking essentials: small flour sack, baking powder tin, vanilla pod, "
        "wooden measuring spoons, whisk on linen cloth",
    "שמנים":
        "artisan cooking oils: olive oil bottle, sesame oil, coconut oil jar, "
        "flaxseed oil, arranged with fresh herbs and olives",
    "תבלינים":
        "colorful spice collection: cinnamon sticks, cardamom pods, turmeric powder, "
        "cumin seeds, dried herbs in small clay bowls",

    # דגני בוקר גרנולה
    "גרנולה ודגני בוקר":
        "granola and oats: ceramic bowl of golden granola with honey drizzle, "
        "rolled oats, dried cranberries, scattered almonds",
    "חטיפים מלוחים ומתוקים":
        "artisan snacks: seed crackers, dark chocolate covered nuts, "
        "energy balls, dried fruit bars on wooden board",
    "סוכריות":
        "natural candies: colorful fruit gummies, lollipops, licorice, "
        "arranged in a small wicker bowl",
    "שוקולד ומוצריו":
        "artisan chocolate: dark chocolate bar broken into pieces, cocoa beans, "
        "chocolate truffles, cacao powder in spoon",

    # דגנים קטניות
    "דגנים":
        "whole grains: wheat berries, brown rice, quinoa, millet, "
        "buckwheat in small wooden bowls arranged on burlap",
    "פסטות, אטריות ופתיתים":
        "pasta variety: spaghetti nest, colorful fusilli, rice noodles, "
        "couscous in bowl, arranged artfully",
    "קטניות":
        "colorful legumes: green lentils, red lentils, chickpeas, black beans, "
        "mung beans in small clay bowls",

    # ממרחים
    "ממרחי אגוזים":
        "nut butters: open jar of almond butter, peanut butter jar, tahini, "
        "scattered whole nuts and seeds around jars",
    "ממרחים מלוחים ורטבים":
        "savory spreads: hummus bowl with olive oil drizzle, olive tapenade jar, "
        "roasted pepper spread, artichoke spread",
    "ממרחים מתוקים":
        "sweet preserves: strawberry jam jar, fig jam, chocolate spread, "
        "honey jar with honeycomb, arranged with fresh berries",
    "סירופים ורטבים":
        "natural syrups: maple syrup bottle, date syrup, carob molasses, "
        "pomegranate molasses in glass bottles with wooden drizzler",
    "שימורים":
        "artisan preserves: glass jar of olives, pickled vegetables, "
        "canned tomatoes, roasted peppers in jars",

    # מקרר וקפוא
    "אוכל קפוא להכנה מהירה":
        "frozen convenience foods: vegetable dumplings, frozen falafel, "
        "veggie burger patties with frost effect",
    "גלידות":
        "natural ice cream: colorful scoops of artisan gelato in a cone, "
        "fruit popsicles, with fresh berries",
    "טופו וביצים":
        "tofu and eggs: block of fresh tofu, brown eggs in a nest, "
        "edamame beans, with green herb garnish",
    "פירות קפואים":
        "frozen fruits: frozen mixed berries, mango chunks, strawberries "
        "with frost crystals in a ceramic bowl",
    "תחליפי בשר":
        "plant-based proteins: veggie burger patty, seitan pieces, "
        "tempeh slice with herbs and vegetables",
    "תחליפי גבינה":
        "vegan cheese alternatives: cashew cheese wheel, sliced vegan cheese, "
        "nutritional yeast flakes with nuts",

    # משקאות
    "חליטות ומוצרי תה":
        "herbal teas: loose leaf tea in wooden spoon, dried chamomile flowers, "
        "tea bags, dried mint, glass teapot",
    "מיצים טבעיים לשתיה":
        "natural juices: glass bottle of orange juice, pomegranate juice, "
        "fresh squeezed citrus with halved fruits",
    "קפה ותחליפים":
        "coffee and alternatives: roasted coffee beans, moka pot, "
        "chicory root, carob powder in wooden bowl",
    "תחליפי חלב צמחיים":
        "plant milks: oat milk carton, almond milk bottle, "
        "glass of creamy plant milk with oats and almonds scattered",
    "תרכיזים ורכזים":
        "concentrated syrups: hibiscus concentrate bottle, elderflower syrup, "
        "fruit concentrate jars with fresh flowers",

    # עוגיות
    "לחמים ופיתות":
        "artisan breads: round sourdough loaf, whole wheat pita stack, "
        "rye bread slices on rustic wooden board",
    "עוגות":
        "small cakes and tarts: individual carrot cake, lemon tart, "
        "chocolate brownie bites on a ceramic plate",
    "עוגיות":
        "assorted artisan cookies: oatmeal raisin, almond cookies, "
        "date-filled pastries on a rustic wooden plate",
    "קרקרים, מצות ופריכיות":
        "crackers and crispbreads: matzo, sesame rice cakes, "
        "whole grain crispbread on linen with herbs",

    # פירות יבשים
    "אגוזים וזרעים":
        "nuts and seeds: walnuts, sunflower seeds, pumpkin seeds, "
        "pine nuts, flaxseeds in small wooden bowls",
    "פירות יבשים":
        "dried fruits: golden raisins, dried figs, medjool dates, "
        "dried apricots, cranberries in a wicker basket",

    # קוסמטיקה
    "דאודורנט":
        "natural deodorants: crystal deodorant stone, stick deodorant, "
        "baking soda and lavender, sage leaves",
    "הגיינה נשית":
        "natural feminine care: cotton pads, reusable cup, "
        "organic cotton products with lavender and chamomile",
    "הגנה מהשמש":
        "sun protection: sunscreen tube, aloe vera leaf, zinc cream, "
        "fresh aloe gel with calendula flowers",
    "היגיינת הפה והשיניים":
        "oral care: bamboo toothbrush, natural toothpaste, "
        "charcoal powder, silk dental floss, peppermint leaves",
    "טיפוח הפנים והגוף":
        "face and body care: small face cream jar, body oil bottle, "
        "rose hip oil, dried rose petals on marble",
    "לאם ולתינוק":
        "mother and baby products: gentle baby soap, soft cotton cloth, "
        "calendula cream, small baby bottle, chamomile flowers",
    "שמפו, סבון, מרכך ומסכות":
        "hair care products: artisan shampoo bar, conditioner bottle, "
        "hair mask jar, rosemary sprigs, argan oil",

    # תוספי תזונה
    "ויטמינים ותוספים":
        "natural vitamins: glass supplement bottles, colorful capsules, "
        "spirulina powder, chia seeds, vitamin C tablets",
    "מוצרים טיפוליים":
        "therapeutic products: tincture bottles with dropper, dried medicinal herbs, "
        "CBD oil, herbal remedy jars with botanical labels",
    "שמנים אתריים ובשמים":
        "essential oils: small amber glass bottles, dried lavender bundle, "
        "eucalyptus leaves, rose petals, diffuser stone",
}

SKIP = {"כללי"}


def generate_image(cat_name):
    subject = PROMPTS.get(cat_name) or SUB_PROMPTS.get(cat_name, cat_name)
    resp = client.images.generate(
        model="gpt-image-1",
        prompt=f"{subject}. {STYLE}",
        size="1024x1024",
        quality="medium",
        n=1,
    )
    raw = base64.b64decode(resp.data[0].b64_json)
    img = Image.open(io.BytesIO(raw)).convert("RGB")
    img = img.resize((800, 800), Image.LANCZOS)
    buf = io.BytesIO()
    img.save(buf, "JPEG", quality=88, optimize=True)
    return buf.getvalue()


def upload_to_wp(img_bytes, filename):
    r = requests.post(
        f"{WC_URL}/wp-json/wp/v2/media",
        headers={
            "Content-Disposition": f'attachment; filename="{filename}"',
            "Content-Type": "image/jpeg",
        },
        data=img_bytes,
        auth=(WP_USER, WP_PASS),
        timeout=60,
    )
    if not r.ok:
        raise Exception(f"העלאה נכשלה {r.status_code}: {r.text[:200]}")
    return r.json()["id"]


def set_category_image(cat_id, media_id):
    resp = wcapi.put(f"products/categories/{cat_id}", {"image": {"id": media_id}})
    return resp.status_code in (200, 201)


def main():
    import sys
    subs_only = "--subs" in sys.argv
    all_cats  = "--all"  in sys.argv

    cats = wcapi.get("products/categories",
                     params={"per_page": 100, "hide_empty": True}).json()

    if subs_only:
        cats = [c for c in cats if c.get("parent", 0) != 0]
    elif not all_cats:
        cats = [c for c in cats if c.get("parent", 0) == 0]

    to_process = [c for c in cats if c["name"] not in SKIP and not c.get("image")]
    print(f"מייצר {len(to_process)} תמונות | עלות משוערת: ${len(to_process) * 0.04:.2f}\n")

    for i, c in enumerate(to_process, 1):
        name   = c["name"]
        cat_id = c["id"]
        print(f"[{i}/{len(to_process)}] {name}... ", end="", flush=True)
        try:
            img_bytes = generate_image(name)
            media_id  = upload_to_wp(img_bytes, f"category_{cat_id}.jpg")
            ok        = set_category_image(cat_id, media_id)
            print(f"✓  (media {media_id})")
        except Exception as e:
            print(f"✗  {e}")

    print("\nסיום! רענן את דף הקטגוריות באתר.")


if __name__ == "__main__":
    main()
