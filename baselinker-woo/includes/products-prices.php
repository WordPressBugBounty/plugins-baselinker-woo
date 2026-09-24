<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('wc-bl/v2', '/products_prices/', [
        'methods' => 'GET',
        'callback' => 'baselinker_products_prices',
        'permission_callback' => 'baselinker_authenticate',
    ]);
});

/**
 * Collect price-related settings from one products_prices GET request.
 *
 * @param array $data GET parameters (special_prices, price_meta, wmc_currency, add_tax, def_tax_rate, etc.).
 * @return array<string, mixed> Shared price rules; variant_prices_by_currency starts empty.
 */
function baselinker_build_products_prices_context(array $data): array
{
    return [
        'use_sale_price' => isset($data['special_prices']) && (string)$data['special_prices'] === '1',
        'price_meta' => isset($data['price_meta']) ? $data['price_meta'] : '',
        'add_tax' => isset($data['add_tax']) ? $data['add_tax'] : 'false',
        'target_country' => isset($data['target_country']) ? $data['target_country'] : '',
        'default_tax_rate' => isset($data['def_tax_rate']) ? (float)$data['def_tax_rate'] : 0.0,
        'strict_default_tax' => isset($data['strict_def_tax']) && (string)$data['strict_def_tax'] === '1',
        'treat_zw_as_exempt' => isset($data['tax0zw']) && (string)$data['tax0zw'] === '1',
        'limit_options' => !isset($data['limit_options']) || $data['limit_options'] !== '0',
        'price_rules' => baselinker_parse_price_rules(
            isset($data['prod_meta_remap']) ? (string)$data['prod_meta_remap'] : '',
            isset($data['pd_remap']) ? (string)$data['pd_remap'] : ''
        ),
        'variant_prices_by_currency' => [],
        'wmc_currency' => strtoupper((string)($data['wmc-currency'] ?? $data['wmc_currency'] ?? '')),
    ];
}

/**
 * Read a CURCY price from _regular_price_wmcp / _sale_price_wmcp meta.
 *
 * @param WC_Product $product Product or variation.
 * @param array $price_context Shared price rules from baselinker_build_products_prices_context().
 * @return float|null Price for the configured wmc_currency, or null when unavailable.
 */
function baselinker_curcy_price($product, array $price_context): ?float
{
    $wmc_currency = $price_context['wmc_currency'] ?? '';

    if ($wmc_currency === '') {
        return null;
    }

    $meta_keys = $price_context['use_sale_price']
        ? ['_sale_price_wmcp', '_regular_price_wmcp']
        : ['_regular_price_wmcp'];

    foreach ($meta_keys as $meta_key) {
        $meta_value = $product->get_meta($meta_key, true);

        if ($meta_value === '') {
            continue;
        }

        $wmc_prices = json_decode($meta_value, true);

        if (!is_array($wmc_prices) || !isset($wmc_prices[$wmc_currency]) || !is_numeric($wmc_prices[$wmc_currency])) {
            continue;
        }

        return (float)$wmc_prices[$wmc_currency];
    }

    return null;
}

/**
 * Keep only remap rules that can change a ProductsPrices result.
 *
 * @param string $prod_meta_remap Shop setting prod_meta_remap.
 * @param string $pd_remap Shop setting pd_remap.
 * @return array{meta_keys: string[], field_rules: array<int, array<string, mixed>>}
 */
