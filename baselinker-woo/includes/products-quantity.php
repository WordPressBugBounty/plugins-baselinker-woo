<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('wc-bl/v2', '/products_quantity/', [
        'methods' => 'GET',
        'callback' => 'baselinker_products_quantity',
        'permission_callback' => 'baselinker_authenticate',
    ]);
});

/**
 * Read one field from REST data (array or object).
 *
 * @param array|object $payload REST response body.
 * @param string $field Key or property name.
 * @param mixed $default Returned when the field is missing.
 * @return mixed
 */
function baselinker_mcp_field($payload, $field, $default = null)
{
    if (is_array($payload) && array_key_exists($field, $payload)) {
        return $payload[$field];
    }

    if (is_object($payload) && property_exists($payload, $field)) {
        return $payload->{$field};
    }

    return $default;
}

/**
 * Fetch product or variation data through Woo REST (for multi-currency / WPML).
 *
 * @param string $resource_type product or variation.
 * @param WC_Product $wc_product Product or variation instance.
 * @param string $language Optional WPML language code.
 * @return array|null REST data as array, or null if REST is unavailable.
 */
function baselinker_mcp_rest_payload($resource_type, $wc_product, $language = '')
{
    $controller_class = $resource_type === 'variation'
        ? 'WC_REST_Product_Variations_Controller'
        : 'WC_REST_Products_Controller';

    // One product and one variation REST controller per request.
    static $controllers = [];

    if (!class_exists($controller_class) || !class_exists('WP_REST_Request')) {
        return null;
    }

    if (!isset($controllers[$controller_class])) {
        $controllers[$controller_class] = new $controller_class();
    }

    $controller = $controllers[$controller_class];

    if (!method_exists($controller, 'prepare_object_for_response')) {
        return null;
    }

    $route = '/wc/v3/products';

    if ($resource_type === 'variation' && method_exists($wc_product, 'get_parent_id')) {
        $route .= '/' . $wc_product->get_parent_id() . '/variations';
    }

    $request = new WP_REST_Request('GET', $route);
    $request->set_param('context', 'view');

    if ($language !== '') {
        $request->set_param('lang', $language);
    }

    if ($resource_type === 'variation' && method_exists($wc_product, 'get_parent_id')) {
        $request->set_param('product_id', $wc_product->get_parent_id());
    }

    $response = $controller->prepare_object_for_response($wc_product, $request);

    if ((function_exists('is_wp_error') && is_wp_error($response)) || !is_object($response)) {
        return null;
    }

    if (method_exists($response, 'get_data')) {
        return $response->get_data();
    }

    return isset($response->data) ? $response->data : null;
}

/**
 * Return raw stock for one catalogue page (REST: products_quantity).
 *
 * Simple products: parent_quantity. Variable products: variant map. Rules from GET params.
 *
 * @param WP_REST_Request $request Incoming REST request with catalogue filters and stock rules.
 * @return WP_REST_Response JSON map product_id => quantities; sets X-WP-TotalPages.
 */
