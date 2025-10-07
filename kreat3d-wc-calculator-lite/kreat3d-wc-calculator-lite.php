<?php
/**
 * Plugin Name: Kreat3D Calculator Lite + Types (Sizes) — License + UI polish
 * Description: Calculator with Variant (Type — Size). Adds per-product License block shown above the form (asset/author/license links). Larger inputs so placeholders are readable. Server-side pricing + cart/checkout meta.
 * Version: 1.5.0
 * Author: Rimsha Rasheed
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class Kreat3D_Calculator_Lite_Types_Sizes_Cart {
  const OPT = 'k3d_lite_settings';
  const META_ENABLE     = '_k3d_enable';
  const META_BASE_PRICE = '_k3d_base_price';
  const META_BASE_W     = '_k3d_base_weight_g';
  const META_BASE_T     = '_k3d_base_time_h';
  const META_TYPES_JSON = '_k3d_types_json';
  // License meta
  const META_L_ASSET_NAME = '_k3d_license_asset_name';
  const META_L_ASSET_URL  = '_k3d_license_asset_url';
  const META_L_AUTHOR_NAME= '_k3d_license_author_name';
  const META_L_AUTHOR_URL = '_k3d_license_author_url';
  const META_L_NAME       = '_k3d_license_name';
  const META_L_URL        = '_k3d_license_url';

  public function __construct(){
    add_action('admin_menu', [$this,'menu']);
    add_action('admin_init', [$this,'register']);
    add_action('admin_post_k3d_lite_csv', [$this,'handle_csv']);
    add_action('init', function(){
      add_shortcode('k3d_calculator', [$this,'shortcode']);
      if (function_exists('is_product')) {
        add_action('woocommerce_before_add_to_cart_button', [$this,'render_on_product'], 20);
      }
    });
    add_action('add_meta_boxes', [$this,'metabox']);
    add_action('save_post_product', [$this,'save_meta'], 10, 2);

    // Cart flow
    add_filter('woocommerce_add_cart_item_data', [$this,'capture_to_cart_item'], 10, 3);
    add_filter('woocommerce_get_item_data',      [$this,'display_cart_item_meta'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [$this,'add_meta_to_order_items'], 10, 4);
    add_action('woocommerce_before_calculate_totals', [$this,'set_custom_price_for_cart'], 10, 1);
  }

  /* ---------- Admin ---------- */
  public function menu(){
    add_menu_page('Kreat3D Lite', 'Kreat3D Lite', 'manage_options', 'k3d-lite', [$this,'settings_page'], 'dashicons-calculator', 56);
    add_submenu_page('k3d-lite', 'CSV Import', 'CSV Import', 'manage_options', 'k3d-lite-import', [$this,'csv_page']);
  }

  public function register(){
    register_setting('k3d_lite_group', self::OPT, [
      'type'=>'array',
      'sanitize_callback'=>[$this,'sanitize'],
      'default'=>[ 'mult_petg'=>1.15, 'currency_symbol'=>'' ]
    ]);
  }
  public function sanitize($o){
    $out=[];
    $out['mult_petg'] = isset($o['mult_petg']) ? floatval($o['mult_petg']) : 1.15;
    $out['currency_symbol'] = isset($o['currency_symbol']) ? sanitize_text_field($o['currency_symbol']) : '';
    return $out;
  }

  public function settings_page(){
    if (!current_user_can('manage_options')) return;
    $o = get_option(self::OPT, ['mult_petg'=>1.15, 'currency_symbol'=>'']);
    $wc_sym = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
    ?>
    <div class="wrap">
      <h1>Kreat3D Calculator Lite — Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields('k3d_lite_group'); ?>
        <table class="form-table">
          <tr><th>PETG Multiplier</th><td><input name="<?php echo self::OPT; ?>[mult_petg]" type="number" step="0.01" value="<?php echo esc_attr($o['mult_petg']); ?>"> <small>(PLA=1.00)</small></td></tr>
          <tr><th>Currency Symbol</th><td>
            <input name="<?php echo self::OPT; ?>[currency_symbol]" type="text" style="width:120px" value="<?php echo esc_attr($o['currency_symbol']); ?>" placeholder="<?php echo esc_attr($wc_sym); ?>">
            <br><small>Leave blank to use WooCommerce symbol (currently "<?php echo esc_html($wc_sym); ?>").</small>
          </td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  public function csv_page(){
    if (!current_user_can('manage_options')) return; ?>
    <div class="wrap">
      <h1>CSV Import — Baselines & Types + Sizes</h1>
      <p>Columns: <code>product_id</code> OR <code>product_sku</code> OR <code>product_title</code>, <code>type_name</code> (optional), <code>base_price</code>, <code>base_weight_g</code>, <code>base_time_hours</code>, <code>base_time_minutes</code>, <code>size_options</code> (optional), <code>enable</code></p>
      <p><strong>size_options</strong> supports either multipliers <code>Small|0.75,Medium|1,Large|1.25</code> OR absolute baselines <code>Small|49.99|10|1|0</code> (Label|Price|Weight|Hours|Minutes)</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('k3d_lite_csv','k3d_lite_csv'); ?>
        <input type="hidden" name="action" value="k3d_lite_csv">
        <input type="file" name="csvfile" accept=".csv" required>
        <?php submit_button('Import CSV'); ?>
      </form>
    </div>
    <?php
  }

  public function handle_csv(){
    if (!current_user_can('manage_options')) wp_die('Not allowed');
    check_admin_referer('k3d_lite_csv','k3d_lite_csv');
    if (empty($_FILES['csvfile']['tmp_name'])) wp_die('No file uploaded');
    $fh = fopen($_FILES['csvfile']['tmp_name'], 'r');
    $header = fgetcsv($fh);
    if (!$header) wp_die('Empty CSV');
    $map = array_flip($header);
    $count = 0;
    while(($row = fgetcsv($fh)) !== false){
      $pid = 0;
      if (isset($map['product_id']) && !empty($row[$map['product_id']])) {
        $pid = intval($row[$map['product_id']]);
      } elseif (isset($map['product_sku']) && !empty($row[$map['product_sku']])) {
        $sku = sanitize_text_field($row[$map['product_sku']]);
        if (function_exists('wc_get_product_id_by_sku')) $pid = wc_get_product_id_by_sku($sku);
      } elseif (isset($map['product_title']) && !empty($row[$map['product_title']])) {
        $title = sanitize_text_field($row[$map['product_title']]);
        $post = get_page_by_title($title, OBJECT, 'product');
        if ($post) $pid = $post->ID;
      }
      if (!$pid) continue;

      $type_name = isset($map['type_name']) ? sanitize_text_field($row[$map['type_name']]) : '';
      $bp = isset($map['base_price']) ? floatval($row[$map['base_price']]) : null;
      $bw = isset($map['base_weight_g']) ? floatval($row[$map['base_weight_g']]) : null;
      $th = isset($map['base_time_hours']) ? floatval($row[$map['base_time_hours']]) : 0;
      $tm = isset($map['base_time_minutes']) ? floatval($row[$map['base_time_minutes']]) : 0;
      $bt = $th + ($tm/60.0);
      $sizes = isset($map['size_options']) ? sanitize_text_field($row[$map['size_options']]) : '';
      $en = isset($map['enable']) ? intval($row[$map['enable']]) : 1;

      if (!empty($type_name)){
        $types = json_decode((string)get_post_meta($pid, self::META_TYPES_JSON, true), true);
        if (!is_array($types)) $types = [];
        $found = false;
        foreach($types as &$t){
          if (isset($t['label']) && strtolower($t['label']) === strtolower($type_name)){
            if ($bp !== null) $t['price'] = $bp;
            if ($bw !== null) $t['w'] = $bw;
            $t['th'] = $th; $t['tm'] = $tm;
            if (!empty($sizes)) $t['sizes'] = $sizes;
            $found = true; break;
          }
        }
        if (!$found){
          $types[] = ['label'=>$type_name, 'price'=>($bp!==null?$bp:0), 'w'=>($bw!==null?$bw:0), 'th'=>$th, 'tm'=>$tm, 'sizes'=>$sizes];
        }
        update_post_meta($pid, self::META_TYPES_JSON, wp_json_encode($types));
      } else {
        if ($bp !== null) update_post_meta($pid, self::META_BASE_PRICE, $bp);
        if ($bw !== null) update_post_meta($pid, self::META_BASE_W, $bw);
        update_post_meta($pid, self::META_BASE_T, $bt);
      }

      update_post_meta($pid, self::META_ENABLE, $en ? '1' : '');
      $count++;
    }
    fclose($fh);
    wp_redirect(add_query_arg(['page'=>'k3d-lite-import','imported'=>$count], admin_url('admin.php')));
    exit;
  }

  /* ---------- Product Meta ---------- */
  public function metabox(){
    add_meta_box('k3d_lite_meta', 'Kreat3D Lite (Enable, Baselines, Types + Sizes, License)', [$this,'metabox_html'], 'product', 'normal', 'high');
  }
  public function metabox_html($post){
    wp_nonce_field('k3d_lite_meta','k3d_lite_meta');
    $enable = get_post_meta($post->ID, self::META_ENABLE, true)==='1';
    $bp = get_post_meta($post->ID, self::META_BASE_PRICE, true);
    $bw = get_post_meta($post->ID, self::META_BASE_W, true);
    $bt = get_post_meta($post->ID, self::META_BASE_T, true);
    $types_json = (string)get_post_meta($post->ID, self::META_TYPES_JSON, true);
    $types = json_decode(wp_unslash($types_json), true);
    if (!is_array($types)) $types = [];
    // License fields
    $l_asset = get_post_meta($post->ID, self::META_L_ASSET_NAME, true);
    $l_asset_url = get_post_meta($post->ID, self::META_L_ASSET_URL, true);
    $l_author = get_post_meta($post->ID, self::META_L_AUTHOR_NAME, true);
    $l_author_url = get_post_meta($post->ID, self::META_L_AUTHOR_URL, true);
    $l_name = get_post_meta($post->ID, self::META_L_NAME, true);
    $l_url = get_post_meta($post->ID, self::META_L_URL, true);
    ?>
      <p><label><input type="checkbox" name="k3d_enable" <?php checked($enable, true); ?>> Enable calculator on this product</label></p>

      <h4>Default PLA-100% Baselines (used if no Type is selected/defined)</h4>
      <table class="form-table">
        <tr><th style="width:200px">Base Price</th><td><input name="k3d_base_price" type="number" step="0.01" value="<?php echo esc_attr($bp); ?>"></td></tr>
        <tr><th>Base Weight (g)</th><td><input name="k3d_base_w" type="number" step="0.01" value="<?php echo esc_attr($bw); ?>"></td></tr>
        <tr><th>Base Time (hours)</th><td><input name="k3d_base_t" type="number" step="0.01" value="<?php echo esc_attr($bt); ?>"></td></tr>
      </table>

      <h4>Product Types (with Sizes)</h4>
      <p>Sizes: either <strong>Label|Multiplier</strong> or <strong>Label|Price|Weight|Hours|Minutes</strong>.</p>
      <input type="hidden" id="k3d_types_json" name="k3d_types_json" value="<?php echo esc_attr($types_json); ?>">
      <table class="widefat k3d-types">
        <thead><tr>
          <th style="width:16%">Type Label</th>
          <th style="width:14%">Base Price</th>
          <th style="width:14%">Base Weight (g)</th>
          <th style="width:14%">Time (h)</th>
          <th style="width:14%">Time (m)</th>
          <th style="width:28%">Sizes</th>
          <th style="width:4%"></th>
        </tr></thead>
        <tbody id="k3d_types_body"></tbody>
      </table>
      <p><button type="button" class="button" id="k3d_add_row">+ Add Type</button></p>

      <script>
      (function(){
        function rowTpl(t){
          t = t || {label:'', price:'', w:'', th:'', tm:'', sizes:''};
          return '<tr>'+
            '<td><input type="text" class="k3d_label" value="'+(t.label||'')+'"></td>'+
            '<td><input type="number" step="0.01" class="k3d_price" value="'+(t.price||'')+'"></td>'+
            '<td><input type="number" step="0.01" class="k3d_w" value="'+(t.w||'')+'"></td>'+
            '<td><input type="number" step="1" class="k3d_th" value="'+(t.th||'')+'"></td>'+
            '<td><input type="number" step="1" class="k3d_tm" value="'+(t.tm||'')+'"></td>'+
            '<td><input type="text" class="k3d_sizes" placeholder="Small|0.75,Medium|1,Large|1.25 OR Small|49.99|10|1|0" value="'+(t.sizes||'')+'"></td>'+
            '<td><button type="button" class="button k3d_del">×</button></td>'+
          '</tr>';
        }
        function read(){ try { return JSON.parse(document.getElementById('k3d_types_json').value||'[]'); } catch(e){ return []; } }
        function write(arr){ document.getElementById('k3d_types_json').value = JSON.stringify(arr); }
        function render(){
          var arr = read(), body = document.getElementById('k3d_types_body');
          body.innerHTML = arr.map(rowTpl).join('');
        }
        function collect(){
          var rows = Array.from(document.querySelectorAll('#k3d_types_body tr'));
          return rows.map(function(r){
            return {
              label: r.querySelector('.k3d_label').value.trim(),
              price: parseFloat(r.querySelector('.k3d_price').value||0),
              w: parseFloat(r.querySelector('.k3d_w').value||0),
              th: parseFloat(r.querySelector('.k3d_th').value||0),
              tm: parseFloat(r.querySelector('.k3d_tm').value||0),
              sizes: r.querySelector('.k3d_sizes').value.trim()
            };
          }).filter(function(t){ return t.label.length>0; });
        }
        function sync(){ write(collect()); }

        document.addEventListener('click', function(e){
          if (e.target && e.target.id==='k3d_add_row'){
            var arr = read(); arr.push({}); write(arr); render();
          }
          if (e.target && e.target.classList.contains('k3d_del')){
            var tr = e.target.closest('tr'); tr.parentNode.removeChild(tr); sync();
          }
        });
        document.addEventListener('input', function(e){
          if (e.target && e.target.closest('.k3d-types')) sync();
        });
        render();
      })();
      </script>

      <style> table.k3d-types input{width:100%} </style>

      <h4>License (optional)</h4>
      <table class="form-table">
        <tr><th style="width:200px">Asset Name (linked text)</th><td><input name="k3d_l_asset" type="text" value="<?php echo esc_attr($l_asset); ?>" placeholder="e.g., clips panel"></td></tr>
        <tr><th>Asset URL</th><td><input name="k3d_l_asset_url" type="url" value="<?php echo esc_attr($l_asset_url); ?>" placeholder="https://example.com/asset"></td></tr>
        <tr><th>Author Name</th><td><input name="k3d_l_author" type="text" value="<?php echo esc_attr($l_author); ?>" placeholder="e.g., Cubamix"></td></tr>
        <tr><th>Author URL</th><td><input name="k3d_l_author_url" type="url" value="<?php echo esc_attr($l_author_url); ?>" placeholder="https://example.com/author"></td></tr>
        <tr><th>License Name</th><td><input name="k3d_l_name" type="text" value="<?php echo esc_attr($l_name); ?>" placeholder="Creative Commons — Attribution"></td></tr>
        <tr><th>License URL</th><td><input name="k3d_l_url" type="url" value="<?php echo esc_attr($l_url); ?>" placeholder="https://creativecommons.org/licenses/by/4.0/"></td></tr>
      </table>
    <?php
  }

  public function save_meta($post_id, $post){
    if (!isset($_POST['k3d_lite_meta']) || !wp_verify_nonce($_POST['k3d_lite_meta'], 'k3d_lite_meta')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    update_post_meta($post_id, self::META_ENABLE, !empty($_POST['k3d_enable']) ? '1' : '');
    if (isset($_POST['k3d_base_price'])) update_post_meta($post_id, self::META_BASE_PRICE, floatval($_POST['k3d_base_price']));
    if (isset($_POST['k3d_base_w']))     update_post_meta($post_id, self::META_BASE_W, floatval($_POST['k3d_base_w']));
    if (isset($_POST['k3d_base_t']))     update_post_meta($post_id, self::META_BASE_T, floatval($_POST['k3d_base_t']));
    if (isset($_POST['k3d_types_json'])) update_post_meta($post_id, self::META_TYPES_JSON, wp_kses_post($_POST['k3d_types_json']));

    // License save
    $l_asset = isset($_POST['k3d_l_asset']) ? sanitize_text_field($_POST['k3d_l_asset']) : '';
    $l_asset_url = isset($_POST['k3d_l_asset_url']) ? esc_url_raw($_POST['k3d_l_asset_url']) : '';
    $l_author = isset($_POST['k3d_l_author']) ? sanitize_text_field($_POST['k3d_l_author']) : '';
    $l_author_url = isset($_POST['k3d_l_author_url']) ? esc_url_raw($_POST['k3d_l_author_url']) : '';
    $l_name = isset($_POST['k3d_l_name']) ? sanitize_text_field($_POST['k3d_l_name']) : '';
    $l_url = isset($_POST['k3d_l_url']) ? esc_url_raw($_POST['k3d_l_url']) : '';
    update_post_meta($post_id, self::META_L_ASSET_NAME, $l_asset);
    update_post_meta($post_id, self::META_L_ASSET_URL, $l_asset_url);
    update_post_meta($post_id, self::META_L_AUTHOR_NAME, $l_author);
    update_post_meta($post_id, self::META_L_AUTHOR_URL, $l_author_url);
    update_post_meta($post_id, self::META_L_NAME, $l_name);
    update_post_meta($post_id, self::META_L_URL, $l_url);
  }

  /* ---------- Front-end ---------- */
  public function render_on_product(){
    if (!function_exists('is_product') || !is_product()) return;
    global $product;
    $pid = $product ? $product->get_id() : get_the_ID();
    $enabled = get_post_meta($pid, self::META_ENABLE, true)==='1';
    if (!$enabled) return;

    echo $this->render_calc($pid);
  }
  public function shortcode($atts=[]){
    $pid = get_the_ID();
    return $this->render_calc($pid);
  }

  private function render_calc($product_id){
    $o = get_option(self::OPT, ['mult_petg'=>1.15, 'currency_symbol'=>'']);
    $base = [
      'price'=> floatval(get_post_meta($product_id, self::META_BASE_PRICE, true)),
      'w'    => floatval(get_post_meta($product_id, self::META_BASE_W, true)),
      't'    => floatval(get_post_meta($product_id, self::META_BASE_T, true))
    ];
    $types_json = (string)get_post_meta($product_id, self::META_TYPES_JSON, true);
    $types = json_decode(wp_unslash($types_json), true);
    if (!is_array($types)) $types = [];

    // License fetch
    $l_asset = get_post_meta($product_id, self::META_L_ASSET_NAME, true);
    $l_asset_url = get_post_meta($product_id, self::META_L_ASSET_URL, true);
    $l_author = get_post_meta($product_id, self::META_L_AUTHOR_NAME, true);
    $l_author_url = get_post_meta($product_id, self::META_L_AUTHOR_URL, true);
    $l_name = get_post_meta($product_id, self::META_L_NAME, true);
    $l_url = get_post_meta($product_id, self::META_L_URL, true);

    $cur_sym = !empty($o['currency_symbol']) ? $o['currency_symbol'] : (function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$');
    ob_start(); ?>
    <div class="k3d-lite" data-k3d-currency="<?php echo esc_attr($cur_sym); ?>">

      <?php if(!empty($l_asset) || !empty($l_author) || !empty($l_name)) : ?>
        <div class="k3d-license">
          <div class="k3d-license-title">License</div>
          <div class="k3d-license-line">
            <?php if(!empty($l_asset)){ ?>
              <a href="<?php echo esc_url($l_asset_url ?: '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($l_asset); ?></a>
            <?php } ?>
            <?php if(!empty($l_author)){ ?>
              by <a href="<?php echo esc_url($l_author_url ?: '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($l_author); ?></a>
            <?php } ?>
            <?php if(!empty($l_name)){ ?>
              is licensed under the <a href="<?php echo esc_url($l_url ?: '#'); ?>" target="_blank" rel="noopener"><?php echo esc_html($l_name); ?></a> license.
            <?php } ?>
          </div>
        </div>
      <?php endif; ?>

      <script>
      document.addEventListener('DOMContentLoaded', function(){
        var f = document.querySelector('form.cart');
        if (f) f.setAttribute('enctype','multipart/form-data');
      });
      </script>

      <h3>Custom 3D Print</h3>

      <div class="k3d-row">
        <label>Upload File <small>(3D or Image files)</small>
          <input type="file" id="k3d-file" name="k3d_file" accept=".stl,.obj,.3mf,.gcode,.gc,.step,.stp,.svg,.png,.jpg,.jpeg,.gif,.bmp,.webp,.pdf,image/*">
        </label>
      </div>

      <div class="k3d-grid">
        <div>
          <label>Material</label>
          <select id="k3d-material" name="k3d_material">
            <option value="PLA">PLA</option>
            <option value="PETG">PETG</option>
          </select>
        </div>

        <div id="k3d-variant-wrap" style="grid-column:1 / -1;">
          <label>Variant (Type — Size)</label>
          <select id="k3d-variant" name="k3d_variant" required>
            <option value="" selected disabled>Select variant</option>
          </select>
          <input type="hidden" name="k3d_type_label" id="k3d_type_label">
          <input type="hidden" name="k3d_size_label" id="k3d_size_label">
        </div>

        <div>
          <label>Weight (g)</label>
          <div class="k3d-inline">
            <input id="k3d-weight" name="k3d_weight" type="number" step="0.1" placeholder="e.g., 25.0">
          </div>
        </div>

        <div>
          <label>Print Time</label>
          <div class="k3d-inline k3d-time-inline">
            <input id="k3d-time-h" name="k3d_time_h" type="number" min="0" step="1" placeholder="hour(s)">
            <input id="k3d-time-m" name="k3d_time_m" type="number" min="0" max="59" step="1" placeholder="min(s)">
          </div>
        </div>

        <div>
          <label>Color</label>
          <input id="k3d-color" name="k3d_color" type="text" placeholder="e.g., Black / White">
        </div>
      </div>

      <label>Instructions for the maker</label>
      <textarea id="k3d-notes" name="k3d_notes" rows="4" style="width:100%;"></textarea>

      <div class="k3d-total"><strong>Estimated Price:</strong> <span id="k3d-price"><?php echo esc_html($cur_sym); ?> 0.00</span></div>

      <!-- hidden fields used for server-side recompute -->
      <input type="hidden" id="k3d_pb" name="k3d_pb" value="0">
      <input type="hidden" id="k3d_wb" name="k3d_wb" value="1">
      <input type="hidden" id="k3d_tb" name="k3d_tb" value="1">
      <input type="hidden" id="k3d_client_total" name="k3d_client_total" value="0">
    </div>

    <script>
    (function(){
      const currency = <?php echo json_encode($cur_sym); ?>;
      const types = <?php echo wp_json_encode($types); ?>;
      const base  = <?php echo json_encode($base); ?>;
      const el = s=>document.querySelector(s);
      const fmt = n => currency + ' ' + (Math.round(n*100)/100).toFixed(2);

      function parseSizesStr(str){
        if (!str) return [];
        return str.split(',').map(function(p){
          var parts = p.split('|').map(function(s){ return (s||'').trim(); });
          if (parts.length===2){
            var label = parts[0]; var mult = parseFloat(parts[1]);
            if (!label || !isFinite(mult)) return null;
            return {label, mode:'mult', mult};
          } else if (parts.length===5){
            var label=parts[0], price=parseFloat(parts[1]), w=parseFloat(parts[2]), th=parseFloat(parts[3]), tm=parseFloat(parts[4]);
            if (!label || !isFinite(price) || !isFinite(w) || !isFinite(th) || !isFinite(tm)) return null;
            return {label, mode:'abs', price, w, th, tm};
          } else return null;
        }).filter(Boolean);
      }

      function buildVariantList(){
        const varSel = el('#k3d-variant'); if (!varSel) return;
        let all = [];
        for (let ti=0; ti<types.length; ti++){
          const t = types[ti];
          const sizes = parseSizesStr(t.sizes||'');
          if (sizes.length){
            sizes.forEach(function(s, si){
              all.push({label: t.label+' — '+s.label, typeIndex: ti, sizeIndex: si, data: s, t});
            });
          } else {
            all.push({label: t.label, typeIndex: ti, sizeIndex: null, data: null, t});
          }
        }
        if (!all.length){
          const wrap = el('#k3d-variant-wrap');
          if (wrap) wrap.style.display='none';
          setBaselines(base.price||0, (base.w||1), (base.t||1));
          updatePlaceholders(null);
          return;
        }
        varSel.innerHTML = '<option value="" disabled selected>Select variant</option>' +
          all.map(function(v, idx){ return '<option value="'+idx+'">'+v.label+'</option>'; }).join('');
        varSel.dataset.k3dAll = JSON.stringify(all);
      }

      function setBaselines(pb, wb, tb){
        el('#k3d_pb').value = (pb||0);
        el('#k3d_wb').value = (wb||1);
        el('#k3d_tb').value = (tb||1);
      }

      function updatePlaceholders(v){
        const wInput = el('#k3d-weight'); const hInput = el('#k3d-time-h'); const mInput = el('#k3d-time-m');
        if (!v){
          wInput.placeholder = 'e.g., 25.0';
          hInput.placeholder = 'hour(s)';
          mInput.placeholder = 'min(s)';
          return;
        }
        let recW=0, recTh=0, recTm=0;
        if (v.data){
          if (v.data.mode==='mult'){
            recW = (v.t.w||0) * (v.data.mult||1);
            const tb = (((v.t.th||0) + (v.t.tm||0)/60) * (v.data.mult||1)) || 0;
            recTh = Math.floor(tb); recTm = Math.round((tb - recTh)*60);
          } else {
            recW = v.data.w||0; recTh=v.data.th||0; recTm=v.data.tm||0;
          }
        } else { recW=v.t.w||0; recTh=v.t.th||0; recTm=v.t.tm||0; }
        wInput.placeholder = String(recW||0) + ' g (recommended)';
        hInput.placeholder = String(recTh||0) + ' h (recommended)';
        mInput.placeholder = String(recTm||0) + ' m (recommended)';
      }

      function recalc(){
        const pb = parseFloat(el('#k3d_pb').value||0);
        const wb = parseFloat(el('#k3d_wb').value||1);
        const tb = parseFloat(el('#k3d_tb').value||1);
        const mat = el('#k3d-material').value;
        const w   = parseFloat(el('#k3d-weight').value||0);
        const th  = parseFloat(el('#k3d-time-h').value||0);
        const tm  = parseFloat(el('#k3d-time-m').value||0);
        const t   = th + (tm/60.0);

        const ratio = ((w/(wb||1)) + (t/(tb||1))) / 2;
        const matMult = (mat==='PETG') ? <?php echo json_encode(floatval($o['mult_petg'])); ?> : 1.0;
        const price = pb * ratio * matMult;

        el('#k3d-price').textContent = fmt(price);
        el('#k3d_client_total').value = price.toFixed(2);
      }

      function applyVariant(idxStr){
        const varSel = el('#k3d-variant');
        let all = []; try { all = JSON.parse(varSel.dataset.k3dAll||'[]'); } catch(e){ all=[]; }
        if (!all.length || !idxStr) return;
        const v = all[parseInt(idxStr,10)]; if (!v) return;

        el('#k3d_type_label').value = v.t.label || '';
        el('#k3d_size_label').value = (v.data && v.data.label) ? v.data.label : '';

        let pb=(v.t.price||0), wb=(v.t.w||1), tb=(((v.t.th||0) + (v.t.tm||0)/60)||1);
        if (v.data){
          if (v.data.mode==='mult'){ const m = v.data.mult||1; pb*=m; wb*=m; tb*=m; }
          else { pb=v.data.price||0; wb=v.data.w||1; tb=((v.data.th||0)+(v.data.tm||0)/60)||1; }
        }
        setBaselines(pb, wb, tb);
        updatePlaceholders(v);
        recalc();
      }

      document.addEventListener('change', function(e){
        if (e.target && e.target.id==='k3d-variant'){ applyVariant(e.target.value); }
        if (e.target && e.target.closest('.k3d-lite')) recalc();
      });
      document.addEventListener('input', function(e){
        if (e.target && e.target.closest('.k3d-lite')) recalc();
      });

      document.addEventListener('DOMContentLoaded', function(){
        buildVariantList();
        setBaselines(base.price||0, (base.w||1), (base.t||1));
        updatePlaceholders(null);
        recalc();
      });
    })();
    </script>

    <style>
      .k3d-lite{border:1px solid #e6e6e6;padding:18px;background:#fafafa;max-width:860px;margin:16px 0;border-radius:12px}
      .k3d-license{background:#fff;border:1px solid #e2e2e2;padding:10px 12px;border-radius:8px;margin-bottom:12px}
      .k3d-license-title{font-weight:700;margin-bottom:2px}
      .k3d-license-line a{color:#2271b1;text-decoration:underline}
      .k3d-lite h3{margin:0 0 12px}
      .k3d-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:10px 0}
      .k3d-inline{display:flex;align-items:center;gap:10px}
      .k3d-time-inline input{min-width:120px}
      .k3d-total{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding:12px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:18px;font-weight:700}
      .k3d-lite input,.k3d-lite select, .k3d-lite textarea{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:8px;font-size:15px}
      .k3d-lite label{display:block;font-weight:600;margin:6px 0 4px}
      @media (max-width:720px){ .k3d-grid{grid-template-columns:1fr} .k3d-time-inline input{min-width:unset} }
    </style>
    <?php
    return ob_get_clean();
  }

  /* ---------- CART INTEGRATION ---------- */
  private function find_type_and_size($product_id, $type_label, $size_label){
    $types_json = (string)get_post_meta($product_id, self::META_TYPES_JSON, true);
    $types = json_decode(wp_unslash($types_json), true);
    if (!is_array($types)) $types = [];
    $foundType = null; $foundSize = null;
    foreach($types as $t){
      if (isset($t['label']) && strcasecmp($t['label'], $type_label)===0){
        $foundType = $t;
        $sizes = isset($t['sizes']) ? $t['sizes'] : '';
        if ($size_label){
          $sarr = array_map('trim', explode(',', $sizes));
          foreach($sarr as $sline){
            $parts = array_map('trim', explode('|', $sline));
            if (!$parts || !count($parts)) continue;
            if (strcasecmp($parts[0], $size_label)===0){
              if (count($parts)===2){
                $foundSize = ['mode'=>'mult', 'mult'=>floatval($parts[1])];
              } elseif (count($parts)===5){
                $foundSize = ['mode'=>'abs', 'price'=>floatval($parts[1]), 'w'=>floatval($parts[2]), 'th'=>floatval($parts[3]), 'tm'=>floatval($parts[4])];
              }
              break;
            }
          }
        }
        break;
      }
    }
    return [$foundType, $foundSize];
  }

  private function compute_price($product_id, $type_label, $size_label, $material, $weight_g, $hours, $minutes){
    $o = get_option(self::OPT, ['mult_petg'=>1.15]);
    list($t, $s) = $this->find_type_and_size($product_id, $type_label, $size_label);
    if (!$t){
      $pb = floatval(get_post_meta($product_id, self::META_BASE_PRICE, true));
      $wb = floatval(get_post_meta($product_id, self::META_BASE_W, true)); if (!$wb) $wb = 1;
      $tb = floatval(get_post_meta($product_id, self::META_BASE_T, true)); if (!$tb) $tb = 1;
    } else {
      $pb = floatval($t['price'] ?? 0);
      $wb = floatval($t['w'] ?? 1); if (!$wb) $wb = 1;
      $tb = floatval(($t['th'] ?? 0) + ($t['tm'] ?? 0)/60.0); if (!$tb) $tb = 1;
      if ($s){
        if ($s['mode']==='mult'){
          $m = floatval($s['mult'] ?? 1); $pb*=$m; $wb*=$m; $tb*=$m;
        } else {
          $pb = floatval($s['price'] ?? 0);
          $wb = floatval($s['w'] ?? 1); if (!$wb) $wb = 1;
          $tb = floatval(($s['th'] ?? 0) + ($s['tm'] ?? 0)/60.0); if (!$tb) $tb = 1;
        }
      }
    }
    $w = floatval($weight_g);
    $tH = floatval($hours) + floatval($minutes)/60.0;
    $ratio = (( $w/($wb?:1) ) + ( $tH/($tb?:1) ))/2.0;
    $matMult = (strtoupper($material)==='PETG') ? floatval($o['mult_petg']) : 1.0;
    $total = $pb * $ratio * $matMult;
    return [$total, $pb, $wb, $tb];
  }

  public function capture_to_cart_item($cart_item_data, $product_id, $variation_id){
    if (!isset($_POST['k3d_material'])) return $cart_item_data; // not our product
    $material = sanitize_text_field($_POST['k3d_material']);
    $type_label = isset($_POST['k3d_type_label']) ? sanitize_text_field($_POST['k3d_type_label']) : '';
    $size_label = isset($_POST['k3d_size_label']) ? sanitize_text_field($_POST['k3d_size_label']) : '';
    $weight = isset($_POST['k3d_weight']) ? floatval($_POST['k3d_weight']) : 0;
    $time_h = isset($_POST['k3d_time_h']) ? intval($_POST['k3d_time_h']) : 0;
    $time_m = isset($_POST['k3d_time_m']) ? intval($_POST['k3d_time_m']) : 0;
    $color = isset($_POST['k3d_color']) ? sanitize_text_field($_POST['k3d_color']) : '';
    $notes = isset($_POST['k3d_notes']) ? sanitize_textarea_field($_POST['k3d_notes']) : '';

    // File upload
    $file_url = '';
    if (!empty($_FILES['k3d_file']) && !empty($_FILES['k3d_file']['name'])){
      require_once(ABSPATH.'wp-admin/includes/file.php');
      $overrides = ['test_form'=>false, 'mimes'=>[
        'stl'=>'application/sla', 'obj'=>'text/plain', '3mf'=>'application/octet-stream',
        'gcode'=>'text/plain', 'gc'=>'text/plain', 'step'=>'application/step', 'stp'=>'application/step',
        'svg'=>'image/svg+xml', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
        'gif'=>'image/gif', 'bmp'=>'image/bmp', 'webp'=>'image/webp', 'pdf'=>'application/pdf'
      ]];
      $upload = wp_handle_upload($_FILES['k3d_file'], $overrides);
      if (!isset($upload['error'])) $file_url = $upload['url'];
    }

    list($total, $pb, $wb, $tb) = $this->compute_price($product_id, $type_label, $size_label, $material, $weight, $time_h, $time_m);

    $cart_item_data['k3d'] = [
      'material'=>$material,
      'type_label'=>$type_label,
      'size_label'=>$size_label,
      'weight'=>$weight,
      'time_h'=>$time_h,
      'time_m'=>$time_m,
      'color'=>$color,
      'notes'=>$notes,
      'file_url'=>$file_url,
      'computed_total'=>round($total,2),
      'pb'=>$pb, 'wb'=>$wb, 'tb'=>$tb,
    ];

    $cart_item_data['unique_key'] = md5(maybe_serialize($cart_item_data['k3d']).microtime());
    return $cart_item_data;
  }

  public function set_custom_price_for_cart($cart){
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (did_action('woocommerce_before_calculate_totals') >= 2) return;
    foreach($cart->get_cart() as $cart_item){
      if (isset($cart_item['k3d']['computed_total'])){
        $unit = floatval($cart_item['k3d']['computed_total']);
        if ($unit > 0 && isset($cart_item['data']) && is_object($cart_item['data'])){
          $cart_item['data']->set_price($unit);
        }
      }
    }
  }

  public function display_cart_item_meta($item_data, $cart_item){
    if (!isset($cart_item['k3d'])) return $item_data;
    $k = $cart_item['k3d'];
    $add = [];
    if (!empty($k['type_label']) || !empty($k['size_label'])){
      $label = trim($k['type_label'].' — '.$k['size_label'], ' —');
      $add[] = ['name'=>'Variant', 'value'=>wc_clean($label)];
    }
    if (!empty($k['material'])) $add[] = ['name'=>'Material', 'value'=>wc_clean($k['material'])];
    $add[] = ['name'=>'Weight', 'value'=>wc_clean($k['weight'].' g')];
    $add[] = ['name'=>'Print Time', 'value'=>wc_clean($k['time_h'].'h '.$k['time_m'].'m')];
    if (!empty($k['color'])) $add[] = ['name'=>'Color', 'value'=>wc_clean($k['color'])];
    if (!empty($k['notes'])) $add[] = ['name'=>'Instructions', 'value'=>nl2br(wp_kses_post($k['notes']))];
    if (!empty($k['file_url'])) $add[] = ['name'=>'File', 'value'=>'<a href="'.esc_url($k['file_url']).'" target="_blank" rel="noopener">Download</a>'];
    $item_data = array_merge($item_data, $add);
    return $item_data;
  }

  public function add_meta_to_order_items($item, $cart_item_key, $values, $order){
    if (!isset($values['k3d'])) return;
    $k = $values['k3d'];
    $label = trim(($k['type_label']??'').' — '.($k['size_label']??''), ' —');
    if ($label) $item->add_meta_data('Variant', $label, true);
    if (!empty($k['material'])) $item->add_meta_data('Material', $k['material'], true);
    $item->add_meta_data('Weight (g)', $k['weight'], true);
    $item->add_meta_data('Print Time', ($k['time_h'].'h '.$k['time_m'].'m'), true);
    if (!empty($k['color'])) $item->add_meta_data('Color', $k['color'], true);
    if (!empty($k['notes'])) $item->add_meta_data('Instructions', $k['notes'], true);
    if (!empty($k['file_url'])) $item->add_meta_data('File', $k['file_url'], true);
    if (isset($k['computed_total'])) $item->add_meta_data('Computed Price', $k['computed_total'], true);
  }
}

new Kreat3D_Calculator_Lite_Types_Sizes_Cart();