function baselinker_parse_price_rules(string $prod_meta_remap, string $pd_remap): array
{
    $price_rules = [
        'meta_keys' => [],
        'field_rules' => [],
    ];

    if (preg_match_all(
        '/(?<target>\w+)\s*:\s*(?<meta_key>\w+)/',
        $prod_meta_remap,
        $meta_rules,
        PREG_SET_ORDER
    )) {
        foreach ($meta_rules as $meta_rule) {
            if ($meta_rule['target'] === 'price') {
                $price_rules['meta_keys'][] = $meta_rule['meta_key'];
            }
        }
    }

    if (preg_match_all(
        '/(?<target>\w+)\s*=\s*(?<source>[\w.]+)(?:\s*(?<calculation>[*\/])\s*(?<number>[\d.]+))?/',
        $pd_remap,
        $field_rules,
        PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
    )) {
        foreach ($field_rules as $field_rule) {
            if ($field_rule['target'] !== 'price') {
                continue;
            }

            $rule = ['source' => $field_rule['source']];

            if (isset($field_rule['number']) && is_numeric($field_rule['number'])) {
                if ($field_rule['calculation'] === '*') {
                    $rule['multiply_by'] = (float)$field_rule['number'];
                } else {
                    $divisor = (float)$field_rule['number'];

                    // Skip divide rules when the divisor is zero or too close to zero (1e-10).
                    if (abs($divisor) > 1e-10) {
                        $rule['divide_by'] = $divisor;
                    }
                }
            }

            $price_rules['field_rules'][] = $rule;
        }
    }

    return $price_rules;
}

/**
 * Return catalogue prices for one page (REST: products_prices).
 *
 * Flat map per product: key 0 is the parent price, variant IDs are variant prices.
 *
 * @param WP_REST_Request $request Incoming REST request with price and catalogue filters.
 * @return WP_REST_Response JSON map product_id => prices; sets X-WP-TotalPages.
 */
function baselinker_products_prices($request)
{
    $data = $request->get_params();
    baselinker_set_request_currency_params($data);
    $price_context = baselinker_build_products_prices_context($data);

    // Read the published catalogue newest-first, one page at a time.
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

    // Publication status to include (letters/spaces only, e.g. "publish").
    if (isset($data['status'])) {
        $args['status'] = preg_replace('/[^\w\s]/', '', $data['status']);
    }

    // Product IDs to limit the page (include) or skip entirely (exclude).
    if (isset($data['include']) && preg_match('/^\d[\d,]*$/', $data['include'])) {
        $args['include'] = explode(',', $data['include']);
    } elseif (isset($data['exclude']) && preg_match('/^\d[\d,]*$/', $data['exclude'])) {
        $args['exclude'] = explode(',', $data['exclude']);
    }

    if (isset($data['lang']) && preg_match('/^\w+/', $data['lang'])) {
        $args['lang'] = $data['lang'];
    }

    $price_currency = isset($data['multi_currency_prices']) ? $data['multi_currency_prices'] : '';
    $master_language = isset($data['wpml_master']) ? $data['wpml_master'] : '';
    $request_language = isset($data['lang']) ? $data['lang'] : '';

    $products_query_result = wc_get_products($args);
    $products = [];

    if (is_object($products_query_result) && isset($products_query_result->products)) {
        if ($price_currency !== '') {
            $price_context['variant_prices_by_currency'] = baselinker_apply_multi_currency_prices(
                $products_query_result->products,
                $price_currency,
                $master_language,
                $request_language
            );
        }

        foreach ($products_query_result->products as $product) {
            $product_prices = [
                '0' => baselinker_product_price(
                    $product,
                    $price_context
                ),
            ];

            if ($product->get_type() === 'variable') {
                foreach (baselinker_variant_prices($product, $price_context) as $variant_id => $price) {
                    $product_prices[(string)$variant_id] = $price;
                }
            }

            $products[(string)$product->get_id()] = $product_prices;
        }
    }

    $response = new WP_REST_Response($products);
    $total_pages = (is_object($products_query_result) && isset($products_query_result->max_num_pages))
        ? (int)$products_query_result->max_num_pages
        : 1;
    $response->header('X-WP-TotalPages', $total_pages);

    return $response;
}

/**
 * VAT rate (percent) for a tax class and country.
 *
 * Looks up WooCommerce rates, then shop defaults from price_context. Result is cached
 * per request. Returns -1.0 when the class is treated as VAT-exempt (zw).
 *
 * @param string $tax_class WooCommerce tax class slug.
 * @param array $price_context Shared price rules from baselinker_build_products_prices_context().
 * @return float Rate in percent, or -1.0 for no-tax marker.
 */
