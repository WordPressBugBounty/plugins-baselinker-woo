<?php

if (!defined('ABSPATH')) {
    exit;
}

// MCP = Multi-Currency Prices.

/**
 * Mirror reqCurrencyParam(): expose currency choice to WooCommerce and CURCY.
 *
 * Priority: currency, alg_currency, wmc-currency.
 *
 * @param array $data Request parameters from the products_prices endpoint.
 * @return void
 */
function baselinker_set_request_currency_params(array $data): void
{
    if (!empty($data['currency'])) {
        $_GET['currency'] = (string)$data['currency'];
        $_REQUEST['currency'] = (string)$data['currency'];
        return;
    }

    if (!empty($data['alg_currency'])) {
        $_GET['alg_currency'] = (string)$data['alg_currency'];
        $_REQUEST['alg_currency'] = (string)$data['alg_currency'];
        return;
    }

    $wmc_currency = $data['wmc-currency'] ?? $data['wmc_currency'] ?? '';

    if ($wmc_currency !== '') {
        $wmc_currency = strtoupper((string)$wmc_currency);
        $_GET['wmc-currency'] = $wmc_currency;
        $_REQUEST['wmc-currency'] = $wmc_currency;
    }
}

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
 * Convert nested REST objects to plain arrays (recursive).
 *
 * @param mixed $value REST field value.
 * @return mixed Same structure with objects replaced by arrays.
 */
