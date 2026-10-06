<?php
/**
 * Plugin Name: שושקה — ניהול חנות
 * Description: ליקוט ומשלוח הזמנות — שושקה
 * Version: 5.6.6
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
    add_menu_page('שושקה', '📦 שושקה', 'manage_woocommerce',
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

/* ── ייצוא הזמנות נארזות — נגיש לקופאי (PIN) מכל עמוד ── */
add_action('wp_loaded', function () {
    if (($_GET['shpk_export'] ?? '') !== 'packed') return;
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
});

/* ── ייצוא CSV — admin_init (לפני פלט HTML) ─────────── */
add_action('admin_init', function() {
    if ((($_GET['page'] ?? '') !== 'shushka-prices') || !isset($_GET['action'])) return;
    if (!current_user_can('manage_woocommerce')) return;

    $action = $_GET['action'];

    // ייצוא מוצרים חסרים
    if ($action === 'export_missing') {
        $missing = get_transient('shpk_missing_products');
        if (!$missing) wp_die('אין נתונים — טען שוב את קובץ הקופה כדי לרענן את הרשימה.');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="missing-products-' . date('Ymd') . '.csv"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ברקוד', 'שם', 'מחיר']);
        foreach ($missing as $u)
            fputcsv($out, [$u['sku'], $u['name'], $u['price'] ?? '']);
        fclose($out);
        exit;
    }

    // ייצוא הזמנות נארזות
    if ($action === 'export_packed') {
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
});

/* ── AJAX: הסתרה/שחזור מוצרים בבצ'ים ─────────────────── */
add_action('wp_ajax_shpk_hide_batch', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }

    $inactive = get_transient('shpk_inactive_products') ?: [];
    $restore  = get_transient('shpk_restore_products')  ?: [];

    // Build combined list with action flag
    $all = [];
    foreach ($inactive as $u) $all[] = ['id' => (int)$u['id'], 'act' => 'hide'];
    foreach ($restore  as $u) $all[] = ['id' => (int)$u['id'], 'act' => 'restore'];

    $offset   = absint($_POST['offset'] ?? 0);
    $batch_sz = 15;
    $total    = count($all);

    if ($offset >= $total) {
        delete_transient('shpk_inactive_products');
        delete_transient('shpk_restore_products');
        wp_send_json_success(['hidden' => 0, 'restored' => 0, 'offset' => $total, 'total' => $total, 'finished' => true]);
        return;
    }

    @set_time_limit(120);
    $hidden = $restored = 0;

    foreach (array_slice($all, $offset, $batch_sz) as $item) {
        $product = wc_get_product($item['id']);
        if (!$product) continue;
        if ($item['act'] === 'hide') {
            $product->set_catalog_visibility('hidden');
            $hidden++;
        } else {
            $product->set_catalog_visibility('visible');
            $restored++;
        }
        $product->save();
    }

    $next = $offset + $batch_sz;
    $finished = ($next >= $total);
    if ($finished) {
        delete_transient('shpk_inactive_products');
        delete_transient('shpk_restore_products');
    }

    wp_send_json_success([
        'hidden'   => $hidden,
        'restored' => $restored,
        'offset'   => min($next, $total),
        'total'    => $total,
        'finished' => $finished,
    ]);
});

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
    global $wpdb;

    foreach ($data['prices'] ?? [] as $u) {
        $pid   = (int)$u['id'];
        $price = (string)$u['new_price'];
        if (!get_post($pid)) { $fail[] = $u['name']; continue; }
        update_post_meta($pid, '_regular_price',        $price);
        update_post_meta($pid, '_sale_price',           '');
        update_post_meta($pid, '_sale_price_dates_from','');
        update_post_meta($pid, '_sale_price_dates_to',  '');
        update_post_meta($pid, '_price',                $price);
        $wpdb->update(
            $wpdb->prefix . 'wc_product_meta_lookup',
            ['min_price' => $price, 'max_price' => $price, 'onsale' => 0],
            ['product_id' => $pid]
        );
        wc_delete_product_transients($pid);
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

    // ── אבחון SKU ────────────────────────────────────────
    if (isset($_GET['action']) && $_GET['action'] === 'diag_sku') {
        global $wpdb;
        $sku = sanitize_text_field($_GET['sku'] ?? '');
        echo '<div class="wrap" dir="rtl"><h1>🔍 אבחון SKU</h1>';
        if ($sku) {
            echo '<p>מחפש: <strong>' . esc_html($sku) . '</strong></p>';
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title, p.post_status, p.post_type, pm.meta_value AS sku
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                WHERE pm.meta_value = %s OR pm.meta_value = %s
            ", $sku, $sku . '.0'));
            if ($rows) {
                echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>שם</th><th>סטטוס</th><th>סוג</th><th>SKU בDB</th><th>_regular_price</th><th>_sale_price</th><th>_price</th><th>מבצע מ</th><th>מבצע עד</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $prices = $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                         WHERE post_id = %d AND meta_key IN ('_price','_regular_price','_sale_price','_sale_price_dates_from','_sale_price_dates_to')",
                        $r->ID
                    ));
                    $pm = [];
                    foreach ($prices as $p) $pm[$p->meta_key] = $p->meta_value;
                    $from = $pm['_sale_price_dates_from'] ?? '';
                    $to   = $pm['_sale_price_dates_to']   ?? '';
                    echo '<tr><td>' . $r->ID . '</td><td>' . esc_html($r->post_title) . '</td><td>' . $r->post_status . '</td><td>' . $r->post_type . '</td><td>' . esc_html($r->sku) . '</td>';
                    echo '<td>' . ($pm['_regular_price'] ?? '—') . '</td>';
                    echo '<td>' . ($pm['_sale_price'] ?? '—') . '</td>';
                    echo '<td>' . ($pm['_price'] ?? '—') . '</td>';
                    echo '<td>' . ($from ? date('d/m/Y', (int)$from) : '—') . '</td>';
                    echo '<td>' . ($to   ? date('d/m/Y', (int)$to)   : '—') . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="notice notice-error"><p>❌ לא נמצא שום מוצר עם SKU זה בבסיס הנתונים.</p></div>';
            }
            // also show total counts by status
            $counts = $wpdb->get_results("
                SELECT p.post_status, p.post_type, COUNT(*) as cnt
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku' AND pm.meta_value != ''
                WHERE p.post_type IN ('product','product_variation')
                GROUP BY p.post_status, p.post_type ORDER BY cnt DESC
            ");
            echo '<h3 style="margin-top:24px">סה"כ מוצרים עם SKU לפי סטטוס</h3>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>סטטוס</th><th>סוג</th><th>כמות</th></tr></thead><tbody>';
            foreach ($counts as $c)
                echo '<tr><td>' . $c->post_status . '</td><td>' . $c->post_type . '</td><td>' . $c->cnt . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '<form method="get" style="margin-top:20px">';
        echo '<input type="hidden" name="page" value="shushka-prices">';
        echo '<input type="hidden" name="action" value="diag_sku">';
        echo '<input type="text" name="sku" value="' . esc_attr($sku) . '" placeholder="הכנס ברקוד לחיפוש" style="width:240px;font-size:15px">';
        echo ' <button type="submit" class="button button-primary">חפש</button>';
        echo '</form>';
        echo '<p style="margin-top:16px"><a href="' . admin_url('admin.php?page=shushka-prices') . '" class="button">חזרה</a></p></div>';
        return;
    }

    // ── after hide/restore ────────────────────────────────
    if (isset($_GET['hidden'])) {
        $r = get_transient('shpk_hide_result');
        delete_transient('shpk_hide_result');
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        $msgs = [];
        if (is_array($r)) {
            if ($r['hidden']   ?? 0) $msgs[] = '🙈 הוסתרו ' . $r['hidden']   . ' מוצרים לא פעילים';
            if ($r['restored'] ?? 0) $msgs[] = '👁 שוחזרו ' . $r['restored'] . ' מוצרים לתצוגה';
        } elseif ((int)$r) {
            $msgs[] = '🙈 הוסתרו ' . (int)$r . ' מוצרים לא פעילים';
        }
        if ($msgs)
            echo '<div class="notice notice-success is-dismissible"><p>✓ ' . implode(' | ', $msgs) . '.</p></div>';
        echo '<p><a href="' . $base . '" class="button button-primary">חזרה לסנכרון</a></p></div>';
        return;
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

    // ── import customers from POS ────────────────────────
    if (isset($_POST['shpk_import_customers'])) {
        check_admin_referer('shpk_import_customers');
        $tmp = $_FILES['shpk_customers_csv']['tmp_name'] ?? '';
        if (!$tmp) {
            echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
            echo '<div class="notice notice-error"><p>לא נבחר קובץ.</p></div>';
            echo '<p><a href="' . $base . '" class="button">חזרה</a></p></div>'; return;
        }
        $raw   = ltrim(file_get_contents($tmp), "\xEF\xBB\xBF");
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($raw)));
        array_shift($lines); // skip header

        $created = $updated = $skipped = $errors = [];

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) < 2) continue;
            $code  = trim($row[0]);
            $name  = trim($row[1]);
            $email = isset($row[8]) ? trim($row[8]) : '';
            $phone = isset($row[9]) ? trim($row[9]) : '';
            if (!$code || !$name || $code === '999') { $skipped[] = $name ?: $code; continue; }

            // normalize phone
            $digits = preg_replace('/[^\d]/', '', $phone);
            if (strlen($digits) === 9 && $digits[0] === '5') $digits = '0' . $digits;
            if (strlen($digits) === 12 && substr($digits, 0, 3) === '972') $digits = '0' . substr($digits, 3);
            $phone = $digits;

            $use_email = $email ?: "pos_{$code}@shushka-pos.local";
            $parts     = explode(' ', $name, 2);
            $first     = $parts[0];
            $last      = $parts[1] ?? '';

            $existing = email_exists($use_email);
            if ($existing) {
                if (get_user_meta($existing, '_cashier_code', true) == $code) {
                    $skipped[] = $name; continue;
                }
                update_user_meta($existing, '_cashier_code', $code);
                if ($phone) update_user_meta($existing, 'billing_phone', $phone);
                $updated[] = $name;
            } else {
                $user_id = wc_create_new_customer($use_email, "pos_{$code}", wp_generate_password(20, false));
                if (is_wp_error($user_id)) { $errors[] = $name . ': ' . $user_id->get_error_message(); continue; }
                wp_update_user(['ID' => $user_id, 'first_name' => $first, 'last_name' => $last]);
                update_user_meta($user_id, 'billing_first_name', $first);
                update_user_meta($user_id, 'billing_last_name',  $last);
                update_user_meta($user_id, 'billing_phone',      $phone);
                update_user_meta($user_id, '_cashier_code',      $code);
                $created[] = $name;
            }
        }

        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        echo '<div class="notice notice-success"><p>';
        echo '✓ נוצרו: <strong>' . count($created) . '</strong> &nbsp;|&nbsp; ';
        echo 'עודכנו: <strong>' . count($updated) . '</strong> &nbsp;|&nbsp; ';
        echo 'דולגו: <strong>' . count($skipped) . '</strong>';
        if ($errors) echo ' &nbsp;|&nbsp; שגיאות: <strong>' . count($errors) . '</strong>';
        echo '</p></div>';
        if ($errors) {
            echo '<div class="notice notice-warning"><p>שגיאות:</p><ul>';
            foreach ($errors as $e) echo '<li>' . esc_html($e) . '</li>';
            echo '</ul></div>';
        }
        echo '<p><a href="' . $base . '" class="button button-primary">חזרה לסנכרון</a></p></div>';
        return;
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

        // missing products export button
        $missing = get_transient('shpk_missing_products');
        if ($missing) {
            $mc = count($missing);
            $nonce_val = wp_create_nonce('shushka_nonce');
            echo '<div style="background:#e8f4fd;border:1px solid #2196f3;border-radius:8px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
            echo '<span><strong>' . $mc . ' מוצרים</strong> בקופה שאינם באתר</span>';
            echo '<a href="' . admin_url('admin.php?page=shushka-prices&action=export_missing') . '" class="button">⬇ ייצא CSV</a>';
            echo '<button type="button" class="button button-primary" id="shpk-import-missing-btn" onclick="shpkImportMissing(' . $mc . ',\'' . $nonce_val . '\')">⬆ ייבא כטיוטות</button>';
            echo '<button type="button" class="button button-primary" id="shpk-import-ai-btn" style="background:#7c3aed;border-color:#6d28d9" onclick="shpkImportWithAI(' . $mc . ',\'' . $nonce_val . '\')">🤖 ייבא עם AI</button>';
            echo '<span id="shpk-import-missing-status" style="font-size:13px;color:#2d5a27"></span>';
            echo '</div>';
            echo '<div id="shpk-ai-progress" style="display:none;background:#f3f0ff;border:1px solid #7c3aed;border-radius:8px;padding:14px 18px;margin-bottom:16px">';
            echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
            echo '<span id="shpk-ai-progress-label" style="font-weight:600;color:#5b21b6">🤖 מייבא עם AI...</span>';
            echo '<span id="shpk-ai-counter" style="font-size:13px;color:#7c3aed"></span>';
            echo '</div>';
            echo '<div style="background:#e9d5ff;border-radius:4px;height:8px;overflow:hidden"><div id="shpk-ai-bar" style="background:#7c3aed;height:8px;width:0;transition:width .3s"></div></div>';
            echo '<div id="shpk-ai-log" style="font-size:12px;color:#6b7280;margin-top:6px;max-height:80px;overflow-y:auto;direction:rtl"></div>';
            echo '</div>';
            echo '<script>function shpkImportMissing(count,nonce){';
            echo 'if(!confirm("ייבוא "+count+" מוצרים כטיוטות ל-WooCommerce?\nלאחר הייבוא יש לערוך אותם (תמונה, קטגוריה, תיאור) לפני פרסום."))return;';
            echo 'var btn=document.getElementById("shpk-import-missing-btn");';
            echo 'var st=document.getElementById("shpk-import-missing-status");';
            echo 'btn.disabled=true;btn.textContent="⏳ מייבא...";';
            echo 'var fd=new FormData();fd.append("action","shpk_import_missing");fd.append("nonce",nonce);';
            echo 'fetch(ajaxurl,{method:"POST",body:fd}).then(r=>r.json()).then(d=>{';
            echo 'if(d.success){btn.textContent="✓ יובאו "+d.data.done+" מוצרים";';
            echo 'st.innerHTML="("+d.data.skip+" דולגו) — <a href=\'"+d.data.admin_url+"\' target=\'_blank\'>לצפייה בטיוטות</a>";}';
            echo 'else{btn.textContent="✗ שגיאה";btn.disabled=false;st.textContent=d.data;}';
            echo '});}';
            echo 'function shpkImportWithAI(total,nonce){';
            echo 'if(!confirm("ייבוא "+total+" מוצרים עם תיאור AI ותמונה (DALL-E).\nהתהליך ייקח מספר דקות.\nמחיר משוער: כ-$0.04 לכל מוצר.\n\nלהמשיך?"))return;';
            echo 'var btn=document.getElementById("shpk-import-ai-btn");';
            echo 'var btn2=document.getElementById("shpk-import-missing-btn");';
            echo 'var prog=document.getElementById("shpk-ai-progress");';
            echo 'var bar=document.getElementById("shpk-ai-bar");';
            echo 'var ctr=document.getElementById("shpk-ai-counter");';
            echo 'var log=document.getElementById("shpk-ai-log");';
            echo 'var label=document.getElementById("shpk-ai-progress-label");';
            echo 'btn.disabled=true;btn2.disabled=true;prog.style.display="block";';
            echo 'var done=0,skip=0,fail=0;';
            echo 'function step(offset){';
            echo 'var fd=new FormData();';
            echo 'fd.append("action","shpk_import_with_ai");';
            echo 'fd.append("nonce",nonce);';
            echo 'fd.append("offset",offset);';
            echo 'fetch(ajaxurl,{method:"POST",body:fd}).then(r=>r.json()).then(function(d){';
            echo 'if(!d.success){';
            echo 'if(d.data&&d.data.indexOf("מפתח")>=0){alert("שגיאת הגדרה:\n"+d.data);btn.disabled=false;btn2.disabled=false;prog.style.display="none";return;}';
            echo 'log.innerHTML="<span style=\'color:#dc2626\'>✗ שגיאה: "+d.data+"</span><br>"+log.innerHTML;';
            echo 'fail++;step(offset+1);return;}';
            echo 'var r=d.data;';
            echo 'done+=r.done;skip+=r.skip;fail+=r.fail;';
            echo 'var pct=Math.round((r.offset/r.total)*100);';
            echo 'bar.style.width=pct+"%";';
            echo 'ctr.textContent=r.offset+"/"+r.total+" ("+pct+"%)";';
            echo 'var icon=r.done?"✓":r.skip?"—":"✗";';
            echo 'var color=r.done?"#16a34a":r.skip?"#6b7280":"#dc2626";';
            echo 'log.innerHTML="<span style=\'color:"+color+"\'>["+icon+"] "+r.name+(r.log?" — "+r.log:"")+"</span><br>"+log.innerHTML;';
            echo 'if(r.finished){label.textContent="✓ ייבוא הושלם";';
            echo 'ctr.textContent="יובאו "+done+" | דולגו "+skip+" | שגיאות "+fail;';
            echo 'btn.disabled=false;btn2.disabled=false;return;}';
            echo 'step(r.offset);';
            echo '}).catch(function(e){fail++;log.innerHTML="<span style=\'color:#dc2626\'>שגיאת רשת: "+e+"</span><br>"+log.innerHTML;step(offset+1);});';
            echo '}step(0);}';
            echo '</script>';
        }

        // inactive / restore banner
        $inactive_saved = get_transient('shpk_inactive_products');
        $restore_saved  = get_transient('shpk_restore_products');
        if ($inactive_saved || $restore_saved) {
            echo '<div style="background:#fff3e0;border:1px solid #ff9800;border-radius:8px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:20px;flex-wrap:wrap">';
            $banner_parts = [];
            if ($inactive_saved) $banner_parts[] = '<strong>' . count($inactive_saved) . ' מוצרים</strong> לא פעילים';
            if ($restore_saved)  $banner_parts[] = '<strong>' . count($restore_saved) . ' מוצרים</strong> מוסתרים לשחזור';
            echo '<span>' . implode(' | ', $banner_parts) . ' זוהו בסנכרון האחרון</span>';
            $total_vi  = count($inactive_saved ?: []) + count($restore_saved ?: []);
            $nonce_vi  = wp_create_nonce('shushka_nonce');
            $btn_parts = [];
            if ($inactive_saved) $btn_parts[] = '🙈 הסתר ' . count($inactive_saved);
            if ($restore_saved)  $btn_parts[] = '👁 שחזר ' . count($restore_saved);
            echo '<button type="button" class="button button-primary" id="shpk-hide-btn" style="background:#e65100;border-color:#e65100" onclick="shpkHideBatch(' . $total_vi . ',\'' . $nonce_vi . '\')">' . implode(' | ', $btn_parts) . ' מוצרים</button>';
            echo '<span id="shpk-hide-status" style="font-size:13px;color:#555"></span>';
            echo '</div>';
            echo '<script>function shpkHideBatch(total,nonce){';
            echo 'var btn=document.getElementById("shpk-hide-btn");';
            echo 'var st=document.getElementById("shpk-hide-status");';
            echo 'btn.disabled=true;st.textContent="⏳ מעבד 0/"+total+"...";';
            echo 'var hidden=0,restored=0;';
            echo 'function hstep(offset){';
            echo 'var fd=new FormData();fd.append("action","shpk_hide_batch");fd.append("nonce",nonce);fd.append("offset",offset);';
            echo 'fetch(ajaxurl,{method:"POST",body:fd}).then(r=>r.json()).then(function(d){';
            echo 'if(!d.success){st.textContent="✗ שגיאה: "+d.data;btn.disabled=false;return;}';
            echo 'hidden+=d.data.hidden;restored+=d.data.restored;';
            echo 'st.textContent="⏳ "+d.data.offset+"/"+d.data.total+"...";';
            echo 'if(d.data.finished){btn.textContent="✓ הושלם";';
            echo 'st.textContent=(hidden?" הוסתרו "+hidden:"")+(restored?" | שוחזרו "+restored:"");';
            echo 'return;}hstep(d.data.offset);';
            echo '}).catch(function(e){st.textContent="✗ שגיאת רשת";btn.disabled=false;});';
            echo '}hstep(0);}';
            echo '</script>';
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

        shpk_gforms_status_block();

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
        echo '<div style="margin:12px 0;display:flex;flex-direction:column;gap:10px">';
        echo '<label style="font-size:15px;display:flex;align-items:center;gap:8px"><input type="checkbox" name="do_prices" value="1" checked> <strong>עדכן מחירים</strong></label>';
        echo '<label style="font-size:15px;display:flex;align-items:center;gap:8px"><input type="checkbox" name="do_stock" value="1"> <strong>עדכן מלאי</strong> <span style="color:#888;font-size:13px">(מלאי 0 מהקופה לא יעודכן)</span></label>';
        echo '<label style="font-size:15px;display:flex;align-items:center;gap:8px"><input type="checkbox" name="do_inactive" value="1" checked> <strong>זהה מוצרים לא פעילים</strong> <span style="color:#888;font-size:13px">— לא נמכרו מעל</span> <input type="number" name="inactive_months" value="12" min="1" max="60" style="width:55px;text-align:center"> <span style="color:#888;font-size:13px">חודשים</span></label>';
        echo '</div>';
        submit_button('📋 טען ותצוגה מקדימה', 'primary large');
        echo '</form></div>';

        // Section 3: import customers
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-top:24px">';
        echo '<h2 style="margin-top:0">👥 ייבוא לקוחות מהקופה</h2>';
        echo '<p style="color:#555">העלה את קובץ ייצוא הלקוחות מהקופה (עמודות: קוד לקוח, שם, ..., אמייל, טלפון).<br>';
        echo 'לקוחות קיימים ב-WooCommerce יקבלו קוד לקוח, חדשים ייווצרו אוטומטית. בטוח להריץ שוב — מדלג על קיימים.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('shpk_import_customers');
        echo '<input type="hidden" name="shpk_import_customers" value="1">';
        echo '<input type="file" name="shpk_customers_csv" accept=".csv" required style="margin:12px 0;display:block;font-size:15px">';
        submit_button('👥 ייבא לקוחות', 'secondary large');
        echo '</form></div>';

        echo '</div>';
        return;
    }

    // ── parse CSV ────────────────────────────────────────
    check_admin_referer('shpk_prices_preview');
    $do_prices       = !empty($_POST['do_prices']);
    $do_stock        = !empty($_POST['do_stock']);
    $do_inactive     = !empty($_POST['do_inactive']);
    $inactive_months = max(1, (int)($_POST['inactive_months'] ?? 12));
    $tmp = $_FILES['shpk_csv']['tmp_name'] ?? '';
    if (!$tmp) { echo '<div class="wrap" dir="rtl"><p class="notice notice-error">לא נבחר קובץ.</p></div>'; return; }

    $raw     = ltrim(file_get_contents($tmp), "\xEF\xBB\xBF");
    $lines   = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($raw)));
    $headers = str_getcsv(array_shift($lines));

    $ci_sku = $ci_price = $ci_name = $ci_stock = $ci_date = $ci_code = null;
    foreach ($headers as $i => $h) {
        $h = trim($h);
        if (mb_strpos($h, 'ברקוד')       !== false) $ci_sku   = $i;
        if (mb_strpos($h, 'מחיר מכירה') !== false) $ci_price = $i;
        if (mb_strpos($h, 'תאור')        !== false) $ci_name  = $i;
        if (mb_strpos($h, 'מלאי')        !== false) $ci_stock = $i;
        if ($h === 'מכר')                           $ci_date  = $i;
        if (mb_strpos($h, 'קוד פריט')   !== false) $ci_code  = $i;
    }
    if ($ci_sku === null || ($do_prices && $ci_price === null) || ($do_stock && $ci_stock === null)) {
        echo '<div class="wrap" dir="rtl"><h1>🔄 סנכרון קופה</h1>';
        echo '<div class="notice notice-error"><p>חסרות עמודות בקובץ. בדוק את הפורמט.</p></div>';
        echo '<p><a href="' . $base . '" class="button">חזרה</a></p></div>'; return;
    }

    $csv = []; $csv_pos_codes = [];
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $c       = str_getcsv($line);
        $barcode = preg_replace('/\.0+$/', '', trim($c[$ci_sku] ?? ''));
        $code    = $ci_code !== null ? preg_replace('/\.0+$/', '', trim($c[$ci_code] ?? '')) : '';
        // use barcode when valid; fall back to POS internal code for items packed in-house (no barcode)
        $sku     = ($barcode !== '' && $barcode !== '0') ? $barcode : $code;
        if ($sku === '') continue;
        if ($code !== '') $csv_pos_codes[$code] = true;
        $price     = $ci_price !== null ? (float)str_replace([',', ' '], ['.', ''], trim($c[$ci_price] ?? '')) : null;
        $stock     = $ci_stock !== null ? (int)trim($c[$ci_stock] ?? '') : null;
        $name      = trim($c[$ci_name]  ?? '');
        $last_sale = $ci_date  !== null ? trim($c[$ci_date]  ?? '') : '';
        $csv[$sku] = ['name' => $name, 'price' => $price, 'stock' => $stock, 'last_sale' => $last_sale];
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
          AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_value != ''
        ORDER BY p.ID ASC
    ");
    $wc_map = []; $sku_seen = []; $sku_dupes = [];
    foreach ($rows as $r) {
        $s = preg_replace('/\.0+$/', '', trim($r->sku)); // strip Excel .0 suffix
        if (isset($sku_seen[$s])) $sku_dupes[$s][] = (int)$r->ID;
        else $sku_seen[$s] = (int)$r->ID;
        $wc_map[$s] = (int)$r->ID; // highest ID wins (most recently created)
    }
    if ($sku_dupes) {
        echo '<div class="notice notice-warning"><p><strong>⚠ ברקודים כפולים ב-WooCommerce:</strong> ';
        $msgs = [];
        foreach ($sku_dupes as $sk => $ids) {
            $all_ids = array_unique(array_merge([$sku_seen[$sk]], $ids));
            $msgs[] = 'ברקוד ' . esc_html($sk) . ' — מוצרים: ' . implode(', ', $all_ids);
        }
        echo implode('; ', $msgs);
        echo '<br>הפלאגין משתמש במוצר האחרון שנוצר (ID גבוה ביותר). מומלץ למחוק את הכפילויות מ-WooCommerce.</p></div>';
    }

    // ── match & build lists ──────────────────────────────
    @set_time_limit(300);
    $price_changes = []; $stock_changes = []; $stock_zero = []; $not_in_wc = []; $inactive = []; $to_restore = []; $in_wc_not_csv = [];
    $price_same = $stock_same = 0;
    $threshold = $do_inactive ? new DateTime('-' . $inactive_months . ' months') : null;

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
        if ($do_inactive && $threshold && $ci_date !== null) {
            $last_sale = $d['last_sale'] ?? '';
            $date      = $last_sale ? DateTime::createFromFormat('d/m/Y', $last_sale) : false;
            if ($date && $date < $threshold && $product->get_catalog_visibility() !== 'hidden') {
                $inactive[] = ['id' => $wc_map[$sku], 'name' => $product->get_name(),
                               'sku' => $sku, 'last_sale' => $last_sale ?: '—'];
            } elseif ($product->get_catalog_visibility() === 'hidden' && $date && $date >= $threshold) {
                // Restore only if hidden AND has a recent sale date (within threshold)
                $to_restore[] = ['id' => $wc_map[$sku], 'name' => $product->get_name(),
                                 'sku' => $sku, 'last_sale' => $last_sale];
            }
        }
    }

    // ── מוצרים באתר שאינם בקובץ הקופה ──────────────────
    $not_in_csv_map = [];
    foreach ($wc_map as $sku => $id) {
        if (!isset($csv[$sku]) && !isset($csv_pos_codes[$sku])) $not_in_csv_map[$id] = $sku;
    }
    if ($not_in_csv_map) {
        $ids_sql = implode(',', array_map('intval', array_keys($not_in_csv_map)));
        $rows = $wpdb->get_results("
            SELECT p.ID, p.post_title,
                   COALESCE(pm_vis.meta_value, 'visible') AS visibility
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_vis ON p.ID = pm_vis.post_id AND pm_vis.meta_key = '_catalog_visibility'
            WHERE p.ID IN ($ids_sql)
            ORDER BY p.post_title ASC
        ");
        foreach ($rows as $r) {
            $in_wc_not_csv[] = [
                'id'   => (int)$r->ID,
                'sku'  => $not_in_csv_map[(int)$r->ID],
                'name' => $r->post_title,
            ];
        }
    }

    // ── store in transient ───────────────────────────────
    $tkey = wp_generate_password(16, false);
    set_transient('shpk_pu_' . $tkey, [
        'prices'     => $price_changes,
        'stocks'     => $stock_changes,
        'stock_zero' => $stock_zero,
        'not_in_wc'  => $not_in_wc,
    ], MINUTE_IN_SECONDS * 30);
    // שמור חסרים בנפרד ל-24 שעות (גם אחרי אישור עדכון)
    if ($not_in_wc)  set_transient('shpk_missing_products',  $not_in_wc,   DAY_IN_SECONDS);
    if ($inactive)   set_transient('shpk_inactive_products', $inactive,   DAY_IN_SECONDS);
    if ($to_restore) set_transient('shpk_restore_products',  $to_restore, DAY_IN_SECONDS);

    // ── preview UI ───────────────────────────────────────
    $total_changes = count($price_changes) + count($stock_changes);
    echo '<div class="wrap" dir="rtl"><h1>🔄 תצוגה מקדימה — סנכרון קופה</h1>';

    // summary chips
    $chips = [];
    if ($do_prices) $chips[] = ['<strong>' . count($price_changes) . '</strong> מחירים ישתנו', '#e8f5e9', '#4caf50'];
    if ($do_prices) $chips[] = ['<strong>' . $price_same . '</strong> מחירים ללא שינוי', '#f5f5f5', '#ccc'];
    if ($do_stock)  $chips[] = ['<strong>' . count($stock_changes) . '</strong> כמויות ישתנו', '#e3f2fd', '#2196f3'];
    if ($do_stock)  $chips[] = ['<strong>' . count($stock_zero) . '</strong> מלאי 0 — לא יעודכנו', '#fff8e1', '#ff9800'];
    if ($not_in_wc)       $chips[] = ['<strong>' . count($not_in_wc) . '</strong> לא נמצאו באתר', '#fce4ec', '#e91e63'];
    if ($in_wc_not_csv)  $chips[] = ['<strong>' . count($in_wc_not_csv) . '</strong> באתר אך לא בקופה', '#fff3e0', '#ff9800'];
    if ($do_inactive && $ci_date !== null) {
        $chips[] = ['<strong>' . count($inactive) . '</strong> לא פעילים (מעל ' . $inactive_months . ' חודשים)', '#f3e5f5', '#9c27b0'];
        if ($to_restore) $chips[] = ['<strong>' . count($to_restore) . '</strong> מוסתרים לשחזור', '#e8f5e9', '#4caf50'];
    }
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
        echo '<p style="color:#555">מוצרים אלו קיימים בקופה אך לא ב-WooCommerce. לאחר אישור העדכון תוכל לייבא אותם לאתר ישירות.</p>';
        echo '<a href="' . admin_url('admin.php?page=shushka-prices&action=export_missing') . '" class="button" style="margin-bottom:16px">⬇ ייצא CSV</a>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>שם</th><th>ברקוד</th><th>מחיר</th></tr></thead><tbody>';
        foreach ($not_in_wc as $u)
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td><td>₪' . number_format($u['price'] ?? 0, 2) . '</td></tr>';
        echo '</tbody></table>';
    }

    if ($inactive || $to_restore) {
        echo '<form method="post" style="margin-top:28px;margin-bottom:8px">';
        wp_nonce_field('shpk_hide_inactive');
        echo '<input type="hidden" name="shpk_hide_inactive" value="1">';
        $btn_parts = [];
        if ($inactive)   $btn_parts[] = '🙈 הסתר ' . count($inactive);
        if ($to_restore) $btn_parts[] = '👁 שחזר ' . count($to_restore);
        submit_button(implode(' | ', $btn_parts) . ' מוצרים', 'primary large', '', false, ['style' => 'font-size:15px']);
        echo '</form>';
    }
    if ($inactive) {
        echo '<h3 style="margin-top:20px">😴 מוצרים לא פעילים — לא נמכרו מעל ' . $inactive_months . ' חודשים (' . count($inactive) . ')</h3>';
        echo '<p style="color:#555">יוסתרו מהקטלוג — ישארו ב-WooCommerce אך לא יופיעו בחנות.</p>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>מוצר</th><th style="width:160px">ברקוד</th><th style="width:130px">נמכר לאחרונה</th></tr></thead><tbody>';
        foreach ($inactive as $u)
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td><td>' . esc_html($u['last_sale']) . '</td></tr>';
        echo '</tbody></table>';
    }
    if ($to_restore) {
        echo '<h3 style="margin-top:28px">✅ מוצרים מוסתרים לשחזור — נמכרו לאחרונה (' . count($to_restore) . ')</h3>';
        echo '<p style="color:#555">מוצרים אלו מוסתרים כרגע אך יש להם מכירה עדכנית — יוחזרו לתצוגה בקטלוג.</p>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>מוצר</th><th style="width:160px">ברקוד</th><th style="width:130px">נמכר לאחרונה</th></tr></thead><tbody>';
        foreach ($to_restore as $u)
            echo '<tr><td>' . esc_html($u['name']) . '</td><td>' . esc_html($u['sku']) . '</td><td>' . esc_html($u['last_sale']) . '</td></tr>';
        echo '</tbody></table>';
    }

    if ($in_wc_not_csv) {
        echo '<h3 style="margin-top:28px">🔎 באתר אך לא בקובץ הקופה (' . count($in_wc_not_csv) . ')</h3>';
        echo '<p style="color:#555">מוצרים אלו קיימים ב-WooCommerce אך לא נמצאו בקובץ הקופה שהועלה. ייתכן שהוסרו מהקופה, ברקוד השתנה, או נוצרו ישירות באתר.</p>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>מוצר</th><th style="width:180px">SKU באתר</th><th style="width:80px">עריכה</th></tr></thead><tbody>';
        foreach ($in_wc_not_csv as $u) {
            $sku_len   = strlen($u['sku']);
            $sku_note  = $sku_len >= 8 ? '' : ' <span style="color:#e65100;font-size:11px">(מזהה ישן? ' . $sku_len . ' ספרות)</span>';
            $edit_url  = get_edit_post_link($u['id']);
            echo '<tr><td>' . esc_html($u['name']) . '</td>';
            echo '<td>' . esc_html($u['sku']) . $sku_note . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '" target="_blank">✏ ערוך</a></td></tr>';
        }
        echo '</tbody></table>';
    }
} // shpk_prices_page()

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
.pbtn-export{background:#183625}
.export-badge{display:inline-block;background:#ff9800;color:#fff;border-radius:10px;font-size:11px;font-weight:700;padding:1px 6px;margin-right:4px;vertical-align:middle}
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
.btn-all{padding:5px 12px;border-radius:16px;border:2px solid #183625;background:#fff;color:#183625;cursor:pointer;font-size:13px;font-weight:600}
.btn-all:hover{background:#183625;color:#fff}
.btn-pack{padding:5px 14px;border-radius:16px;border:none;background:#4caf50;color:#fff;cursor:pointer;font-size:13px;font-weight:600}
.btn-pack:disabled{background:#aaa;cursor:default}
.btn-unpack{padding:5px 14px;border-radius:16px;border:2px solid #9e9e9e;background:#fff;color:#666;cursor:pointer;font-size:13px;font-weight:600}
.btn-unpack:hover{background:#9e9e9e;color:#fff}
.oc-items{padding:0;margin:0;list-style:none}
.oc-item{display:flex;align-items:center;gap:10px;padding:8px 14px;border-top:1px solid #eee;transition:opacity .2s}
.oc-item:nth-child(even){background:#fafafa}
.oc-item.missing{opacity:.4;background:#fff5f5}
.ic{width:20px;height:20px;cursor:pointer;flex-shrink:0;accent-color:#183625}
.iname{flex:1;font-size:14px;color:inherit;text-decoration:none}
.iname[href]:hover{text-decoration:underline;color:#183625}
.iname.done,.iname.done:hover{text-decoration:line-through;color:#bbb}
.iqty-wrap{display:flex;align-items:center;gap:4px;flex-shrink:0}
.iqty-lbl{font-size:12px;color:#888}
.iqty{width:58px;padding:3px 6px;border:1px solid #ccc;border-radius:6px;font-size:14px;font-weight:700;color:#183625;text-align:center}
.iqty:focus{outline:2px solid #183625;border-color:transparent}
.iqty.changed{background:#fff8e1;border-color:#ff9800}
.iqty-orig{font-size:11px;color:#bbb}
.isku{font-size:11px;color:#ccc;flex-shrink:0}
.iprice{font-size:12px;color:#888;background:#f5f5f5;border-radius:10px;padding:1px 7px;flex-shrink:0;white-space:nowrap}
.cprice{color:#555;font-size:13px}
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
.shpk-nav-btn{background:none;border:none;cursor:pointer;font-size:20px;padding:2px 4px;line-height:1}
.shpk-nav-btn.has-coords{text-decoration:none;font-size:20px}
.shpk-nav-btn.no-coords{opacity:.45;transition:opacity .2s}.shpk-nav-btn.no-coords:hover{opacity:1}
.shpk-note-input{width:100%;padding:4px 6px;border:1px solid #ddd;border-radius:6px;font-size:13px;direction:rtl;transition:background .3s}
.shpk-note-input:focus{outline:2px solid #183625;border-color:transparent}
@media print{
    .shpk-note-input{border:none;background:transparent;padding:0;font-size:12px}
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
    $orders       = wc_get_orders(array('status' => array('processing','picking','packed'),'limit' => -1,'orderby' => 'date','order' => 'ASC'));
    $packed_count = count(array_filter($orders, fn($o) => $o->get_status() === 'packed'));
    $export_url   = esc_url(add_query_arg('shpk_export', 'packed', home_url('/')));
    $dk     = date('Ymd');
    $ds     = date_i18n('j/n/Y');
    $total  = count($orders) - $packed_count;

    $cmap = array();
    foreach ($orders as $ord) {
        if ($ord->get_status() === 'packed') continue;
        foreach ($ord->get_items() as $item) {
            $p=$item->get_product(); $name=$item->get_name(); $qty=(int)$item->get_quantity();
            $sku=$p?$p->get_sku():''; $price=$p?(float)$p->get_price():0; $key=$sku?:$name;
            if(!isset($cmap[$key])) $cmap[$key]=array('name'=>$name,'sku'=>$sku,'price'=>$price,'total'=>0,'orders'=>array());
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
            <a href="<?php echo $export_url; ?>" class="pbtn pbtn-export" style="text-decoration:none">⬇ ייצוא לקופה<?php if($packed_count): ?> <span class="export-badge"><?php echo $packed_count; ?></span><?php endif; ?></a>
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
                        <button class="btn-all" id="all-<?php echo $oid; ?>" onclick="checkAll(<?php echo $oid; ?>,this)">☑ הכל</button>
                        <button class="mob-pick-btn" onclick="enterPickMode(<?php echo $oid; ?>)">📱 ליקוט</button>
                        <button class="btn-pack" onclick="packOrder(<?php echo $oid; ?>,this)">✓ סמן כנארז</button>
                    <?php else: ?>
                        <span class="oc-badge badge-packed">נארז ✓</span>
                        <button class="btn-unpack" onclick="setSt(<?php echo $oid; ?>,'picking',this)">↩ החזר לליקוט</button>
                    <?php endif; ?>
                </div>
            </div>
            <ul class="oc-items" id="items-<?php echo $oid; ?>">
            <?php foreach ($ord->get_items() as $iid => $item):
                $p=$item->get_product(); $sku=$p?$p->get_sku():'';
                $qty=(int)$item->get_quantity(); $nm=$item->get_name();
                $unit_price=$p?(float)$p->get_price():0;
                $ck="ck_{$dk}_{$oid}_{$iid}"; $qk="qty_{$dk}_{$oid}_{$iid}";
            ?>
            <li class="oc-item" id="li_<?php echo $ck; ?>">
                <input class="ic no-print" type="checkbox" id="<?php echo $ck; ?>"
                    onchange="onCheck(this)"
                    data-oid="<?php echo $oid; ?>"
                    data-iid="<?php echo $iid; ?>"
                    data-name="<?php echo esc_attr($nm); ?>">
                <?php $purl=$p?get_permalink($p->get_id()):''; ?>
                <a class="iname" id="nm_<?php echo $ck; ?>" <?php if($purl): ?>href="<?php echo esc_url($purl); ?>" target="_blank"<?php endif; ?>><?php echo esc_html($nm); ?></a>
                <?php if($sku): ?><span class="isku"><?php echo esc_html($sku); ?></span><?php endif; ?>
                <?php if($unit_price > 0): ?><span class="iprice">₪<?php echo number_format($unit_price, 2); ?></span><?php endif; ?>
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
            <thead><tr><th>מוצר</th><th>ברקוד</th><th>מחיר</th><th>סה"כ כמות</th><th>הזמנות</th></tr></thead>
            <tbody>
            <?php foreach($cmap as $d): ?>
            <tr><td><?php echo esc_html($d['name']); ?></td><td><?php echo esc_html($d['sku']); ?></td>
            <td class="cprice"><?php echo $d['price']>0 ? '₪'.number_format($d['price'],2) : ''; ?></td>
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
            card.querySelector('.hd-actions').innerHTML = '<span class="oc-badge badge-packed">נארז ✓</span><button class="btn-unpack" onclick="setSt(' + oid + ',\'picking\',this)">↩ החזר לליקוט</button>';
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
                '<button class="btn-all" id="all-' + oid + '" onclick="checkAll(' + oid + ',this)">☑ הכל</button>' +
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
    function checkAll(oid, btn) {
        var cbs = Array.from(document.querySelectorAll('.ic[data-oid="' + oid + '"]'));
        var allChecked = cbs.every(function(cb) { return cb.checked; });
        cbs.forEach(function(cb) {
            cb.checked = !allChecked;
            if (cb.checked) {
                var li = document.getElementById('li_' + cb.id);
                if (li) li.classList.remove('missing');
                var nm = document.getElementById('nm_' + cb.id);
                if (nm) nm.classList.remove('done');
            } else {
                shpkApplyUncheck(cb);
            }
            localStorage.setItem(cb.id, cb.checked ? '1' : '0');
        });
        if (btn) btn.textContent = allChecked ? '☑ הכל' : '☐ בטל הכל';
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
                <th class="no-print" style="width:44px">ניווט</th>
                <th>הערות</th>
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
                $uid    = (int)$ord->get_customer_id();
                $coords = $uid ? get_user_meta($uid, '_shpk_nav_coords', true) : get_post_meta($oid, '_shpk_nav_coords', true);
                $note   = $uid ? get_user_meta($uid, '_shpk_delivery_notes', true) : get_post_meta($oid, '_shpk_delivery_notes', true);
            ?>
            <tr id="row-<?php echo $oid; ?>" data-city="<?php echo esc_attr($city); ?>" data-phone="<?php echo esc_attr($phone); ?>">
                <td><strong><?php echo esc_html($city); ?></strong></td>
                <td><?php echo esc_html($cname); ?><br><small class="co">#<?php echo $onum; ?></small></td>
                <td><?php echo esc_html($addr); ?></td>
                <td class="no-print"><a href="tel:<?php echo esc_attr($phone); ?>">📞 <?php echo esc_html($phone); ?></a></td>
                <td class="no-print"><?php echo $total_price; ?></td>
                <td class="no-print" style="text-align:center">
                    <?php if ($coords):
                        [$clat, $clng] = array_map('trim', explode(',', $coords, 2)); ?>
                    <a class="shpk-nav-btn has-coords" href="https://maps.google.com/?q=<?php echo esc_attr($clat.','.$clng); ?>" target="_blank" title="פתח ב-Google Maps">📍</a>
                    <?php else: ?>
                    <button class="shpk-nav-btn no-coords" data-oid="<?php echo $oid; ?>" data-uid="<?php echo $uid; ?>" onclick="shpkSaveCoords(this)" title="אני ליד הבית — שמור מיקום">📍<sup style="font-size:9px">+</sup></button>
                    <?php endif; ?>
                </td>
                <td>
                    <input class="shpk-note-input" type="text"
                           value="<?php echo esc_attr($note ?: ''); ?>"
                           data-oid="<?php echo $oid; ?>" data-uid="<?php echo $uid; ?>"
                           placeholder="הערות…" onblur="shpkSaveNote(this)">
                </td>
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

    /* ── ניווט: שמירת נ"צ ── */
    function shpkSaveCoords(btn) {
        if (!navigator.geolocation) { alert('הדפדפן לא תומך ב-GPS'); return; }
        if (!confirm('לשמור את המיקום הנוכחי שלך כנ"צ של לקוח זה?')) return;
        btn.textContent = '⏳';
        navigator.geolocation.getCurrentPosition(function(pos) {
            var lat = pos.coords.latitude.toFixed(6);
            var lng = pos.coords.longitude.toFixed(6);
            fetch(SHPK_AJAX, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'shpk_save_nav_coords', nonce:SHPK_NONCE,
                      order_id:btn.dataset.oid, user_id:btn.dataset.uid, lat:lat, lng:lng})
            }).then(function(r){return r.json();}).then(function(d){
                if (d.success) {
                    var a = document.createElement('a');
                    a.className = 'shpk-nav-btn has-coords';
                    a.href = 'https://maps.google.com/?q=' + lat + ',' + lng;
                    a.target = '_blank';
                    a.title = 'פתח ב-Google Maps';
                    a.textContent = '📍';
                    btn.parentNode.replaceChild(a, btn);
                } else { btn.textContent = '❌'; }
            }).catch(function(){ btn.textContent = '❌'; });
        }, function() { btn.textContent = '📍+'; alert('לא הצלחנו לקבל מיקום. ודא שה-GPS פעיל.'); });
    }

    /* ── הערות: שמירה ── */
    function shpkSaveNote(input) {
        fetch(SHPK_AJAX, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action:'shpk_save_delivery_note', nonce:SHPK_NONCE,
                  order_id:input.dataset.oid, user_id:input.dataset.uid, note:input.value})
        }).then(function(r){return r.json();}).then(function(d){
            input.style.background = d.success ? '#e8f5ec' : '#ffebee';
            setTimeout(function(){ input.style.background = ''; }, 1200);
        });
    }

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

/* ── AJAX: ייבוא מוצרים חסרים לאתר כטיוטות ──────────────── */
add_action('wp_ajax_shpk_import_missing', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }

    $missing = get_transient('shpk_missing_products');
    if (!$missing) { wp_send_json_error('אין מוצרים — טען שוב קובץ קופה.'); return; }

    $done = $skip = $fail = 0;
    foreach ($missing as $m) {
        $barcode = sanitize_text_field($m['sku']   ?? '');
        $name    = sanitize_text_field($m['name']  ?? '');
        $price   = (float)($m['price'] ?? 0);
        if (!$barcode || !$name) { $skip++; continue; }
        if (wc_get_product_id_by_sku($barcode))  { $skip++; continue; }
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($barcode);
        $product->set_regular_price((string)$price);
        $product->set_status('draft');
        $product->set_catalog_visibility('visible');
        $id = $product->save();
        if ($id) $done++; else $fail++;
    }
    wp_send_json_success(['done' => $done, 'skip' => $skip, 'fail' => $fail,
                          'admin_url' => admin_url('edit.php?post_type=product&post_status=draft')]);
});

/* ── AJAX: ייבוא מוצרים עם AI ──────────────────────────── */
add_action('wp_ajax_shpk_import_with_ai', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }

    $anthropic_key = get_option('shushka_anthropic_key', '');
    $openai_key    = get_option('shushka_openai_key', '');
    if (!$anthropic_key) { wp_send_json_error('מפתח Anthropic לא מוגדר — הגדר בדף תמונות מוצרים'); return; }
    if (!$openai_key)    { wp_send_json_error('מפתח OpenAI לא מוגדר — הגדר בדף תמונות מוצרים'); return; }

    $missing = get_transient('shpk_missing_products');
    if (!$missing) { wp_send_json_error('אין מוצרים — טען שוב קובץ קופה.'); return; }

    $offset = absint($_POST['offset'] ?? 0);
    $total  = count($missing);
    if ($offset >= $total) {
        wp_send_json_success(['done' => 0, 'skip' => 0, 'fail' => 0, 'name' => '', 'log' => '',
                              'offset' => $total, 'total' => $total, 'finished' => true]);
        return;
    }

    $m       = $missing[$offset];
    $barcode = sanitize_text_field($m['sku']   ?? '');
    $name    = sanitize_text_field($m['name']  ?? '');
    $price   = (float)($m['price'] ?? 0);

    @set_time_limit(300);

    $skip = $done = $fail = 0;
    $log  = '';

    if (!$barcode || !$name) {
        $skip = 1; $log = 'חסר שם או ברקוד';
    } elseif (wc_get_product_id_by_sku($barcode)) {
        $skip = 1; $log = 'כבר קיים';
    } else {
        try {
            $cats_prompt =
                "ירקניה (129): ירקות (48), פירות (58), עלים ירוקים (41)\n" .
                "דגנים קטניות ופסטות (130): דגנים (83), קטניות (84), פסטות אטריות ופתיתים (85)\n" .
                "בישול ואפייה (131): תבלינים (87), שמנים (1895), קמחים ועזרי אפייה (4932), ממתיקים (88), מחלקה אסייתית (91)\n" .
                "ממרחים רטבים ושימורים (132): ממרחי אגוזים (94), ממרחים מלוחים ורטבים (92), ממרחים מתוקים (1887), סירופים (86), שימורים (93)\n" .
                "דגני בוקר גרנולה שוקולד וחטיפים (133): גרנולה ודגני בוקר (96), שוקולד (97), חטיפים (100), סוכריות (98)\n" .
                "עוגיות מאפים וקרקרים (134): עוגיות (103), קרקרים מצות ופריכיות (4929), עוגות (102), לחמים ופיתות (101)\n" .
                "פירות יבשים ואגוזים (35): פירות יבשים (140), אגוזים וזרעים (4920)\n" .
                "משקאות (135): מיצים (109), חליטות ותה (107), קפה (108), תחליפי חלב (106), תרכיזים (105)\n" .
                "מקרר וקפוא (136): תחליפי גבינה (111), תחליפי בשר (4930), טופו וביצים (110), אוכל קפוא (113), גלידות (112), פירות קפואים (4922)\n" .
                "תוספי תזונה ובריאות (137): ויטמינים ותוספים (114), מוצרים טיפוליים (4927), שמנים אתריים (116)\n" .
                "קוסמטיקה ורחצה טבעיים (138): שמפו סבון מרכך (121), טיפוח פנים וגוף (119), היגיינת פה ושיניים (120), דאודורנט (4931), הגנת שמש (117), היגיינה נשית (122), לאם ולתינוק (123)\n" .
                "אקולוגי ומוצרים לבית (139): ניקוי אקולוגי (125), חד פעמי וניירות (124)\n" .
                "כללי (15)";

            // Pass 1: Claude Haiku — generate descriptions + category + image hint
            $haiku_prompt =
                "אתה כותב תוכן לחנות טבע ישראלית בשם שושקה.\n" .
                "כתוב עבור המוצר: \"{$name}\"\n\n" .
                "החזר JSON בלבד (ללא markdown):\n" .
                "{\n" .
                "  \"short_description\": \"תיאור קצר של 1-2 משפטים המדגיש יתרונות בריאותיים עיקריים\",\n" .
                "  \"description\": \"תיאור מפורט של 3-5 משפטים הכולל: מה המוצר, מהיכן מגיע, יתרונות תזונתיים, ואיך ניתן להשתמש בו\",\n" .
                "  \"category_id\": <מספר קטגוריה מהרשימה הכי מתאימה>,\n" .
                "  \"image_hint\": \"תיאור ויזואלי קצר באנגלית של המוצר עצמו (צבע, צורה, אריזה) — משפט אחד, ללא שמות מותגים\"\n" .
                "}\n\n" .
                "קטגוריות:\n{$cats_prompt}";

            $haiku_raw = shpk_claude_call($anthropic_key, $haiku_prompt, 'claude-haiku-4-5-20251001', 1200);
            $haiku_raw = preg_replace('/```json?\s*|\s*```/', '', trim($haiku_raw));
            // Hebrew abbreviation quotes (מ"ג, ד"ר) break JSON — escape them before parsing
            $haiku_raw = preg_replace('/([א-ת])"([א-ת])/u', '$1\\"$2', $haiku_raw);
            // Extract JSON object even if model added text before/after it
            if (ltrim($haiku_raw) === '' || ltrim($haiku_raw)[0] !== '{') {
                preg_match('/\{[\s\S]*\}/u', $haiku_raw, $jm);
                $haiku_raw = $jm[0] ?? $haiku_raw;
            }
            $d1 = json_decode($haiku_raw, true);
            if (!$d1 || !isset($d1['short_description']))
                throw new Exception('Haiku parse error: ' . mb_substr(preg_replace('/\s+/', ' ', $haiku_raw), 0, 150));

            // Pass 2: Claude Sonnet — validate & correct
            $sonnet_prompt =
                "בדוק ותקן את התוכן הבא עבור המוצר \"{$name}\" בחנות טבע ישראלית:\n\n" .
                "short_description: " . ($d1['short_description'] ?? '') . "\n" .
                "description: "       . ($d1['description']       ?? '') . "\n" .
                "category_id: "       . ($d1['category_id']       ?? 15) . "\n\n" .
                "קטגוריות:\n{$cats_prompt}\n\n" .
                "בדוק שגיאות עברית, דיוק המידע והתאמת הקטגוריה.\n" .
                "החזר JSON בלבד (ללא markdown):\n" .
                "{\n  \"short_description\": \"...\",\n  \"description\": \"...\",\n  \"category_id\": <מספר>\n}";

            $sonnet_raw = shpk_claude_call($anthropic_key, $sonnet_prompt, 'claude-sonnet-4-6', 1200);
            $sonnet_raw = preg_replace('/```json?\s*|\s*```/', '', trim($sonnet_raw));
            $sonnet_raw = preg_replace('/([א-ת])"([א-ת])/u', '$1\\"$2', $sonnet_raw);
            $d2 = json_decode($sonnet_raw, true);
            if (!$d2 || !isset($d2['short_description'])) $d2 = $d1;

            $short_desc  = sanitize_text_field($d2['short_description'] ?? '');
            $description = wp_kses_post($d2['description']       ?? '');
            $cat_id      = (int)($d2['category_id']       ?? 15);
            $img_hint    = sanitize_text_field($d1['image_hint']  ?? $name);

            // Pass 3: DALL-E 3 image
            $img_prompt =
                "Warm illustrated style for a natural health food store. Soft watercolor texture, cozy organic aesthetic, " .
                "natural palette (sage green, warm cream, terracotta, honey yellow). Clean white background, simple centered " .
                "composition, hand-drawn feel. No text, no labels, no brand names. " .
                "The product is: {$img_hint}";
            $img_resp = shpk_openai_image($openai_key, $img_prompt);
            if (!isset($img_resp['data'][0]['b64_json'])) throw new Exception('DALL-E error: ' . (isset($img_resp['error']['message']) ? $img_resp['error']['message'] : 'unknown'));
            $image_bytes = base64_decode($img_resp['data'][0]['b64_json']);

            // Upload image to WordPress Media Library
            $filename  = sanitize_title($name) . '-' . $barcode . '.png';
            $attach_id = shpk_upload_product_image($image_bytes, $filename, $name);

            // Create WooCommerce product
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_sku($barcode);
            $product->set_regular_price((string)$price);
            $product->set_short_description($short_desc);
            $product->set_description($description);
            $product->set_category_ids([$cat_id]);
            $product->set_image_id($attach_id);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $id = $product->save();
            if (!$id) throw new Exception('product save failed');

            $done = 1; $log = "קטגוריה {$cat_id}";

        } catch (Exception $e) {
            $fail = 1; $log = mb_substr($e->getMessage(), 0, 120);
        }
    }

    if (ob_get_length()) ob_clean();
    wp_send_json_success([
        'done'     => $done,
        'skip'     => $skip,
        'fail'     => $fail,
        'name'     => $name,
        'log'      => $log,
        'offset'   => $offset + 1,
        'total'    => $total,
        'finished' => ($offset + 1 >= $total),
    ]);
});

/* ── AJAX: שמירת נ"צ ────────────────────────────────────── */
add_action('wp_ajax_shpk_save_nav_coords', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    $oid = absint($_POST['order_id'] ?? 0);
    $uid = absint($_POST['user_id']  ?? 0);
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);
    if (!$lat || !$lng) { wp_send_json_error('invalid coords'); return; }
    $val = round($lat, 6) . ',' . round($lng, 6);
    if ($uid) update_user_meta($uid, '_shpk_nav_coords', $val);
    else      update_post_meta($oid, '_shpk_nav_coords', $val);
    wp_send_json_success();
});

/* ── AJAX: שמירת הערת משלוח ──────────────────────────────── */
add_action('wp_ajax_shpk_save_delivery_note', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    $oid  = absint($_POST['order_id'] ?? 0);
    $uid  = absint($_POST['user_id']  ?? 0);
    $note = sanitize_text_field($_POST['note'] ?? '');
    if ($uid) update_user_meta($uid, '_shpk_delivery_notes', $note);
    else      update_post_meta($oid, '_shpk_delivery_notes', $note);
    wp_send_json_success();
});

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

function shpkMakeBanner(isoDate, extra) {
    var extraHtml = extra ? '<br><span style="font-size:.85rem;opacity:.85">' + extra + '</span>' : '';
    // At page-load: if data-date is still in the future use it, else auto-compute next Thursday
    var js = "(function(){" +
        "if(sessionStorage.getItem('sk_sb'))return;" +
        "var b=document.getElementById('sk-ship-bar');if(!b)return;" +
        "var override=b.getAttribute('data-date');" +
        "var today=new Date().toISOString().slice(0,10);" +
        "var d=new Date();" +
        "if(override&&override>today){" +
        "d=new Date(override+'T12:00:00');" +
        "}else{" +
        "var delta=(4-d.getDay()+7)%7;" +
        "if(delta===0)delta=7;" +
        "d.setDate(d.getDate()+delta);" +
        "}" +
        "var nm=['ראשון','שני','שלישי','רביעי','חמישי','שישי','שבת'];" +
        "var s=b.querySelector('.sk-ship-date');" +
        "if(s)s.textContent='יום '+nm[d.getDay()]+' '+d.getDate()+'.'+(d.getMonth()+1);" +
        "b.style.display='block';" +
        "document.body.prepend?document.body.prepend(b):document.body.insertBefore(b,document.body.firstChild);" +
        "})();";
    return '<!-- wp:html -->\n' +
        '<div id="sk-ship-bar" data-date="' + (isoDate || '') + '" style="display:none;background:#2d5a27;color:#fff;padding:.9rem 8rem .9rem 1.5rem;text-align:center;font-family:Rubik,sans-serif;font-size:1.05rem;position:relative;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,.25);line-height:1.5">\n' +
        '  &#x1F4E6; <strong>המשלוח הבא: <span class="sk-ship-date"></span></strong>&nbsp;&nbsp;|&nbsp;&nbsp;&#x1F69A; משלוח רק 10&#8362;&nbsp;&nbsp;|&nbsp;&nbsp;חינם מהזמנה מעל 380&#8362;' + extraHtml + '\n' +
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
            {sidebar: 'footer1', instance: {raw: {content: shpkMakeBanner(iso, extra)}}});
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

/* ── AJAX: שמירת מפתח Anthropic ─────────────────────────── */
add_action('wp_ajax_shpk_save_anthropic_key', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    update_option('shushka_anthropic_key', sanitize_text_field($_POST['key'] ?? ''));
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

/* ── helpers Anthropic ─────────────────────────────────── */
function shpk_claude_call($key, $prompt, $model = 'claude-haiku-4-5-20251001', $max_tokens = 1024) {
    $resp = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);
    if (is_wp_error($resp)) throw new Exception($resp->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'Anthropic error');
    return trim($data['content'][0]['text'] ?? '');
}

function shpk_upload_product_image($image_bytes, $filename, $alt_text) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $tmp = wp_tempnam($filename);
    file_put_contents($tmp, $image_bytes);

    $file = [
        'name'     => $filename,
        'type'     => 'image/png',
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => strlen($image_bytes),
    ];

    $attach_id = media_handle_sideload($file, 0, $alt_text);
    @unlink($tmp);

    if (is_wp_error($attach_id)) throw new Exception($attach_id->get_error_message());
    update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);
    return (int)$attach_id;
}

/* ── דף התמונות ────────────────────────────────────────── */
function shpk_images_page() {
    $nonce         = wp_create_nonce('shushka_nonce');
    $wp_nonce      = wp_create_nonce('wp_rest');
    $openai_key    = get_option('shushka_openai_key', '');
    $anthropic_key = get_option('shushka_anthropic_key', '');
    $ajax_url      = admin_url('admin-ajax.php');
    $wc_rest       = rtrim(rest_url('wc/v3'), '/');
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
    <button onclick="shpkSaveKey()">שמור</button>
    <span class="shpk-key-status" id="shpk-key-status"><?php echo $openai_key ? '✓ מוגדר' : '⚠ לא מוגדר'; ?></span>
    <label style="margin-right:12px">מפתח Anthropic:</label>
    <input type="password" id="shpk-anthropic-key-input" value="<?php echo esc_attr($anthropic_key); ?>"
           placeholder="sk-ant-...">
    <button onclick="shpkSaveAnthropicKey()">שמור</button>
    <span class="shpk-key-status" id="shpk-ant-key-status"><?php echo $anthropic_key ? '✓ מוגדר' : '⚠ לא מוגדר'; ?></span>
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

window.shpkSaveAnthropicKey = function() {
    var key = document.getElementById('shpk-anthropic-key-input').value.trim();
    fetch(AJAX, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=shpk_save_anthropic_key&nonce=' + NONCE + '&key=' + encodeURIComponent(key)
    }).then(function(r){ return r.json(); }).then(function(d){
        document.getElementById('shpk-ant-key-status').textContent = d.success ? '✓ נשמר' : '✗ שגיאה';
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


/* ═══════════════════════════════════════════════════════════
   📖 מתכונים — Custom Post Type, Admin & Frontend
   ═══════════════════════════════════════════════════════════ */

/* ── Activation: seed קטגוריות ותגיות ברירת-מחדל ────────── */
register_activation_hook(__FILE__, 'shpk_recipes_activate');
function shpk_recipes_activate() {
    shpk_recipes_register_cpt(); // register CPT+tax before seeding
    $cats = ['ארוחה עיקרית', 'ארוחה קלה', 'מאפים', 'קינוחים', 'חטיפים'];
    foreach ($cats as $c)
        if (!term_exists($c, 'shpk_rcat')) wp_insert_term($c, 'shpk_rcat');
    $tags = ['ללא גלוטן', 'ללא סוכר', 'ללא חלב', 'טבעוני', 'עם ביצים', 'קל להכנה', 'עד 30 דקות'];
    foreach ($tags as $t)
        if (!term_exists($t, 'shpk_rtag')) wp_insert_term($t, 'shpk_rtag');
    flush_rewrite_rules();
}

/* ── רישום CPT + טקסונומיות ─────────────────────────────── */
function shpk_recipes_register_cpt() {
    register_post_type('shpk_recipe', [
        'labels' => [
            'name'          => 'מתכונים',
            'singular_name' => 'מתכון',
            'add_new_item'  => 'הוסף מתכון',
            'edit_item'     => 'ערוך מתכון',
            'view_item'     => 'צפה במתכון',
            'all_items'     => 'כל המתכונים',
            'search_items'  => 'חפש מתכונים',
            'not_found'     => 'לא נמצאו מתכונים',
        ],
        'public'        => true,
        'has_archive'   => 'מתכונים',
        'rewrite'       => ['slug' => 'מתכון', 'with_front' => false],
        'supports'      => ['title', 'editor', 'thumbnail', 'excerpt'],
        'menu_icon'     => 'dashicons-food',
        'show_in_rest'  => true,
        'menu_position' => 6,
    ]);

    register_taxonomy('shpk_rcat', 'shpk_recipe', [
        'labels' => [
            'name'          => 'קטגוריות',
            'singular_name' => 'קטגוריה',
            'all_items'     => 'כל הקטגוריות',
            'edit_item'     => 'ערוך קטגוריה',
            'add_new_item'  => 'הוסף קטגוריה',
        ],
        'hierarchical' => true,
        'rewrite'      => ['slug' => 'קטגוריית-מתכון'],
        'show_in_rest' => true,
    ]);

    register_taxonomy('shpk_rtag', 'shpk_recipe', [
        'labels' => [
            'name'          => 'תגיות מתכון',
            'singular_name' => 'תגית',
            'all_items'     => 'כל התגיות',
            'add_new_item'  => 'הוסף תגית',
        ],
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'תגית-מתכון'],
        'show_in_rest' => true,
    ]);
}
add_action('init', 'shpk_recipes_register_cpt');

add_action('init', function() {
    foreach (['_shpk_prep_time' => 'integer', '_shpk_servings' => 'integer', '_shpk_ingredients' => 'string'] as $key => $type)
        register_post_meta('shpk_recipe', $key, ['show_in_rest' => true, 'single' => true, 'type' => $type, 'auth_callback' => '__return_true']);
});

/* ── Admin: Meta Box ─────────────────────────────────────── */
add_action('add_meta_boxes', function() {
    add_meta_box('shpk_recipe_meta', 'פרטי מתכון ומרכיבים', 'shpk_recipe_meta_box', 'shpk_recipe', 'normal', 'high');
});

/* ── AJAX: חיפוש מוצרים לתיבת מטא ─────────────────────── */
add_action('wp_ajax_shpk_product_search', function() {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('edit_posts')) { wp_send_json_error(); return; }
    $q = sanitize_text_field($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { wp_send_json_success([]); return; }

    $by_sku  = wc_get_products(['sku' => $q,  'limit' => 5, 'status' => 'publish', 'return' => 'objects']);
    $by_name = wc_get_products(['s'   => $q,  'limit' => 8, 'status' => 'publish', 'return' => 'objects']);

    $results = [];
    $seen = [];
    foreach (array_merge($by_sku, $by_name) as $p) {
        if (isset($seen[$p->get_id()])) continue;
        $seen[$p->get_id()] = true;
        $results[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku()];
    }
    wp_send_json_success($results);
});

function shpk_recipe_meta_box($post) {
    wp_nonce_field('shpk_recipe_save', 'shpk_recipe_nonce');
    $prep   = get_post_meta($post->ID, '_shpk_prep_time', true);
    $serves = get_post_meta($post->ID, '_shpk_servings',  true);
    $ingr   = json_decode(get_post_meta($post->ID, '_shpk_ingredients', true) ?: '[]', true) ?: [];
    ?>
    <style>
    #shpk_recipe_meta .inside{direction:rtl;padding:12px 16px}
    .shpk-r-label{font-weight:600;font-size:13px;display:block;margin:14px 0 5px}
    .shpk-r-detail-row{display:flex;gap:16px;margin-bottom:4px}
    .shpk-r-detail-row>div{flex:1}
    .shpk-r-detail-row input{width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:14px}
    .shpk-ingr-header{display:flex;gap:6px;color:#888;font-size:11px;padding:0 10px 4px;margin-top:8px}
    .shpk-ingr-header span:first-child{width:86px;flex-shrink:0}
    .shpk-ingr-header span:nth-child(2){flex:1}
    .shpk-ingr-header span:last-child{width:180px;flex-shrink:0}
    .shpk-ingr-row{display:flex;gap:6px;align-items:center;margin-bottom:5px;background:#f9f9f9;padding:6px 10px;border-radius:7px;border:1px solid #eee}
    .shpk-ingr-row input{flex:1;padding:5px 8px;border:1px solid #ddd;border-radius:5px;font-size:13px;background:#fff}
    .shpk-ingr-row .shpk-qty{flex:0 0 86px}
    .shpk-ingr-row .shpk-del{background:#e53935;color:#fff;border:none;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:14px;line-height:1;flex-shrink:0}
    #shpk-ingr-list{margin-bottom:8px}
    #shpk-add-ingr{background:#2d8c4e;color:#fff;border:none;border-radius:7px;padding:7px 18px;cursor:pointer;font-size:13px;font-weight:700}
    .shpk-ingr-hint{color:#999;font-size:11px;margin-top:6px}
    .shpk-pid-wrap{flex:0 0 180px;position:relative}
    .shpk-pid-search{width:100%;padding:5px 8px;border:1px solid #ddd;border-radius:5px;font-size:12px;background:#fff;box-sizing:border-box}
    .shpk-pid-search.has-product{border-color:#2d8c4e;background:#f0faf4}
    .shpk-pid-results{position:absolute;top:100%;right:0;left:0;background:#fff;border:1px solid #ccc;border-radius:5px;box-shadow:0 4px 10px rgba(0,0,0,.15);z-index:99999;display:none;max-height:220px;overflow-y:auto;margin-top:2px}
    .shpk-pid-item{padding:7px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;direction:rtl;text-align:right;line-height:1.4}
    .shpk-pid-item:last-child{border-bottom:none}
    .shpk-pid-item:hover{background:#f0f7f0}
    .shpk-pid-item small{color:#999;display:block;font-size:11px}
    .shpk-pid-clear{position:absolute;left:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#bbb;cursor:pointer;font-size:14px;line-height:1;padding:0;display:none}
    .shpk-pid-wrap:hover .shpk-pid-clear{display:block}
    </style>
    <div>
        <div class="shpk-r-detail-row">
            <div>
                <label class="shpk-r-label">⏱ זמן הכנה (דקות)</label>
                <input type="number" name="shpk_prep_time" value="<?php echo esc_attr($prep); ?>" min="1" max="600">
            </div>
            <div>
                <label class="shpk-r-label">🍽 מספר מנות</label>
                <input type="number" name="shpk_servings" value="<?php echo esc_attr($serves); ?>" min="1" max="200">
            </div>
        </div>
        <label class="shpk-r-label" style="margin-top:22px">📋 מרכיבים</label>
        <div class="shpk-ingr-header">
            <span>כמות</span><span>שם מרכיב</span><span>מוצר בחנות</span>
        </div>
        <div id="shpk-ingr-list">
        <?php foreach ($ingr as $row):
            $pid      = $row['pid'] ?? '';
            $pid_name = '';
            if ($pid) {
                $p = wc_get_product($pid);
                if ($p) $pid_name = $p->get_name();
            }
        ?>
            <div class="shpk-ingr-row">
                <input class="shpk-qty" type="text" name="shpk_ingr_qty[]"  value="<?php echo esc_attr($row['qty']  ?? ''); ?>" placeholder="2 כפות">
                <input                  type="text" name="shpk_ingr_name[]" value="<?php echo esc_attr($row['name'] ?? ''); ?>" placeholder="שם מרכיב">
                <div class="shpk-pid-wrap">
                    <input class="shpk-pid-search<?php echo $pid_name ? ' has-product' : ''; ?>" type="text" placeholder="חפש לפי שם או ברקוד…" autocomplete="off" value="<?php echo esc_attr($pid_name); ?>">
                    <input type="hidden" name="shpk_ingr_pid[]" value="<?php echo esc_attr($pid); ?>">
                    <button type="button" class="shpk-pid-clear" title="נקה">✕</button>
                    <div class="shpk-pid-results"></div>
                </div>
                <button type="button" class="shpk-del" onclick="this.closest('.shpk-ingr-row').remove()">✕</button>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" id="shpk-add-ingr">+ הוסף מרכיב</button>
        <p class="shpk-ingr-hint">חיפוש לפי שם מוצר או ברקוד — רק מוצרים שנמכרים בחנות (אופציונלי).</p>
    </div>
    <script>
    const shpkAjaxNonce = '<?php echo wp_create_nonce("shushka_nonce"); ?>';

    function shpkInitPidSearch(wrap) {
        const search  = wrap.querySelector('.shpk-pid-search');
        const hidden  = wrap.querySelector('input[type=hidden]');
        const results = wrap.querySelector('.shpk-pid-results');
        const clear   = wrap.querySelector('.shpk-pid-clear');
        let timer;

        search.addEventListener('input', function() {
            hidden.value = '';
            search.classList.remove('has-product');
            clear.style.display = 'none';
            clearTimeout(timer);
            const q = this.value.trim();
            if (q.length < 2) { results.style.display = 'none'; return; }
            timer = setTimeout(function() {
                fetch(ajaxurl + '?action=shpk_product_search&nonce=' + shpkAjaxNonce + '&q=' + encodeURIComponent(q))
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data.length) { results.style.display = 'none'; return; }
                        results.innerHTML = data.data.map(function(p) {
                            return '<div class="shpk-pid-item" data-id="' + p.id + '" data-name="' + p.name.replace(/"/g,'&quot;') + '">'
                                + p.name
                                + (p.sku ? '<small>ברקוד: ' + p.sku + '</small>' : '')
                                + '</div>';
                        }).join('');
                        results.style.display = 'block';
                        results.querySelectorAll('.shpk-pid-item').forEach(function(item) {
                            item.addEventListener('click', function() {
                                hidden.value = this.dataset.id;
                                search.value = this.dataset.name;
                                search.classList.add('has-product');
                                clear.style.display = 'block';
                                results.style.display = 'none';
                            });
                        });
                    });
            }, 300);
        });

        clear.addEventListener('click', function() {
            hidden.value = '';
            search.value = '';
            search.classList.remove('has-product');
            clear.style.display = 'none';
            results.style.display = 'none';
        });

        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) results.style.display = 'none';
        });
    }

    // אתחול שורות קיימות
    document.querySelectorAll('.shpk-pid-wrap').forEach(shpkInitPidSearch);

    // כפתור "הוסף מרכיב"
    document.getElementById('shpk-add-ingr').addEventListener('click', function() {
        const div = document.createElement('div');
        div.className = 'shpk-ingr-row';
        div.innerHTML = '<input class="shpk-qty" type="text" name="shpk_ingr_qty[]" placeholder="2 כפות">'
            + '<input type="text" name="shpk_ingr_name[]" placeholder="שם מרכיב">'
            + '<div class="shpk-pid-wrap">'
            +   '<input class="shpk-pid-search" type="text" placeholder="חפש לפי שם או ברקוד…" autocomplete="off">'
            +   '<input type="hidden" name="shpk_ingr_pid[]" value="">'
            +   '<button type="button" class="shpk-pid-clear" title="נקה">✕</button>'
            +   '<div class="shpk-pid-results"></div>'
            + '</div>'
            + '<button type="button" class="shpk-del" onclick="this.closest(\'.shpk-ingr-row\').remove()">✕</button>';
        document.getElementById('shpk-ingr-list').appendChild(div);
        shpkInitPidSearch(div.querySelector('.shpk-pid-wrap'));
    });
    </script>
    <?php
}

/* ── Admin: שמירת Meta ───────────────────────────────────── */
add_action('save_post_shpk_recipe', function($post_id) {
    if (!isset($_POST['shpk_recipe_nonce']) || !wp_verify_nonce($_POST['shpk_recipe_nonce'], 'shpk_recipe_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    update_post_meta($post_id, '_shpk_prep_time', absint($_POST['shpk_prep_time'] ?? 0));
    update_post_meta($post_id, '_shpk_servings',  absint($_POST['shpk_servings']  ?? 0));

    $qtys  = (array)($_POST['shpk_ingr_qty']  ?? []);
    $names = (array)($_POST['shpk_ingr_name'] ?? []);
    $pids  = (array)($_POST['shpk_ingr_pid']  ?? []);
    $ingr  = [];
    foreach ($names as $i => $name) {
        $name = sanitize_text_field($name);
        if (!$name) continue;
        $ingr[] = [
            'qty'  => sanitize_text_field($qtys[$i] ?? ''),
            'name' => $name,
            'pid'  => absint($pids[$i] ?? 0) ?: null,
        ];
    }
    update_post_meta($post_id, '_shpk_ingredients', wp_json_encode($ingr, JSON_UNESCAPED_UNICODE));
});

/* ── AJAX: הוספת מרכיבים לסל ────────────────────────────── */
add_action('wp_ajax_shpk_recipe_cart',        'shpk_recipe_cart_ajax');
add_action('wp_ajax_nopriv_shpk_recipe_cart', 'shpk_recipe_cart_ajax');
function shpk_recipe_cart_ajax() {
    check_ajax_referer('shpk_cart_nonce', 'nonce');
    $ids   = array_filter(array_map('absint', (array)($_POST['ids'] ?? [])));
    $added = 0;
    foreach ($ids as $pid) {
        $product = wc_get_product($pid);
        if ($product && $product->is_purchasable() && $product->is_in_stock()) {
            WC()->cart->add_to_cart($pid, 1);
            $added++;
        }
    }
    wp_send_json_success(['added' => $added, 'cart_url' => wc_get_cart_url()]);
}

/* ── Frontend: תבניות ───────────────────────────────────── */
add_action('template_redirect', function() {
    if (is_singular('shpk_recipe')) {
        get_header(); shpk_single_recipe(); get_footer(); exit;
    }
    if (is_post_type_archive('shpk_recipe') || is_tax('shpk_rcat') || is_tax('shpk_rtag')) {
        get_header(); shpk_recipe_archive(); get_footer(); exit;
    }
});

function shpk_recipe_css() { ?>
<style>
.shpk-recipes-wrap,.shpk-recipe-single{max-width:1100px;margin:0 auto;padding:24px 20px;direction:rtl;font-family:'Rubik',Arial,sans-serif}
.shpk-recipes-wrap h1,.shpk-recipe-single h1{font-size:28px;font-weight:800;color:#183625;margin:0 0 20px}
.shpk-filter-cats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.shpk-filter-cats a{padding:7px 20px;border:2px solid #183625;border-radius:20px;text-decoration:none;color:#183625;font-weight:700;font-size:14px;transition:.15s}
.shpk-filter-cats a.active,.shpk-filter-cats a:hover{background:#183625;color:#fff}
.shpk-filter-tags{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:28px}
.shpk-filter-tags a{padding:5px 16px;border:1px solid #bbb;border-radius:20px;text-decoration:none;color:#555;font-size:13px;transition:.15s}
.shpk-filter-tags a.active,.shpk-filter-tags a:hover{background:#e8f5ec;border-color:#2d8c4e;color:#183625}
.shpk-recipe-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px}
.shpk-recipe-card{border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);transition:transform .2s,box-shadow .2s;background:#fff}
.shpk-recipe-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,.15)}
.shpk-recipe-card a{text-decoration:none;color:inherit;display:block}
.shpk-recipe-card img{width:100%;height:200px;object-fit:cover;display:block}
.shpk-card-no-img{width:100%;height:200px;background:linear-gradient(135deg,#e8f5ec,#c8e6c9);display:flex;align-items:center;justify-content:center;font-size:64px}
.shpk-card-body{padding:16px}
.shpk-card-body h3{margin:0 0 8px;font-size:17px;font-weight:700;color:#183625;line-height:1.3}
.shpk-card-meta{display:flex;gap:12px;color:#888;font-size:13px;margin-bottom:8px}
.shpk-card-tags{display:flex;gap:6px;flex-wrap:wrap}
.shpk-card-tags span{background:#f0f0f0;border-radius:10px;padding:2px 10px;font-size:11px;color:#555}
.shpk-recipe-empty{text-align:center;padding:60px 20px;color:#888;font-size:16px}
.shpk-recipe-single{max-width:900px}
.shpk-recipe-cover{width:100%;max-height:420px;object-fit:cover;border-radius:14px;margin-bottom:24px;display:block}
.shpk-recipe-meta-bar{display:flex;gap:24px;background:#f0f7f3;border-radius:12px;padding:16px 20px;margin-bottom:28px;flex-wrap:wrap}
.shpk-recipe-meta-bar .meta-item{display:flex;flex-direction:column;align-items:center;gap:4px}
.shpk-recipe-meta-bar .meta-val{font-size:22px;font-weight:800;color:#183625}
.shpk-recipe-meta-bar .meta-lbl{font-size:12px;color:#888}
.shpk-recipe-layout{display:grid;grid-template-columns:320px 1fr;gap:36px;align-items:start}
.shpk-recipe-tags-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.shpk-recipe-tags-row a{background:#e8f5ec;color:#183625;border-radius:12px;padding:4px 14px;font-size:13px;text-decoration:none;font-weight:600}
.shpk-recipe-cats-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.shpk-recipe-cats-row a{background:#183625;color:#fff;border-radius:12px;padding:4px 14px;font-size:13px;text-decoration:none;font-weight:600}
.shpk-ingr-panel{background:#fafafa;border-radius:14px;padding:20px;border:1px solid #e8f5ec;position:sticky;top:80px}
.shpk-ingr-panel h2{font-size:18px;font-weight:800;color:#183625;margin:0 0 16px}
.shpk-ingr-list{list-style:none;margin:0 0 20px;padding:0}
.shpk-ingr-list li{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #eee;font-size:15px}
.shpk-ingr-list li:last-child{border-bottom:none}
.shpk-ingr-list input[type=checkbox]{width:20px;height:20px;flex-shrink:0;accent-color:#2d8c4e;cursor:pointer;margin-top:2px}
.shpk-ingr-list .ingr-text{flex:1;line-height:1.4}
.shpk-ingr-list .ingr-qty{color:#888;font-size:13px}
.shpk-ingr-list .ingr-link{font-size:12px;color:#2d8c4e;text-decoration:none;display:block;margin-top:2px}
.shpk-ingr-list .ingr-link:hover{text-decoration:underline}
.shpk-ingr-list li.shpk-have-it .ingr-text{text-decoration:line-through;color:#bbb}
.shpk-ingr-list li.shpk-no-product{opacity:.75}
.shpk-ingr-list .shpk-bullet{width:20px;height:20px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:20px;margin-top:2px}
#shpk-cart-btn{width:100%;background:#183625;color:#fff;border:none;border-radius:10px;padding:14px;font-size:16px;font-weight:700;cursor:pointer;transition:background .15s}
#shpk-cart-btn:hover{background:#2d8c4e}
#shpk-cart-btn:disabled{background:#aaa;cursor:default}
.shpk-cart-msg{display:none;margin-top:10px;padding:10px;border-radius:8px;font-size:14px;text-align:center;font-weight:600}
.shpk-cart-msg.ok{background:#e8f5ec;color:#183625}
.shpk-cart-msg.err{background:#ffebee;color:#c62828}
.shpk-instructions h2{font-size:20px;font-weight:800;color:#183625;margin:0 0 16px}
.shpk-instructions .entry-content,.shpk-instructions p,.shpk-instructions ol,.shpk-instructions ul{font-size:16px;line-height:1.8;color:#333}
.shpk-breadcrumb{font-size:13px;color:#888;margin-bottom:12px}
.shpk-breadcrumb a{color:#2d8c4e;text-decoration:none}
@media(max-width:700px){
    .shpk-recipe-layout{grid-template-columns:1fr}
    .shpk-ingr-panel{position:static}
    .shpk-recipe-grid{grid-template-columns:1fr 1fr}
    .shpk-recipe-single h1{font-size:22px}
}
@media(max-width:480px){.shpk-recipe-grid{grid-template-columns:1fr}}
</style>
<?php }

/* ── ארכיון מתכונים ──────────────────────────────────────── */
function shpk_recipe_archive() {
    shpk_recipe_css();
    $cats    = get_terms(['taxonomy' => 'shpk_rcat', 'hide_empty' => true]);
    $tags    = get_terms(['taxonomy' => 'shpk_rtag', 'hide_empty' => true]);
    $cur_cat = is_tax('shpk_rcat') ? get_queried_object() : null;
    $cur_tag = is_tax('shpk_rtag') ? get_queried_object() : null;

    $q_args = ['post_type' => 'shpk_recipe', 'posts_per_page' => 24, 'post_status' => 'publish'];
    if ($cur_cat) $q_args['tax_query'] = [['taxonomy' => 'shpk_rcat', 'terms' => $cur_cat->term_id]];
    if ($cur_tag) $q_args['tax_query'] = [['taxonomy' => 'shpk_rtag', 'terms' => $cur_tag->term_id]];
    $recipes = new WP_Query($q_args);

    echo '<div class="shpk-recipes-wrap">';
    echo '<h1>מתכונים</h1>';

    if (!is_wp_error($cats) && $cats) {
        $all_active = (!$cur_cat && !$cur_tag) ? 'active' : '';
        echo '<div class="shpk-filter-cats">';
        echo '<a href="' . esc_url(get_post_type_archive_link('shpk_recipe')) . '" class="' . $all_active . '">הכל</a>';
        foreach ($cats as $cat) {
            $a = ($cur_cat && $cur_cat->term_id === $cat->term_id) ? 'active' : '';
            echo '<a href="' . esc_url(get_term_link($cat)) . '" class="' . $a . '">' . esc_html($cat->name) . '</a>';
        }
        echo '</div>';
    }

    if (!is_wp_error($tags) && $tags) {
        echo '<div class="shpk-filter-tags">';
        foreach ($tags as $tag) {
            $a = ($cur_tag && $cur_tag->term_id === $tag->term_id) ? 'active' : '';
            echo '<a href="' . esc_url(get_term_link($tag)) . '" class="' . $a . '">' . esc_html($tag->name) . '</a>';
        }
        echo '</div>';
    }

    if ($recipes->have_posts()) {
        echo '<div class="shpk-recipe-grid">';
        while ($recipes->have_posts()) {
            $recipes->the_post();
            $prep   = get_post_meta(get_the_ID(), '_shpk_prep_time', true);
            $serves = get_post_meta(get_the_ID(), '_shpk_servings',  true);
            $rtags  = get_the_terms(get_the_ID(), 'shpk_rtag') ?: [];
            echo '<div class="shpk-recipe-card"><a href="' . esc_url(get_permalink()) . '">';
            if (has_post_thumbnail()) {
                echo get_the_post_thumbnail(null, 'medium_large', ['alt' => esc_attr(get_the_title())]);
            } else {
                echo '<div class="shpk-card-no-img">🥗</div>';
            }
            echo '<div class="shpk-card-body"><h3>' . esc_html(get_the_title()) . '</h3>';
            echo '<div class="shpk-card-meta">';
            if ($prep)   echo '<span>⏱ ' . (int)$prep   . ' דק׳</span>';
            if ($serves) echo '<span>🍽 ' . (int)$serves . ' מנות</span>';
            echo '</div>';
            if ($rtags) {
                echo '<div class="shpk-card-tags">';
                foreach (array_slice($rtags, 0, 3) as $t)
                    echo '<span>' . esc_html($t->name) . '</span>';
                echo '</div>';
            }
            echo '</div></a></div>';
        }
        wp_reset_postdata();
        echo '</div>';
    } else {
        echo '<div class="shpk-recipe-empty">😔 לא נמצאו מתכונים בקטגוריה זו.</div>';
    }
    echo '</div>';
}

/* ── מתכון בודד ──────────────────────────────────────────── */
function shpk_single_recipe() {
    shpk_recipe_css();
    the_post();
    $prep   = get_post_meta(get_the_ID(), '_shpk_prep_time', true);
    $serves = get_post_meta(get_the_ID(), '_shpk_servings',  true);
    $ingr   = json_decode(get_post_meta(get_the_ID(), '_shpk_ingredients', true) ?: '[]', true) ?: [];
    $rcats  = get_the_terms(get_the_ID(), 'shpk_rcat') ?: [];
    $rtags  = get_the_terms(get_the_ID(), 'shpk_rtag') ?: [];
    $has_products = !empty(array_filter($ingr, function($i){ return !empty($i['pid']); }));

    echo '<div class="shpk-recipe-single">';
    echo '<p class="shpk-breadcrumb"><a href="' . esc_url(get_post_type_archive_link('shpk_recipe')) . '">← כל המתכונים</a></p>';

    if ($rcats) {
        echo '<div class="shpk-recipe-cats-row">';
        foreach ($rcats as $c) echo '<a href="' . esc_url(get_term_link($c)) . '">' . esc_html($c->name) . '</a>';
        echo '</div>';
    }

    echo '<h1>' . esc_html(get_the_title()) . '</h1>';

    if ($rtags) {
        echo '<div class="shpk-recipe-tags-row">';
        foreach ($rtags as $t) echo '<a href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
        echo '</div>';
    }

    if (has_post_thumbnail())
        echo '<img class="shpk-recipe-cover" src="' . esc_url(get_the_post_thumbnail_url(null, 'large')) . '" alt="' . esc_attr(get_the_title()) . '">';

    if ($prep || $serves) {
        echo '<div class="shpk-recipe-meta-bar">';
        if ($prep)   echo '<div class="meta-item"><span class="meta-val">' . (int)$prep   . '</span><span class="meta-lbl">דקות הכנה</span></div>';
        if ($serves) echo '<div class="meta-item"><span class="meta-val">' . (int)$serves . '</span><span class="meta-lbl">מנות</span></div>';
        echo '</div>';
    }

    echo '<div class="shpk-recipe-layout">';

    if ($ingr) {
        $nonce = wp_create_nonce('shpk_cart_nonce');
        echo '<div class="shpk-ingr-panel"><h2>מרכיבים</h2><ul class="shpk-ingr-list">';
        foreach ($ingr as $item) {
            $pid  = !empty($item['pid']) ? (int)$item['pid'] : 0;
            $link = $pid ? get_permalink($pid) : '';
            if ($pid) {
                echo '<li data-pid="' . $pid . '">';
                echo '<input type="checkbox" checked onchange="this.closest(\'li\').classList.toggle(\'shpk-have-it\',!this.checked)">';
            } else {
                echo '<li class="shpk-no-product" data-pid="0">';
                echo '<span class="shpk-bullet">•</span>';
            }
            echo '<div class="ingr-text">';
            if (!empty($item['qty'])) echo '<span class="ingr-qty">' . esc_html($item['qty']) . '</span> ';
            echo esc_html($item['name']);
            if ($link) echo '<a class="ingr-link" href="' . esc_url($link) . '" target="_blank">🛒 לצפייה בחנות</a>';
            echo '</div></li>';
        }
        echo '</ul>';
        if ($has_products) {
            echo '<button id="shpk-cart-btn" onclick="shpkAddToCart()">🛒 הוסף מסומנים לסל</button>';
            echo '<div class="shpk-cart-msg" id="shpk-cart-msg"></div>';
            $ajax = esc_url(admin_url('admin-ajax.php'));
            $n    = esc_js($nonce);
            echo '<script>
function shpkAddToCart(){
    var checked=[].slice.call(document.querySelectorAll(".shpk-ingr-list li")).filter(function(li){
        return li.querySelector("input:checked") && li.dataset.pid && li.dataset.pid!=="0";
    }).map(function(li){return li.dataset.pid;});
    var msg=document.getElementById("shpk-cart-msg");
    if(!checked.length){msg.textContent="אנא סמן לפחות מרכיב אחד שנמכר בחנות";msg.className="shpk-cart-msg err";msg.style.display="block";return;}
    var btn=document.getElementById("shpk-cart-btn");
    btn.disabled=true;btn.textContent="⏳ מוסיף...";
    var body=new URLSearchParams({action:"shpk_recipe_cart",nonce:"' . $n . '"});
    checked.forEach(function(id){body.append("ids[]",id);});
    fetch("' . $ajax . '",{method:"POST",body:body})
    .then(function(r){return r.json();}).then(function(d){
        if(d.success){
            msg.textContent="✓ "+d.data.added+" מוצרים נוספו לסל!";
            msg.className="shpk-cart-msg ok";msg.style.display="block";
            btn.textContent="✓ נוסף לסל";
            setTimeout(function(){btn.disabled=false;btn.textContent="🛒 הוסף מסומנים לסל";},3000);
        } else {
            msg.textContent="שגיאה בהוספה לסל";
            msg.className="shpk-cart-msg err";msg.style.display="block";
            btn.disabled=false;btn.textContent="🛒 הוסף מסומנים לסל";
        }
    }).catch(function(){btn.disabled=false;btn.textContent="🛒 הוסף מסומנים לסל";});
}
</script>';
        }
        echo '</div>';
    }

    echo '<div class="shpk-instructions"><h2>אופן הכנה</h2><div class="entry-content">';
    the_content();
    echo '</div></div>';

    echo '</div>'; // layout
    echo '</div>'; // single
}


/* ═══════════════════════════════════════════════════════════
   🔄 המלצות מוצרים — המוצרים שאתה בדרך כלל קונה
   ═══════════════════════════════════════════════════════════ */

/* ── ליבה: מוצרים נרכשים לעיתים קרובות ─────────────────── */
function shpk_get_frequent_products($user_id, $limit = 15, $exclude_ids = []) {
    $cache_key = 'shpk_freq_' . $user_id;
    $counts    = get_transient($cache_key);
    if ($counts === false) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => ['wc-completed', 'wc-processing'],
            'limit'       => 60,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        $counts = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = (int)$item->get_product_id();
                if ($pid) $counts[$pid] = ($counts[$pid] ?? 0) + 1;
            }
        }
        arsort($counts);
        set_transient($cache_key, $counts, HOUR_IN_SECONDS * 6);
    }

    $result = [];
    foreach ($counts as $pid => $cnt) {
        if (!in_array($pid, $exclude_ids, true)) $result[$pid] = $cnt;
        if (count($result) >= $limit) break;
    }
    return $result;
}

// נקה קאש כשמוזמן הזמנה חדשה
add_action('woocommerce_order_status_changed', function($order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_customer_id())
        delete_transient('shpk_freq_' . $order->get_customer_id());
});

/* ── CSS משותף להמלצות ───────────────────────────────────── */
function shpk_rec_css() {
    static $printed = false;
    if ($printed) return;
    $printed = true; ?>
<style>
/* checkout */
.shpk-rec-checkout{direction:rtl;margin:24px 0;padding:20px;background:#f9fdf9;border:1px solid #d4edda;border-radius:12px}
.shpk-rec-checkout h3{font-size:16px;font-weight:700;color:#183625;margin:0 0 14px}
.shpk-rec-list{display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;scrollbar-width:thin}
.shpk-rec-item{flex:0 0 130px;background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:10px;text-align:center;font-size:13px}
.shpk-rec-item img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;margin-bottom:6px}
.shpk-rec-name{font-size:12px;color:#333;margin-bottom:4px;line-height:1.3;min-height:32px}
.shpk-rec-price{color:#183625;font-weight:700;font-size:13px;margin-bottom:8px}
.shpk-rec-add{width:100%;background:#183625;color:#fff;border:none;border-radius:7px;padding:6px;cursor:pointer;font-size:13px;font-weight:700;transition:.15s}
.shpk-rec-add:hover{background:#2d8c4e}
.shpk-rec-add.added{background:#aaa;cursor:default}
/* my account */
.shpk-freq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-bottom:20px;direction:rtl}
.shpk-freq-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.shpk-freq-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
.shpk-freq-info{padding:10px;flex:1}
.shpk-freq-name{font-size:13px;font-weight:600;color:#183625;margin-bottom:4px;line-height:1.3}
.shpk-freq-name a{color:inherit;text-decoration:none}
.shpk-freq-price{color:#555;font-size:13px;margin-bottom:4px}
.shpk-freq-count{font-size:11px;color:#aaa}
.shpk-freq-add{width:100%;background:#183625;color:#fff;border:none;padding:10px;cursor:pointer;font-size:14px;font-weight:700;transition:.15s}
.shpk-freq-add:hover{background:#2d8c4e}
.shpk-freq-add.added{background:#aaa;cursor:default}
.shpk-freq-add-all{display:block;margin:0 auto 20px;background:#2d8c4e;color:#fff;border:none;border-radius:10px;padding:14px 32px;font-size:16px;font-weight:700;cursor:pointer;direction:rtl}
.shpk-freq-add-all:hover{background:#183625}
.shpk-freq-msg{text-align:center;padding:10px;border-radius:8px;font-size:14px;font-weight:600;margin-top:10px}
.shpk-freq-msg.ok{background:#e8f5ec;color:#183625}
/* product page carousel */
.shpk-rec-carousel-wrap{direction:rtl;margin:32px 0;padding:24px 0;border-top:1px solid #eee}
.shpk-rec-carousel-wrap h3{font-size:18px;font-weight:800;color:#183625;margin:0 0 16px}
.shpk-rec-carousel{display:flex;gap:14px;overflow-x:auto;padding-bottom:8px;scrollbar-width:thin;scroll-snap-type:x mandatory}
.shpk-rec-card{flex:0 0 160px;background:#fff;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;scroll-snap-align:start;display:flex;flex-direction:column}
.shpk-rec-card img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
.shpk-rec-card-name{padding:8px 10px 4px;font-size:13px;font-weight:600;color:#183625;flex:1}
.shpk-rec-card-name a{color:inherit;text-decoration:none}
.shpk-rec-card-price{padding:0 10px 8px;font-size:13px;color:#555}
.shpk-rec-card-add{margin:0 10px 10px;background:#183625;color:#fff;border:none;border-radius:7px;padding:8px;cursor:pointer;font-size:13px;font-weight:700;transition:.15s}
.shpk-rec-card-add:hover{background:#2d8c4e}
.shpk-rec-card-add.added{background:#aaa;cursor:default}
@media(max-width:600px){
    .shpk-rec-item{flex:0 0 120px}
    .shpk-rec-card{flex:0 0 140px}
    .shpk-freq-grid{grid-template-columns:1fr 1fr}
}
</style>
<?php }

/* ── 1. דף Checkout ─────────────────────────────────────── */
add_action('woocommerce_review_order_before_submit', 'shpk_checkout_recommendations');
function shpk_checkout_recommendations() {
    if (!is_user_logged_in()) return;
    $user_id  = get_current_user_id();
    $cart_ids = array_column(WC()->cart->get_cart(), 'product_id');
    $frequent = shpk_get_frequent_products($user_id, 15, $cart_ids);
    if (!$frequent) return;

    $products = [];
    foreach (array_keys($frequent) as $pid) {
        $p = wc_get_product($pid);
        if ($p && $p->is_purchasable() && $p->is_in_stock()) $products[] = $p;
        if (count($products) >= 8) break;
    }
    if (!$products) return;

    shpk_rec_css();
    echo '<div class="shpk-rec-checkout"><h3>🔄 בדרך כלל גם קנית — שכחת משהו?</h3><div class="shpk-rec-list">';
    foreach ($products as $p) {
        $pid = $p->get_id();
        echo '<div class="shpk-rec-item">';
        echo '<a href="' . esc_url(get_permalink($pid)) . '">' . $p->get_image('thumbnail') . '</a>';
        echo '<div class="shpk-rec-name">' . esc_html($p->get_name()) . '</div>';
        echo '<div class="shpk-rec-price">' . $p->get_price_html() . '</div>';
        echo '<button class="shpk-rec-add" data-pid="' . $pid . '">+ הוסף</button>';
        echo '</div>';
    }
    echo '</div></div>';
    ?>
    <script>
    document.querySelectorAll('.shpk-rec-add').forEach(function(btn){
        btn.addEventListener('click',function(){
            var pid=this.dataset.pid, self=this;
            self.disabled=true; self.textContent='⏳';
            fetch('/?wc-ajax=add_to_cart',{method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'product_id='+pid+'&quantity=1'
            }).then(function(r){return r.json();}).then(function(d){
                if(d.error){self.textContent='+ הוסף';self.disabled=false;}
                else{self.textContent='✓ נוסף';self.classList.add('added');
                    if(typeof jQuery!=='undefined') jQuery(document.body).trigger('update_checkout');}
            }).catch(function(){self.textContent='+ הוסף';self.disabled=false;});
        });
    });
    </script>
    <?php
}

/* ── 2. דף My Account — לשונית "הזמנות רגילות" ──────────── */
add_filter('woocommerce_account_menu_items', function($items) {
    $out = [];
    foreach ($items as $k => $v) {
        $out[$k] = $v;
        if ($k === 'orders') $out['frequent-orders'] = '🔄 הזמנות רגילות';
    }
    return $out;
});

add_action('init', function() {
    add_rewrite_endpoint('frequent-orders', EP_ROOT | EP_PAGES);
});

add_action('woocommerce_account_frequent-orders_endpoint', function() {
    if (!is_user_logged_in()) return;
    $user_id  = get_current_user_id();
    $frequent = shpk_get_frequent_products($user_id, 24);
    shpk_rec_css();

    echo '<h2 style="direction:rtl;font-size:20px;font-weight:800;color:#183625;margin-bottom:8px">המוצרים שאתה בדרך כלל קונה</h2>';
    if (!$frequent) {
        echo '<p style="direction:rtl;color:#888">לאחר הזמנה ראשונה, המוצרים שתרכוש לעיתים קרובות יופיעו כאן.</p>';
        return;
    }
    echo '<p style="direction:rtl;font-size:13px;color:#888;margin-bottom:20px">לחץ + כדי להוסיף לסל הקניות</p>';
    echo '<button class="shpk-freq-add-all" onclick="shpkAddAll()">🛒 הוסף הכל לסל</button>';
    echo '<div class="shpk-freq-grid">';
    foreach (array_keys($frequent) as $pid) {
        $p = wc_get_product($pid);
        if (!$p || !$p->is_purchasable()) continue;
        echo '<div class="shpk-freq-card">';
        echo '<a href="' . esc_url(get_permalink($pid)) . '">' . $p->get_image('thumbnail') . '</a>';
        echo '<div class="shpk-freq-info">';
        echo '<div class="shpk-freq-name"><a href="' . esc_url(get_permalink($pid)) . '">' . esc_html($p->get_name()) . '</a></div>';
        echo '<div class="shpk-freq-price">' . $p->get_price_html() . '</div>';
        echo '<div class="shpk-freq-count">נרכש ' . $frequent[$pid] . '×</div>';
        echo '</div>';
        echo '<button class="shpk-freq-add" data-pid="' . $pid . '">+ הוסף לסל</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '<div id="shpk-freq-msg" class="shpk-freq-msg"></div>';
    ?>
    <script>
    function shpkDoAdd(btn){
        var pid=btn.dataset.pid;
        btn.disabled=true; btn.textContent='⏳';
        return fetch('/?wc-ajax=add_to_cart',{method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'product_id='+pid+'&quantity=1'
        }).then(function(r){return r.json();}).then(function(d){
            if(d.error){btn.textContent='+ הוסף לסל';btn.disabled=false;}
            else{btn.textContent='✓ נוסף';btn.classList.add('added');}
            return d;
        });
    }
    document.querySelectorAll('.shpk-freq-add').forEach(function(b){
        b.addEventListener('click',function(){shpkDoAdd(this);});
    });
    function shpkAddAll(){
        var btns=[].slice.call(document.querySelectorAll('.shpk-freq-add:not(.added):not(:disabled)'));
        if(!btns.length) return;
        var msg=document.getElementById('shpk-freq-msg');
        msg.textContent='מוסיף '+btns.length+' מוצרים...';
        msg.className='shpk-freq-msg ok'; msg.style.display='block';
        var i=0;
        function next(){
            if(i>=btns.length){
                msg.textContent='✓ '+btns.length+' מוצרים נוספו לסל!';
                if(typeof jQuery!=='undefined') jQuery(document.body).trigger('wc_fragment_refresh');
                return;
            }
            shpkDoAdd(btns[i++]).then(function(){setTimeout(next,200);});
        }
        next();
    }
    </script>
    <?php
});

/* ── 3. דף מוצר — carousel ───────────────────────────────── */
add_action('woocommerce_after_single_product_summary', 'shpk_product_rec_carousel', 25);
function shpk_product_rec_carousel() {
    if (!is_user_logged_in()) return;
    $user_id  = get_current_user_id();
    $cur_pid  = get_the_ID();
    $frequent = shpk_get_frequent_products($user_id, 14, [$cur_pid]);
    if (!$frequent) return;

    $products = [];
    foreach (array_keys($frequent) as $pid) {
        $p = wc_get_product($pid);
        if ($p && $p->is_purchasable() && $p->is_in_stock()) $products[] = $p;
        if (count($products) >= 10) break;
    }
    if (count($products) < 3) return;

    shpk_rec_css();
    echo '<div class="shpk-rec-carousel-wrap"><h3>המוצרים שאתה בדרך כלל קונה</h3><div class="shpk-rec-carousel">';
    foreach ($products as $p) {
        $pid = $p->get_id();
        echo '<div class="shpk-rec-card">';
        echo '<a href="' . esc_url(get_permalink($pid)) . '">' . $p->get_image('thumbnail') . '</a>';
        echo '<div class="shpk-rec-card-name"><a href="' . esc_url(get_permalink($pid)) . '">' . esc_html($p->get_name()) . '</a></div>';
        echo '<div class="shpk-rec-card-price">' . $p->get_price_html() . '</div>';
        echo '<button class="shpk-rec-card-add" data-pid="' . $pid . '">+ הוסף</button>';
        echo '</div>';
    }
    echo '</div></div>';
    ?>
    <script>
    document.querySelectorAll('.shpk-rec-card-add').forEach(function(btn){
        btn.addEventListener('click',function(){
            var pid=this.dataset.pid,self=this;
            self.disabled=true; self.textContent='⏳';
            fetch('/?wc-ajax=add_to_cart',{method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'product_id='+pid+'&quantity=1'
            }).then(function(r){return r.json();}).then(function(d){
                self.textContent=d.error?'+ הוסף':'✓';
                self.disabled=!d.error?true:false;
                if(!d.error && typeof jQuery!=='undefined') jQuery(document.body).trigger('wc_fragment_refresh');
            }).catch(function(){self.textContent='+ הוסף';self.disabled=false;});
        });
    });
    </script>
    <?php
}

/* ═══════════════════════════════════════════════════════════
   ייבוא הזמנות מגוגל טפסים → WooCommerce   (v5.4.0)
   ═══════════════════════════════════════════════════════════ */

add_action('admin_menu', function () {
    add_submenu_page(
        'shushka-pick',
        'ייבוא מטפסי Google',
        '📋 טפסי Google',
        'manage_woocommerce',
        'shushka-gforms',
        'shpk_gforms_page'
    );
}, 20);

/* ── helpers ──────────────────────────────────────────────── */

/**
 * Save the column-to-product mapping using raw SQL to bypass WordPress's
 * charset/length validation, which rejects Hebrew text in maybe_serialize().
 * Mapping: [ col_key (md5 hex) => ['p'=>pid, 'p2'=>pid2, 's2'=>size2] ]
 * p2/s2 optional — used for large-package split (e.g. 1 kg vs 2 kg bags)
 */
function shpk_gf_save_mapping($sheet_idx, $mapping) {
    global $wpdb;
    $opt = 'shpk_gf_mapping_' . (int)$sheet_idx;
    $wpdb->query(sprintf(
        "REPLACE INTO `%s` (`option_name`, `option_value`, `autoload`) VALUES ('%s', '%s', 'no')",
        $wpdb->options,
        esc_sql($opt),
        esc_sql(maybe_serialize($mapping))
    ));
    wp_cache_delete($opt, 'options');
    $alloptions = wp_cache_get('alloptions', 'options');
    if (is_array($alloptions)) { unset($alloptions[$opt]); wp_cache_set('alloptions', $alloptions, 'options'); }
}

function shpk_gf_get_mapping($sheet_idx) {
    return get_option('shpk_gf_mapping_' . (int)$sheet_idx, []);
}

function shpk_gforms_sheet_csv_url($url) {
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv';
    return '';
}

function shpk_gforms_fetch_and_parse($url) {
    $csv_url = shpk_gforms_sheet_csv_url($url);
    if (!$csv_url) return null;
    $resp = wp_remote_get($csv_url, ['timeout' => 30, 'sslverify' => true]);
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $body = ltrim(wp_remote_retrieve_body($resp), "\xEF\xBB\xBF");
    if (!$body) return null;
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($body)));
    if (!$lines) return null;
    $headers = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        if (!trim($line)) continue;
        $rows[] = str_getcsv($line);
    }
    return ['headers' => $headers, 'rows' => $rows];
}

function shpk_gforms_get_sheet_data($sheet_idx) {
    $t = get_transient('shpk_gf_data_' . $sheet_idx);
    if ($t !== false) return $t;
    $sheets = get_option('shpk_gforms_sheets', []);
    $url = $sheets[$sheet_idx]['url'] ?? '';
    if (!$url) return null;
    $data = shpk_gforms_fetch_and_parse($url);
    if ($data) set_transient('shpk_gf_data_' . $sheet_idx, $data, 20 * MINUTE_IN_SECONDS);
    return $data;
}

function shpk_gforms_classify_col($h) {
    $h = trim($h);
    if ($h === '') return 'skip';
    if (stripos($h, 'timestamp') !== false) return 'timestamp';
    if (preg_match('/שם\s*(מלא|פרטי|פרטי\s*ומשפחה)/u', $h)) return 'name';
    if (preg_match('/^ישוב$/u', $h)) return 'city';
    if (preg_match('/טלפון|מס\.?\s*טל/u', $h)) return 'phone';
    if (preg_match('/מייל|email/iu', $h)) return 'email';
    if (preg_match('/דברים\s*נוספים|הפתעת\s*השבוע|אינטרס|הערות\s*לה?זמנה/u', $h)) return 'notes';
    if (preg_match('/אופן\s*איסוף|שעות\s*פתיחה|איך\s*נחשפת/u', $h)) return 'notes';
    if (preg_match('/Row\s+\d+/i', $h)) return 'skip';
    if (preg_match('/\[[^\]]+\]/u', $h)) return 'product';
    return 'skip';
}

function shpk_gforms_extract_product_name($header) {
    preg_match_all('/\[([^\]]+)\]/u', $header, $m);
    $name = !empty($m[1]) ? trim(end($m[1])) : trim($header);
    $name = preg_replace('/\s*[\d,]+(?:\.\d+)?\s*(?:ש[״"\'"]?ח|₪|שח)[\s\d.]*$/u', '', $name);
    $name = preg_replace('/^(?:אזל\s*[-–]?\s*|חדש!\s*|מוגבל\s*[-–]?\s*|נגמר\s*[-–]?\s*|חזר!\s*|כמות\s*מוגבלת\s*[-–]?\s*)/u', '', trim($name));
    return trim(trim($name, " -–\t"));
}

function shpk_gforms_col_key($header) {
    return substr(md5(trim($header)), 0, 12);
}

function shpk_gforms_parse_timestamp($ts) {
    $ts = trim($ts ?? '');
    if (!$ts) return null;
    foreach (['d/m/Y H:i:s', 'n/j/Y H:i:s', 'd/m/Y G:i:s', 'j/n/Y H:i:s', 'Y-m-d H:i:s'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $ts);
        if ($dt) return $dt;
    }
    $t = @strtotime($ts);
    if ($t && $t > 0) { $dt = new DateTime(); $dt->setTimestamp($t); return $dt; }
    return null;
}

function shpk_gforms_update_checkpoint($sheet_idx, $new_rows, $ts_col) {
    $max_ts = 0;
    foreach ($new_rows as $row) {
        $ts_str = $ts_col !== null ? trim($row[$ts_col] ?? '') : '';
        $dt = $ts_str ? shpk_gforms_parse_timestamp($ts_str) : null;
        if ($dt && $dt->getTimestamp() > $max_ts) $max_ts = $dt->getTimestamp();
    }
    if (!$max_ts) $max_ts = time();
    update_option('shpk_gf_checkpoint_' . $sheet_idx, $max_ts);
    delete_transient('shpk_gf_status_' . $sheet_idx);
    delete_transient('shpk_gf_data_' . $sheet_idx);
}

/* ── status block (injected into sync page) ───────────────── */

function shpk_gforms_status_block() {
    $sheets = get_option('shpk_gforms_sheets', []);
    if (!$sheets) return;
    $nonce = wp_create_nonce('shushka_nonce');

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin-bottom:24px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    echo '<h2 style="margin:0">📋 טפסי הזמנות ישנים</h2>';
    echo '<a href="' . admin_url('admin.php?page=shushka-gforms') . '" class="button" style="font-size:12px">⚙ הגדרות ומיפוי</a>';
    echo '</div>';

    foreach ($sheets as $i => $sheet) {
        if (empty($sheet['url'])) continue;

        $status_key = 'shpk_gf_status_' . $i;
        $status = get_transient($status_key);
        if ($status === false) {
            $data = shpk_gforms_get_sheet_data($i);
            $new_count = 0; $newest_ts = ''; $err = '';
            if ($data) {
                $ts_col = null;
                foreach ($data['headers'] as $ci => $ch) {
                    if (shpk_gforms_classify_col($ch) === 'timestamp') { $ts_col = $ci; break; }
                }
                $checkpoint = (int)get_option('shpk_gf_checkpoint_' . $i, 0);
                foreach ($data['rows'] as $row) {
                    $ts_str = $ts_col !== null ? trim($row[$ts_col] ?? '') : '';
                    $dt = $ts_str ? shpk_gforms_parse_timestamp($ts_str) : null;
                    $is_new = $dt ? ($dt->getTimestamp() > $checkpoint) : ($checkpoint === 0);
                    if ($is_new) { $new_count++; if ($dt && $ts_str > $newest_ts) $newest_ts = $ts_str; }
                }
            } else {
                $err = 'שגיאה בגישה לגיליון';
            }
            $status = ['count' => $new_count, 'newest' => $newest_ts, 'err' => $err];
            if (!$err) set_transient($status_key, $status, 15 * MINUTE_IN_SECONDS);
        }

        $sep = $i > 0 ? 'border-top:1px solid #eee;' : '';
        echo '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;' . $sep . 'flex-wrap:wrap">';
        echo '<span style="min-width:160px;font-weight:600">' . esc_html($sheet['name']) . '</span>';

        if ($status['err']) {
            echo '<span style="color:#c00;font-size:13px">⚠ ' . esc_html($status['err']) . '</span>';
        } elseif ($status['count'] > 0) {
            echo '<span style="color:#e65100;font-weight:600">' . $status['count'] . ' הזמנות חדשות</span>';
            if ($status['newest']) echo '<span style="color:#888;font-size:12px">(עד ' . esc_html($status['newest']) . ')</span>';
            echo ' <button class="button button-primary" onclick="shpkGfImport(' . $i . ',\'' . esc_js($nonce) . '\',this)" style="background:#4caf50;border-color:#388e3c">⬆ ייבא ל-WC</button>';
        } else {
            $cp = (int)get_option('shpk_gf_checkpoint_' . $i, 0);
            $cp_label = $cp ? ' <span style="color:#aaa;font-size:12px">(' . date_i18n('d/m H:i', $cp) . ')</span>' : '';
            echo '<span style="color:#4caf50">✓ עדכני' . $cp_label . '</span>';
        }

        $mapping_url = admin_url('admin.php?page=shushka-gforms&tab=mapping&sheet=' . $i);
        echo ' <a href="' . esc_url($mapping_url) . '" class="button" style="font-size:12px;margin-right:auto">מיפוי מוצרים</a>';
        echo '<span id="shpk-gf-st-' . $i . '" style="font-size:13px;color:#555"></span>';
        echo '</div>';
    }

    echo '<script>
function shpkGfImport(idx,nonce,btn){
    var st=document.getElementById("shpk-gf-st-"+idx);
    btn.disabled=true; var done=0,skip=0,fail=0;
    function step(offset){
        fetch(ajaxurl,{method:"POST",body:new URLSearchParams({action:"shpk_gforms_import_one",nonce:nonce,sheet_idx:idx,offset:offset})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success){st.textContent="שגיאה: "+(d.data||"");btn.disabled=false;return;}
            var r=d.data; done+=r.done||0; skip+=r.skip||0; fail+=r.fail||0;
            st.innerHTML="יובאו <strong>"+done+"</strong>"+(skip?" | דולגו "+skip:"")+(fail?" | ⚠ "+fail+" שגיאות":"");
            if(r.finished){btn.textContent="✓ סיום";btn.style.background="#aaa";btn.disabled=true;return;}
            step(r.next_offset);
        }).catch(function(e){st.textContent="שגיאת רשת: "+e.message;btn.disabled=false;});
    }
    step(0);
}
</script>';
    echo '</div>';
}

/* ── admin page ───────────────────────────────────────────── */

function shpk_gforms_page() {
    $tab       = sanitize_key($_GET['tab'] ?? 'settings');
    $sheet_idx = isset($_GET['sheet']) ? absint($_GET['sheet']) : 0;
    $nonce     = wp_create_nonce('shushka_nonce');
    $base      = admin_url('admin.php?page=shushka-gforms');

    echo '<div class="wrap" dir="rtl"><h1>&#128203; ייבוא הזמנות מגוגל טפסים</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
    $active = $tab === 'settings' ? ' nav-tab-active' : '';
    echo '<a href="' . $base . '" class="nav-tab' . $active . '">&#9881; הגדרות</a>';
    $sheets = get_option('shpk_gforms_sheets', []);
    foreach ($sheets as $i => $s) {
        $active = ($tab === 'mapping' && $sheet_idx === $i) ? ' nav-tab-active' : '';
        echo '<a href="' . $base . '&tab=mapping&sheet=' . $i . '" class="nav-tab' . $active . '">מיפוי: ' . esc_html($s['name']) . '</a>';
    }
    echo '</nav>';

    if ($tab === 'settings') {
        shpk_gforms_settings_tab($nonce);
    } elseif ($tab === 'mapping') {
        shpk_gforms_mapping_tab($sheet_idx, $nonce);
    }
    echo '</div>';
}

function shpk_gforms_settings_tab($nonce) {
    $default = [
        ['name' => 'פירות קפואים וגלידות', 'url' => ''],
        ['name' => 'קניה חברתית',            'url' => ''],
    ];
    $sheets = get_option('shpk_gforms_sheets', $default);
    ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;max-width:720px">
    <h2 style="margin-top:0">גיליונות Google Sheets</h2>
    <p style="color:#555;margin-top:0">הגיליון חייב להיות <strong>פתוח לצפייה לכל מי שיש לו את הקישור</strong>.</p>
    <table id="shpk-gf-sheets-tbl" class="widefat" style="margin-bottom:16px">
    <thead><tr><th style="width:200px">שם לתצוגה</th><th>קישור לגיליון</th><th style="width:40px"></th></tr></thead>
    <tbody>
    <?php foreach ($sheets as $s): ?>
    <tr>
        <td><input type="text" class="shpk-gf-nm" value="<?php echo esc_attr($s['name']); ?>" style="width:100%"></td>
        <td><input type="url" class="shpk-gf-url" value="<?php echo esc_attr($s['url']); ?>" style="width:100%" placeholder="https://docs.google.com/spreadsheets/d/..."></td>
        <td><button onclick="this.closest('tr').remove()" class="button" style="color:#c00;padding:0 8px">&#x2715;</button></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
    <button onclick="shpkGfAddRow()" class="button" style="margin-bottom:20px">+ הוסף גיליון</button><br>
    <button onclick="shpkGfSaveSettings('<?php echo esc_js($nonce); ?>')" class="button button-primary button-large">&#128190; שמור הגדרות</button>
    <span id="shpk-gf-set-st" style="margin-right:12px;font-size:14px"></span>
    </div>
    <script>
    function shpkGfAddRow(){
        var tb=document.querySelector('#shpk-gf-sheets-tbl tbody');
        var tr=document.createElement('tr');
        tr.innerHTML='<td><input type="text" class="shpk-gf-nm" style="width:100%" placeholder="שם הגיליון"></td>'+
            '<td><input type="url" class="shpk-gf-url" style="width:100%" placeholder="https://docs.google.com/spreadsheets/d/..."></td>'+
            '<td><button onclick="this.closest(\'tr\').remove()" class="button" style="color:#c00;padding:0 8px">&#x2715;</button></td>';
        tb.appendChild(tr);
    }
    function shpkGfSaveSettings(nonce){
        var sheets=[];
        document.querySelectorAll('#shpk-gf-sheets-tbl tbody tr').forEach(function(tr){
            sheets.push({name:tr.querySelector('.shpk-gf-nm').value,url:tr.querySelector('.shpk-gf-url').value});
        });
        var st=document.getElementById('shpk-gf-set-st'); st.textContent='שומר...';
        fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'shpk_gforms_save_settings',nonce:nonce,sheets:JSON.stringify(sheets)})})
        .then(function(r){return r.json();}).then(function(d){
            st.textContent=d.success?'✓ נשמר':'✗ שגיאה: '+(d.data||'');
            if(d.success) setTimeout(function(){location.reload();},700);
        });
    }
    </script>
    <?php
}

function shpk_gforms_mapping_tab($sheet_idx, $nonce) {
    $sheets = get_option('shpk_gforms_sheets', []);
    $sheet  = $sheets[$sheet_idx] ?? null;
    if (!$sheet) { echo '<div class="notice notice-error"><p>גיליון לא נמצא.</p></div>'; return; }

    $mapping    = shpk_gf_get_mapping($sheet_idx);
    $checkpoint = (int)get_option('shpk_gf_checkpoint_' . $sheet_idx, 0);

    $cnt_mapped = $cnt_skip = $cnt_unmapped = 0;
    foreach ($mapping as $e) {
        $pid = is_array($e) ? (int)($e['p'] ?? 0) : (int)$e;
        if ($pid > 0) $cnt_mapped++;
        elseif ($pid === -1) $cnt_skip++;
        else $cnt_unmapped++;
    }

    $esi = (int)$sheet_idx;
    $en  = esc_js($nonce);

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap">';
    echo '<h2 style="margin:0">מיפוי: ' . esc_html($sheet['name']) . '</h2>';
    echo '<button onclick="shpkGfDetect(' . $esi . ',\'' . $en . '\')" class="button button-primary" id="shpk-gf-det-btn">&#128269; זיהוי עמודות מהגיליון</button>';
    echo '<span id="shpk-gf-det-st" style="font-size:13px;color:#555"></span>';
    echo '</div>';

    echo '<div style="display:flex;gap:20px;margin-bottom:12px;font-size:13px;flex-wrap:wrap">';
    echo '<span style="color:#4caf50">&#10003; ממופים: <strong>' . $cnt_mapped . '</strong></span>';
    echo '<span style="color:#ff9800">&#9888; ממתינים: <strong>' . $cnt_unmapped . '</strong></span>';
    echo '<span style="color:#aaa">&#8212; דלג: <strong>' . $cnt_skip . '</strong></span>';
    echo '</div>';

    // Checkpoint control
    $cp_date  = $checkpoint ? date('Y-m-d', $checkpoint) : '';
    $cp_label = $checkpoint ? date_i18n('d/m/Y H:i', $checkpoint) : 'ללא הגבלה (יובאו כל השורות)';
    echo '<div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px">';
    echo '<strong>&#128197; נקודת התחלה לייבוא</strong>';
    echo '<div style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
    echo '<span style="color:#555">ייבא הזמנות מתאריך:</span>';
    echo '<input type="date" id="shpk-gf-cpinp-' . $esi . '" value="' . esc_attr($cp_date) . '" style="font-size:13px;padding:3px 6px">';
    echo '<button onclick="shpkGfSetCp(' . $esi . ',\'' . $en . '\')" class="button button-small">&#128190; שמור תאריך</button>';
    echo '<button onclick="shpkGfSetCpNow(' . $esi . ',\'' . $en . '\')" class="button button-small" style="background:#ff9800;border-color:#e65100;color:#fff" title="מסמן את כל השורות הקיימות כ\'כבר מיובאות\' — רק הזמנות חדשות שיתווספו אחר כך ייובאו">';
    echo '&#9654; סמן הכל כמיובא (התחל מעכשיו)</button>';
    echo '</div>';
    echo '<div style="margin-top:6px;color:#888" id="shpk-gf-cplabel-' . $esi . '">נוכחי: ' . esc_html($cp_label) . '</div>';
    echo '</div>';

    if ($mapping) {
        echo '<div style="margin-bottom:10px;font-size:13px">הצג: ';
        $filters = ['all' => 'הכל (' . count($mapping) . ')', 'unmapped' => 'ממתינים (' . $cnt_unmapped . ')', 'mapped' => 'ממופים (' . $cnt_mapped . ')', 'skip' => 'דלג (' . $cnt_skip . ')'];
        foreach ($filters as $v => $lbl) {
            $chk = $v === 'all' ? ' checked' : '';
            echo '<label style="margin-left:14px"><input type="radio" name="shpk-gf-flt" value="' . $v . '"' . $chk . ' onchange="shpkGfFilter(this.value)"> ' . $lbl . '</label>';
        }
        echo '</div>';

        // Re-derive column names from cached sheet data (Hebrew stays out of the DB)
        $key_to_name = [];
        $sdata = shpk_gforms_get_sheet_data($sheet_idx);
        if ($sdata) {
            foreach ($sdata['headers'] as $_h) {
                if (shpk_gforms_classify_col($_h) === 'product')
                    $key_to_name[shpk_gforms_col_key($_h)] = shpk_gforms_extract_product_name($_h);
            }
        }

        echo '<table class="wp-list-table widefat fixed striped" id="shpk-gf-map-tbl" style="table-layout:fixed">';
        echo '<colgroup><col style="width:38%"><col style="width:35%"><col style="width:27%"></colgroup>';
        echo '<thead><tr><th>שם מוצר מהטופס</th><th>מוצר ב-WooCommerce</th><th>פעולות</th></tr></thead><tbody>';

        foreach ($mapping as $key => $e) {
            $pid1     = is_array($e) ? (int)($e['p']  ?? 0) : (int)$e;
            $pid2     = is_array($e) ? (int)($e['p2'] ?? 0) : 0;
            $sz2      = is_array($e) ? max(2, (int)($e['s2'] ?? 2)) : 2;
            $col_name = esc_html($key_to_name[$key] ?? '(עמודה לא נמצאה)');
            $ek       = esc_attr($key);

            if ($pid1 > 0) {
                $wc_nm1     = esc_html(get_the_title($pid1));
                $match_html = '<span style="color:#4caf50">&#10003; ' . $wc_nm1 . '</span>';
                if ($pid2 > 0) {
                    $wc_nm2      = esc_html(get_the_title($pid2));
                    $match_html .= '<br><span style="color:#1976d2;font-size:12px">&#128230; ' . $sz2 . ' ק"ג: ' . $wc_nm2
                        . ' <a href="#" onclick="shpkGfClearP2(\'' . $ek . '\',' . $esi . ',\'' . $en . '\');return false" style="color:#c00;margin-right:4px;font-size:11px">&#10005;</a></span>';
                } else {
                    $match_html .= '<br><a href="#" onclick="shpkGfShowP2Src(\'' . $ek . '\');return false" style="color:#aaa;font-size:12px">+ הוסף חבילה גדולה</a>';
                }
                $status_cls = 'mapped';
            } elseif ($pid1 === -1) {
                $match_html = '<span style="color:#aaa">&#8212; דלג</span>';
                $status_cls = 'skip';
            } else {
                $match_html = '<span style="color:#ff9800">&#9888; לא ממופה</span>';
                $status_cls = 'unmapped';
            }

            echo '<tr data-key="' . $ek . '" data-status="' . $status_cls . '">';
            echo '<td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $col_name . '</td>';
            echo '<td id="shpk-gf-m-' . $ek . '" style="overflow:hidden;vertical-align:top;padding-top:8px">' . $match_html . '</td>';
            echo '<td style="vertical-align:top;padding-top:8px">';
            echo '<div id="shpk-gf-a-' . $ek . '">';
            if ($pid1 !== -1) {
                echo '<button onclick="shpkGfShowSrc(\'' . $ek . '\',' . $esi . ',\'' . $en . '\')" class="button button-small">' . ($pid1 > 0 ? 'שנה' : 'חפש') . '</button> ';
                echo '<button onclick="shpkGfSaveMap(\'' . $ek . '\',-1,' . $esi . ',\'' . $en . '\')" class="button button-small" style="color:#888">דלג</button>';
            } else {
                echo '<button onclick="shpkGfSaveMap(\'' . $ek . '\',0,' . $esi . ',\'' . $en . '\')" class="button button-small">בטל</button>';
            }
            echo '</div>';
            // Primary product search box
            echo '<div id="shpk-gf-s-' . $ek . '" style="display:none;margin-top:4px">';
            echo '<input type="text" placeholder="חיפוש מוצר..." onkeyup="shpkGfSearch(\'' . $ek . '\',this.value,' . $esi . ',\'' . $en . '\')" style="width:100%;font-size:13px" autocomplete="off">';
            echo '<div id="shpk-gf-r-' . $ek . '" style="background:#fff;border:1px solid #ddd;border-radius:4px;max-height:150px;overflow-y:auto;margin-top:3px"></div>';
            echo '<button onclick="document.getElementById(\'shpk-gf-s-' . $ek . '\').style.display=\'none\'" class="button button-small" style="margin-top:3px;font-size:11px">סגור</button>';
            echo '</div>';
            // Large-package search box
            echo '<div id="shpk-gf-p2s-' . $ek . '" style="display:none;margin-top:6px;border-top:1px solid #eee;padding-top:6px">';
            echo '<div style="font-size:12px;color:#555;margin-bottom:4px">&#128230; חבילה גדולה — גודל: <input type="number" id="shpk-gf-sz-' . $ek . '" value="' . $sz2 . '" min="2" max="20" style="width:45px;font-size:12px" title="כמה ק&quot;ג מייצגת האריזה הגדולה"> ק"ג</div>';
            echo '<input type="text" placeholder="חיפוש מוצר לחבילה גדולה..." onkeyup="shpkGfSearchP2(\'' . $ek . '\',this.value,' . $esi . ',\'' . $en . '\')" style="width:100%;font-size:13px" autocomplete="off">';
            echo '<div id="shpk-gf-p2r-' . $ek . '" style="background:#fff;border:1px solid #ddd;border-radius:4px;max-height:150px;overflow-y:auto;margin-top:3px"></div>';
            echo '<button onclick="document.getElementById(\'shpk-gf-p2s-' . $ek . '\').style.display=\'none\'" class="button button-small" style="margin-top:3px;font-size:11px">סגור</button>';
            echo '</div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#888">לחץ על <strong>זיהוי עמודות מהגיליון</strong> כדי לטעון את רשימת המוצרים ולהתחיל מיפוי.</p>';
    }

    echo '</div>';
    ?>
    <script>
    var shpkGfT={};
    function shpkGfFilter(v){
        document.querySelectorAll('#shpk-gf-map-tbl tbody tr').forEach(function(tr){
            tr.style.display=(v==='all'||tr.dataset.status===v)?'':'none';
        });
    }
    function shpkGfShowSrc(key,si,n){
        var el=document.getElementById('shpk-gf-s-'+key);
        el.style.display=''; el.querySelector('input').value=''; el.querySelector('input').focus();
        document.getElementById('shpk-gf-r-'+key).innerHTML='';
    }
    function shpkGfSearch(key,q,si,n){
        clearTimeout(shpkGfT[key]);
        var rd=document.getElementById('shpk-gf-r-'+key);
        if(q.length<2){rd.innerHTML='';return;}
        shpkGfT[key]=setTimeout(function(){
            fetch(ajaxurl+'?action=shpk_gforms_search_products&nonce='+n+'&q='+encodeURIComponent(q))
            .then(function(r){return r.json();}).then(function(d){
                if(!d.success)return;
                rd.innerHTML=d.data.length?d.data.map(function(p){
                    var pn=p.name.replace(/'/g,"\\'");
                    return '<div onclick="shpkGfSel(\''+key+'\','+p.id+',\''+pn+'\','+si+',\''+n+'\')" '+
                           'style="padding:5px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px" '+
                           'onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'\'">'+
                           '<strong>'+p.name+'</strong> <span style="color:#aaa;font-size:11px">'+p.sku+'</span></div>';
                }).join(''):'<div style="padding:8px;color:#888;font-size:13px">לא נמצאו תוצאות</div>';
            });
        },280);
    }
    function shpkGfSel(key,pid,pname,si,n){
        document.getElementById('shpk-gf-s-'+key).style.display='none';
        shpkGfSaveMap(key,pid,si,n,pname);
    }
    function shpkGfSaveMap(key,pid,si,n,pname){
        fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'shpk_gforms_save_mapping',nonce:n,sheet_idx:si,col_key:key,wc_product_id:pid})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success)return;
            var mc=document.getElementById('shpk-gf-m-'+key);
            var ac=document.getElementById('shpk-gf-a-'+key);
            var tr=document.querySelector('tr[data-key="'+key+'"]');
            if(pid>0){
                var nm=pname||(d.data&&d.data.name)||pid;
                mc.innerHTML='<span style="color:#4caf50">✓ '+nm+'</span>'+
                    '<br><a href="#" onclick="shpkGfShowP2Src(\''+key+'\');return false" style="color:#aaa;font-size:12px">+ הוסף חבילה גדולה</a>';
                ac.innerHTML='<button onclick="shpkGfShowSrc(\''+key+'\','+si+',\''+n+'\')" class="button button-small">שנה</button> '+
                    '<button onclick="shpkGfSaveMap(\''+key+'\',-1,'+si+',\''+n+'\')" class="button button-small" style="color:#888">דלג</button>';
                tr.dataset.status='mapped';
            } else if(pid==-1){
                mc.innerHTML='<span style="color:#aaa">— דלג</span>';
                ac.innerHTML='<button onclick="shpkGfSaveMap(\''+key+'\',0,'+si+',\''+n+'\')" class="button button-small">בטל</button>';
                tr.dataset.status='skip';
            } else {
                mc.innerHTML='<span style="color:#ff9800">⚠ לא ממופה</span>';
                ac.innerHTML='<button onclick="shpkGfShowSrc(\''+key+'\','+si+',\''+n+'\')" class="button button-small">חפש</button> '+
                    '<button onclick="shpkGfSaveMap(\''+key+'\',-1,'+si+',\''+n+'\')" class="button button-small" style="color:#888">דלג</button>';
                tr.dataset.status='unmapped';
            }
        });
    }
    function shpkGfShowP2Src(key){
        document.getElementById('shpk-gf-p2s-'+key).style.display='';
        document.getElementById('shpk-gf-p2s-'+key).querySelector('input[type=text]').focus();
    }
    function shpkGfSearchP2(key,q,si,n){
        clearTimeout(shpkGfT[key+'_p2']);
        var rd=document.getElementById('shpk-gf-p2r-'+key);
        if(q.length<2){rd.innerHTML='';return;}
        shpkGfT[key+'_p2']=setTimeout(function(){
            fetch(ajaxurl+'?action=shpk_gforms_search_products&nonce='+n+'&q='+encodeURIComponent(q))
            .then(function(r){return r.json();}).then(function(d){
                if(!d.success)return;
                rd.innerHTML=d.data.length?d.data.map(function(p){
                    var pn=p.name.replace(/'/g,"\\'");
                    return '<div onclick="shpkGfSelP2(\''+key+'\','+p.id+',\''+pn+'\','+si+',\''+n+'\')" '+
                           'style="padding:5px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px" '+
                           'onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'\'">'+
                           '<strong>'+p.name+'</strong> <span style="color:#aaa;font-size:11px">'+p.sku+'</span></div>';
                }).join(''):'<div style="padding:8px;color:#888;font-size:13px">לא נמצאו תוצאות</div>';
            });
        },280);
    }
    function shpkGfSelP2(key,pid2,pname,si,n){
        document.getElementById('shpk-gf-p2s-'+key).style.display='none';
        var sz2=parseInt(document.getElementById('shpk-gf-sz-'+key).value)||2;
        shpkGfSaveP2(key,pid2,sz2,si,n,pname);
    }
    function shpkGfClearP2(key,si,n){
        shpkGfSaveP2(key,0,0,si,n,'');
    }
    function shpkGfSaveP2(key,pid2,sz2,si,n,pname){
        fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'shpk_gforms_save_mapping_p2',nonce:n,sheet_idx:si,col_key:key,pid2:pid2,sz2:sz2})})
        .then(function(r){return r.json();}).then(function(d){
            if(!d.success)return;
            var mc=document.getElementById('shpk-gf-m-'+key);
            var cur=mc.querySelector('span');
            var nm1=cur?cur.textContent:'';
            if(pid2>0){
                var nm2=pname||(d.data&&d.data.name2)||pid2;
                var s2=d.data&&d.data.sz2||sz2;
                mc.innerHTML='<span style="color:#4caf50">'+nm1+'</span>'+
                    '<br><span style="color:#1976d2;font-size:12px">📦 '+s2+' ק"ג: '+nm2+
                    ' <a href="#" onclick="shpkGfClearP2(\''+key+'\','+si+',\''+n+'\');return false" style="color:#c00;margin-right:4px;font-size:11px">✕</a></span>';
            } else {
                mc.innerHTML='<span style="color:#4caf50">'+nm1+'</span>'+
                    '<br><a href="#" onclick="shpkGfShowP2Src(\''+key+'\');return false" style="color:#aaa;font-size:12px">+ הוסף חבילה גדולה</a>';
            }
        });
    }
    function shpkGfDetect(si,n){
        var btn=document.getElementById('shpk-gf-det-btn');
        var st=document.getElementById('shpk-gf-det-st');
        btn.disabled=true; st.innerHTML='&#9203; טוען גיליון...';
        fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'shpk_gforms_detect',nonce:n,sheet_idx:si})})
        .then(function(r){return r.json();}).then(function(d){
            btn.disabled=false;
            if(!d.success){st.innerHTML='<span style="color:#c00">&#x2717; '+(d.data||'שגיאה')+'</span>';return;}
            st.innerHTML='<span style="color:#4caf50">&#x2714; נמצאו '+d.data.new_cols+' עמודות חדשות (סה"כ '+d.data.total+')</span>'
                +'&nbsp;&nbsp;<button onclick="location.reload()" class="button button-primary button-small">&#8594; הצג עמודות למיפוי</button>';
            setTimeout(function(){location.reload();},1800);
        }).catch(function(){btn.disabled=false;st.innerHTML='<span style="color:#c00">שגיאת רשת</span>';});
    }
    function shpkGfSetCp(si,n){
        var val=document.getElementById('shpk-gf-cpinp-'+si).value;
        if(!val){alert('בחר תאריך');return;}
        var ts=Math.floor(new Date(val+'T00:00:00').getTime()/1000);
        shpkGfSaveCp(si,n,ts);
    }
    function shpkGfSetCpNow(si,n){
        if(!confirm('פעולה זו תסמן את כל ההזמנות הקיימות כ"מיובאות". רק הזמנות חדשות שיתווספו אחרי כן ייובאו. להמשיך?')) return;
        shpkGfSaveCp(si,n,Math.floor(Date.now()/1000));
    }
    function shpkGfSaveCp(si,n,ts){
        fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'shpk_gforms_set_checkpoint',nonce:n,sheet_idx:si,ts:ts})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.success){
                document.getElementById('shpk-gf-cplabel-'+si).textContent='נוכחי: '+d.data.date;
                document.getElementById('shpk-gf-cpinp-'+si).value=d.data.ymd;
            }
        });
    }
    </script>
    <?php
}

/* ── AJAX: set checkpoint (start date) ───────────────────── */
add_action('wp_ajax_shpk_gforms_set_checkpoint', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $sheet_idx = absint($_POST['sheet_idx'] ?? 0);
    $ts        = max(0, (int)($_POST['ts'] ?? 0));
    update_option('shpk_gf_checkpoint_' . $sheet_idx, $ts);
    delete_transient('shpk_gf_status_' . $sheet_idx);
    delete_transient('shpk_gf_data_'   . $sheet_idx);
    $label = $ts ? date_i18n('d/m/Y H:i', $ts) : 'ללא הגבלה';
    $ymd   = $ts ? date('Y-m-d', $ts) : '';
    if (ob_get_length()) ob_clean();
    wp_send_json_success(['date' => $label, 'ymd' => $ymd]);
});

/* ── AJAX: save settings ──────────────────────────────────── */
add_action('wp_ajax_shpk_gforms_save_settings', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $raw = json_decode(wp_unslash($_POST['sheets'] ?? '[]'), true);
    if (!is_array($raw)) { wp_send_json_error('bad data'); return; }
    $sheets = [];
    foreach ($raw as $s) {
        $name = sanitize_text_field($s['name'] ?? '');
        $url  = esc_url_raw($s['url']  ?? '');
        if ($name && $url) $sheets[] = ['name' => $name, 'url' => $url];
    }
    update_option('shpk_gforms_sheets', $sheets);
    if (ob_get_length()) ob_clean();
    wp_send_json_success(['count' => count($sheets)]);
});

/* ── AJAX: detect + auto-map columns ─────────────────────── */
add_action('wp_ajax_shpk_gforms_detect', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    @set_time_limit(120);

    $sheet_idx = absint($_POST['sheet_idx'] ?? 0);
    $sheets    = get_option('shpk_gforms_sheets', []);
    if (empty($sheets[$sheet_idx]['url'])) { wp_send_json_error('גיליון לא מוגדר'); return; }

    delete_transient('shpk_gf_data_' . $sheet_idx);
    $data = shpk_gforms_get_sheet_data($sheet_idx);
    if (!$data) { wp_send_json_error('לא ניתן לגשת לגיליון — בדוק שהוא ציבורי'); return; }

    // One DB query builds the full WC name→id map (avoids N searches per column)
    global $wpdb;
    $db_rows = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
    );
    $wc_name_map = [];
    foreach ($db_rows as $r) {
        $wc_name_map[mb_strtolower(trim($r->post_title))] = (int)$r->ID;
    }

    $mapping  = shpk_gf_get_mapping($sheet_idx);
    $new_cols = 0;

    foreach ($data['headers'] as $header) {
        if (shpk_gforms_classify_col($header) !== 'product') continue;
        $key = shpk_gforms_col_key($header);
        if (isset($mapping[$key])) continue;

        $product_name = shpk_gforms_extract_product_name($header);
        $suggested_id = 0;

        if ($product_name) {
            $norm = mb_strtolower($product_name);
            if (isset($wc_name_map[$norm])) {
                $suggested_id = $wc_name_map[$norm];
            } else {
                foreach ($wc_name_map as $wc_norm => $wc_id) {
                    if (mb_strpos($wc_norm, $norm) !== false || mb_strpos($norm, $wc_norm) !== false) {
                        $suggested_id = $wc_id;
                        break;
                    }
                }
            }
        }

        $mapping[$key] = ['p' => (int)$suggested_id];
        $new_cols++;
    }

    shpk_gf_save_mapping($sheet_idx, $mapping);
    if (ob_get_length()) ob_clean();
    wp_send_json_success(['new_cols' => $new_cols, 'total' => count($mapping)]);
});

/* ── AJAX: product search ─────────────────────────────────── */
add_action('wp_ajax_shpk_gforms_search_products', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $q = sanitize_text_field($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { wp_send_json_success([]); return; }
    global $wpdb;
    $like = '%' . $wpdb->esc_like($q) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title,
                (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) AS sku
         FROM {$wpdb->posts} p
         WHERE p.post_type='product' AND p.post_status='publish' AND p.post_title LIKE %s
         ORDER BY p.post_title LIMIT 10",
        $like
    ));
    $results = [];
    foreach ($rows as $r) {
        $results[] = ['id' => (int)$r->ID, 'name' => $r->post_title, 'sku' => (string)$r->sku];
    }
    if (ob_get_length()) ob_clean();
    wp_send_json_success($results);
});

/* ── AJAX: save single mapping entry ─────────────────────── */
add_action('wp_ajax_shpk_gforms_save_mapping', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $sheet_idx = absint($_POST['sheet_idx'] ?? 0);
    $col_key   = sanitize_key($_POST['col_key'] ?? '');
    $pid       = (int)($_POST['wc_product_id'] ?? 0);
    if (!$col_key) { wp_send_json_error('missing key'); return; }
    $mapping = shpk_gf_get_mapping($sheet_idx);
    if (!array_key_exists($col_key, $mapping)) { wp_send_json_error('key not found'); return; }
    $cur = is_array($mapping[$col_key]) ? $mapping[$col_key] : [];
    $cur['p'] = $pid;
    $mapping[$col_key] = $cur;
    shpk_gf_save_mapping($sheet_idx, $mapping);
    $name = $pid > 0 ? get_the_title($pid) : '';
    if (ob_get_length()) ob_clean();
    wp_send_json_success(['name' => $name]);
});

/* ── AJAX: save large-package (p2) for a column ─────────── */
add_action('wp_ajax_shpk_gforms_save_mapping_p2', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    $sheet_idx = absint($_POST['sheet_idx'] ?? 0);
    $col_key   = sanitize_key($_POST['col_key'] ?? '');
    $pid2      = (int)($_POST['pid2'] ?? 0);
    $sz2       = max(2, (int)($_POST['sz2'] ?? 2));
    if (!$col_key) { wp_send_json_error('missing key'); return; }
    $mapping = shpk_gf_get_mapping($sheet_idx);
    if (!array_key_exists($col_key, $mapping)) { wp_send_json_error('key not found'); return; }
    $cur = is_array($mapping[$col_key]) ? $mapping[$col_key] : ['p' => (int)$mapping[$col_key]];
    if ($pid2 > 0) {
        $cur['p2'] = $pid2;
        $cur['s2'] = $sz2;
    } else {
        unset($cur['p2'], $cur['s2']);
    }
    $mapping[$col_key] = $cur;
    shpk_gf_save_mapping($sheet_idx, $mapping);
    $name2 = $pid2 > 0 ? get_the_title($pid2) : '';
    if (ob_get_length()) ob_clean();
    wp_send_json_success(['name2' => $name2, 'sz2' => $sz2]);
});

/* ── AJAX: import one order (one row per AJAX call) ──────── */
add_action('wp_ajax_shpk_gforms_import_one', function () {
    check_ajax_referer('shushka_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error('unauthorized'); return; }
    @set_time_limit(60);

    $sheet_idx  = absint($_POST['sheet_idx'] ?? 0);
    $offset     = absint($_POST['offset']    ?? 0);
    $sheets     = get_option('shpk_gforms_sheets', []);
    $sheet_name = $sheets[$sheet_idx]['name'] ?? ('גיליון ' . $sheet_idx);

    $data = shpk_gforms_get_sheet_data($sheet_idx);
    if (!$data) { wp_send_json_error('לא ניתן לגשת לגיליון'); return; }

    $headers  = $data['headers'];
    $all_rows = $data['rows'];
    $mapping  = shpk_gf_get_mapping($sheet_idx);

    $ts_col = null;
    foreach ($headers as $ci => $ch) {
        if (shpk_gforms_classify_col($ch) === 'timestamp') { $ts_col = $ci; break; }
    }

    $checkpoint = (int)get_option('shpk_gf_checkpoint_' . $sheet_idx, 0);
    $new_rows   = [];
    foreach ($all_rows as $row) {
        $ts_str = $ts_col !== null ? trim($row[$ts_col] ?? '') : '';
        $dt     = $ts_str ? shpk_gforms_parse_timestamp($ts_str) : null;
        $is_new = $dt ? ($dt->getTimestamp() > $checkpoint) : ($checkpoint === 0);
        if ($is_new) $new_rows[] = $row;
    }

    $total = count($new_rows);
    if ($offset >= $total) {
        if (ob_get_length()) ob_clean();
        wp_send_json_success(['done' => 0, 'skip' => 0, 'fail' => 0, 'finished' => true, 'next_offset' => $total, 'total' => $total]);
        return;
    }

    $row      = $new_rows[$offset];
    $next     = $offset + 1;
    $finished = ($next >= $total);

    $cust_name = $city = $phone = $email = '';
    $notes     = [];
    $name_seen = $city_seen = $phone_seen = false;

    foreach ($headers as $ci => $h) {
        $type = shpk_gforms_classify_col($h);
        $val  = trim($row[$ci] ?? '');
        if (!$val) continue;
        if ($type === 'name'  && !$name_seen)  { $cust_name = $val; $name_seen  = true; }
        if ($type === 'city'  && !$city_seen)  { $city      = $val; $city_seen  = true; }
        if ($type === 'phone' && !$phone_seen) { $phone     = $val; $phone_seen = true; }
        if ($type === 'email') $email = $val;
        if ($type === 'notes') $notes[] = trim(preg_replace('/\s*\[[^\]]+\]\s*$/u', '', trim($h))) . ': ' . $val;
    }

    if (!$cust_name) {
        if ($finished) shpk_gforms_update_checkpoint($sheet_idx, $new_rows, $ts_col);
        if (ob_get_length()) ob_clean();
        wp_send_json_success(['done' => 0, 'skip' => 1, 'fail' => 0, 'log' => 'שורה ללא שם', 'next_offset' => $next, 'total' => $total, 'finished' => $finished]);
        return;
    }

    $items = [];
    foreach ($headers as $ci => $h) {
        if (shpk_gforms_classify_col($h) !== 'product') continue;
        $val = trim($row[$ci] ?? '');
        if (!$val || $val === '0' || !(float)$val) continue;
        $col_key = shpk_gforms_col_key($h);
        $entry   = $mapping[$col_key] ?? null;
        if ($entry === null) continue;
        $pid1 = is_array($entry) ? (int)($entry['p']  ?? 0) : (int)$entry;
        $pid2 = is_array($entry) ? (int)($entry['p2'] ?? 0) : 0;
        $sz2  = is_array($entry) ? max(2, (int)($entry['s2'] ?? 2)) : 2;
        if ($pid1 <= 0) continue;
        $qty = (int)(float)$val;
        if ($qty <= 0) continue;
        if ($pid2 > 0) {
            // Split: as many large packages as possible, remainder in small
            $bags_large = intdiv($qty, $sz2);
            $bags_small = $qty % $sz2;
            if ($bags_large > 0) { $p = wc_get_product($pid2); if ($p) $items[] = ['product' => $p, 'qty' => $bags_large]; }
            if ($bags_small > 0) { $p = wc_get_product($pid1); if ($p) $items[] = ['product' => $p, 'qty' => $bags_small]; }
        } else {
            $product = wc_get_product($pid1);
            if ($product) $items[] = ['product' => $product, 'qty' => max(1, $qty)];
        }
    }

    if (!$items) {
        if ($finished) shpk_gforms_update_checkpoint($sheet_idx, $new_rows, $ts_col);
        if (ob_get_length()) ob_clean();
        wp_send_json_success(['done' => 0, 'skip' => 1, 'fail' => 0, 'log' => $cust_name . ' — ללא פריטים ממופים', 'next_offset' => $next, 'total' => $total, 'finished' => $finished]);
        return;
    }

    try {
        $order = wc_create_order();
        $np    = explode(' ', $cust_name, 2);
        $order->set_billing_first_name($np[0]);
        $order->set_billing_last_name($np[1] ?? '');
        $order->set_billing_city($city);
        $order->set_billing_phone($phone);
        $order->set_billing_country('IL');
        if ($email) $order->set_billing_email($email);
        foreach ($items as $item) $order->add_product($item['product'], $item['qty']);
        if ($notes) $order->add_order_note('הערות לקוח: ' . implode(' | ', $notes));
        $order->add_order_note('יובא מטופס: ' . $sheet_name);
        $order->calculate_totals();
        $order->update_status('processing');
        $order->save();
        if ($finished) shpk_gforms_update_checkpoint($sheet_idx, $new_rows, $ts_col);
        if (ob_get_length()) ob_clean();
        wp_send_json_success(['done' => 1, 'skip' => 0, 'fail' => 0, 'log' => $cust_name, 'order_id' => $order->get_id(), 'next_offset' => $next, 'total' => $total, 'finished' => $finished]);
    } catch (Exception $e) {
        if ($finished) shpk_gforms_update_checkpoint($sheet_idx, $new_rows, $ts_col);
        if (ob_get_length()) ob_clean();
        wp_send_json_success(['done' => 0, 'skip' => 0, 'fail' => 1, 'log' => $cust_name . ': ' . $e->getMessage(), 'next_offset' => $next, 'total' => $total, 'finished' => $finished]);
    }
});