function baselinker_products_quantity($request)
{
    $data = $request->get_params();

    $args = [
        'status' => 'publish',
        'limit' => 100,
        'page' => 1,
        'paginate' => true,
        'orderby' => 'ID',
        'order' => 'DESC',
    ];

    if (isset($data['per_page']) && (int)$data['per_page'] > 0) {
        $args['limit'] = (int)$data['per_page'];
    }

    if (isset($data['page']) && (int)$data['page'] > 0) {
        $args['page'] = (int)$data['page'];
    }

    if (isset($data['status'])) {
        $args['status'] = preg_replace('/[^\w\s]/', '', $data['status']);
    }

    if (isset($data['exclude']) && preg_match('/^\d[\d,]*$/', $data['exclude'])) {
        $args['exclude'] = explode(',', $data['exclude']);
    }

    $language = '';

    // Optional WPML language to read the catalogue in (different IDs)
    if (isset($data['lang']) && preg_match('/^\w+/', $data['lang'])) {
        $language = $data['lang'];
        $args['lang'] = $language;
    }

    // Stock rules from request: qty_fld and def_qty.
    $quantity_field = isset($data['qty_fld']) ? $data['qty_fld'] : 'stock_quantity';
    $default_quantity = isset($data['def_qty']) ? (int)$data['def_qty'] : 1;

    $res = wc_get_products($args);
    $products = [];

    if (is_object($res) && isset($res->products)) {
        foreach ($res->products as $product) {
            $variation_ids = ($product->get_type() === 'variable') ? $product->get_children() : [];
            $stock = new stdClass();
            $stock->manage_stock = (bool)$product->get_manage_stock();
            $stock->stock_status = $product->get_stock_status();
            $stock->stock_quantity = $product->get_stock_quantity();
            $stock->wc = $product;
            $parent_quantity = baselinker_available_quantity(
                $stock,
                $quantity_field,
                $default_quantity,
                $language
            );
            $products[(string)$product->get_id()] = ['0' => $parent_quantity];

            if (!empty($variation_ids)) {
                $variants = baselinker_variant_quantities(
                    $variation_ids,
                    $quantity_field,
                    $default_quantity,
                    $language
                );

                $total_quantity = 0;

                if (!empty($variants)) {
                    foreach ($variants as $variant_id => $variant_quantity) {
                        $total_quantity += $variant_quantity;
                        $products[(string)$product->get_id()][(string)$variant_id] = $variant_quantity;
                    }
                }

                if (!$stock->manage_stock) {
                    $products[(string)$product->get_id()]['0'] = $total_quantity;
                }
            }
        }
    }

    $response = new WP_REST_Response($products);
    $total_pages = (is_object($res) && isset($res->max_num_pages))
        ? (int)$res->max_num_pages
        : 1;
    $response->header('X-WP-TotalPages', $total_pages);

    return $response;
}

/**
 * Raw stock for one product or variation.
 *
 * Reads tracked stock from a Woo REST field. Untracked items use a default when in stock.
 *
 * @param object $stock Stock snapshot (manage_stock, stock_quantity, stock_status, wc).
 * @param string $quantity_field Woo REST field containing quantity.
 * @param int $default_quantity Units to report when stock is not tracked but item is in stock.
 * @param string $language Optional WPML language for the Woo REST payload.
 * @return int Raw quantity before connector-level availability rules.
 */
function baselinker_available_quantity($stock, $quantity_field, $default_quantity, $language = '')
{
    if ($stock->manage_stock) {
        if (empty($quantity_field) || $quantity_field === 'stock_quantity') {
            $quantity = (int)$stock->stock_quantity;
        } else {
            $resource_type = method_exists($stock->wc, 'get_parent_id') && $stock->wc->get_parent_id() > 0
                ? 'variation'
                : 'product';

            $payload = baselinker_mcp_rest_payload($resource_type, $stock->wc, $language);
            $quantity = baselinker_mcp_field($payload, $quantity_field, 0);
        }
    } else {
        // No tracked stock: def_qty when in stock, 0 otherwise.
        $in_stock = isset($stock->stock_status)
            ? (int)($stock->stock_status === 'instock')
            : (int)$stock->in_stock;

        $quantity = $in_stock * $default_quantity;
    }

    return (int)$quantity;
}

/**
 * Raw quantity for every variation of a variable product.
 *
 * Each stock value is returned under its real WooCommerce variation id.
 *
 * @param int[] $variation_ids WooCommerce variation ids.
 * @param string $quantity_field Woo REST field containing quantity.
 * @param int $default_quantity Default for untracked in-stock variations.
 * @param string $language Optional WPML language for the Woo REST payload.
 * @return array<int, int> WooCommerce variation id => raw quantity.
 */
function baselinker_variant_quantities(
    $variation_ids,
    $quantity_field,
    $default_quantity,
    $language = ''
) {
    $result = [];

    foreach ($variation_ids as $variation_id) {
        $wc_variation = new WC_Product_Variation($variation_id);

        if ($wc_variation->get_id() <= 0) {
            continue;
        }

        $stock = new stdClass();
        $stock->manage_stock = (bool)$wc_variation->get_manage_stock();
        $stock->in_stock = $wc_variation->is_in_stock();
        $stock->stock_quantity = $wc_variation->get_stock_quantity();
        $stock->wc = $wc_variation;

        $quantity = baselinker_available_quantity(
            $stock,
            $quantity_field,
            $default_quantity,
            $language
        );

        $result[$variation_id] = $quantity;
    }

    return $result;
}