function baselinker_tax_rate_for_class(string $tax_class, array $price_context): float
{
    static $cache = [];
    $cache_key = implode('|', [
        $price_context['target_country'],
        $tax_class,
        $price_context['default_tax_rate'],
        (int)$price_context['strict_default_tax'],
        (int)$price_context['treat_zw_as_exempt'],
    ]);

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    if ($price_context['treat_zw_as_exempt'] && preg_match('/^zw\.?$/i', $tax_class)) {
        $cache[$cache_key] = -1.0;
        return -1.0;
    }

    $rate = null;

    if (class_exists('WC_Tax')) {
        $rates = WC_Tax::find_rates([
            'country' => $price_context['target_country'],
            'state' => '',
            'postcode' => '',
            'city' => '',
            'tax_class' => $tax_class,
        ]);

        if (!empty($rates)) {
            $first = reset($rates);
            $rate = isset($first['rate']) ? (float)$first['rate'] : null;
        }
    }

    if ($rate === null) {
        global $wpdb;
        $class_rate = isset($wpdb)
            ? $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT tax_rate FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_id DESC LIMIT 1",
                    $tax_class
                )
            )
            : null;

        if ($class_rate !== null) {
            $rate = (float)$class_rate;
        } else {
            $all_rates_count = isset($wpdb)
                ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates")
                : 0;

            if ($all_rates_count > 0) {
                $rate = $price_context['default_tax_rate'];
            } elseif ($price_context['treat_zw_as_exempt']) {
                $rate = -1.0;
            } else {
                $rate = $price_context['strict_default_tax']
                    ? $price_context['default_tax_rate']
                    : 0.0;
            }
        }
    }

    $cache[$cache_key] = $rate;

    return $rate;
}

/**
 * Override price from product meta when a positive value is found.
 *
 * Supports dotted paths (e.g. nested.amount). Only replaces when value >= 0.01.
 *
 * @param float|int|string $price Current price; updated in place when meta applies.
 * @param array|object $meta_source Product meta array or object.
 * @param string $price_meta Meta key or dotted path.
 * @return void
 */
function baselinker_apply_price_meta(&$price, $meta_source, $price_meta)
{
    $candidate = $meta_source;

    foreach (explode('.', $price_meta) as $field) {
        if (is_array($candidate)) {
            if (!array_key_exists($field, $candidate)) {
                return;
            }

            $candidate = $candidate[$field];
        } elseif (is_object($candidate)) {
            if (!property_exists($candidate, $field)) {
                return;
            }

            $candidate = $candidate->{$field};
        } else {
            // Scalar mid-path: unresolved, keep base price.
            return;
        }
    }

    if (!is_scalar($candidate)) {
        return;
    }

    $candidate = baselinker_format_price($candidate);

    if ($candidate >= 0.01) {
        $price = $candidate;
    }
}

/**
 * Apply pd_remap field rules that target price.
 *
 * @param float|int|string $price Working price; updated in place.
 * @param WC_Product $product Product or variation used as the source.
 * @param array<int,array<string,mixed>> $rules Rules from baselinker_parse_price_rules().
 * @return void
 */
function baselinker_apply_price_field_rules(&$price, $product, array $rules): void
{
    if (empty($rules)) {
        return;
    }

    $sources = [
        'price' => (float)$product->get_price(),
        'regular_price' => (float)$product->get_regular_price(),
        'sale_price' => (float)$product->get_sale_price(),
        'weight' => (float)$product->get_weight(),
        'dimensions' => [
            'length' => (float)$product->get_length(),
            'width' => (float)$product->get_width(),
            'height' => (float)$product->get_height(),
        ],
    ];

    foreach ($rules as $rule) {
        if (!is_array($rule) || empty($rule['source'])) {
            continue;
        }

        $value = $sources;

        foreach (explode('.', (string)$rule['source']) as $field_name) {
            if (!is_array($value) || !array_key_exists($field_name, $value)) {
                continue 2;
            }

            $value = $value[$field_name];
        }

        if (!is_scalar($value) || !is_numeric($value)) {
            continue;
        }

        $price = (float)$value;

        if (isset($rule['multiply_by'])) {
            $price *= (float)$rule['multiply_by'];
        } elseif (isset($rule['divide_by'])) {
            $price /= (float)$rule['divide_by'];
        }
    }
}