function baselinker_mcp_to_array($value)
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = baselinker_mcp_to_array($item);
    }

    return $value;
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

    foreach (['currency', 'alg_currency', 'wmc-currency'] as $currency_param) {
        if (!empty($_REQUEST[$currency_param])) {
            $request->set_param($currency_param, $_REQUEST[$currency_param]);
        }
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
 * Pull multi-currency price tables from a WPML master product REST payload.
 *
 * Tries the multi-currency-prices field first, then product and baselinker_variations meta.
 *
 * @param array|object $payload Master product REST data.
 * @param bool $found_meta Set true when prices came from meta fallback.
 * @return array{product: array, variants: array} Parent and per-variant currency prices.
 */
function baselinker_mcp_master_prices($payload, &$found_meta)
{
    if ((is_array($payload) && array_key_exists('multi-currency-prices', $payload))
        || (is_object($payload) && property_exists($payload, 'multi-currency-prices'))
    ) {
        $prices = baselinker_mcp_to_array(baselinker_mcp_field($payload, 'multi-currency-prices'));

        return [
            'product' => is_array($prices) ? $prices : [],
            'variants' => [],
        ];
    }

    $product_prices = [];
    $variant_prices = [];

    foreach ((array)baselinker_mcp_field($payload, 'meta_data', []) as $meta) {
        $key = baselinker_mcp_field($meta, 'key', '');

        if (preg_match('/^_((regular_|sale_)?price)_([A-Z]{3})$/', $key, $match)) {
            $product_prices[$match[3]][$match[1]] = baselinker_mcp_field($meta, 'value');
            $found_meta = true;
        }
    }

    foreach ((array)baselinker_mcp_field($payload, 'baselinker_variations', []) as $variation) {
        $variation_id = (int)baselinker_mcp_field($variation, 'id', 0);

        foreach ((array)baselinker_mcp_field($variation, 'meta_data', []) as $meta) {
            $key = baselinker_mcp_field($meta, 'key', '');

            if (preg_match('/^_((regular_|sale_)?price)_([A-Z]{3})$/', $key, $match)) {
                $variant_prices[$variation_id][$match[3]][$match[1]] = baselinker_mcp_field($meta, 'value');
                $found_meta = true;
            }
        }
    }

    return [
        'product' => $product_prices,
        'variants' => $variant_prices,
    ];
}

/**
 * Swap in-memory prices to the requested currency (WPML / multi-currency plugin).
 *
 * @param WC_Product[] $products Products on the current page; may be updated in place.
 * @param string $price_currency Target ISO currency code.
 * @param string $master_language WPML master language when translations differ.
 * @param string $request_language Language of the catalogue read.
 * @return array<int, array{price: float, regular_price: float}> Variation id => currency prices.
 */
function baselinker_apply_multi_currency_prices(
    &$products,
    $price_currency,
    $master_language,
    $request_language
) {
    if ($price_currency === '') {
        return [];
    }

    $product_payloads = [];
    $products_by_id = [];

    // Build REST payload for each product on this page.
    foreach ($products as $product) {
        $product_id = (int)$product->get_id();
        $products_by_id[$product_id] = $product;
        $product_payloads[$product_id] = baselinker_mcp_rest_payload(
            'product',
            $product,
            $request_language
        );
    }

    $master_ids_by_product = [];
    $needs_master_mapping = false;

    // Detect products that need prices from WPML master.
    foreach ($product_payloads as $payload) {
        $product_language = baselinker_mcp_field($payload, 'lang');
        $translations = baselinker_mcp_field($payload, 'translations');

        if (!empty($master_language)
            && $product_language !== null
            && $master_language !== $product_language
            && $translations !== null
        ) {
            $needs_master_mapping = true;
            break;
        }
    }

    if ($needs_master_mapping) {
        foreach ($product_payloads as $candidate) {
            $candidate_id = (int)baselinker_mcp_field($candidate, 'id', 0);
            $candidate_translations = baselinker_mcp_to_array(
                baselinker_mcp_field($candidate, 'translations', [])
            );

            if (!is_array($candidate_translations)) {
                continue;
            }

            foreach ($candidate_translations as $language => $translated_product_id) {
                if ($language === $master_language) {
                    $master_ids_by_product[$candidate_id] = (int)$translated_product_id;
                    break;
                }
            }
        }
    }

    $master_currency_prices = [];
    $master_products = [];
    $found_meta = false;

    // Load each master once; collect product and variation prices.
    foreach (array_values(array_unique($master_ids_by_product)) as $master_product_id) {
        $master_product = wc_get_product($master_product_id);

        if (!$master_product) {
            continue;
        }

        $master_products[$master_product_id] = $master_product;
        $master_payload = baselinker_mcp_rest_payload('product', $master_product, $master_language);

        if ($master_payload === null) {
            continue;
        }

        $prices = baselinker_mcp_master_prices($master_payload, $found_meta);
        $master_currency_prices[$master_product_id] = $prices['product'];

        foreach ($prices['variants'] as $variation_id => $variation_prices) {
            $master_currency_prices[(int)$variation_id] = $variation_prices;
        }
    }

    $variant_prices_by_currency = [];

    // Apply parent prices, then map master variations back to current-language IDs.
    foreach ($products_by_id as $product_id => $product) {
        $payload = $product_payloads[$product_id];
        $payload_currency_prices = baselinker_mcp_field($payload, 'multi-currency-prices');

        if (!(is_array($payload_currency_prices) || $found_meta)) {
            continue;
        }

        $currency_prices = is_array($payload_currency_prices) ? $payload_currency_prices : [];
        $translations = baselinker_mcp_to_array(
            baselinker_mcp_field($payload, 'translations', [])
        );

        if (is_array($translations)) {
            foreach ($translations as $translated_product_id) {
                if (isset($master_currency_prices[$translated_product_id])) {
                    $currency_prices = $master_currency_prices[$translated_product_id];
                }
            }
        }

        $parent_regular_price_set = false;

        foreach ($currency_prices as $currency => $prices) {
            if ($currency !== $price_currency) {
                continue;
            }

            if (!empty($prices['regular_price'])) {
                $product->set_price($prices['regular_price']);
                $product->set_regular_price($prices['regular_price']);
                $parent_regular_price_set = true;
            }

            if (!empty($prices['sale_price'])) {
                $product->set_price($prices['sale_price']);
            }

            break;
        }

        $product_language = baselinker_mcp_field($payload, 'lang');
        $variation_ids = (array)baselinker_mcp_field($payload, 'variations', []);

        if (empty($variation_ids)
            || $master_language === $product_language
            || !isset($master_ids_by_product[$product_id], $master_products[$master_ids_by_product[$product_id]])
        ) {
            continue;
        }

        $current_variant_prices = [];

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);

            if ($variation) {
                $current_variant_prices[(int)$variation_id] = [
                    'price' => $variation->get_price(),
                    'regular_price' => $variation->get_regular_price(),
                ];
            }
        }

        $master_product = $master_products[$master_ids_by_product[$product_id]];

        foreach ($master_product->get_children() as $master_variation_id) {
            $master_variation = wc_get_product($master_variation_id);

            if (!$master_variation) {
                continue;
            }

            $variation_payload = baselinker_mcp_rest_payload(
                'variation',
                $master_variation,
                $master_language
            );
            
            $variation_translations = baselinker_mcp_to_array(
                baselinker_mcp_field($variation_payload, 'translations')
            );

            if (!is_array($variation_translations)) {
                continue;
            }

            $current_variation_id = (int)baselinker_mcp_field($variation_payload, 'id', 0);
            $mapped_master_variation_id = 0;

            foreach ($variation_translations as $language => $translated_variation_id) {
                if ($language === $request_language) {
                    $current_variation_id = (int)$translated_variation_id;
                } elseif ($language === $master_language) {
                    $mapped_master_variation_id = (int)$translated_variation_id;
                }
            }

            $variation_currency_prices = baselinker_mcp_field(
                $variation_payload,
                'multi-currency-prices'
            );

            if (empty($variation_currency_prices) && !empty($master_language)) {
                $variation_currency_prices = isset($master_currency_prices[$mapped_master_variation_id])
                    ? $master_currency_prices[$mapped_master_variation_id]
                    : [];
            }

            foreach ((array)$variation_currency_prices as $currency => $prices) {
                if ($currency !== $price_currency) {
                    continue;
                }

                if (isset($current_variant_prices[$current_variation_id])) {
                    if (!empty($prices['regular_price'])) {
                        $current_variant_prices[$current_variation_id]['price'] = $prices['regular_price'];
                        $current_variant_prices[$current_variation_id]['regular_price'] = $prices['regular_price'];
                    }

                    if (!empty($prices['sale_price'])) {
                        $current_variant_prices[$current_variation_id]['price'] = $prices['sale_price'];
                    }

                    $variant_prices_by_currency[$current_variation_id] = $current_variant_prices[$current_variation_id];
                }

                if (!$parent_regular_price_set && !empty($current_variant_prices)) {
                    $first_variant_prices = reset($current_variant_prices);
                    $product->set_price($first_variant_prices['price']);
                    $product->set_regular_price($first_variant_prices['regular_price']);
                    $parent_regular_price_set = true;
                }
            }
        }
    }

    return $variant_prices_by_currency;
}
