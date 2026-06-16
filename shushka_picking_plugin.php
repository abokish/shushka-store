<?php
/**
 * Plugin Name: Shushka Likut
 * Description: ליקוט ומשלוח הזמנות — שושקה
 * Version: 4.8.0
 */
defined('ABSPATH') || exit;

/* ── Shortcodes ──────────────────────────────────────── */
add_action('init', function() {
    add_shortcode('shushka_picking',  function() { return shpk_frontend_page('pick'); });
    add_shortcode('shushka_delivery', function() { return shpk_frontend_page('deliver'); });
});

/* ── PIN helper ──────────────────────────────────────── */
function shpk_check_pin() {
    $pin = get_option('shushka_pick_pin', '1234');
    if (isset($_POST['pick_pin'])) {
        if ($_POST['pick_pin'] === $pin) {
            setcookie('shushka_pin', md5($pin . AUTH_KEY), time() + 28800, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE['shushka_pin'] = md5($pin . AUTH_KEY);
        }
    }
    return isset($_COOKIE['shushka_pin']) && $_COOKIE['shushka_pin'] === md5($pin . AUTH_KEY);
}

function shpk_pin_form($title = 'כניסה לדף הליקוט') {
    echo '<div style="max-width:320px;margin:60px auto;text-align:center;direction:rtl;">';
    echo '<h2 style="font-family:Rubik,Arial;margin-bottom:20px;">🔐 ' . esc_html($title) . '</h2>';
    echo '<form method="post">';
    echo '<input type="number" name="pick_pin" placeholder="קוד PIN" autofocus '
        . 'style="font-size:26px;text-align:center;width:160px;padding:10px;border:2px solid #183625;border-radius:10px;">';
    echo '<br><br><button type="submit" '
        . 'style="padding:10px 34px;background:#183625;color:#fff;border:none;border-radius:20px;font-size:16px;cursor:pointer;">כניסה</button>';
    echo '</form></div>';
}

/* ── סטטוסים מותאמים ─────────────────────────────────── */
add_action('init', function () {
    foreach (['wc-picking' => 'בליקוט', 'wc-packed' => 'נארז'] as $slug => $label) {
        register_post_status($slug, [
            'label'                     => $label,
            'public'                    => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop("$label (%s)", "$label (%s)"),
        ]);
    }
});

add_filter('wc_order_statuses', function ($s) {
    $out = [];
    foreach ($s as $k => $v) {
        $out[$k] = $v;
        if ($k === 'wc-processing') {
            $out['wc-picking'] = 'בליקוט';
            $out['wc-packed']  = 'נארז';
        }
    }
    return $out;
});

/* ── AJAX: עדכון סטטוס + פריטים ─────────────────────── */
add_action('wp_ajax_nopriv_shushka_set_status', 'shpk_set_status_handler');
add_action('wp_ajax_shushka_set_status',        'shpk_set_status_handler');
function shpk_set_status_handler() {
    $pin    = get_option('shushka_pick_pin', '1234');
    $pin_ok = isset($_COOKIE['shushka_pin']) && $_COOKIE['shushka_pin'] === md5($pin . AUTH_KEY);
    if (!$pin_ok && !current_user_can('manage_woocommerce')) {
        wp_send_json_error('unauthorized'); return;
    }
    check_ajax_referer('shushka_nonce', 'nonce');
    $oid    = intval($_POST['order_id']);
    $status = sanitize_text_field($_POST['status']);
    if (!in_array($status, ['picking', 'packed', 'completed'], true)) wp_die('bad status');

    $order = wc_get_order($oid);
    if (!$order) { wp_send_json_error('הזמנה לא נמצאה'); return; }

    if ($status === 'packed') {
        $removed = json_decode(stripslashes(isset($_POST['removed']) ? $_POST['removed'] : '[]'), true);
        if (!$removed) $removed = [];
        $changed = json_decode(stripslashes(isset($_POST['changed']) ? $_POST['changed'] : '[]'), true);
        if (!$changed) $changed = [];

        foreach ($removed as $iid) {
            $order->remove_item(intval($iid));
        }

        foreach ($changed as $ch) {
            $iid     = intval($ch['item_id']);
            $new_qty = floatval($ch['new_qty']);
            $item    = $order->get_item($iid);
            if (!$item) continue;
            $orig_qty = $item->get_quantity();
            if ($orig_qty > 0 && $new_qty > 0) {
                $ratio = $new_qty / $orig_qty;
                $item->set_quantity($new_qty);
                $item->set_subtotal($item->get_subtotal() * $ratio);
                $item->set_total($item->get_total() * $ratio);
                $item->save();
            }
        }

        if ($removed || $changed) {
            $parts = [];
            if ($removed) $parts[] = count($removed) . ' פריטים הוסרו (לא סופקו)';
            if ($changed) $parts[] = count($changed) . ' פריטים עודכנו בכמות ומחיר';
            $order->add_order_note('ליקוט: ' . implode(' | ', $parts));
        }

        $order->calculate_totals();
    }

    $order->update_status($status);
    $order->save();
    wp_send_json_success();
}


/* ── עדכון עצמי ──────────────────────────────────────── */
add_action('admin_init', function() {
    if (!isset($_POST['shpk_self_update']) || !current_user_can('manage_options')) return;
    check_admin_referer('shpk_self_update');
    if (empty($_FILES['shpk_file']['tmp_name'])) {
        add_settings_error('shpk_update', 'no_file', 'לא נבחר קובץ.', 'error'); return;
    }
    $content = file_get_contents($_FILES['shpk_file']['tmp_name']);
    if (!$content || strpos($content, 'Plugin Name:') === false) {
        add_settings_error('shpk_update', 'bad_file', 'קובץ לא תקין — חסר Plugin Name.', 'error'); return;
    }
    if (file_put_contents(__FILE__, $content) === false) {
        add_settings_error('shpk_update', 'write_fail', 'כתיבה נכשלה — בעיית הרשאות.', 'error'); return;
    }
    add_settings_error('shpk_update', 'ok', 'התוסף עודכן בהצלחה!', 'success');
});

function shpk_update_page() {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    settings_errors('shpk_update');
    $ver = '4.6.0';
    preg_match('/Version:\s*([\d.]+)/', file_get_contents(__FILE__), $m);
    if ($m) $ver = $m[1];
    echo '<div class="wrap" dir="rtl"><h1>🔄 עדכון תוסף Shushka Likut</h1>';
    echo '<p>גרסה מותקנת: <strong>' . esc_html($ver) . '</strong></p>';
    echo '<p style="color:#666;">העלה את הקובץ <code>shushka_picking_plugin.php</code> החדש. התוסף יחליף את עצמו ללא מחיקת תיקיות.</p>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('shpk_self_update');
    echo '<input type="file" name="shpk_file" accept=".php" required style="margin:10px 0;display:block">';
    echo '<input type="hidden" name="shpk_self_update" value="1">';
    submit_button('⬆ עדכן עכשיו');
    echo '</form></div>';
}

/* ── תפריט ───────────────────────────────────────────── */
add_action('admin_menu', function () {
    add_menu_page('ליקוט', '📦 ליקוט', 'manage_woocommerce',
        'shushka-pick', 'shpk_pick_page', 'dashicons-clipboard', 55);
    add_submenu_page('shushka-pick', 'ליקוט הזמנות', '📦 ליקוט',
        'manage_woocommerce', 'shushka-pick', 'shpk_pick_page');
    add_submenu_page('shushka-pick', 'דף השליח', '🚚 משלוח',
        'manage_woocommerce', 'shushka-deliver', 'shpk_deliver_page');
    add_submenu_page('shushka-pick', 'הגדרות PIN', '⚙️ קוד PIN',
        'manage_woocommerce', 'shushka-pin', 'shpk_pin_settings');
    add_submenu_page('shushka-pick', 'סנכרון קופה', '🔄 סנכרון קופה',
        'manage_woocommerce', 'shushka-prices', 'shpk_prices_page');
    add_submenu_page('shushka-pick', 'עדכון תוסף', '🔄 עדכון',
        'manage_options', 'shushka-update', 'shpk_update_page');
});

add_action('admin_init', function() {
    if (isset($_POST['shushka_save_pin']) && current_user_can('manage_woocommerce')) {
        check_admin_referer('shushka_pin_save');
        update_option('shushka_pick_pin', sanitize_text_field($_POST['new_pin']));
        add_settings_error('shushka_pin', 'saved', 'קוד PIN עודכן בהצלחה.', 'success');
    }
});

function shpk_pin_settings() {
    $pin = get_option('shushka_pick_pin', '1234');
    settings_errors('shushka_pin');
    echo '<div class="wrap" dir="rtl"><h1>⚙️ קוד PIN לדפי הליקוט</h1>';
    echo '<form method="post">';
    wp_nonce_field('shushka_pin_save');
    echo '<table class="form-table"><tr><th>קוד PIN נוכחי:</th><td><strong>' . esc_html($pin) . '</strong></td></tr>';
    echo '<tr><th>קוד PIN חדש:</th><td><input type="number" name="new_pin" min="1000" max="99999999" placeholder="לפחות 4 ספרות" style="font-size:18px;width:160px;padding:6px;"></td></tr></table>';
    echo '<input type="hidden" name="shushka_save_pin" value="1">';
    submit_button('שמור קוד');
    echo '</form></div>';
}

/* ── ניקוי ברקודים חד-פעמי ──────────────────────────── */
add_action('admin_init', function() {
    if (!isset($_POST['shpk_fix_skus']) || !current_user_can('manage_woocommerce')) return;
    check_admin_referer('shpk_fix_skus');
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key='_sku' AND meta_value LIKE '%.0'"
    );
    $fixed = 0;
    foreach ($rows as $r) {
        $clean = preg_replace('/\.0+$/', '', $r->meta_value);
        if ($clean === $r->meta_value) continue;
        $wpdb->update($wpdb->postmeta, ['meta_value' => $clean], ['meta_id' => $r->meta_id]);
        $fixed++;
    }
    set_transient('shpk_sku_fix_result', $fixed, MINUTE_IN_SECONDS * 5);
    wp_redirect(admin_url('admin.php?page=shushka-prices&sku_fixed=1'));
    exit;
});

/* ── סנכרון קופה — apply ─────────────────────────────── */
add_action('admin_init', function() {
    if (!isset($_POST['shpk_prices_apply']) || !current_user_can('manage_woocommerce')) return;
    check_admin_referer('shpk_prices_apply');
    $tkey = sanitize_key($_POST['shpk_tkey'] ?? '');
    $data = $tkey ? get_transient('shpk_pu_' . $tkey) : null;
    if (!$data) { wp_redirect(admin_url('admin.php?page=shushka-prices&err=expired')); exit; }

    @set_time_limit(300);
    $price_done = $stock_done = 0; $fail = [];

    foreach ($data['prices'] ?? [] as $u) {
        $product = wc_get_product($u['id']);
        if (!$product) { $fail[] = $u['name']; continue; }
        $product->set_regular_price($u['new_price']);
        $product->set_sale_price('');
        $product->set_price($u['new_price']);
        $product->save();
        $price_done++;
    }
    foreach ($data['stocks'] ?? [] as $u) {
        if ($u['new_stock'] <= 0) continue; // never update to 0
        $product = wc_get_product($u['id']);
        if (!$product) continue;
        $product->set_manage_stock(true);
        $product->set_stock_quantity($u['new_stock']);
        $product->set_stock_status($u['new_stock'] > 0 ? 'instock' : 'outofstock');
        $product->save();
        $stock_done++;
    }

    delete_transient('shpk_pu_' . $tkey);
    set_transient('shpk_pr_result', [
        'price_done' => $price_done, 'stock_done' => $stock_done, 'fail' => $fail,
        'stock_zero' => $data['stock_zero'] ?? [],
    ], MINUTE_IN_SECONDS * 5);
    wp_redirect(admin_url('admin.php?page=shushka-prices&done=1'));
    exit;
});