/**
 * Catalogue price for a simple product or a variable product parent.
 *
 * Uses regular or sale price, adds VAT if configured, then optional meta override.
 * VAT is applied before meta; meta does not trigger a second VAT pass.
 *
 * @param WC_Product $product Simple or variable parent product.
 * @param array $price_context Shared price rules.
 * @return float Price with two decimal places.
 */
function baselinker_product_price($product, array $price_context): float
{
    $price = $price_context['use_sale_price']
        ? (float)$product->get_price()
        : (float)$product->get_regular_price();

    if ($price_context['add_tax'] === 'true') {
        $rate = baselinker_tax_rate_for_class($product->get_tax_class(), $price_context);
        $price = number_format($price * (1 + $rate / 100), 2, '.', '');
    }

    if ($price_context['price_meta'] !== '') {
        $meta_path = explode('.', $price_context['price_meta']);
        $meta_key = reset($meta_path);

        foreach ($product->get_meta_data() as $meta) {
            $meta_data = $meta->get_data();

            if ($meta_data['key'] === $meta_key) {
                baselinker_apply_price_meta(
                    $price,
                    [$meta_data['key'] => $meta_data['value']],
                    $price_context['price_meta']
                );
                break;
            }
        }
    }

    $parent_price_rule = $price_context['price_rules']['field_rules'][0] ?? null;

    if ($parent_price_rule !== null) {
        baselinker_apply_price_field_rules($price, $product, [$parent_price_rule]);
    }

    $curcy_price = baselinker_curcy_price($product, $price_context);

    if ($curcy_price !== null) {
        $price = $curcy_price;
    }

    return baselinker_format_price($price);
}

/**
 * Load all variations of a variable product with attribute and WooCommerce data.
 *
 * @param WC_Product $product Variable parent product.
 * @return array<int, object> Variation rows; each has id, attributes, and ->wc.
 */
function baselinker_build_variations($product)
{
    static $attribute_ids;

    if (!isset($attribute_ids)) {
        $attribute_ids = [];

        foreach (wc_get_attribute_taxonomies() as $taxonomy) {
            $attribute_ids['pa_' . $taxonomy->attribute_name] = $taxonomy->attribute_id;
        }
    }

    $variations = [];

    foreach ($product->get_children() as $variation_id) {
        $variation = new WC_Product_Variation($variation_id);

        if ($variation->get_id() <= 0) {
            continue;
        }

        $attributes = [];

        foreach ($variation->get_attributes() as $name => $value) {
            $attribute_key = $name;

            if ($term = get_term_by('slug', $value, $name)) {
                $value = $term->name;
                $taxonomies = get_taxonomies(['name' => $term->taxonomy], 'objects');

                if (isset($taxonomies[$name])) {
                    $name = $taxonomies[$name]->label;
                }
            }

            $attribute = new stdClass();
            // WooCommerce global attributes have a real id; product-only attributes do not,
            // so we store -1 and match them later by attribute name.
            $attribute->id = isset($attribute_ids[$attribute_key]) ? $attribute_ids[$attribute_key] : -1;
            $attribute->name = $name;
            $attribute->option = $value;
            $attributes[] = $attribute;
        }

        $variation_data = new stdClass();
        $variation_data->id = $variation_id;
        $variation_data->attributes = $attributes;
        $variation_data->wc = $variation;
        $variations[] = $variation_data;
    }

    return $variations;
}

