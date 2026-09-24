<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalize a name for comparing attributes (accents, case, spaces).
 *
 * @param string $text Attribute or option label from WooCommerce.
 * @param bool $lowercase When true, lower-case before normalizing.
 * @return string ASCII-friendly string safe for key matching.
 */
function baselinker_normalize_name($text = '', $lowercase = true)
{
    if ($lowercase) {
        $text = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
    }

    $normalized_name = preg_replace(
        '/-+/',
        '-',
        str_replace(
            [
                ' ',
                'ą',
                'ć',
                'ę',
                'ł',
                'ń',
                'ó',
                'ś',
                'ź',
                'ż',
                'á',
                'č',
                'ď',
                'é',
                'ě',
                'í',
                'ň',
                'ó',
                'ř',
                'š',
                'ť',
                'ú',
                'ů',
                'ý',
                'ž',
            ],
            [
                '-',
                'a',
                'c',
                'e',
                'l',
                'n',
                'o',
                's',
                'z',
                'z',
                'a',
                'c',
                'd',
                'e',
                'e',
                'i',
                'n',
                'o',
                'r',
                's',
                't',
                'u',
                'u',
                'y',
                'z',
            ],
            $text
        )
    );

    if (!function_exists('iconv')) {
        return $normalized_name;
    }

    $ascii_name = iconv('UTF-8', 'ASCII//TRANSLIT', $normalized_name);
    return $ascii_name === false ? $normalized_name : $ascii_name;
}

/**
 * Turn a raw price string or number into a float with two decimals.
 *
 * @param int|float|string $price Value from Woo fields or meta (commas allowed).
 * @return float Rounded price, dot as decimal separator.
 */
function baselinker_format_price($price)
{
    $price = str_replace(',', '.', (string)$price);
    $price = preg_replace('/[^-0-9.]/', '', $price);

    return (float)number_format((float)$price, 2, '.', '');
}

/**
 * Flush WP Redis and WooCommerce product cache after a product update.
 *
 * @param int $product_id WooCommerce product or variation ID.
 * @return void
 */
function baselinker_flush_product_cache($product_id)
{
    // only apply to REST requests
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }

    clean_post_cache($product_id);

    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($product_id, 'posts');
        wp_cache_delete($product_id, 'post_meta');
    }

    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }

    $terms = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);

    if (!empty($terms) && !is_wp_error($terms)) {
        delete_transient('wc_term_counts');

        if (class_exists('WC_Cache_Helper')) {
            WC_Cache_Helper::clean_term_cache($terms, 'product_cat');
        }
    }
}