function shpk_prices_page() {
    if (!current_user_can('manage_woocommerce')) wp_die('אין הרשאה');

    $base = admin_url('admin.php?page=shushka-prices');

    // ── ייצוא הזמנות נארזות ─────────────────────────────
    if (isset($_GET['action']) && $_GET['action'] === 'export_packed') {
        $pin    = get_option('shushka_pick_pin', '1234');
        $pin_ok = isset($_COOKIE['shushka_pin']) && $_COOKIE['shushka_pin'] === md5($pin . AUTH_KEY);
        if (!$pin_ok && !current_user_can('manage_woocommerce')) wp_die('אין הרשאה');
        $orders = wc_get_orders(['status' => ['packed'], 'limit' => -1, 'orderby' => 'date', 'order' => 'ASC']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shushka-packed-' . date('Ymd-His') . '.csv"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['מספר הזמנה', 'שם לקוח', 'ברקוד', 'שם מוצר', 'כמות', 'מחיר ליחידה', 'סה"כ שורה']);
        $seq = 0;
        foreach ($orders as $order) {
            $seq++;
            $cname = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            foreach ($order->get_items() as $item) {
                $product    = $item->get_product();
                $sku        = $product ? $product->get_sku() : '';
                $qty        = $item->get_quantity();
                $unit_price = $qty > 0 ? round($item->get_total() / $qty, 2) : 0;
                fputcsv($out, [$seq, $cname, $sku, $item->get_name(), $qty,
                               number_format((float)$unit_price, 2, '.', ''),
                               number_format((float)$item->get_total(), 2, '.', '')]);
            }
        }
        fclose($out);
        exit;
    }

    // ── after apply ──────────────────────────────────────
    if (isset($_GET['done'])) {
        $r = get_transient('shpk_pr_result');
        delete_transient('shpk_pr_result');
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        if ($r) {
            $msg = [];
            if ($r['price_done']) $msg[] = $r['price_done'] . ' מחירים עודכנו';
            if ($r['stock_done']) $msg[] = $r['stock_done'] . ' כמויות מלאי עודכנו';
            if ($msg) echo '<div class="notice notice-success is-dismissible"><p>✓ ' . implode(' | ', $msg) . '.</p></div>';
            if ($r['fail'] ?? []) {
                echo '<div class="notice notice-warning"><p>⚠ ' . count($r['fail']) . ' מוצרים נכשלו:</p><ul>';
                foreach ($r['fail'] as $n) echo '<li>' . esc_html($n) . '</li>';
                echo '</ul></div>';
            }
            if ($r['stock_zero'] ?? []) {
                echo '<div class="notice notice-info"><p>ℹ ' . count($r['stock_zero']) . ' מוצרים עם מלאי 0 בקופה — לא עודכנו (בדוק ידנית):</p><ul>';
                foreach ($r['stock_zero'] as $n) echo '<li>' . esc_html($n) . '</li>';
                echo '</ul></div>';
            }
        }
        echo '<p><a href="' . $base . '" class="button button-primary">חזרה לסנכרון</a></p></div>';
        return;
    }
    if (isset($_GET['err'])) {
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        echo '<div class="notice notice-error"><p>פג תוקף הנתונים — אנא העלה שוב.</p></div>';
        echo '<p><a href="' . $base . '" class="button">חזרה</a></p></div>'; return;
    }

    // ── main page ────────────────────────────────────────
    if (!isset($_POST['shpk_prices_preview'])) {
        $packed_count = count(wc_get_orders(['status' => ['packed'], 'limit' => -1]));
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';

        // sku_fixed notice
        if (isset($_GET['sku_fixed'])) {
            $n = (int)get_transient('shpk_sku_fix_result');
            delete_transient('shpk_sku_fix_result');
            echo '<div class="notice notice-success is-dismissible"><p>✓ תוקנו ' . $n . ' ברקודים — הוסרה סיומת .0</p></div>';
        }

        // sku cleanup button
        global $wpdb;
        $dirty = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE '%.0'"
        );
        if ($dirty > 0) {
            echo '<div style="background:#fff3e0;border:1px solid #ff9800;border-radius:8px;padding:16px 20px;margin-bottom:20px">';
            echo '<strong>⚠ נמצאו ' . $dirty . ' ברקודים עם סיומת .0</strong> — יש לנקות אותם כדי שההתאמה עם הקופה תעבוד.';
            echo '<form method="post" style="display:inline;margin-right:16px">';
            wp_nonce_field('shpk_fix_skus');
            echo '<input type="hidden" name="shpk_fix_skus" value="1">';
            submit_button('🔧 נקה ברקודים עכשיו', 'primary', '', false, ['style' => 'margin:8px 0 0']);
            echo '</form></div>';
        }

        // Section 1: export
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:24px">';
        echo '<h2 style="margin-top:0">📥 ייצוא הזמנות נארזות לקופה</h2>';
        echo '<p style="color:#555">' . $packed_count . ' הזמנות בסטטוס "נארז" מוכנות לייצוא.</p>';
        echo '<a href="' . $base . '&action=export_packed" class="button button-primary button-large">⬇ הורד CSV</a>';
        echo '</div>';

        // Section 2: import
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">';
        echo '<h2 style="margin-top:0">⬆ ייבוא מהקופה</h2>';
        echo '<p style="color:#555">העלה את קובץ ייצוא המוצרים מהקופה. ההתאמה לפי <strong>ברקוד ↔ SKU</strong>.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('shpk_prices_preview');
        echo '<input type="hidden" name="shpk_prices_preview" value="1">';
        echo '<input type="file" name="shpk_csv" accept=".csv" required style="margin:12px 0;display:block;font-size:15px">';
        echo '<div style="margin:12px 0;display:flex;gap:24px;flex-wrap:wrap">';
        echo '<label style="font-size:15px;display:flex;align-items:center;gap:8px"><input type="checkbox" name="do_prices" value="1" checked> <strong>עדכן מחירים</strong></label>';
        echo '<label style="font-size:15px;display:flex;align-items:center;gap:8px"><input type="checkbox" name="do_stock" value="1"> <strong>עדכן מלאי</strong> <span style="color:#888;font-size:13px">(מלאי 0 מהקופה לא יעודכן — יוצג בנפרד)</span></label>';
        echo '</div>';
        submit_button('📋 טען ותצוגה מקדימה', 'primary large');
        echo '</form></div></div>';
        return;
    }

    // ── parse CSV ────────────────────────────────────────
    check_admin_referer('shpk_prices_preview');
    $do_prices = !empty($_POST['do_prices']);
    $do_stock  = !empty($_POST['do_stock']);
    $tmp = $_FILES['shpk_csv']['tmp_name'] ?? '';
    if (!$tmp) { echo '<div class="wrap" dir="rtl"><p class="notice notice-error">לא נבחר קובץ.</p></div>'; return; }

    $raw     = ltrim(file_get_contents($tmp), "\xEF\xBB\xBF");
    $lines   = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($raw)));
    $headers = str_getcsv(array_shift($lines));

    $ci_sku = $ci_price = $ci_name = $ci_stock = null;
    foreach ($headers as $i => $h) {
        $h = trim($h);
        if (mb_strpos($h, 'ברקוד')       !== false) $ci_sku   = $i;
        if (mb_strpos($h, 'מחיר מכירה') !== false) $ci_price = $i;
        if (mb_strpos($h, 'תאור')        !== false) $ci_name  = $i;
        if (mb_strpos($h, 'מלאי')        !== false) $ci_stock = $i;
    }
    if ($ci_sku === null || ($do_prices && $ci_price === null) || ($do_stock && $ci_stock === null)) {
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        echo '<div class="notice notice-error"><p>חסרות עמודות בקובץ. בדוק את הפורמט.</p></div>';
        echo '<p><a href="' . $base . '" class="button">חזרה</a></p></div>'; return;
    }

    $csv = [];
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $c     = str_getcsv($line);
        $sku   = preg_replace('/\.0+$/', '', trim($c[$ci_sku] ?? ''));
        if ($sku === '') continue;
        $price = $ci_price !== null ? (float)str_replace([',', ' '], ['.', ''], trim($c[$ci_price] ?? '')) : null;
        $stock = $ci_stock !== null ? (int)trim($c[$ci_stock] ?? '') : null;
        $name  = trim($c[$ci_name] ?? '');
        $csv[$sku] = ['name' => $name, 'price' => $price, 'stock' => $stock];
    }
    if (!$csv) {
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        echo '<div class="notice notice-error"><p>לא נמצאו שורות תקינות.</p></div>';
        echo '<p><a href="' . $base . '" class="button">חזרה</a></p></div>'; return;
    }

    // ── WC SKU→ID map (fast DB query) ───────────────────
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT p.ID, pm.meta_value AS sku
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
        WHERE p.post_type IN ('product','product_variation')
          AND p.post_status = 'publish' AND pm.meta_value != ''
    ");
    $wc_map = [];
    foreach ($rows as $r) {
        $s = preg_replace('/\.0+$/', '', trim($r->sku)); // strip Excel .0 suffix
        $wc_map[$s] = (int)$r->ID;
    }

    // ── match & build lists ──────────────────────────────
    @set_time_limit(300);
    $price_changes = []; $stock_changes = []; $stock_zero = []; $not_in_wc = [];
    $price_same = $stock_same = 0;

    foreach ($csv as $sku => $d) {
        if (!isset($wc_map[$sku])) { $not_in_wc[] = $d + ['sku' => $sku]; continue; }
        $product = wc_get_product($wc_map[$sku]);
        if (!$product) { $not_in_wc[] = $d + ['sku' => $sku]; continue; }

        if ($do_prices && $d['price'] > 0) {
            $old = (float)$product->get_regular_price();
            if (abs($old - $d['price']) > 0.001)
                $price_changes[] = ['id' => $wc_map[$sku], 'sku' => $sku, 'name' => $product->get_name(), 'old_price' => $old, 'new_price' => $d['price']];
            else $price_same++;
        }
        if ($do_stock && $d['stock'] !== null) {
            $old_s = (int)$product->get_stock_quantity();
            if ($d['stock'] <= 0) { $stock_zero[] = $product->get_name() . ' (ברקוד: ' . $sku . ')'; }
            elseif ($d['stock'] !== $old_s)
                $stock_changes[] = ['id' => $wc_map[$sku], 'sku' => $sku, 'name' => $product->get_name(), 'old_stock' => $old_s, 'new_stock' => $d['stock']];
            else $stock_same++;
        }
    }

    // ── store in transient ───────────────────────────────
    $tkey = wp_generate_password(16, false);
    set_transient('shpk_pu_' . $tkey, [
        'prices'     => $price_changes,
        'stocks'     => $stock_changes,
        'stock_zero' => $stock_zero,
    ], MINUTE_IN_SECONDS * 30);

    // ── preview UI ───────────────────────────────────────
    $total_changes = count($price_changes) + count($stock_changes);
    echo '<div class="wrap" dir="rtl"><h1>🔄 תצוגה מקדימה — סנכרון קופה</h1>';

    // summary chips
    $chips = [];
    if ($do_prices) $chips[] = ['<strong>' . count($price_changes) . '</strong> מחירים ישתנו', '#e8f5e9', '#4caf50'];
    if ($do_prices) $chips[] = ['<strong>' . $price_same . '</strong> מחירים ללא שינוי', '#f5f5f5', '#ccc'];
    if ($do_stock)  $chips[] = ['<strong>' . count($stock_changes) . '</strong> כמויות ישתנו', '#e3f2fd', '#2196f3'];
    if ($do_stock)  $chips[] = ['<strong>' . count($stock_zero) . '</strong> מלאי 0 — לא יעודכנו', '#fff8e1', '#ff9800'];
    if ($not_in_wc) $chips[] = ['<strong>' . count($not_in_wc) . '</strong> לא נמצאו באתר', '#fce4ec', '#e91e63'];
    echo '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">';
    foreach ($chips as [$label, $bg, $border])
        echo '<span style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:8px;padding:8px 16px;font-size:14px">' . $label . '</span>';
    echo '</div>';

    if ($total_changes > 0) {
        echo '<form method="post" style="margin-bottom:24px">';
        wp_nonce_field('shpk_prices_apply');
        echo '<input type="hidden" name="shpk_prices_apply" value="1">';
        echo '<input type="hidden" name="shpk_tkey" value="' . esc_attr($tkey) . '">';
        submit_button('✓ אשר ועדכן (' . $total_changes . ' שינויים)', 'primary large', '', false, ['style' => 'font-size:16px;padding:8px 28px']);
        echo ' &nbsp;<a href="' . $base . '" class="button button-large">ביטול</a>';
        echo '</form>';
    } else {
        echo '<div class="notice notice-success"><p>✓ הכל עדכני — אין שינויים להחיל.</p></div>';
    }

    if ($price_changes) {
        echo '<h3>💰 שינויי מחיר (' . count($price_changes) . ')</h3>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>מוצר</th><th style="width:130px">ברקוד</th><th style="width:100px">מחיר ישן</th><th style="width:100px">מחיר חדש</th><th style="width:50px"></th>';
        echo '</tr></thead><tbody>';
        foreach ($price_changes as $u) {
            $up = $u['new_price'] > $u['old_price'];
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td>';
            echo '<td>₪' . number_format($u['old_price'], 2) . '</td>';
            echo '<td><strong>₪' . number_format($u['new_price'], 2) . '</strong></td>';
            echo '<td style="color:' . ($up ? '#c00' : '#080') . ';font-weight:700">' . ($up ? '▲' : '▼') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if ($stock_changes) {
        echo '<h3 style="margin-top:28px">📦 שינויי מלאי (' . count($stock_changes) . ')</h3>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>מוצר</th><th style="width:130px">ברקוד</th><th style="width:100px">מלאי ישן</th><th style="width:100px">מלאי חדש</th>';
        echo '</tr></thead><tbody>';
        foreach ($stock_changes as $u) {
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td>';
            echo '<td>' . $u['old_stock'] . '</td><td><strong>' . $u['new_stock'] . '</strong></td></tr>';
        }
        echo '</tbody></table>';
    }

    if ($stock_zero) {
        echo '<h3 style="margin-top:28px">⚠ מלאי 0 בקופה — לא יעודכנו (' . count($stock_zero) . ')</h3>';
        echo '<p style="color:#666">בדוק אם אלו מוצרים שאזלו באמת או שגיאה בנתוני הקופה.</p>';
        echo '<ul style="list-style:disc;padding-right:20px">';
        foreach ($stock_zero as $n) echo '<li>' . esc_html($n) . '</li>';
        echo '</ul>';
    }

    if ($not_in_wc) {
        echo '<h3 style="margin-top:28px">🆕 בקופה אך לא באתר (' . count($not_in_wc) . ')</h3>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>שם</th><th>ברקוד</th><th>מחיר</th></tr></thead><tbody>';
        foreach ($not_in_wc as $u)
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td><td>₪' . number_format($u['price'] ?? 0, 2) . '</td></tr>';
        echo '</tbody></table>';
    }

    echo '</div>';
}

/* ── CSS ─────────────────────────────────────────────── */
function shpk_css() { ?>
<style>
*{box-sizing:border-box}
.sp{font-family:'Rubik',Arial,sans-serif;direction:rtl;padding:12px 16px;max-width:960px}
.sp h1{font-size:21px;margin:0 0 4px}.sp .meta{color:#666;font-size:13px;margin:0 0 14px}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab-btn{padding:7px 18px;border:2px solid #183625;border-radius:20px;background:#fff;color:#183625;cursor:pointer;font-size:13px;font-weight:600}
.tab-btn.active,.tab-btn:hover{background:#183625;color:#fff}
.pbtn{padding:7px 18px;border-radius:20px;background:#555;color:#fff;border:none;cursor:pointer;font-size:13px}
.view{display:none}.view.active{display:block}
.oc{border:1px solid #ddd;border-radius:10px;margin-bottom:14px;overflow:hidden;transition:opacity .3s}
.oc.picking .oc-hd{background:#fff3e0}
.oc.packed .oc-hd{background:#e8f5e9}
.oc.packed{opacity:.55}
.oc-hd{background:#f0ece4;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px}
.oc-title{font-weight:700;font-size:15px}
.oc-city{background:#183625;color:#fff;padding:2px 9px;border-radius:12px;font-size:12px}
.oc-phone{font-size:13px;color:#333;text-decoration:none}
.oc-badge{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600}
.badge-picking{background:#ff9800;color:#fff}.badge-packed{background:#4caf50;color:#fff}
.hd-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.btn-start{padding:5px 14px;border-radius:16px;border:2px solid #ff9800;background:#fff;color:#ff9800;cursor:pointer;font-size:13px;font-weight:600}
.btn-start:hover{background:#ff9800;color:#fff}
.btn-pack{padding:5px 14px;border-radius:16px;border:none;background:#4caf50;color:#fff;cursor:pointer;font-size:13px;font-weight:600}
.btn-pack:disabled{background:#aaa;cursor:default}
.oc-items{padding:0;margin:0;list-style:none}
.oc-item{display:flex;align-items:center;gap:10px;padding:8px 14px;border-top:1px solid #eee;transition:opacity .2s}
.oc-item:nth-child(even){background:#fafafa}
.oc-item.missing{opacity:.4;background:#fff5f5}
.ic{width:20px;height:20px;cursor:pointer;flex-shrink:0;accent-color:#183625}
.iname{flex:1;font-size:14px}
.iname.done{text-decoration:line-through;color:#bbb}
.iqty-wrap{display:flex;align-items:center;gap:4px;flex-shrink:0}
.iqty-lbl{font-size:12px;color:#888}
.iqty{width:58px;padding:3px 6px;border:1px solid #ccc;border-radius:6px;font-size:14px;font-weight:700;color:#183625;text-align:center}
.iqty:focus{outline:2px solid #183625;border-color:transparent}
.iqty.changed{background:#fff8e1;border-color:#ff9800}
.iqty-orig{font-size:11px;color:#bbb}
.isku{font-size:11px;color:#ccc;flex-shrink:0}
.ct{width:100%;border-collapse:collapse;font-size:14px}
.ct th{background:#183625;color:#fff;padding:8px 12px;text-align:right}
.ct td{padding:8px 12px;border-bottom:1px solid #eee}
.ct tr:nth-child(even) td{background:#f9f9f9}
.cq{font-weight:700;color:#183625}.co{font-size:12px;color:#888}
.dt{width:100%;border-collapse:collapse;font-size:14px}
.dt th{background:#183625;color:#fff;padding:9px 12px;text-align:right;cursor:pointer;user-select:none}
.dt th:hover{background:#0f2318}
.dt td{padding:9px 12px;border-bottom:1px solid #eee;vertical-align:middle}
.dt tr:nth-child(even) td{background:#f9f9f9}
.dt tr.delivered td{opacity:.4;text-decoration:line-through}
.btn-del{padding:5px 14px;border-radius:16px;border:none;background:#183625;color:#fff;cursor:pointer;font-size:13px}
.btn-del:disabled{background:#aaa;cursor:default}
.mob-pick-btn{display:none}
.pick-bottom{display:none;position:fixed;bottom:0;left:0;right:0;z-index:999;background:#fff;border-top:2px solid #183625;padding:12px 16px;gap:10px;align-items:center}
.pick-bottom .pb-back{flex:1;padding:14px;background:#f0ece4;border:2px solid #183625;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer}
.pick-bottom .pb-pack{flex:2;padding:14px;background:#4caf50;color:#fff;border:none;border-radius:12px;font-size:17px;font-weight:700;cursor:pointer}
.sp.pick-mode-active{padding-bottom:90px!important}
@media(max-width:700px){
    .sp{padding:8px 10px}
    .sp h1{font-size:18px}
    .tabs{gap:6px}
    .tab-btn{padding:7px 12px;font-size:12px}
    .pbtn{padding:7px 12px;font-size:12px}
    .oc-hd{flex-direction:column;align-items:flex-start;gap:8px}
    .hd-actions{width:100%}
    .btn-start,.btn-pack{width:100%;padding:10px;font-size:15px;text-align:center;display:block}
    .ic{width:26px;height:26px}
    .iname{font-size:15px}
    .iqty{width:65px;font-size:15px;padding:4px}
    .oc-item{gap:8px;padding:10px 10px}
    .ct th:nth-child(2),.ct td:nth-child(2){display:none}
    .mob-pick-btn{display:block;width:100%;padding:11px;font-size:15px;background:#ff9800;color:#fff;border:none;border-radius:12px;font-weight:700;cursor:pointer;margin-top:4px}
    .oc-items{display:none}
    #view-orders.pick-mode .oc:not(.active-pick){display:none}
    #view-orders.pick-mode .oc.active-pick{border:2px solid #ff9800}
    #view-orders.pick-mode .oc.active-pick .oc-items{display:block!important}
    .pick-bottom.active{display:flex}
    .isku{display:none}
    .iqty-lbl{display:none}
    .iname{min-width:0;word-break:break-word}
    .iqty{width:52px}
}
@media(max-width:700px){
    .dt thead{display:none}
    .dt,.dt tbody,.dt tr,.dt td{display:block;width:100%}
    .dt tr{background:#fff;border:1px solid #ddd;border-radius:14px;margin-bottom:14px;padding:14px;box-shadow:0 2px 6px rgba(0,0,0,.07)}
    .dt tr.delivered{opacity:.4}
    .dt td{border:none;padding:2px 0;font-size:15px}
    .dt td:nth-child(1){font-size:20px;font-weight:700;color:#183625;margin-bottom:4px}
    .dt td:nth-child(2){font-size:16px;margin-bottom:2px}
    .dt td:nth-child(3){color:#666;font-size:14px;margin-bottom:8px}
    .dt td:nth-child(4) a{display:block;background:#e8f5e9;color:#183625;text-align:center;padding:12px;border-radius:10px;font-size:17px;font-weight:700;text-decoration:none;margin:6px 0}
    .dt td:nth-child(5){font-size:16px;font-weight:700;text-align:center;margin:4px 0}
    .dt td:nth-child(6){margin-top:6px}
    .btn-del{width:100%;padding:14px;font-size:18px;border-radius:12px;letter-spacing:.5px}
}
@media print{
    #wpadminbar,.tabs,.no-print,.hd-actions,.ic,.btn-del{display:none!important}
    .iname.done{text-decoration:none;color:#000}
    .oc{page-break-inside:avoid;border:1px solid #999}
    .oc.packed{opacity:1}
    .dt thead{display:table-header-group!important}
    .dt,.dt tbody,.dt tr,.dt td{display:revert!important}
}
</style>
<?php }

/* ── דף ליקוט ────────────────────────────────────────── */
function shpk_frontend_page($type) {
    ob_start();
    if ($type === 'pick') shpk_pick_page(true);
    else shpk_deliver_page(true);
    return ob_get_clean();
}

function shpk_pick_page($frontend = false) {
    if (!shpk_check_pin()) { shpk_pin_form('כניסה לדף הליקוט'); return; }
    $ajax_url = admin_url('admin-ajax.php');
    $nonce  = wp_create_nonce('shushka_nonce');
    $orders = wc_get_orders(array('status' => array('processing','picking'),'limit' => -1,'orderby' => 'date','order' => 'ASC'));
    $dk     = date('Ymd');
    $ds     = date_i18n('j/n/Y');
    $total  = count($orders);

    $cmap = array();
    foreach ($orders as $ord) {
        foreach ($ord->get_items() as $item) {
            $p=$item->get_product(); $name=$item->get_name(); $qty=(int)$item->get_quantity();
            $sku=$p?$p->get_sku():''; $key=$sku?:$name;
            if(!isset($cmap[$key])) $cmap[$key]=array('name'=>$name,'sku'=>$sku,'total'=>0,'orders'=>array());
            $cmap[$key]['total']+=$qty; $cmap[$key]['orders'][]=$ord->get_order_number();
        }
    }
    uasort($cmap, function($a,$b){ return strcmp($a['name'],$b['name']); });
    shpk_css();
    ?>
    <div class="sp" dir="rtl">
        <h1>📦 ליקוט הזמנות — <?php echo $ds; ?></h1>
        <p class="meta no-print"><?php echo $total; ?> הזמנות בסטטוס "בעיבוד" / "בליקוט"</p>
        <div class="tabs no-print">
            <button class="tab-btn active" onclick="showTab('orders',this)">לפי הזמנה</button>
            <button class="tab-btn"        onclick="showTab('consol',this)">מרוכז לפי מוצר</button>
            <button class="pbtn"           onclick="window.print()">🖨️ הדפס</button>
        </div>

        <div id="view-orders" class="view active">
        <?php foreach ($orders as $ord):
            $oid=$ord->get_id(); $st=$ord->get_status(); $onum=$ord->get_order_number();
            $cname=trim($ord->get_billing_first_name().' '.$ord->get_billing_last_name());
            $city=$ord->get_shipping_city()?:$ord->get_billing_city();
            $phone=$ord->get_billing_phone();
        ?>
        <div class="oc <?php echo esc_attr($st); ?>" id="oc-<?php echo $oid; ?>">
            <div class="oc-hd">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <span class="oc-title">#<?php echo $onum; ?><?php if($cname) echo ' — '.esc_html($cname); ?></span>
                    <?php if($city): ?><span class="oc-city"><?php echo esc_html($city); ?></span><?php endif; ?>
                    <?php if($phone): ?><a class="oc-phone no-print" href="tel:<?php echo esc_attr($phone); ?>">📞 <?php echo esc_html($phone); ?></a><?php endif; ?>
                </div>
                <div class="hd-actions no-print">
                    <?php if($st==='processing'): ?>
                        <button class="btn-start" onclick="setSt(<?php echo $oid; ?>,'picking',this)">▶ התחל ליקוט</button>
                    <?php elseif($st==='picking'): ?>
                        <span class="oc-badge badge-picking">בליקוט</span>
                        <button class="mob-pick-btn" onclick="enterPickMode(<?php echo $oid; ?>)">📱 ליקוט</button>
                        <button class="btn-pack" onclick="packOrder(<?php echo $oid; ?>,this)">✓ סמן כנארז</button>
                    <?php else: ?>
                        <span class="oc-badge badge-packed">נארז ✓</span>
                    <?php endif; ?>
                </div>
            </div>
            <ul class="oc-items" id="items-<?php echo $oid; ?>">
            <?php foreach ($ord->get_items() as $iid => $item):
                $p=$item->get_product(); $sku=$p?$p->get_sku():'';
                $qty=(int)$item->get_quantity(); $nm=$item->get_name();
                $ck="ck_{$dk}_{$oid}_{$iid}"; $qk="qty_{$dk}_{$oid}_{$iid}";
            ?>
            <li class="oc-item" id="li_<?php echo $ck; ?>">
                <input class="ic no-print" type="checkbox" id="<?php echo $ck; ?>"
                    onchange="onCheck(this)"
                    data-oid="<?php echo $oid; ?>"
                    data-iid="<?php echo $iid; ?>"
                    data-name="<?php echo esc_attr($nm); ?>">
                <span class="iname" id="nm_<?php echo $ck; ?>"><?php echo esc_html($nm); ?></span>
                <?php if($sku): ?><span class="isku"><?php echo esc_html($sku); ?></span><?php endif; ?>
                <div class="iqty-wrap">
                    <span class="iqty-lbl">כמות:</span>
                    <input class="iqty" type="number" step="0.01" min="0"
                        id="<?php echo $qk; ?>"
                        value="<?php echo $qty; ?>"
                        data-orig="<?php echo $qty; ?>"
                        data-oid="<?php echo $oid; ?>"
                        data-iid="<?php echo $iid; ?>"
                        onchange="onQtyChange(this)">
                    <span class="iqty-orig" id="orig_<?php echo $qk; ?>"></span>
                </div>
            </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
        <?php if(!$total): ?><p>אין הזמנות לליקוט כרגע.</p><?php endif; ?>
        </div>

        <div id="view-consol" class="view">
        <?php if($cmap): ?>
        <table class="ct">
            <thead><tr><th>מוצר</th><th>ברקוד</th><th>סה"כ כמות</th><th>הזמנות</th></tr></thead>
            <tbody>
            <?php foreach($cmap as $d): ?>
            <tr><td><?php echo esc_html($d['name']); ?></td><td><?php echo esc_html($d['sku']); ?></td>
            <td class="cq"><?php echo $d['total']; ?></td><td class="co">#<?php echo implode(', #',$d['orders']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p>אין הזמנות.</p><?php endif; ?>
        </div>

        <div class="pick-bottom" id="pick-bottom">
            <button class="pb-back" onclick="exitPickMode()">← חזרה</button>
            <button class="pb-pack" onclick="packFromPickMode()">✓ נארז</button>
        </div>
    </div>

    <script>
    var SHPK_NONCE = '<?php echo $nonce; ?>';
    var SHPK_DK    = '<?php echo $dk; ?>';
    var SHPK_AJAX  = '<?php echo $ajax_url; ?>';
    var _pmOid     = null;

    document.querySelectorAll('.ic').forEach(function(cb) {
        var saved = localStorage.getItem(cb.id);
        if (saved === '0') { cb.checked = false; shpkApplyUncheck(cb); }
    });
    document.querySelectorAll('.iqty').forEach(function(inp) {
        var saved = localStorage.getItem(inp.id);
        if (saved !== null && saved !== inp.dataset.orig) {
            inp.value = saved; shpkMarkQtyChanged(inp);
        }
    });

    function onCheck(cb) {
        localStorage.setItem(cb.id, cb.checked ? '1' : '0');
        if (cb.checked) {
            var li = document.getElementById('li_' + cb.id);
            if (li) li.classList.remove('missing');
            var nm = document.getElementById('nm_' + cb.id);
            if (nm) nm.classList.remove('done');
        } else { shpkApplyUncheck(cb); }
    }
    function shpkApplyUncheck(cb) {
        var li = document.getElementById('li_' + cb.id);
        if (li) li.classList.add('missing');
        var nm = document.getElementById('nm_' + cb.id);
        if (nm) nm.classList.add('done');
    }
    function onQtyChange(inp) {
        localStorage.setItem(inp.id, inp.value);
        shpkMarkQtyChanged(inp);
    }
    function shpkMarkQtyChanged(inp) {
        var orig = inp.dataset.orig;
        var changed = parseFloat(inp.value) !== parseFloat(orig);
        inp.classList.toggle('changed', changed);
        var ol = document.getElementById('orig_' + inp.id);
        if (ol) ol.textContent = changed ? '(הוזמן: ' + orig + ')' : '';
    }
    function packOrder(oid, btn) {
        var removed = [], removedNames = [], changed = [];
        document.querySelectorAll('.ic[data-oid="' + oid + '"]').forEach(function(cb) {
            var iid = cb.dataset.iid;
            var qInp = document.querySelector('.iqty[data-oid="' + oid + '"][data-iid="' + iid + '"]');
            var newQty = qInp ? parseFloat(qInp.value) : 1;
            var origQty = qInp ? parseFloat(qInp.dataset.orig) : 1;
            if (!cb.checked || newQty <= 0) {
                removed.push(iid); removedNames.push(cb.dataset.name);
            } else if (Math.abs(newQty - origQty) > 0.001) {
                changed.push({item_id: iid, new_qty: newQty});
            }
        });
        var msg = 'לסמן הזמנה כנארז?';
        if (removedNames.length) msg += '\n\nפריטים שיוסרו:\n• ' + removedNames.join('\n• ');
        if (changed.length) msg += '\n\n' + changed.length + ' פריט(ים) עם כמות מעודכנת.';
        if (!confirm(msg)) return;
        btn.disabled = true; btn.textContent = '...';
        fetch(SHPK_AJAX, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'shushka_set_status', order_id: oid, status: 'packed', nonce: SHPK_NONCE,
                removed: JSON.stringify(removed), changed: JSON.stringify(changed)})
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (!d.success) { btn.disabled = false; btn.textContent = 'שגיאה'; return; }
            var card = document.getElementById('oc-' + oid);
            card.className = 'oc packed';
            card.querySelector('.hd-actions').innerHTML = '<span class="oc-badge badge-packed">נארז ✓</span>';
            if (_pmOid === oid) exitPickMode();
        });
    }
    function setSt(oid, status, btn) {
        btn.disabled = true; btn.textContent = '...';
        fetch(SHPK_AJAX, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'shushka_set_status', order_id:oid, status:status, nonce:SHPK_NONCE,
                removed:'[]', changed:'[]'})
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (!d.success) { btn.disabled = false; btn.textContent = 'שגיאה'; return; }
            var card = document.getElementById('oc-' + oid);
            card.className = 'oc ' + status;
            card.querySelector('.hd-actions').innerHTML =
                '<span class="oc-badge badge-picking">בליקוט</span>' +
                '<button class="mob-pick-btn" onclick="enterPickMode(' + oid + ')">📱 ליקוט</button>' +
                '<button class="btn-pack" onclick="packOrder(' + oid + ',this)">✓ סמן כנארז</button>';
            if (window.innerWidth <= 700) enterPickMode(oid);
        });
    }
    function enterPickMode(oid) {
        _pmOid = oid;
        var ordersDiv = document.getElementById('view-orders');
        ordersDiv.classList.add('pick-mode');
        ordersDiv.querySelectorAll('.oc').forEach(function(c){ c.classList.remove('active-pick'); });
        var target = document.getElementById('oc-' + oid);
        if (target) target.classList.add('active-pick');
        document.getElementById('pick-bottom').classList.add('active');
        document.querySelector('.sp').classList.add('pick-mode-active');
        window.scrollTo(0,0);
    }
    function exitPickMode() {
        _pmOid = null;
        var ordersDiv = document.getElementById('view-orders');
        ordersDiv.classList.remove('pick-mode');
        ordersDiv.querySelectorAll('.oc').forEach(function(c){ c.classList.remove('active-pick'); });
        document.getElementById('pick-bottom').classList.remove('active');
        document.querySelector('.sp').classList.remove('pick-mode-active');
    }
    function packFromPickMode() {
        if (!_pmOid) return;
        var packBtn = document.querySelector('#oc-' + _pmOid + ' .btn-pack');
        if (packBtn) packBtn.click();
    }
    function showTab(name, btn) {
        document.querySelectorAll('.view').forEach(function(v){ v.classList.remove('active'); });
        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.getElementById('view-' + name).classList.add('active');
        btn.classList.add('active');
    }
    </script>
    <?php
}

/* ── דף השליח ────────────────────────────────────────── */
function shpk_deliver_page($frontend = false) {
    if (!shpk_check_pin()) { shpk_pin_form('כניסה לדף השליח'); return; }
    $ajax_url = admin_url('admin-ajax.php');
    $nonce  = wp_create_nonce('shushka_nonce');
    $orders = wc_get_orders(array('status' => array('packed'),'limit' => -1,'orderby' => 'date','order' => 'ASC'));
    usort($orders, function($a,$b){
        return strcmp($a->get_shipping_city()?:$a->get_billing_city(), $b->get_shipping_city()?:$b->get_billing_city());
    });
    $ds = date_i18n('j/n/Y'); $total = count($orders);
    shpk_css();
    ?>
    <div class="sp" dir="rtl">
        <h1>🚚 דף השליח — <?php echo $ds; ?></h1>
        <p class="meta no-print"><?php echo $total; ?> הזמנות נארזות, ממוינות לפי עיר</p>
        <button class="pbtn no-print" onclick="window.print()" style="margin-bottom:14px">🖨️ הדפס</button>
        <?php if(!$total): ?>
        <p>אין הזמנות נארזות. סמן הזמנות כ"נארז" בדף הליקוט.</p>
        <?php else: ?>
        <table class="dt" id="dtbl">
            <thead><tr>
                <th onclick="sortBy('city')">עיר ↕</th>
                <th>שם</th>
                <th>כתובת</th>
                <th class="no-print">טלפון</th>
                <th class="no-print">סה"כ</th>
                <th class="no-print">פעולה</th>
            </tr></thead>
            <tbody id="dtbody">
            <?php foreach($orders as $ord):
                $oid=$ord->get_id(); $onum=$ord->get_order_number();
                $cname=trim($ord->get_billing_first_name().' '.$ord->get_billing_last_name());
                $city=$ord->get_shipping_city()?:$ord->get_billing_city();
                $addr=trim(($ord->get_shipping_address_1()?:$ord->get_billing_address_1()).' '.($ord->get_shipping_address_2()?:''));
                $phone=$ord->get_billing_phone();
                $total_price=wc_price($ord->get_total());
            ?>
            <tr id="row-<?php echo $oid; ?>" data-city="<?php echo esc_attr($city); ?>" data-phone="<?php echo esc_attr($phone); ?>">
                <td><strong><?php echo esc_html($city); ?></strong></td>
                <td><?php echo esc_html($cname); ?><br><small class="co">#<?php echo $onum; ?></small></td>
                <td><?php echo esc_html($addr); ?></td>
                <td class="no-print"><a href="tel:<?php echo esc_attr($phone); ?>">📞 <?php echo esc_html($phone); ?></a></td>
                <td class="no-print"><?php echo $total_price; ?></td>
                <td class="no-print">
                    <button class="btn-del" id="del-<?php echo $oid; ?>"
                        onclick="markDelivered(<?php echo $oid; ?>,this)">מסרתי ✓</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <script>
    var SHPK_NONCE = '<?php echo $nonce; ?>';
    var SHPK_AJAX  = '<?php echo $ajax_url; ?>';
    function markDelivered(oid, btn) {
        if (!confirm('לסמן הזמנה כ"הושלמה"?')) return;
        btn.disabled = true; btn.textContent = '...';
        fetch(SHPK_AJAX, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'shushka_set_status', order_id:oid, status:'completed', nonce:SHPK_NONCE, removed:'[]', changed:'[]'})
        }).then(function(r){ return r.json(); }).then(function(d) {
            if (d.success) { document.getElementById('row-'+oid).classList.add('delivered'); btn.textContent='✓ נמסר'; }
            else { btn.disabled=false; btn.textContent='שגיאה'; }
        });
    }
    function sortBy(col) {
        var tbody = document.getElementById('dtbody');
        Array.from(tbody.querySelectorAll('tr'))
            .sort(function(a,b){ return (a.dataset[col]||'').localeCompare(b.dataset[col]||'','he'); })
            .forEach(function(r){ tbody.appendChild(r); });
    }
    </script>
    <?php
}

/* ═══════════════════════════════════════════════════════════
   דף ניהול מוצרים
   ═══════════════════════════════════════════════════════════ */

add_action('admin_menu', function() {
    add_submenu_page('shushka-pick', 'ניהול מוצרים', '🛒 מוצרים',
        'manage_woocommerce', 'shushka-products', 'shpk_products_page');
    add_submenu_page('shushka-pick', 'תמונות מוצרים', '🖼 תמונות',
        'manage_woocommerce', 'shushka-images', 'shpk_images_page');
});

/* ── AJAX: שמירת/קריאת הגדרות באנר ───────────────────────── */
add_action('wp_ajax_shpk_get_shipping',  'shpk_get_shipping_handler');
add_action('wp_ajax_shpk_save_shipping', 'shpk_save_shipping_handler');

function shpk_get_shipping_handler() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    wp_send_json_success([
        'date'      => get_option('shushka_shipping_iso_date', ''), // ISO: YYYY-MM-DD
        'extra'     => get_option('shushka_shipping_extra', ''),
        'widget_id' => get_option('shushka_banner_widget',  'block-26'),
    ]);
}

function shpk_save_shipping_handler() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $iso = sanitize_text_field($_POST['date'] ?? '');
    if (DateTime::createFromFormat('Y-m-d', $iso)) {
        update_option('shushka_shipping_iso_date', $iso);
    }
    update_option('shushka_shipping_extra', sanitize_text_field($_POST['extra'] ?? ''));
    wp_send_json_success();
}

/* ── הדף עצמו ─────────────────────────────────────────────── */
function shpk_products_page() {
    if (!current_user_can('manage_woocommerce')) { wp_die('אין הרשאה'); }

    /* ייצוא CSV */
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        shpk_export_pos_csv();
        exit;
    }

    $wc_rest    = rtrim(rest_url('wc/v3'), '/');
    $wp_rest    = rtrim(rest_url('wp/v2'), '/');
    $nonce      = wp_create_nonce('wp_rest');
    $shpk_nonce = wp_create_nonce('shushka_nonce');
    $ajax_url   = admin_url('admin-ajax.php');
    $csv_url    = admin_url('admin.php?page=shushka-products&export=csv');
?>
<link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
#shpk-products *{box-sizing:border-box}
#shpk-products{font-family:'Rubik',sans-serif;background:#f7f4f0;color:#2b2b2b;font-size:14px;direction:rtl;margin:-10px -20px 0}
#shpk-products .hdr{background:#2d5a27;color:#fff;padding:.85rem 1.5rem;display:flex;align-items:center;gap:1rem;box-shadow:0 2px 6px rgba(0,0,0,.2)}
#shpk-products .hdr-h1{font-size:1.2rem;font-weight:700}
#shpk-products .hdr-sub{font-size:.8rem;opacity:.75;margin-top:.15rem}
#shpk-products .mod-badge{background:#f0a500;color:#fff;border-radius:12px;padding:.2rem .75rem;font-size:.78rem;font-weight:600;display:none}
#shpk-products .hdr-links{margin-right:auto;display:flex;gap:1rem;align-items:center}
#shpk-products .hdr-links a{color:#b8e0b5;font-size:.8rem;text-decoration:none}
#shpk-products .hdr-links a:hover{color:#fff}
#shpk-products .ship-section{background:#fffde7;border-bottom:2px solid #ffe082;padding:.7rem 1.5rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
#shpk-products .ship-section label{font-weight:600;font-size:.85rem;white-space:nowrap;color:#5a4800}
#shpk-products .ship-section input[type=text]{border:1px solid #ccc;border-radius:6px;padding:.38rem .65rem;font-family:'Rubik',sans-serif;font-size:.85rem}
#shpk-products .ship-section input:focus{outline:none;border-color:#2d5a27}
#shpk-products .btn-ship{background:#2d5a27;color:#fff;border:none;border-radius:6px;padding:.4rem 1rem;cursor:pointer;font-family:'Rubik',sans-serif;font-weight:600;font-size:.83rem;white-space:nowrap}
#shpk-products .btn-ship:hover{background:#245020}
#shpk-products .ship-status{font-size:.82rem;min-width:160px}
#shpk-products .ship-hint{font-size:.75rem;color:#a08030;margin-right:auto}
#shpk-products .filters{background:#fff;padding:.7rem 1.5rem;border-bottom:1px solid #e8e3dc;display:flex;gap:.55rem;align-items:center;flex-wrap:wrap}
#shpk-products .filters select,#shpk-products .filters input[type=text]{border:1px solid #d0cbc4;border-radius:6px;padding:.38rem .6rem;font-family:'Rubik',sans-serif;font-size:.84rem;background:#fff}
#shpk-products .filters select:focus,#shpk-products .filters input:focus{outline:none;border-color:#2d5a27}
#shpk-products .btn{border:none;border-radius:6px;padding:.38rem 1rem;cursor:pointer;font-family:'Rubik',sans-serif;font-weight:600;font-size:.83rem}
#shpk-products .btn-green{background:#2d5a27;color:#fff}#shpk-products .btn-green:hover{background:#245020}
#shpk-products .btn-gray{background:#eae7e2;color:#555}#shpk-products .btn-gray:hover{background:#ddd}
#shpk-products .btn-save-all{background:#f0a500;color:#fff;font-size:.85rem}#shpk-products .btn-save-all:hover{background:#d48f00}
#shpk-products .stats{background:#fff;padding:.35rem 1.5rem;border-bottom:1px solid #ede8e1;font-size:.77rem;color:#888}
#shpk-products .tbl-wrap{padding:1rem 1.5rem;overflow-x:auto}
#shpk-products table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07)}
#shpk-products thead th{background:#2d5a27;color:#fff;padding:.65rem .75rem;text-align:right;font-size:.82rem;font-weight:600;white-space:nowrap}
#shpk-products tbody td{padding:.45rem .6rem;border-bottom:1px solid #f3ede6;vertical-align:middle}
#shpk-products tbody tr:last-child td{border-bottom:none}
#shpk-products tbody tr:hover td{background:#faf7f3}
#shpk-products tbody tr.modified td{background:#fff9e6}
#shpk-products tbody tr.saved td{background:#f0faf0}
#shpk-products tbody tr.err td{background:#fff0f0}
#shpk-products .num{color:#aaa;font-size:.75rem;text-align:center}
#shpk-products .inp-name{border:1px solid #d8d0c8;border-radius:5px;padding:.32rem .5rem;font-family:'Rubik',sans-serif;font-size:.84rem;width:100%;min-width:180px;background:#fafaf8;transition:.15s;color:#2b2b2b}
#shpk-products .inp-name:hover{border-color:#aaa;background:#fff}
#shpk-products .inp-name:focus{border-color:#2d5a27;background:#fff;outline:none;box-shadow:0 0 0 2px rgba(45,90,39,.12)}
#shpk-products .inp-brand,#shpk-products .inp-tags{border:1px solid #d8d0c8;border-radius:5px;padding:.32rem .5rem;font-family:'Rubik',sans-serif;font-size:.84rem;width:100%;background:#fafaf8;transition:.15s;color:#2b2b2b}
#shpk-products .inp-brand:focus,#shpk-products .inp-tags:focus{border-color:#2d5a27;background:#fff;outline:none}
#shpk-products .td-brand{white-space:nowrap}
#shpk-products .td-brand input{width:calc(100% - 30px);display:inline-block}
#shpk-products .btn-suggest{background:none;border:none;cursor:pointer;font-size:1rem;padding:0 2px;vertical-align:middle;opacity:.7}
#shpk-products .btn-suggest:hover{opacity:1}
#shpk-products .sku{color:#aaa;font-size:.76rem;font-family:monospace;white-space:nowrap}
#shpk-products select.sel{border:1px solid #ddd;border-radius:5px;padding:.3rem .45rem;font-family:'Rubik',sans-serif;font-size:.8rem;width:100%;min-width:120px;background:#fff;cursor:pointer}
#shpk-products select.sel:focus{border-color:#2d5a27;outline:none}
#shpk-products .btn-save{background:#e8f5e9;color:#2d5a27;border:1px solid #a5d6a7;border-radius:5px;padding:.3rem .8rem;cursor:pointer;font-family:'Rubik',sans-serif;font-size:.8rem;font-weight:600;transition:.15s;white-space:nowrap}
#shpk-products .btn-save:hover{background:#2d5a27;color:#fff;border-color:#2d5a27}
#shpk-products .btn-save.saving{background:#fff9c4;color:#e65100;border-color:#ffd54f;cursor:wait}
#shpk-products .btn-save.ok{background:#c8e6c9;color:#1b5e20;border-color:#66bb6a}
#shpk-products .btn-save.fail{background:#ffcdd2;color:#b71c1c;border-color:#ef9a9a}
#shpk-products .act-btns{display:flex;flex-direction:column;gap:.28rem}
#shpk-products .btn-vis{background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;border-radius:5px;padding:.3rem .8rem;cursor:pointer;font-family:'Rubik',sans-serif;font-size:.8rem;font-weight:600;transition:.15s;white-space:nowrap}
#shpk-products .btn-vis:hover{background:#1565c0;color:#fff;border-color:#1565c0}
#shpk-products .btn-vis:disabled{opacity:.5;cursor:wait}
#shpk-products .btn-del{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;border-radius:5px;padding:.3rem .8rem;cursor:pointer;font-family:'Rubik',sans-serif;font-size:.8rem;font-weight:600;transition:.15s;white-space:nowrap}
#shpk-products .btn-del:hover{background:#c62828;color:#fff;border-color:#c62828}
#shpk-products .btn-del:disabled{opacity:.5;cursor:wait}
#shpk-products tbody tr.hidden-prod .inp-name{color:#aaa;text-decoration:line-through}
#shpk-products tbody tr.hidden-prod td{opacity:.6}
#shpk-products .pager{display:flex;gap:.4rem;padding:.9rem 1.5rem;justify-content:center;align-items:center;flex-wrap:wrap}
#shpk-products .pager button{background:#fff;border:1px solid #d0cbc4;border-radius:5px;padding:.35rem .7rem;cursor:pointer;font-family:'Rubik',sans-serif;font-size:.82rem;min-width:34px}
#shpk-products .pager button:hover{background:#f0ebe4}
#shpk-products .pager button.cur{background:#2d5a27;color:#fff;border-color:#2d5a27}
#shpk-products .pager button:disabled{opacity:.35;cursor:default}
#shpk-products .pg-info{font-size:.78rem;color:#888;padding:0 .5rem}
#shpk-products .loading-msg{text-align:center;padding:3rem;color:#888;font-size:1rem}
#shpk-products .toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:.6rem 1.4rem;border-radius:8px;font-size:.87rem;z-index:9999;opacity:0;transition:.25s;pointer-events:none;white-space:nowrap}
#shpk-products .toast.show{opacity:1}
@media(max-width:768px){
#shpk-products{margin:-10px -12px 0}
#shpk-products .hdr{flex-wrap:wrap;padding:.7rem 1rem;gap:.5rem}
#shpk-products .hdr-links{margin-right:0}
#shpk-products .ship-section{flex-direction:column;align-items:stretch;gap:.5rem;padding:.8rem 1rem}
#shpk-products .ship-section input[type=text]{width:100%!important;font-size:1rem;padding:.55rem .8rem}
#shpk-products .btn-ship{width:100%;padding:.65rem;font-size:.95rem}
#shpk-products .ship-hint{margin-right:0}
#shpk-products .ship-status{min-width:0}
#shpk-products .filters{flex-direction:column;gap:.5rem;padding:.8rem 1rem}
#shpk-products .filters select,#shpk-products .filters input[type=text]{width:100%!important;font-size:1rem;padding:.55rem .8rem}
#shpk-products .btn{width:100%;padding:.65rem;font-size:.95rem}
#shpk-products .stats{padding:.4rem 1rem}
#shpk-products .tbl-wrap{padding:.5rem .6rem}
#shpk-products table{background:transparent;box-shadow:none}
#shpk-products thead{display:none}
#shpk-products tbody tr{display:block;background:#fff;border-radius:10px;margin-bottom:.7rem;padding:.8rem 1rem;box-shadow:0 2px 8px rgba(0,0,0,.07)}
#shpk-products tbody tr.modified{background:#fff9e6}
#shpk-products tbody tr.saved{background:#f0faf0}
#shpk-products tbody tr.err{background:#fff0f0}
#shpk-products tbody tr:hover td{background:transparent}
#shpk-products tbody td{display:block;border-bottom:none;padding:.22rem 0}
#shpk-products tbody td.num{display:none}
#shpk-products tbody td.sku{display:none}
#shpk-products .inp-name{min-width:0;font-size:1rem;padding:.55rem .7rem}
#shpk-products .sku{display:block;padding-bottom:.2rem}
#shpk-products select.sel{min-width:0;width:100%;font-size:.97rem;padding:.48rem .6rem}
#shpk-products .btn-save,#shpk-products .btn-vis,#shpk-products .btn-del{width:100%;padding:.6rem;font-size:.95rem}
#shpk-products .act-btns{margin-top:.35rem;gap:.4rem}
#shpk-products .pager{padding:.7rem .5rem;gap:.3rem}
#shpk-products .pager button{min-width:42px;padding:.45rem .7rem;font-size:.92rem}
#shpk-products .toast{white-space:normal;text-align:center;width:80%;max-width:300px}
}
</style>

<div id="shpk-products">
<div class="hdr">
  <div>
    <div class="hdr-h1">🌿 שושקה – ניהול מוצרים</div>
    <div class="hdr-sub" id="shpk-total-lbl">טוען...</div>
  </div>
  <span class="mod-badge" id="shpk-mod-badge"></span>
  <div class="hdr-links">
    <a href="https://shushka.co.il/shop/" target="_blank">👁 חנות ↗</a>
    <a href="<?php echo esc_url($csv_url); ?>" title="ייצוא כל המוצרים לקובץ CSV לקופה">📥 CSV לקופה</a>
  </div>
</div>

<div class="ship-section">
  <label>📦 באנר משלוח:</label>
  <input id="shpk-ship-date" type="date" style="width:160px" onchange="shpkUpdateDatePreview()">
  <span id="shpk-date-preview" style="font-size:.85rem;color:#7a6200;font-style:italic"></span>
  <input id="shpk-ship-extra" type="text" placeholder="הערה נוספת (אופציונלי)" style="width:240px">
  <button class="btn-ship" onclick="shpkUpdateShipping()">עדכן באנר בחנות ↑</button>
  <span class="ship-status" id="shpk-ship-status"></span>
  <span class="ship-hint">לבדיקה: פתח חלון גלישה פרטי</span>
</div>

<div class="filters">
  <select id="shpk-f-cat" onchange="shpkOnParentChange()"><option value="">כל הקטגוריות</option></select>
  <select id="shpk-f-sub"><option value="">כל תת-הקטגוריות</option></select>
  <input id="shpk-f-search" type="text" placeholder="חיפוש לפי שם מוצר..." style="width:225px"
    onkeydown="if(event.key==='Enter')shpkLoad(1)">
  <button class="btn btn-green"    onclick="shpkLoad(1)">🔍 חפש</button>
  <button class="btn btn-gray"     onclick="shpkClearFilters()">נקה</button>
  <button class="btn btn-save-all" id="shpk-btn-save-all" onclick="shpkSaveAll()" style="display:none">✔ שמור הכל</button>
</div>
<div class="stats" id="shpk-stats">–</div>

<div class="tbl-wrap">
  <div class="loading-msg" id="shpk-loading">⏳ טוען מוצרים...</div>
  <table id="shpk-tbl" style="display:none">
    <thead><tr>
      <th style="width:38px">#</th>
      <th style="min-width:200px">✏ שם מוצר</th>
      <th style="width:85px">SKU</th>
      <th style="min-width:130px">קטגוריה</th>
      <th style="min-width:130px">תת-קטגוריה</th>
      <th style="min-width:110px">מותג</th>
      <th style="min-width:160px">תגיות</th>
      <th style="width:100px"></th>
    </tr></thead>
    <tbody id="shpk-tbody"></tbody>
  </table>
</div>
<div class="pager" id="shpk-pager"></div>
<div class="toast" id="shpk-toast"></div>
</div><!-- #shpk-products -->

<script>
(function() {
'use strict';
var WC_REST    = '<?php echo esc_url($wc_rest); ?>';
var WP_REST    = '<?php echo esc_url($wp_rest); ?>';
var WP_NONCE   = '<?php echo esc_js($nonce); ?>';
var SHPK_NONCE = '<?php echo esc_js($shpk_nonce); ?>';
var AJAX_URL   = '<?php echo esc_url($ajax_url); ?>';

var cats = [], parents = [], children = {};
var totalPages = 1, modified = new Set();
var shippingWidgetId = 'block-26';

function apiHdrs() { return {'X-WP-Nonce': WP_NONCE, 'Content-Type': 'application/json'}; }
function wcGet(path)       { return fetch(WC_REST + path, {headers: apiHdrs()}); }
function wcPut(path, body) { return fetch(WC_REST + path, {method:'PUT',    headers: apiHdrs(), body: JSON.stringify(body)}); }
function wcDel(path)       { return fetch(WC_REST + path, {method:'DELETE', headers: apiHdrs()}); }
function wpPut(path, body) { return fetch(WP_REST + path, {method:'PUT',    headers: apiHdrs(), body: JSON.stringify(body)}); }
function ajaxPost(action, params) {
    var p = Object.assign({action: action, nonce: SHPK_NONCE}, params);
    return fetch(AJAX_URL, {method:'POST', body: new URLSearchParams(p)}).then(function(r){ return r.json(); });
}

async function shpkInit() {
    var all = [], page = 1;
    while (true) {
        var r = await wcGet('/products/categories?per_page=100&page=' + page + '&orderby=name');
        var data = await r.json();
        all = all.concat(data);
        if (page >= parseInt(r.headers.get('X-WP-TotalPages') || 1)) break;
        page++;
    }
    cats    = all;
    parents = cats.filter(function(c){ return c.parent === 0 && c.slug !== 'uncategorized'; });
    cats.filter(function(c){ return c.parent !== 0; }).forEach(function(c){
        (children[c.parent] = children[c.parent] || []).push(c);
    });
    var sel = document.getElementById('shpk-f-cat');
    parents.forEach(function(c){ sel.add(new Option(c.name + ' (' + c.count + ')', c.id)); });
    shpkLoad(1);
    shpkLoadShipping();
}

window.shpkOnParentChange = function() {
    var pid = +document.getElementById('shpk-f-cat').value || 0;
    var sub = document.getElementById('shpk-f-sub');
    sub.innerHTML = '<option value="">כל תת-הקטגוריות</option>';
    (children[pid] || []).forEach(function(c){ sub.add(new Option(c.name + ' (' + c.count + ')', c.id)); });
    shpkLoad(1);
};

window.shpkLoad = async function(page) {
    page = page || 1;
    document.getElementById('shpk-loading').style.display = 'block';
    document.getElementById('shpk-tbl').style.display = 'none';
    var cat = document.getElementById('shpk-f-sub').value || document.getElementById('shpk-f-cat').value;
    var q   = document.getElementById('shpk-f-search').value.trim();
    var path = '/products?per_page=50&page=' + page + '&status=publish&orderby=id&order=asc';
    if (cat) path += '&category=' + cat;
    if (q)   path += '&search='   + encodeURIComponent(q);
    var r     = await wcGet(path);
    var prods = await r.json();
    var total  = parseInt(r.headers.get('X-WP-Total')      || 0);
    totalPages = parseInt(r.headers.get('X-WP-TotalPages') || 1);
    document.getElementById('shpk-total-lbl').textContent = total + ' מוצרים בסך הכל';
    document.getElementById('shpk-stats').textContent =
        'עמוד ' + page + ' מתוך ' + totalPages + ' | ' + prods.length + ' מוצרים מוצגים מתוך ' + total;
    shpkRender(prods, page);
    shpkRenderPager(page, totalPages);
    document.getElementById('shpk-loading').style.display = 'none';
    document.getElementById('shpk-tbl').style.display = 'table';
};

function shpkRender(prods, page) {
    var tbody = document.getElementById('shpk-tbody');
    tbody.innerHTML = '';
    prods.forEach(function(p, i) {
        var parentId = 0, subId = 0;
        (p.categories || []).forEach(function(pc) {
            var full = cats.find(function(c){ return c.id === pc.id; });
            if (!full) return;
            if (full.parent === 0) { if (!subId) parentId = full.id; }
            else { subId = full.id; parentId = full.parent; }
        });
        var tr = document.createElement('tr');
        tr.dataset.pid    = p.id;
        tr.dataset.status = p.catalog_visibility || 'visible';
        tr.dataset.attrs  = JSON.stringify(p.attributes || []);
        if (p.catalog_visibility === 'hidden') tr.classList.add('hidden-prod');
        var skuRaw = p.sku || '';
        var skuNum = parseFloat(skuRaw);
        var sku = skuRaw && !isNaN(skuNum) ? String(Math.round(skuNum)) : (skuRaw || '–');
        var visLabel = p.catalog_visibility === 'hidden' ? 'הצג' : 'הסתר';
        var brandAttr = (p.attributes || []).find(function(a){ return a.name === 'מותג' || a.name.toLowerCase() === 'brand'; });
        var brandVal  = brandAttr ? ((brandAttr.options || [])[0] || '') : '';
        var tagsVal   = (p.tags || []).map(function(t){ return t.name; }).join(', ');
        tr.innerHTML =
            '<td class="num">' + ((page-1)*50+i+1) + '</td>' +
            '<td><input class="inp-name" type="text" value="' + shpkEsc(p.name) + '" oninput="shpkMark(this)"></td>' +
            '<td class="sku">' + shpkEsc(sku) + '</td>' +
            '<td>' + shpkCatSel(parentId) + '</td>' +
            '<td>' + shpkSubSel(parentId, subId) + '</td>' +
            '<td class="td-brand"><input class="inp-brand" type="text" value="' + shpkEsc(brandVal) + '" placeholder="מותג" oninput="shpkMark(this)"><button class="btn-suggest" onclick="shpkSuggest(this)" title="הצע AI">💡</button></td>' +
            '<td><input class="inp-tags" type="text" value="' + shpkEsc(tagsVal) + '" placeholder="תגית1, תגית2" oninput="shpkMark(this)"></td>' +
            '<td><div class="act-btns">' +
              '<button class="btn-save" onclick="shpkSave(this)">שמור</button>' +
              '<button class="btn-vis"  onclick="shpkToggle(this)">' + visLabel + '</button>' +
              '<button class="btn-del"  onclick="shpkDelete(this)">מחק</button>' +
            '</div></td>';
        tbody.appendChild(tr);
        tr.querySelector('select.parent-sel').addEventListener('change', function() {
            var pid2 = +this.value || 0;
            var sub  = tr.querySelector('select.sub-sel');
            sub.innerHTML = '<option value="">— תת-קטגוריה —</option>';
            (children[pid2] || []).forEach(function(c){ sub.add(new Option(c.name, c.id)); });
            shpkMark(this);
        });
        tr.querySelector('select.sub-sel').addEventListener('change', function(){ shpkMark(this); });
    });
}

function shpkCatSel(selected) {
    var h = '<select class="sel parent-sel"><option value="">— קטגוריה —</option>';
    parents.forEach(function(c){ h += '<option value="'+c.id+'"'+(c.id===selected?' selected':'')+'>'+shpkEsc(c.name)+'</option>'; });
    return h + '</select>';
}
function shpkSubSel(parentId, selected) {
    var h = '<select class="sel sub-sel"><option value="">— תת-קטגוריה —</option>';
    (children[parentId]||[]).forEach(function(c){ h += '<option value="'+c.id+'"'+(c.id===selected?' selected':'')+'>'+shpkEsc(c.name)+'</option>'; });
    return h + '</select>';
}

window.shpkSave = async function(btn) {
    var tr    = btn.closest('tr');
    var pid   = +tr.dataset.pid;
    var name  = tr.querySelector('.inp-name').value.trim();
    var parId = +tr.querySelector('.parent-sel').value || 0;
    var subId = +tr.querySelector('.sub-sel').value   || 0;
    var catId = subId || parId;
    var tagsStr    = (tr.querySelector('.inp-tags') || {value:''}).value.trim();
    var tagsList   = tagsStr ? tagsStr.split(',').map(function(t){ return {name: t.trim()}; }).filter(function(t){ return t.name; }) : [];
    var brandStr   = (tr.querySelector('.inp-brand') || {value:''}).value.trim();
    var existAttrs = JSON.parse(tr.dataset.attrs || '[]');
    var brandIdx   = existAttrs.findIndex(function(a){ return a.name === 'מותג' || a.name.toLowerCase() === 'brand'; });
    if (brandStr) {
        if (brandIdx >= 0) existAttrs[brandIdx].options = [brandStr];
        else existAttrs.push({name: 'מותג', options: [brandStr], visible: true, variation: false});
    } else if (brandIdx >= 0) {
        existAttrs.splice(brandIdx, 1);
    }
    btn.textContent = '...'; btn.className = 'btn-save saving';
    tr.classList.remove('modified','saved','err');
    try {
        var r = await wcPut('/products/' + pid, {name: name, categories: catId ? [{id: catId}] : [], tags: tagsList, attributes: existAttrs});
        var d = await r.json();
        if (d.code) throw new Error(d.message || 'שגיאה');
        tr.dataset.attrs = JSON.stringify(d.attributes || existAttrs);
        tr.classList.add('saved');
        btn.textContent = '✓'; btn.className = 'btn-save ok';
        modified.delete(String(pid)); shpkRefreshBadge();
        setTimeout(function(){ btn.textContent='שמור'; btn.className='btn-save'; tr.classList.remove('saved'); }, 2200);
    } catch(e) {
        tr.classList.add('err'); btn.textContent = '✗'; btn.className = 'btn-save fail';
        shpkToast('שגיאה: ' + e.message);
        setTimeout(function(){ btn.textContent='שמור'; btn.className='btn-save'; }, 3000);
    }
};

window.shpkToggle = async function(btn) {
    var tr       = btn.closest('tr');
    var pid      = +tr.dataset.pid;
    var isHidden = tr.dataset.status === 'hidden';
    var newVis   = isHidden ? 'visible' : 'hidden';
    btn.disabled = true; btn.textContent = '...';
    try {
        var r = await wcPut('/products/' + pid, {catalog_visibility: newVis});
        var d = await r.json();
        if (d.code) throw new Error(d.message || 'שגיאה');
        tr.dataset.status = newVis;
        if (newVis === 'hidden') { tr.classList.add('hidden-prod'); btn.textContent = 'הצג'; }
        else                     { tr.classList.remove('hidden-prod'); btn.textContent = 'הסתר'; }
        shpkToast(newVis === 'hidden' ? 'המוצר הוסתר מהחנות' : 'המוצר מוצג שוב בחנות');
    } catch(e) {
        shpkToast('שגיאה: ' + e.message);
        btn.textContent = isHidden ? 'הצג' : 'הסתר';
    }
    btn.disabled = false;
};

window.shpkDelete = async function(btn) {
    var tr   = btn.closest('tr');
    var name = tr.querySelector('.inp-name').value.trim();
    if (!window.confirm('למחוק את המוצר "' + name + '"?\n(יועבר לפח — ניתן לשחזר מלוח הבקרה)')) return;
    var pid  = +tr.dataset.pid;
    btn.disabled = true; btn.textContent = '...';
    try {
        var r = await wcDel('/products/' + pid);
        var d = await r.json();
        if (d.code) throw new Error(d.message || 'שגיאה');
        tr.style.transition = 'opacity .3s';
        tr.style.opacity = '0';
        setTimeout(function(){ tr.remove(); modified.delete(String(pid)); shpkRefreshBadge(); }, 320);
        shpkToast('המוצר נמחק (הועבר לפח)');
    } catch(e) {
        shpkToast('שגיאה: ' + e.message);
        btn.disabled = false; btn.textContent = 'מחק';
    }
};

window.shpkSuggest = async function(btn) {
    var tr   = btn.closest('tr');
    var name = tr.querySelector('.inp-name').value.trim();
    var catEl = tr.querySelector('.parent-sel');
    var cat  = catEl ? (catEl.options[catEl.selectedIndex] || {text:''}).text : '';
    btn.disabled = true; btn.textContent = '⏳';
    try {
        var d = await shpkAjax('shpk_suggest_tags', {name: name, cat: cat});
        if (d.success) {
            var brandInp = tr.querySelector('.inp-brand');
            var tagsInp  = tr.querySelector('.inp-tags');
            if (d.data.brand && brandInp && !brandInp.value) brandInp.value = d.data.brand;
            if (d.data.tags  && d.data.tags.length && tagsInp && !tagsInp.value)
                tagsInp.value = d.data.tags.join(', ');
            shpkMark(btn);
            shpkToast('הוצעו תגיות ומותג — בדוק ושמור');
        } else {
            shpkToast('שגיאה: ' + d.data);
        }
    } catch(e) { shpkToast('שגיאת רשת'); }
    btn.disabled = false; btn.textContent = '💡';
};

window.shpkMark = function(el) {
    var tr = el.closest('tr'); tr.classList.add('modified'); modified.add(tr.dataset.pid); shpkRefreshBadge();
};

function shpkRefreshBadge() {
    var b = document.getElementById('shpk-mod-badge');
    var s = document.getElementById('shpk-btn-save-all');
    b.style.display = s.style.display = modified.size ? 'inline' : 'none';
    b.textContent = modified.size + ' שינויים ממתינים';
    s.textContent = '✔ שמור את כל השינויים (' + modified.size + ')';
}

window.shpkSaveAll = async function() {
    var btn  = document.getElementById('shpk-btn-save-all');
    var rows = Array.from(document.querySelectorAll('#shpk-tbody tr.modified'));
    if (!rows.length) return;
    btn.disabled = true; btn.textContent = 'שומר...';
    for (var i = 0; i < rows.length; i++) await shpkSave(rows[i].querySelector('.btn-save'));
    btn.disabled = false; btn.style.display = 'none';
};

function shpkRenderPager(page, total) {
    var p = document.getElementById('shpk-pager');
    p.innerHTML = '';
    p.style.direction = 'ltr';
    if (total <= 1) return;
    function mkBtn(txt, pg, cls) {
        var b = document.createElement('button'); b.textContent = txt; if (cls) b.className = cls;
        b.disabled = (pg < 1 || pg > total); b.onclick = function(){ shpkLoad(pg); }; p.appendChild(b);
    }
    function mkDot() { var s = document.createElement('span'); s.style.padding='0 .3rem'; s.textContent='…'; p.appendChild(s); }
    mkBtn('←', page-1);
    if (page > 3) { mkBtn('1', 1); if (page > 4) mkDot(); }
    for (var i = Math.max(1,page-2); i <= Math.min(total,page+2); i++) mkBtn(i, i, i===page?'cur':'');
    if (page < total-2) { if (page < total-3) mkDot(); mkBtn(total, total); }
    mkBtn('→', page+1);
    var info = document.createElement('span');
    info.className = 'pg-info'; info.textContent = page + ' / ' + total; p.appendChild(info);
}

function shpkNextThursday() {
    var d = new Date();
    var dow = d.getDay(); // 0=Sun,1=Mon,...,4=Thu
    var delta = (4 - dow + 7) % 7;
    if (delta === 0) delta = 7; // today is Thursday → next Thursday
    d.setDate(d.getDate() + delta);
    return d.toISOString().slice(0, 10);
}

function shpkFormatHebrew(iso) {
    if (!iso) return '';
    var d = new Date(iso + 'T12:00:00');
    var names = ['ראשון','שני','שלישי','רביעי','חמישי','שישי','שבת'];
    return 'יום ' + names[d.getDay()] + ' ' + d.getDate() + '.' + (d.getMonth() + 1);
}

window.shpkUpdateDatePreview = function() {
    var val = document.getElementById('shpk-ship-date').value;
    document.getElementById('shpk-date-preview').textContent = shpkFormatHebrew(val);
};

function shpkLoadShipping() {
    ajaxPost('shpk_get_shipping', {}).then(function(d) {
        if (!d.success) return;
        if (d.data.widget_id) shippingWidgetId = d.data.widget_id;
        var today = new Date().toISOString().slice(0, 10);
        var iso = (d.data.date && d.data.date > today) ? d.data.date : shpkNextThursday();
        document.getElementById('shpk-ship-date').value = iso;
        shpkUpdateDatePreview();
        if (d.data.extra) document.getElementById('shpk-ship-extra').value = d.data.extra;
    });
}

function shpkMakeBanner(date, extra) {
    var extraHtml = extra ? '<br><span style="font-size:.85rem;opacity:.85">' + extra + '</span>' : '';
    var js = '(function(){if(sessionStorage.getItem(\'sk_sb\'))return;var b=document.getElementById(\'sk-ship-bar\');if(!b)return;b.style.display=\'block\';document.body.prepend?document.body.prepend(b):document.body.insertBefore(b,document.body.firstChild);})();';
    return '<!-- wp:html -->\n' +
        '<div id="sk-ship-bar" style="display:none;background:#2d5a27;color:#fff;padding:.9rem 8rem .9rem 1.5rem;text-align:center;font-family:Rubik,sans-serif;font-size:1.05rem;position:relative;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.25);line-height:1.5">\n' +
        '  &#x1F4E6; <strong>המשלוח הבא: ' + date + '</strong>&nbsp;&nbsp;|&nbsp;&nbsp;&#x1F69A; משלוח רק 10&#8362;&nbsp;&nbsp;|&nbsp;&nbsp;חינם מהזמנה מעל 380&#8362;' + extraHtml + '\n' +
        '  <button onclick="document.getElementById(\'sk-ship-bar\').style.display=\'none\';sessionStorage.setItem(\'sk_sb\',\'1\')" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);border-radius:6px;color:#fff;font-size:.85rem;font-family:Rubik,sans-serif;padding:.3rem .7rem;cursor:pointer;font-weight:600;white-space:nowrap" aria-label="סגור">הבנתי &#x2713;</button>\n' +
        '</div>\n' +
        '<scr' + 'ipt>\n' + js + '\n</sc' + 'ript>\n' +
        '<!-- /wp:html -->';
}

window.shpkUpdateShipping = async function() {
    var iso   = document.getElementById('shpk-ship-date').value;
    var extra = document.getElementById('shpk-ship-extra').value.trim();
    var st    = document.getElementById('shpk-ship-status');
    if (!iso) { shpkToast('יש לבחור תאריך משלוח'); return; }
    var hebrewDate = shpkFormatHebrew(iso);
    st.textContent = 'מעדכן...'; st.style.color = '#888';
    try {
        var saved = await ajaxPost('shpk_save_shipping', {date: iso, extra: extra});
        if (!saved.success) throw new Error('שגיאה בשמירה');
        var wr = await wpPut('/widgets/' + shippingWidgetId,
            {sidebar: 'footer1', instance: {raw: {content: shpkMakeBanner(hebrewDate, extra)}}});
        var wd = await wr.json();
        if (wd.code) throw new Error(wd.message || 'widget error');
        st.textContent = '✓ הבאנר עודכן — ' + hebrewDate; st.style.color = '#2d5a27';
        setTimeout(function(){ st.textContent = ''; }, 6000);
    } catch(e) {
        st.textContent = '✗ ' + e.message; st.style.color = '#c62828';
    }
};

window.shpkClearFilters = function() {
    document.getElementById('shpk-f-cat').value = '';
    document.getElementById('shpk-f-sub').innerHTML = '<option value="">כל תת-הקטגוריות</option>';
    document.getElementById('shpk-f-search').value = '';
    shpkLoad(1);
};
function shpkEsc(s) {
    return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function shpkToast(msg) {
    var t = document.getElementById('shpk-toast'); t.textContent = msg; t.classList.add('show');
    setTimeout(function(){ t.classList.remove('show'); }, 3500);
}

shpkInit();
})();
</script>
<?php
}

/* ── Live product search ─────────────────────────────────── */

add_action('wp_ajax_nopriv_shpk_search', 'shpk_search_handler');
add_action('wp_ajax_shpk_search',        'shpk_search_handler');

function shpk_search_handler() {
    $q = sanitize_text_field($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { wp_send_json_success([]); return; }

    $wq = new WP_Query([
        's'              => $q,
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 12,
    ]);

    $out = [];
    foreach ($wq->posts as $post) {
        $p = wc_get_product($post->ID);
        if (!$p || !$p->is_visible()) continue;
        $img = wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail')
             ?: wc_placeholder_img_src('woocommerce_thumbnail');
        $out[] = [
            'id'    => $post->ID,
            'name'  => $p->get_name(),
            'price' => $p->get_price() ? wc_price($p->get_price()) : '',
            'url'   => get_permalink($post->ID),
            'img'   => $img ?: '',
        ];
    }
    wp_send_json_success($out);
}

// Intercept WP search + custom shpk_search GET param → show our live search page
add_action('template_redirect', function() {
    if (is_admin()) return;
    $is_wp_search  = is_search();
    $is_shpk_param = isset($_GET['shpk_search']);
    if (!$is_wp_search && !$is_shpk_param) return;

    $initial = $is_wp_search
        ? get_search_query()
        : sanitize_text_field($_GET['q'] ?? '');

    shpk_render_search_page($initial);
    exit;
}, 1);

add_action('wp_head', function() {
    if (is_admin()) return;
    ?>
<style>
/* Search icon — desktop: green background */
.search-toggle-open-container .search-toggle-open {
    background: #2d5a27 !important;
    color: #fff !important;
    border-radius: 8px !important;
    padding: 6px 11px !important;
}
.search-toggle-open-container .search-toggle-open:hover,
.search-toggle-open-container .search-toggle-open:focus {
    background: #1e3e1a !important;
    opacity: 1 !important;
}
/* Mobile search button (injected by JS) */
.shpk-mob-search {
    background: #2d5a27;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 6px 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    margin-left: 6px;
}
.shpk-mob-search:hover { background: #1e3e1a; }
/* Shipping banner — mobile: reduce padding, move close button below */
@media(max-width:640px){
    #sk-ship-bar {
        padding: .85rem 1rem 3rem !important;
        text-align: center !important;
    }
    #sk-ship-bar button {
        top: auto !important;
        bottom: .45rem !important;
        left: 50% !important;
        right: auto !important;
        transform: translateX(-50%) !important;
    }
}
/* WhatsApp button in "יש שאלה" section — reduce icon size on mobile */
@media(max-width:640px){
    .kb-btn7933_737672-0f.kb-button .kb-svg-icon-wrap {
        font-size: 28px !important;
        --kb-button-icon-size: 28px !important;
    }
    .kb-btn7933_737672-0f.kb-button {
        min-width: 180px;
    }
}
</style>
    <?php
});

add_action('wp_footer', function() {
    if (is_admin()) return;
    $search_url = esc_url(home_url('/?shpk_search=1'));
    ?>
<script>
(function(){
var SHPK_S = <?php echo json_encode($search_url); ?>;
document.addEventListener('DOMContentLoaded', function() {
    // Desktop: intercept Kadence search drawer button → go to live search page
    var btn = document.querySelector('[data-toggle-target="#search-drawer"]');
    if (btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.location.href = SHPK_S;
        }, true);
    }
    // Mobile: add search button to mobile header (Kadence hides desktop search on mobile)
    var mobileRight = document.querySelector('.site-mobile-header-wrap .site-header-main-section-right');
    if (mobileRight && !mobileRight.querySelector('.shpk-mob-search')) {
        var searchBtn = document.createElement('button');
        searchBtn.className = 'shpk-mob-search';
        searchBtn.setAttribute('aria-label', 'חיפוש');
        searchBtn.innerHTML = '<svg fill="currentColor" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 26 28"><path d="M18 13c0-3.859-3.141-7-7-7s-7 3.141-7 7 3.141 7 7 7 7-3.141 7-7zM26 26c0 1.094-0.906 2-2 2-0.531 0-1.047-0.219-1.406-0.594l-5.359-5.344c-1.828 1.266-4.016 1.937-6.234 1.937-6.078 0-11-4.922-11-11s4.922-11 11-11 11 4.922 11 11c0 2.219-0.672 4.406-1.937 6.234l5.359 5.359c0.359 0.359 0.578 0.875 0.578 1.406z"/></svg>';
        searchBtn.onclick = function() { window.location.href = SHPK_S; };
        mobileRight.insertBefore(searchBtn, mobileRight.firstChild);
    }
});
})();
</script>
    <?php
});

function shpk_render_search_page($initial_q = '') {
    global $wp_query;
    $wp_query->is_search = false; // prevent theme from applying search-specific styling
    $ajax_url = esc_url(admin_url('admin-ajax.php'));
    get_header();
    ?>
<div id="shpk-search-page">
  <div class="shpk-search-hero">
    <h1>חיפוש מוצרים</h1>
    <div class="shpk-search-box-wrap">
      <input id="shpk-q" type="text" autocomplete="off" autofocus
        placeholder="חפש מוצר, קטגוריה..."
        value="<?php echo esc_attr($initial_q); ?>">
      <span id="shpk-spinner" class="shpk-spinner" aria-hidden="true"></span>
    </div>
  </div>
  <div class="shpk-search-body">
    <div id="shpk-hint">הקלד לפחות 2 אותיות לחיפוש</div>
    <div id="shpk-results"></div>
  </div>
</div>

<style>
#shpk-search-page{font-family:Rubik,Arial,sans-serif;direction:rtl;min-height:60vh;background:#f7f5f0}
.shpk-search-hero{background:#2d5a27;padding:2.2rem 1.5rem 2rem;text-align:center}
.shpk-search-hero h1{color:#fff;font-size:1.55rem;font-weight:700;margin:0 0 1.1rem}
.shpk-search-box-wrap{max-width:540px;margin:0 auto;position:relative}
#shpk-q{width:100%;font-size:1.1rem;padding:.75rem 1.1rem;border-radius:50px;border:none;font-family:Rubik,Arial,sans-serif;direction:rtl;box-sizing:border-box;outline:none;box-shadow:0 2px 14px rgba(0,0,0,.22)}
#shpk-q:focus{box-shadow:0 2px 18px rgba(0,0,0,.32)}
.shpk-spinner{position:absolute;left:1rem;top:50%;transform:translateY(-50%);width:18px;height:18px;border:2px solid #ccc;border-top-color:#2d5a27;border-radius:50%;animation:shpk-spin .7s linear infinite;display:none}
@keyframes shpk-spin{to{transform:translateY(-50%) rotate(360deg)}}
.shpk-search-body{max-width:1200px;margin:0 auto;padding:1.5rem 1.2rem 3rem}
#shpk-hint{text-align:center;color:#888;padding:2.5rem 0;font-size:1rem}
#shpk-count{color:#666;font-size:.88rem;margin-bottom:.9rem}
#shpk-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.1rem}
@media(max-width:900px){#shpk-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:580px){#shpk-grid{grid-template-columns:repeat(2,1fr)}}
.shpk-card{background:#fff;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 2px 8px rgba(0,0,0,.07);display:block;transition:box-shadow .15s,transform .15s}
.shpk-card:hover,.shpk-card:focus{box-shadow:0 5px 18px rgba(0,0,0,.13);transform:translateY(-2px);color:inherit;text-decoration:none}
.shpk-card img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
.shpk-card-body{padding:.55rem .8rem .75rem}
.shpk-card-name{font-size:.85rem;font-weight:600;color:#222;line-height:1.3;margin-bottom:.3rem}
.shpk-card-price{font-size:.9rem;color:#2d5a27;font-weight:700}
.shpk-card-price .woocommerce-Price-currencySymbol{font-size:.8em}
</style>

<script>
(function(){
var AJAX = <?php echo json_encode($ajax_url); ?>;
var q = document.getElementById('shpk-q');
var hint = document.getElementById('shpk-hint');
var results = document.getElementById('shpk-results');
var spinner = document.querySelector('.shpk-spinner');
var timer = null;

q.addEventListener('input', function() {
    clearTimeout(timer);
    var val = this.value.trim();
    if (val.length < 2) {
        results.innerHTML = '';
        hint.style.display = 'block';
        hint.innerHTML = 'הקלד לפחות 2 אותיות לחיפוש';
        spinner.style.display = 'none';
        return;
    }
    spinner.style.display = 'block';
    timer = setTimeout(function(){ doSearch(val); }, 300);
});

function doSearch(query) {
    fetch(AJAX + '?action=shpk_search&q=' + encodeURIComponent(query))
        .then(function(r){ return r.json(); })
        .then(function(d){
            spinner.style.display = 'none';
            if (!d.success || !d.data.length) {
                results.innerHTML = '';
                hint.style.display = 'block';
                hint.innerHTML = '&#128269; לא נמצאו מוצרים עבור &ldquo;' + escH(query) + '&rdquo;';
                return;
            }
            hint.style.display = 'none';
            results.innerHTML =
                '<div id="shpk-count">נמצאו ' + d.data.length + ' מוצרים</div>' +
                '<div id="shpk-grid">' +
                d.data.map(function(p){
                    return '<a href="' + escH(p.url) + '" class="shpk-card">' +
                        '<img src="' + escH(p.img) + '" alt="" loading="lazy">' +
                        '<div class="shpk-card-body">' +
                        '<div class="shpk-card-name">' + escH(p.name) + '</div>' +
                        (p.price ? '<div class="shpk-card-price">' + p.price + '</div>' : '') +
                        '</div></a>';
                }).join('') +
                '</div>';
        })
        .catch(function(){ spinner.style.display = 'none'; });
}

function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

var initVal = q.value.trim();
if (initVal.length >= 2) { spinner.style.display = 'block'; setTimeout(function(){ doSearch(initVal); }, 80); }
})();
</script>
    <?php
    get_footer();
}


/* ═══════════════════════════════════════════════════════════
   ייצוא CSV לקופה הרושמת
   ═══════════════════════════════════════════════════════════ */

function shpk_export_pos_csv() {
    if (!current_user_can('manage_woocommerce')) { wp_die('אין הרשאה'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shushka-pos-' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM for Excel Hebrew

    fputcsv($out, ['קוד פריט ', 'ברקוד ', 'תאור פריט ', 'מחיר קניה ', 'מחיר מכירה ',
                   'חברתית', 'מהפרדס', 'אחוז רווח ', 'שקיל ', 'מעמ',
                   'שם מחלקה ', 'מכר', 'מלאי נוכחי ', 'יצרן', 'מותג', 'יחידת מידה ', '']);

    $row_num = 1;
    $page    = 1;
    while (true) {
        $query   = new WC_Product_Query(['status' => 'publish', 'limit' => 100, 'page' => $page,
                                         'return' => 'objects', 'paginate' => true]);
        $result  = $query->get_products();
        if (empty($result->products)) break;

        foreach ($result->products as $product) {
            /* קטגוריה */
            $cat_ids  = $product->get_category_ids();
            $cat_name = '';
            if ($cat_ids) {
                $term = get_term(end($cat_ids), 'product_cat');
                if ($term && !is_wp_error($term)) $cat_name = $term->name;
            }

            /* מותג מ-attribute */
            $brand = '';
            foreach ($product->get_attributes() as $attr) {
                $aname = $attr->get_name();
                if ($aname === 'מותג' || strtolower($aname) === 'brand' || $aname === 'pa_brand') {
                    $terms = $attr->get_terms();
                    if ($terms) { $brand = $terms[0]->name; break; }
                    $opts = $attr->get_options();
                    if ($opts) { $brand = $opts[0]; break; }
                }
            }

            $price = $product->get_price();
            $stock = $product->get_stock_quantity();
            $sku   = $product->get_sku();

            fputcsv($out, [
                $row_num,
                $sku,
                $product->get_name(),
                '',
                $price !== '' ? number_format((float)$price, 2, '.', '') : '',
                '', '', '',
                'לא',
                18,
                $cat_name,
                '',
                $stock !== null ? $stock : '',
                '',
                $brand,
                "יח'",
                '',
            ]);
            $row_num++;
        }

        if ($page >= $result->max_num_pages) break;
        $page++;
    }

    fclose($out);
}

/* ═══════════════════════════════════════════════════════════
   דף סקירת תמונות מוצרים
   ═══════════════════════════════════════════════════════════ */

/* ── AJAX: הצעת תגיות ומותג ─────────────────────────────── */
add_action('wp_ajax_shpk_suggest_tags', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $name = sanitize_text_field($_POST['name'] ?? '');
    $cat  = sanitize_text_field($_POST['cat']  ?? '');
    $key  = get_option('shushka_openai_key', '');
    if (!$key) { wp_send_json_error('מפתח OpenAI לא מוגדר'); return; }

    @set_time_limit(120);
    try {
        $resp = shpk_openai_chat($key, [[
            'role' => 'user',
            'content' =>
                "For this product in a Hebrew natural health food store, suggest relevant Hebrew tags and brand.\n" .
                "Product: {$name}\nCategory: {$cat}\n\n" .
                "Respond with ONLY valid JSON, no markdown:\n" .
                "{\"brand\": \"brand name if clearly identifiable from the product name, else empty string\", " .
                "\"tags\": [\"Hebrew tag1\", \"tag2\", \"tag3\"]}\n\n" .
                "Rules: 3-6 short Hebrew descriptors for tags (ingredient, type, dietary info). Brand only if clear."
        ]], 200);
        $content = trim($resp['choices'][0]['message']['content'] ?? '');
        $content = preg_replace('/```json?\s*|\s*```/', '', $content);
        $data = json_decode($content, true);
        if (!$data) { wp_send_json_error('parse error: ' . $content); return; }
        wp_send_json_success($data);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ── AJAX: שמירת מפתח OpenAI ───────────────────────────── */
add_action('wp_ajax_shpk_save_openai_key', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    update_option('shushka_openai_key', sanitize_text_field($_POST['key'] ?? ''));
    wp_send_json_success();
});

/* ── AJAX: ציור מחדש של תמונת מוצר ───────────────────── */
add_action('wp_ajax_shpk_regen_image', 'shpk_regen_image_handler');

function shpk_regen_image_handler() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }

    $pid      = intval($_POST['pid']      ?? 0);
    $name     = sanitize_text_field($_POST['name']    ?? '');
    $cat      = sanitize_text_field($_POST['cat']     ?? '');
    $feedback = sanitize_textarea_field($_POST['feedback'] ?? '');
    $ref_url  = esc_url_raw(trim($_POST['ref_url'] ?? ''));

    $openai_key = get_option('shushka_openai_key', '');
    if (!$openai_key) { wp_send_json_error('מפתח OpenAI לא מוגדר'); return; }

    @set_time_limit(300);

    try {
        /* 1. תיאור — מתמונת מקור (Vision) או מהשם בלבד */
        $feedback_line = $feedback
            ? " Important: the user noted the previous image was wrong. Feedback: \"{$feedback}\". Incorporate this."
            : '';

        if ($ref_url) {
            /* הורד את התמונה ב-PHP */
            $img_resp = wp_remote_get($ref_url, ['timeout' => 30, 'sslverify' => false]);
            if (is_wp_error($img_resp)) {
                wp_send_json_error('לא ניתן להוריד את התמונה: ' . $img_resp->get_error_message());
                return;
            }
            $img_body = wp_remote_retrieve_body($img_resp);

            /* שימוש ב-image/edits — מעתיק את המוצר לסגנון האיורים */
            $edit_prompt =
                "Transform this product photo into a warm watercolor illustration for a natural health food store. " .
                "Keep the exact same product, shape, composition and characteristic colors, but render entirely in a cozy organic illustrated style. " .
                "Soft watercolor texture, natural palette (sage green, warm cream, terracotta, honey yellow), clean white background, hand-drawn feel. " .
                "No text, no labels, no brand names in the final image." .
                ($feedback ? " Additional note from user: {$feedback}" : '');

            $edit_resp = shpk_openai_image_edit($openai_key, $img_body, $edit_prompt);
            if (!isset($edit_resp['data'][0]['b64_json'])) {
                wp_send_json_error('שגיאה ב-image/edits: ' . wp_json_encode($edit_resp));
                return;
            }
            $image_bytes = base64_decode($edit_resp['data'][0]['b64_json']);
        } else {
            /* טקסט בלבד — תיאור לפי שם וקטגוריה ואז יצירה */
            $messages = [[
                'role' => 'user',
                'content' =>
                    "You are describing a product sold in a natural health food store for an illustrator.\n" .
                    "Product: {$name}\nCategory: {$cat}\n\n" .
                    "First decide: is this a packaged product (bottle, bag, jar, box) or a prepared food/drink item (a dish, a drink served in a glass/cup, a slice of something)?\n" .
                    "Then describe its visual appearance in 2 sentences: shape, colors, key visual elements. " .
                    "Do NOT mention any brand names. Be specific and accurate." .
                    ($feedback ? " User note: {$feedback}" : '')
            ]];
            $desc_resp = shpk_openai_chat($openai_key, $messages, 200);

            if (!isset($desc_resp['choices'][0]['message']['content'])) {
                wp_send_json_error('שגיאה בתיאור: ' . wp_json_encode($desc_resp));
                return;
            }
            $description = trim($desc_resp['choices'][0]['message']['content']);

            $style  = "Warm illustrated style for a natural health food store. " .
                      "Soft watercolor texture, cozy organic aesthetic, natural palette " .
                      "(sage green, warm cream, terracotta, honey yellow). " .
                      "Clean white background, simple centered composition, hand-drawn feel. " .
                      "No text, no labels, no brand names.";
            $gen_resp = shpk_openai_image($openai_key, "{$style} The product is: {$description}");
            if (!isset($gen_resp['data'][0]['b64_json'])) {
                wp_send_json_error('שגיאה ביצירת תמונה: ' . wp_json_encode($gen_resp));
                return;
            }
            $image_bytes = base64_decode($gen_resp['data'][0]['b64_json']);
        }

        /* 3. העלאה ל-WordPress Media */
        $filename    = "product-{$pid}-" . time() . ".png";
        $upload      = wp_upload_bits($filename, null, $image_bytes);
        if (!empty($upload['error'])) {
            wp_send_json_error('שגיאת העלאה: ' . $upload['error']);
            return;
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title'     => $name,
            'post_status'    => 'inherit',
        ], $upload['file']);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachment_id,
            wp_generate_attachment_metadata($attachment_id, $upload['file']));
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $name);

        /* 4. קישור למוצר */
        $product = wc_get_product($pid);
        if (!$product) { wp_send_json_error('מוצר לא נמצא'); return; }
        $product->set_image_id($attachment_id);
        $product->save();

        $thumb_url = wp_get_attachment_image_url($attachment_id, 'woocommerce_thumbnail');
        wp_send_json_success([
            'img_url'    => $thumb_url,
            'media_id'   => $attachment_id,
            'description' => $description,
        ]);

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

/* ── helpers OpenAI ────────────────────────────────────── */
function shpk_openai_chat($key, $messages, $max_tokens = 150) {
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 90,
        'headers' => [
            'Authorization' => "Bearer {$key}",
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => 'gpt-4o-mini',
            'max_tokens' => $max_tokens,
            'messages'   => $messages,
        ]),
    ]);
    if (is_wp_error($resp)) throw new Exception($resp->get_error_message());
    return json_decode(wp_remote_retrieve_body($resp), true);
}

function shpk_openai_image($key, $prompt) {
    $resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
        'timeout' => 180,
        'headers' => [
            'Authorization' => "Bearer {$key}",
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'   => 'gpt-image-1',
            'prompt'  => $prompt,
            'size'    => '1024x1024',
            'quality' => 'medium',
            'n'       => 1,
        ]),
    ]);
    if (is_wp_error($resp)) throw new Exception($resp->get_error_message());
    return json_decode(wp_remote_retrieve_body($resp), true);
}

function shpk_openai_image_edit($key, $image_bytes, $prompt) {
    if (!function_exists('curl_init')) throw new Exception('cURL not available on this server');
    $tmp = tempnam(sys_get_temp_dir(), 'shpk') . '.png';
    file_put_contents($tmp, $image_bytes);
    $ch = curl_init('https://api.openai.com/v1/images/edits');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => [
            'model'   => 'gpt-image-1',
            'image[]' => new CURLFile($tmp, 'image/png', 'product.png'),
            'prompt'  => $prompt,
            'size'    => '1024x1024',
            'quality' => 'medium',
            'n'       => '1',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);
    if ($err) throw new Exception('cURL error: ' . $err);
    return json_decode($body, true);
}

/* ── דף התמונות ────────────────────────────────────────── */
function shpk_images_page() {
    $nonce      = wp_create_nonce('shushka_nonce');
    $wp_nonce   = wp_create_nonce('wp_rest');
    $openai_key = get_option('shushka_openai_key', '');
    $ajax_url   = admin_url('admin-ajax.php');
    $wc_rest    = rtrim(rest_url('wc/v3'), '/');
    ?>
<style>
#shpk-img-wrap{font-family:system-ui,sans-serif;direction:rtl;max-width:1400px;margin:0 auto;padding:20px}
#shpk-img-wrap h1{color:#2d8c4e;margin-bottom:16px}
.shpk-key-bar{background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.shpk-key-bar label{font-weight:600;white-space:nowrap}
.shpk-key-bar input{flex:1;min-width:260px;padding:7px 10px;border:1px solid #bbb;border-radius:6px;font-size:13px}
.shpk-key-bar button{background:#2d8c4e;color:#fff;border:none;border-radius:6px;padding:8px 18px;cursor:pointer;font-size:13px;white-space:nowrap}
.shpk-key-bar button:hover{background:#236e3d}
.shpk-key-bar .shpk-key-status{font-size:12px;color:#666}
#shpk-img-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
#shpk-img-toolbar select,#shpk-img-toolbar input{padding:7px 10px;border:1px solid #bbb;border-radius:6px;font-size:13px}
#shpk-img-toolbar input{min-width:200px}
#shpk-load-btn{background:#2d8c4e;color:#fff;border:none;border-radius:6px;padding:8px 18px;cursor:pointer;font-size:13px}
#shpk-load-btn:hover{background:#236e3d}
#shpk-img-status{font-size:13px;color:#666;margin-bottom:10px}
#shpk-img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.shpk-img-card{background:#fff;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:box-shadow .2s}
.shpk-img-card:hover{box-shadow:0 3px 12px rgba(0,0,0,.15)}
.shpk-img-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block;background:#f5f5f5}
.shpk-img-card img.shpk-no-img{filter:grayscale(1);opacity:.4}
.shpk-card-info{padding:8px 10px}
.shpk-card-name{font-size:12px;font-weight:600;line-height:1.3;margin-bottom:6px;color:#333;min-height:32px}
.shpk-card-cat{font-size:11px;color:#888;margin-bottom:8px}
.shpk-regen-btn{width:100%;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;padding:6px;font-size:12px;cursor:pointer;color:#333;transition:background .15s}
.shpk-regen-btn:hover{background:#e8f5ec;border-color:#2d8c4e;color:#2d8c4e}
.shpk-feedback-area{display:none;margin-top:8px}
.shpk-fb-label{display:block;font-size:11px;color:#666;margin:6px 0 3px}
.shpk-ref-url{width:100%;box-sizing:border-box;font-size:12px;border:1px solid #bbb;border-radius:6px;padding:6px;margin-bottom:2px;direction:ltr}
.shpk-feedback-area textarea{width:100%;box-sizing:border-box;font-size:12px;border:1px solid #bbb;border-radius:6px;padding:6px;resize:vertical;min-height:50px;direction:rtl}
.shpk-submit-btn{margin-top:6px;width:100%;background:#2d8c4e;color:#fff;border:none;border-radius:6px;padding:7px;font-size:12px;cursor:pointer}
.shpk-submit-btn:disabled{opacity:.6;cursor:not-allowed}
.shpk-submit-btn:hover:not(:disabled){background:#236e3d}
.shpk-spinner{display:none;text-align:center;padding:8px;font-size:12px;color:#2d8c4e}
.shpk-spinner::after{content:'⏳ מציירת...';animation:shpk-dots 1s infinite}
.shpk-img-card.shpk-loading img{opacity:.4}
.shpk-img-card.shpk-done{outline:2px solid #2d8c4e}
.shpk-img-card.shpk-error{outline:2px solid #c0392b}
#shpk-pagination{margin-top:20px;display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap}
#shpk-pagination button{padding:6px 14px;border:1px solid #bbb;border-radius:6px;background:#fff;cursor:pointer;font-size:13px}
#shpk-pagination button.active{background:#2d8c4e;color:#fff;border-color:#2d8c4e}
#shpk-pagination button:disabled{opacity:.4;cursor:not-allowed}
@media(max-width:600px){
  #shpk-img-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .shpk-key-bar input{min-width:160px}
}
</style>

<div id="shpk-img-wrap">
  <h1>🖼 תמונות מוצרים</h1>

  <div class="shpk-key-bar">
    <label>מפתח OpenAI:</label>
    <input type="password" id="shpk-openai-key-input" value="<?php echo esc_attr($openai_key); ?>"
           placeholder="sk-proj-...">
    <button onclick="shpkSaveKey()">שמור מפתח</button>
    <span class="shpk-key-status" id="shpk-key-status"><?php echo $openai_key ? '✓ מוגדר' : '⚠ לא מוגדר'; ?></span>
  </div>

  <div id="shpk-img-toolbar">
    <select id="shpk-filter">
      <option value="all">כל המוצרים</option>
      <option value="with">עם תמונה</option>
      <option value="without">ללא תמונה</option>
    </select>
    <input type="search" id="shpk-search-name" placeholder="חיפוש לפי שם...">
    <button id="shpk-load-btn" onclick="shpkLoadImages(1)">טען מוצרים</button>
  </div>

  <div id="shpk-img-status"></div>
  <div id="shpk-img-grid"></div>
  <div id="shpk-pagination"></div>
</div>

<script>
(function(){
var AJAX = <?php echo json_encode($ajax_url); ?>;
var NONCE = <?php echo json_encode($nonce); ?>;
var WC_REST = <?php echo json_encode($wc_rest); ?>;
var WP_NONCE = <?php echo json_encode($wp_nonce); ?>;
var PLACEHOLDER = <?php echo json_encode(wc_placeholder_img_src()); ?>;

var currentPage = 1, totalPages = 1, perPage = 40;

function wcGet(path) {
    return fetch(WC_REST + path, { headers: { 'X-WP-Nonce': WP_NONCE, 'Content-Type': 'application/json' } }).then(function(r){
        totalPages = parseInt(r.headers.get('X-WP-TotalPages') || 1);
        return r.json();
    });
}

window.shpkSaveKey = function() {
    var key = document.getElementById('shpk-openai-key-input').value.trim();
    fetch(AJAX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=shpk_save_openai_key&nonce=' + NONCE + '&key=' + encodeURIComponent(key)
    }).then(function(r){ return r.json(); }).then(function(d){
        document.getElementById('shpk-key-status').textContent = d.success ? '✓ נשמר' : '✗ שגיאה';
    });
};

window.shpkLoadImages = function(page) {
    currentPage = page || 1;
    var filter = document.getElementById('shpk-filter').value;
    var search = document.getElementById('shpk-search-name').value.trim();
    var status  = document.getElementById('shpk-img-status');
    var grid    = document.getElementById('shpk-img-grid');
    var pager   = document.getElementById('shpk-pagination');

    status.textContent = 'טוען...';
    grid.innerHTML = '';
    pager.innerHTML = '';

    var params = '?per_page=' + perPage + '&page=' + currentPage + '&status=publish&orderby=id&order=asc';
    if (search) params += '&search=' + encodeURIComponent(search);

    wcGet('/products' + params).then(function(products) {
        var filtered = products;
        if (filter === 'with')    filtered = products.filter(function(p){ return p.images && p.images.length; });
        if (filter === 'without') filtered = products.filter(function(p){ return !p.images || !p.images.length; });

        status.textContent = 'מוצגים ' + filtered.length + ' מוצרים (עמוד ' + currentPage + ' מתוך ' + totalPages + ')';

        grid.innerHTML = filtered.map(function(p) {
            var img  = (p.images && p.images.length) ? p.images[0].src : PLACEHOLDER;
            var cat  = (p.categories && p.categories.length) ? p.categories[p.categories.length-1].name : '';
            var hasImg = p.images && p.images.length;
            return '<div class="shpk-img-card" id="shpk-card-' + p.id + '" data-name="' + escA(p.name) + '" data-cat="' + escA(cat) + '">' +
                '<img src="' + escA(img) + '" alt="" loading="lazy"' + (!hasImg ? ' class="shpk-no-img"' : '') + ' id="shpk-img-' + p.id + '">' +
                '<div class="shpk-card-info">' +
                  '<div class="shpk-card-name">' + esc(p.name) + '</div>' +
                  '<div class="shpk-card-cat">' + esc(cat) + '</div>' +
                  '<button class="shpk-regen-btn" onclick="shpkToggleFeedback(' + p.id + ')">✏️ צייר מחדש</button>' +
                  '<div class="shpk-feedback-area" id="shpk-fb-' + p.id + '">' +
                    '<label class="shpk-fb-label">🔗 URL תמונת מקור (אופציונלי — המודל יצייר לפיה):</label>' +
                    '<input class="shpk-ref-url" type="url" placeholder="https://...">' +
                    '<label class="shpk-fb-label">💬 הסבר (אופציונלי):</label>' +
                    '<textarea placeholder="מה לא מתאים? מה צריך להיות?"></textarea>' +
                    '<button class="shpk-submit-btn" onclick="shpkRegen(' + p.id + ')">▶ צייר עכשיו</button>' +
                    '<div class="shpk-spinner" id="shpk-spin-' + p.id + '">⏳ מציירת...</div>' +
                  '</div>' +
                '</div>' +
            '</div>';
        }).join('');

        /* pagination */
        if (totalPages > 1) {
            var html = '';
            html += '<button onclick="shpkLoadImages(' + (currentPage-1) + ')" ' + (currentPage<=1?'disabled':'') + '>◀ הקודם</button>';
            var start = Math.max(1, currentPage-3), end = Math.min(totalPages, currentPage+3);
            for (var i=start; i<=end; i++) {
                html += '<button onclick="shpkLoadImages(' + i + ')" class="' + (i===currentPage?'active':'') + '">' + i + '</button>';
            }
            html += '<button onclick="shpkLoadImages(' + (currentPage+1) + ')" ' + (currentPage>=totalPages?'disabled':'') + '>הבא ▶</button>';
            pager.innerHTML = html;
        }
    }).catch(function(e){ status.textContent = 'שגיאה: ' + e.message; });
};

window.shpkToggleFeedback = function(pid) {
    var area = document.getElementById('shpk-fb-' + pid);
    area.style.display = (area.style.display === 'block') ? 'none' : 'block';
};

window.shpkRegen = function(pid) {
    var card    = document.getElementById('shpk-card-' + pid);
    var fbArea  = document.getElementById('shpk-fb-' + pid);
    var spin    = document.getElementById('shpk-spin-' + pid);
    var btn     = fbArea.querySelector('.shpk-submit-btn');
    var ta      = fbArea.querySelector('textarea');
    var urlInp  = fbArea.querySelector('.shpk-ref-url');
    var imgEl   = document.getElementById('shpk-img-' + pid);
    var feedback = ta.value.trim();
    var refUrl  = urlInp ? urlInp.value.trim() : '';
    var name    = card.dataset.name;
    var cat     = card.dataset.cat;

    card.classList.remove('shpk-done','shpk-error');
    card.classList.add('shpk-loading');
    btn.disabled = true;
    spin.style.display = 'block';

    var body = 'action=shpk_regen_image&nonce=' + NONCE +
        '&pid=' + pid +
        '&name=' + encodeURIComponent(name) +
        '&cat=' + encodeURIComponent(cat) +
        '&feedback=' + encodeURIComponent(feedback) +
        '&ref_url=' + encodeURIComponent(refUrl);

    fetch(AJAX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
        card.classList.remove('shpk-loading');
        spin.style.display = 'none';
        btn.disabled = false;
        if (d.success) {
            imgEl.src = d.data.img_url + '?t=' + Date.now();
            imgEl.classList.remove('shpk-no-img');
            card.classList.add('shpk-done');
            fbArea.style.display = 'none';
            ta.value = '';
        } else {
            card.classList.add('shpk-error');
            alert('שגיאה: ' + d.data);
        }
    })
    .catch(function(e){
        card.classList.remove('shpk-loading');
        card.classList.add('shpk-error');
        spin.style.display = 'none';
        btn.disabled = false;
        alert('שגיאת רשת: ' + e.message);
    });
};

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escA(s){ return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

/* טוען אוטומטית */
shpkLoadImages(1);
})();
</script>
    <?php
}