/**
 * Price for every variation of a variable product.
 *
 * Handles VAT, price meta, attribute lookups, multi-currency overrides, and
 * price-specific variant grids.
 *
 * @param WC_Product $product Variable parent product.
 * @param array $price_context Shared price rules.
 * @return array<string|int, float> Variant key => formatted price.
 */
function baselinker_variant_prices($product, array $price_context): array
{
    $result = [];
    $variations = baselinker_build_variations($product);
    $parent_tax_class = $product->get_tax_class();
    $use_sale_price = $price_context['use_sale_price'];
    $price_meta = $price_context['price_meta'];
    $limit_options = $price_context['limit_options'];
    $variant_prices_by_currency = $price_context['variant_prices_by_currency'];

    $product_attributes = [];

    foreach ($product->get_attributes() as $product_attribute) {
        $attribute = new stdClass();
        $attribute->variation = (bool)$product_attribute->get_variation();

        if ($product_attribute->is_taxonomy()) {
            $attribute->id = $product_attribute->get_id();
            $attribute->name = wc_attribute_label($product_attribute->get_name(), $product);
            $attribute->options = wc_get_product_terms(
                $product->get_id(),
                $product_attribute->get_name(),
                ['fields' => 'names']
            );
        } else {
            $attribute->id = 0;
            $attribute->name = $product_attribute->get_name();
            $attribute->options = $product_attribute->get_options();
        }

        $product_attributes[] = $attribute;
    }

    // Only attributes marked "used for variations" can be missing on a child row.
    $variation_attributes = [];

    foreach ($product_attributes as $attribute) {
        if (!empty($attribute->variation)) {
            $variation_attributes[$attribute->id] = $attribute;
        }
    }

    // When limit_options=true, skip attribute values that are not on the parent list.
    $allowed_options = [];

    if ($limit_options) {
        foreach ($product_attributes as $attribute) {
            $attribute_key = $attribute->id ?: baselinker_normalize_name($attribute->name);
            $allowed_options[$attribute_key] = $attribute->options;
        }
    }

    foreach ($variations as $variation) {
        // Track which parent variation attributes this child still does not set.
        $unmatched_attributes = array_combine(array_keys($variation_attributes), array_keys($variation_attributes));
        $attribute_values = [];

        foreach ($variation->attributes as $attribute) {
            if ($limit_options) {
                if ($attribute->id > 0) {
                    if (!isset($allowed_options[$attribute->id])
                        || !in_array(
                            $attribute->option,
                            $allowed_options[$attribute->id]
                        )) {
                        continue;
                    }
                } elseif (!isset($allowed_options[$attribute->name])
                    || !in_array(
                        $attribute->option,
                        $allowed_options[$attribute->name]
                    )) {
                    continue;
                }
            }

            $attribute_name = $attribute->name;

            if ($attribute->id > 0 && isset($variation_attributes[$attribute->id])) {
                // price_meta=@Name uses parent attribute label, not pa_ slug.
                $attribute_name = $variation_attributes[$attribute->id]->name;
            }

            $attribute_values[$attribute_name] = $attribute->option;

            if (isset($unmatched_attributes[$attribute->id])) {
                unset($unmatched_attributes[$attribute->id]);
            } elseif ($attribute->id < 0) {
                // Local (non-taxonomy) attribute: match parent row by normalized name.
                foreach ($variation_attributes as $parent_attribute_id => $parent_attribute) {
                    if (baselinker_normalize_name($attribute->name)
                        === baselinker_normalize_name(trim($parent_attribute->name, ': '))) {
                        if ($attribute->name === baselinker_normalize_name($attribute->name, false)) {
                            unset($unmatched_attributes[$parent_attribute_id]);
                        } else {
                            unset($unmatched_attributes[$attribute->id]);
                        }

                        break;
                    }
                }
            }
        }

        $woocommerce_variation = $variation->wc;

        // Use multi-currency prices when available.
        if (isset($variant_prices_by_currency[$variation->id])) {
            $price = $use_sale_price
                ? (float)$variant_prices_by_currency[$variation->id]['price']
                : (float)$variant_prices_by_currency[$variation->id]['regular_price'];
        } else {
            $price = $use_sale_price ? (float)$woocommerce_variation->get_price(
            ) : (float)$woocommerce_variation->get_regular_price();
        }

        // VAT from parent tax class, not the variation's.
        if ($price_context['add_tax'] === 'true') {
            $rate = baselinker_tax_rate_for_class($parent_tax_class, $price_context);
            $price = number_format($price * (1 + $rate / 100), 2, '.', '');
        }

        $meta_map = [];
        $meta_items = [];

        // Native meta first — private keys and duplicates live here.
        foreach ($woocommerce_variation->get_meta_data() as $meta) {
            $meta_items[] = $meta->get_data();
        }

        // Merge REST display_* only; never replace native meta.
        $price_rules = $price_context['price_rules'];
        // Load REST meta only when price_meta or remap rules need it.
        $needs_variant_meta = $price_meta !== '' || !empty($price_rules['meta_keys']);

        if ($needs_variant_meta) {
            $variation_payload = baselinker_mcp_rest_payload('variation', $woocommerce_variation);
            $rest_meta_items = (array)baselinker_mcp_field($variation_payload, 'meta_data', []);

            if (!empty($rest_meta_items)) {
                $by_id = [];            // meta id → index
                $by_key = [];           // meta key → index queue
                $already_matched = [];  // paired REST rows

                foreach ($rest_meta_items as $rest_index => $rest_meta) {
                    $rest_id = baselinker_mcp_field($rest_meta, 'id');
                    $rest_key = baselinker_mcp_field($rest_meta, 'key');

                    if ($rest_id !== null && $rest_id !== '') {
                        $by_id[(string)$rest_id] = $rest_index;
                    }

                    if ($rest_key !== null) {
                        $by_key[$rest_key][] = $rest_index;
                    }
                }

                // For each meta from Woo: find the same row in REST data (first by id, else by key).
                // Then copy display_value / display_key from REST onto the Woo row.
                foreach ($meta_items as $index => $native_meta) {
                    $match_index = null;

                    if (isset($native_meta['id']) && isset($by_id[(string)$native_meta['id']])) {
                        $candidate = $by_id[(string)$native_meta['id']];

                        if (!isset($already_matched[$candidate])) {
                            $match_index = $candidate;
                        }
                    }

                    if ($match_index === null && isset($native_meta['key']) && !empty($by_key[$native_meta['key']])) {
                        while (!empty($by_key[$native_meta['key']])) {
                            $candidate = array_shift($by_key[$native_meta['key']]);

                            if (!isset($already_matched[$candidate])) {
                                $match_index = $candidate;
                                break;
                            }
                        }
                    }

                    if ($match_index === null) {
                        continue;
                    }

                    $already_matched[$match_index] = true;
                    $rest_meta = $rest_meta_items[$match_index];
                    $display_value = baselinker_mcp_field($rest_meta, 'display_value');
                    $display_key = baselinker_mcp_field($rest_meta, 'display_key');

                    if (!empty($display_value)) {
                        $meta_items[$index]['display_value'] = $display_value;
                    }

                    if (!empty($display_key)) {
                        $meta_items[$index]['display_key'] = $display_key;
                    }
                }

                // REST-only meta rows — no matching native row.
                foreach ($rest_meta_items as $rest_index => $rest_meta) {
                    if (isset($already_matched[$rest_index])) {
                        continue;
                    }

                    $meta_items[] = [
                        'id' => baselinker_mcp_field($rest_meta, 'id'),
                        'key' => baselinker_mcp_field($rest_meta, 'key'),
                        'value' => baselinker_mcp_field($rest_meta, 'value'),
                        'display_key' => baselinker_mcp_field($rest_meta, 'display_key'),
                        'display_value' => baselinker_mcp_field($rest_meta, 'display_value'),
                    ];
                }
            }
        }

        // Save meta under its key. Same key twice → list; if all values equal → one value again.
        $attach_meta = function ($key, $value) use (&$meta_map) {
            if (isset($meta_map[$key])) {
                if (!is_array($meta_map[$key])) {
                    $meta_map[$key] = [$meta_map[$key], $value];
                } else {
                    $meta_map[$key][] = $value;
                }

                foreach ($meta_map[$key] as $meta_value) {
                    if (!is_scalar($meta_value)) {
                        return;
                    }
                }

                $meta_map[$key] = array_unique($meta_map[$key]);

                if (count($meta_map[$key]) === 1) {
                    $meta_map[$key] = reset($meta_map[$key]);
                }
            } else {
                $meta_map[$key] = $value;
            }
        };

        // Prefer display_value over raw value when building $meta_map.
        foreach ($meta_items as $meta) {
            $meta_key = baselinker_mcp_field($meta, 'key');

            if ($meta_key === null) {
                continue;
            }

            $meta_value = baselinker_mcp_field($meta, 'value');
            $display_value = baselinker_mcp_field($meta, 'display_value');

            if (!empty($display_value)) {
                $attach_meta($meta_key, $display_value);
                $display_key = baselinker_mcp_field($meta, 'display_key');

                // Some plugins store the readable label under display_key instead of key.
                if (!empty($display_key)
                    && $meta_key !== '_cpo_content'
                    && $meta_key !== '_yith_bundle_cart_key'
                    && $meta_key !== '_bundle_cart_key'
                    && $meta_key !== '_wdp_cart_item_key'
                    && strpos($meta_key, '_uni_cpo_') !== 0
                    && strpos($meta_key, '_wccf_pf_') !== 0
                    && strpos($meta_key, '_woosb_') !== 0
                ) {
                    if (isset($meta_map[$meta_key]) && is_array($meta_map[$meta_key])) {
                        array_pop($meta_map[$meta_key]);
                    } else {
                        unset($meta_map[$meta_key]);
                    }

                    $attach_meta($display_key, $display_value);
                }
            } else {
                $attach_meta($meta_key, $meta_value);
            }
        }

        // Shop price_meta: @AttributeName or a meta key from $meta_map.
        if ($price_meta !== '') {
            if (preg_match('/^@(.+)$/', $price_meta, $match) && isset($attribute_values[$match[1]])
                && is_numeric(
                    $attribute_values[$match[1]]
                )) {
                $price = $attribute_values[$match[1]];
            } else {
                baselinker_apply_price_meta($price, $meta_map, $price_meta);
            }
        }

        // prod_meta_remap rules that target price.
        foreach ($price_rules['meta_keys'] as $meta_key) {
            if (isset($meta_map[$meta_key])) {
                $price = $meta_map[$meta_key];
            }
        }

        // pd_remap field rules
        baselinker_apply_price_field_rules(
            $price,
            $woocommerce_variation,
            $price_rules['field_rules']
        );
        $price = baselinker_format_price($price);

        $curcy_price = baselinker_curcy_price($woocommerce_variation, $price_context);

        if ($curcy_price !== null) {
            $price = baselinker_format_price($curcy_price);
        }

        // Full variation row: one output key with the real variation id.
        if (empty($unmatched_attributes)
            || $limit_options
            || (empty($variation->name) && empty($variation->attributes))) {
            $result[$variation->id] = $price;
        } else {
            // Missing attributes: expand to every option combo.
            $variant_key_suffixes = [''];

            foreach ($unmatched_attributes as $attribute_id) {
                $expanded_suffixes = [];

                foreach ($variant_key_suffixes as $suffix) {
                    foreach ($variation_attributes[$attribute_id]->options as $option) {
                        $expanded_suffixes[] = $suffix . '_' . sprintf('%u', crc32($attribute_id . ':' . $option));
                    }
                }
                $variant_key_suffixes = $expanded_suffixes;
            }

            foreach ($variant_key_suffixes as $suffix) {
                $result[$variation->id . $suffix] = $price;
            }
        }
    }

    return $result;
}
