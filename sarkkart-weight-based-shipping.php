<?php
/**
 * Plugin Name: Sarkkart Weight Based Shipping
 * Description: Weight-based rates per WooCommerce zone, fully configured in wp-admin. Rules support weight/subtotal/qty, per-kg, %, handling fee, free threshold, shipping class, plus optional state & postcode filters.
 * Author: AJ
 * Version: 1.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.2
 */

if (!defined('ABSPATH')) { exit; }

add_action('plugins_loaded', function () {

  if (!class_exists('WC_Shipping_Method')) {
    return; // WooCommerce not active
  }

  class WC_Sarkkart_Weight_Based_Shipping extends WC_Shipping_Method {

    public function __construct($instance_id = 0) {
      $this->id                 = 'sarkkart_wbs';
      $this->instance_id        = absint($instance_id);
      $this->method_title       = __('Sarkkart Weight Based', 'sarkkart-wbs');
      $this->method_description = __('Configure weight-based shipping rules per zone entirely in wp-admin (no JSON editing).', 'sarkkart-wbs');
      $this->supports           = array('shipping-zones', 'instance-settings', 'instance-settings-modal');
      $this->enabled            = 'yes';
      $this->title              = __('Weight Based Shipping', 'sarkkart-wbs');

      $this->init_form_fields();
      $this->init_settings();

      // Load instance settings
      $this->title                   = $this->get_option('title', $this->title);
      $this->tax_status              = $this->get_option('tax_status', 'taxable');
      $this->free_shipping_threshold = floatval($this->get_option('free_shipping_threshold', '0'));
      $this->handling_fee            = floatval($this->get_option('handling_fee', '0'));
      $this->calc_type               = $this->get_option('calc_type', 'cheapest');
      $this->weight_rounding         = $this->get_option('weight_rounding', 'none');
      $this->rules_json              = $this->get_option('rules_json', '[]');

      add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
      add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function enqueue_admin_assets($hook) {
      if ($hook !== 'woocommerce_page_wc-settings') return;
      if (isset($_GET['tab']) && $_GET['tab'] === 'shipping') {

        // Prepare state list from store’s base country (defaults to IN for you)
        $base_country = function_exists('wc_get_base_location')
          ? (wc_get_base_location()['country'] ?? 'IN')
          : (get_option('woocommerce_default_country') ?: 'IN');

        if (strpos($base_country, ':') !== false) {
          $base_country = explode(':', $base_country)[0];
        }

        $countries = wc()->countries ?? new WC_Countries();
        $states    = $countries->get_states($base_country);
        if (!is_array($states)) $states = array();

        wp_enqueue_style('sarkkart-wbs-admin', plugins_url('assets/admin.css', __FILE__), array(), '1.2.0');
        wp_enqueue_script('sarkkart-wbs-admin', plugins_url('assets/admin.js', __FILE__), array('jquery'), '1.2.0', true);

        // Pass state list & country to JS for a native multi-select UI
        wp_localize_script('sarkkart-wbs-admin', 'SarkkartWBS', array(
          'country' => $base_country,
          'states'  => $states, // array('DL' => 'Delhi', ...)
        ));
      }
    }

    public function init_form_fields() {
      // Per-instance (per-zone) settings
      $this->instance_form_fields = array(
        'title' => array(
          'title'       => __('Method Title', 'sarkkart-wbs'),
          'type'        => 'text',
          'default'     => __('Weight Based Shipping', 'sarkkart-wbs'),
          'description' => __('Shown at checkout.', 'sarkkart-wbs'),
        ),
        'tax_status' => array(
          'title'       => __('Tax Status', 'sarkkart-wbs'),
          'type'        => 'select',
          'default'     => 'taxable',
          'options'     => array('taxable' => __('Taxable', 'sarkkart-wbs'), 'none' => __('None', 'sarkkart-wbs')),
        ),
        'free_shipping_threshold' => array(
          'title'       => __('Free Shipping Threshold', 'sarkkart-wbs'),
          'type'        => 'price',
          'default'     => '0',
          'description' => __('If cart subtotal (excl. tax) ≥ this amount, shipping is free & handling fee ignored.', 'sarkkart-wbs'),
        ),
        'handling_fee' => array(
          'title'       => __('Handling Fee (flat)', 'sarkkart-wbs'),
          'type'        => 'price',
          'default'     => '0',
          'description' => __('Added once when not free.', 'sarkkart-wbs'),
        ),
        'calc_type' => array(
          'title'       => __('When multiple rules match', 'sarkkart-wbs'),
          'type'        => 'select',
          'default'     => 'cheapest',
          'options'     => array(
            'cheapest' => __('Cheapest', 'sarkkart-wbs'),
            'highest'  => __('Highest', 'sarkkart-wbs'),
            'sum'      => __('Sum all', 'sarkkart-wbs'),
          ),
        ),
        'weight_rounding' => array(
          'title'       => __('Weight Rounding', 'sarkkart-wbs'),
          'type'        => 'select',
          'default'     => 'none',
          'options'     => array(
            'none'  => __('None (exact)', 'sarkkart-wbs'),
            'ceil'  => __('Ceil to next kg', 'sarkkart-wbs'),
            'floor' => __('Floor to whole kg', 'sarkkart-wbs'),
          ),
        ),
        'rules_help' => array(
          'title'       => __('Rules', 'sarkkart-wbs'),
          'type'        => 'title',
          'description' =>
            '<p><strong>Add rules with the table below.</strong> No JSON editing required.</p>' .
            '<ul style="margin-left:1.2em;list-style:disc">' .
            '<li>Weight bounds are in <strong>kg</strong> (store unit converts automatically).</li>' .
            '<li>Subtotal is <em>ex-tax</em>, before coupons.</li>' .
            '<li><strong>States</strong>: choose specific states (optional). Leave empty to ignore.</li>' .
            '<li><strong>Postcodes</strong>: comma-separated list or regex (e.g. <code>^11.*</code>).</li>' .
            '</ul>',
        ),
        'rules_json' => array(
          'title'       => __('Rules Data (auto-managed)', 'sarkkart-wbs'),
          'type'        => 'textarea',
          'default'     => '[]',
          'css'         => 'width:100%;height:0;opacity:0;pointer-events:none;', // hidden but preserved
          'description' => __('Stored automatically by the UI above.', 'sarkkart-wbs'),
        ),
      );
    }

    public function calculate_shipping($package = array()) {

      $cart_subtotal = $this->get_package_subtotal_excl_tax($package);

      // Free shipping by threshold
      if ($this->free_shipping_threshold > 0 && $cart_subtotal >= $this->free_shipping_threshold) {
        $this->add_rate(array(
          'id'    => $this->get_rate_id(),
          'label' => $this->title,
          'cost'  => 0,
          'package' => $package,
        ));
        return;
      }

      $weight_kg   = $this->get_package_weight_kg($package);
      $item_qty    = $this->get_package_qty($package);
      $rules       = $this->parse_rules();

      if (empty($rules)) return;

      $matches = array();

      foreach ($rules as $r) {
        if (!$this->rule_matches($r, $weight_kg, $cart_subtotal, $item_qty, $package)) {
          continue;
        }
        $use_weight = $this->rounded_weight($weight_kg);

        $base    = isset($r['base']) ? floatval($r['base']) : 0;
        $per_kg  = isset($r['per_kg']) ? floatval($r['per_kg']) : 0;
        $percent = isset($r['percent']) ? floatval($r['percent']) : 0;

        $cost = $base + ($per_kg * $use_weight) + ($percent > 0 ? ($percent * $cart_subtotal / 100) : 0);
        $matches[] = max(0, $cost);
      }

      if (empty($matches)) return;

      switch ($this->calc_type) {
        case 'highest': $final = max($matches); break;
        case 'sum':     $final = array_sum($matches); break;
        case 'cheapest':
        default:        $final = min($matches); break;
      }

      $final += max(0, $this->handling_fee);

      $this->add_rate(array(
        'id'       => $this->get_rate_id(),
        'label'    => $this->title,
        'cost'     => max(0, $final),
        'package'  => $package,
      ));
    }

    private function get_rate_id() {
      return $this->id . ':' . $this->instance_id;
    }

    private function parse_rules() {
      $json = $this->rules_json;
      if (!is_string($json) || trim($json) === '') return array();
      $decoded = json_decode($json, true);
      return is_array($decoded) ? $decoded : array();
    }

    private function rounded_weight($w) {
      switch ($this->weight_rounding) {
        case 'ceil':  return ceil($w);
        case 'floor': return floor($w);
        default:      return $w;
      }
    }

    private function rule_matches($r, $weight, $subtotal, $qty, $package) {
      $min_w = isset($r['min_weight']) && $r['min_weight'] !== '' ? floatval($r['min_weight']) : null;
      $max_w = isset($r['max_weight']) && $r['max_weight'] !== '' ? floatval($r['max_weight']) : null;
      $min_s = isset($r['min_subtotal']) && $r['min_subtotal'] !== '' ? floatval($r['min_subtotal']) : null;
      $max_s = isset($r['max_subtotal']) && $r['max_subtotal'] !== '' ? floatval($r['max_subtotal']) : null;
      $min_q = isset($r['min_qty']) && $r['min_qty'] !== '' ? intval($r['min_qty']) : null;
      $max_q = isset($r['max_qty']) && $r['max_qty'] !== '' ? intval($r['max_qty']) : null;
      $cls   = isset($r['shipping_class']) ? trim(strval($r['shipping_class'])) : '';

      if ($min_w !== null && $weight < $min_w) return false;
      if ($max_w !== null && $weight > $max_w) return false;

      if ($min_s !== null && $subtotal < $min_s) return false;
      if ($max_s !== null && $subtotal > $max_s) return false;

      if ($min_q !== null && $qty < $min_q) return false;
      if ($max_q !== null && $qty > $max_q) return false;

      if ($cls !== '' && strtolower($cls) !== 'any') {
        if (!$this->package_has_class($package, $cls)) return false;
      }

      // ----- Optional destination filters from UI -----
      $dest_state = strtoupper(trim($package['destination']['state'] ?? ''));
      $dest_pc    = trim($package['destination']['postcode'] ?? '');

      if (!empty($r['states']) && is_array($r['states'])) {
        $states_up = array_map('strtoupper', array_map('trim', $r['states']));
        if (!in_array($dest_state, $states_up, true)) return false;
      }

      if (!empty($r['postcodes']) && is_array($r['postcodes'])) {
        $ok = false;
        foreach ($r['postcodes'] as $pat) {
          $pat = trim(strval($pat));
          if ($pat === '') continue;
          $pattern = '/' . str_replace('/', '\/', $pat) . '/i';
          if (@preg_match($pattern, $dest_pc)) {
            if (preg_match($pattern, $dest_pc)) { $ok = true; break; }
          }
        }
        if (!$ok) return false;
      }

      return true;
    }

    private function package_has_class($package, $class_slug) {
      foreach ($package['contents'] as $item) {
        if (!isset($item['data']) || !is_a($item['data'], 'WC_Product')) continue;
        $prod = $item['data'];
        $sc = $prod->get_shipping_class();
        if ($sc === $class_slug) return true;
      }
      return false;
    }

    private function get_package_qty($package) {
      $qty = 0;
      foreach ($package['contents'] as $item) {
        $qty += isset($item['quantity']) ? (int)$item['quantity'] : 0;
      }
      return max(0, $qty);
    }

    private function get_package_weight_kg($package) {
      $store_unit = get_option('woocommerce_weight_unit', 'kg');
      $total = 0.0;
      foreach ($package['contents'] as $item) {
        if (!isset($item['data']) || !is_a($item['data'], 'WC_Product')) continue;
        $prod = $item['data'];
        if ($prod->get_virtual('edit')) continue; // ignore virtual items
        $w = (float)$prod->get_weight('edit');
        $q = (int) ($item['quantity'] ?? 1);
        if ($w > 0 && $q > 0) {
          $kg = wc_get_weight($w, 'kg', $store_unit);
          $total += ($kg * $q);
        }
      }
      return max(0, round((float)$total, 4));
    }

    private function get_package_subtotal_excl_tax($package) {
      $subtotal = 0.0;
      foreach ($package['contents'] as $item) {
        $line_subtotal = isset($item['line_subtotal']) ? (float)$item['line_subtotal'] : 0;
        $subtotal += $line_subtotal;
      }
      return max(0, round($subtotal, 2));
    }
  }

  // Register method
  add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['sarkkart_wbs'] = 'WC_Sarkkart_Weight_Based_Shipping';
    return $methods;
  });

});
