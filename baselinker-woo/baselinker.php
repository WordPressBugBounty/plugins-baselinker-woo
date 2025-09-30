<?php
/**
 * @package BaseLinker
 * @version 1.0.27
 */
/*
Plugin Name: BaseLinker-Woo
Plugin URI: https://developers.baselinker.com/shops_api/extensions/
Description: This modules offers faster WooCommerce product synchronizations to BaseLinker, improved offer filtering and order searching.  A must-have for any BaseLinker user.
Text Domain:  baselinker-woo
Domain Path: /languages
Author: BaseLinker
Version: 1.0.27
Author URI: http://baselinker.com/
License: GPLv3 or later
*/

if (!defined('ABSPATH'))
{
	exit; // Exit if accessed directly
}

function baselinker_version($data)
{
	return '1.0.27';
}

// adds delivery point data from Packetery and some other plugins
function baselinker_prepare_shop_order($response, $post, $request)
{
	global $wpdb;

	if (empty($response->data))
	{
		return $response;
	}

	// packeta
	if ($wpdb->get_row("SHOW TABLES LIKE '{$wpdb->prefix}packetery_order'"))
	{
		if ($result = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}packetery_order` 
WHERE `id` = %s", (int)$post->get_id())))
		{
			$response->data['bl_delivery_point_id'] = $result->point_id;
			$response->data['bl_delivery_point_name'] = $result->point_name;
			$response->data['bl_delivery_point_city'] = $result->point_city;
			$response->data['bl_delivery_point_postcode'] = $result->point_zip;
			$response->data['bl_delivery_point_address'] = $result->point_street;
		}
	}

	// polkurier
	if (!empty($response->data['shipping_lines'][0]['method_title'])
		and preg_match('/orlen/i', $response->data['shipping_lines'][0]['method_title']))
	{
		if ($result = $wpdb->get_row($wpdb->prepare("SELECT meta_value FROM `{$wpdb->prefix}postmeta` WHERE `post_id` = %s AND `meta_key` = '_polkurier_point_id'", (int)$post->get_id())))
		{
			$response->data['bl_delivery_point_id'] = $result->meta_value;
			$response->data['bl_delivery_point_name'] = $result->meta_value;

			if ($result = $wpdb->get_row($wpdb->prepare("SELECT meta_value FROM `{$wpdb->prefix}postmeta` WHERE `post_id` = %s AND `meta_key` = '_polkurier_point_label'", (int)$post->get_id())))
			{
				if (preg_match('/^(.+?)\r?\n(\d\d-\d{3}) (.+?)\r?\n(.+)/s', $result->meta_value, $m))
				{
					$response->data['bl_delivery_point_address'] = $m[1];
					$response->data['bl_delivery_point_postcode'] = $m[2];
					$response->data['bl_delivery_point_city'] = $m[3];
					$response->data['bl_delivery_point_name'] = $m[4];
				}
			}
		}
	}

	$q = array('post_parent' => $post->get_id(), 'post_type' => 'shipment');
	$shipments = get_children($q);

	if (!empty($shipments))
	{
		$shipment = array_shift($shipments);
		$response->data['shipment_meta'] = serialize(get_post_meta($shipment->ID));
	}

	return $response;
}

// additional handling of Paczkomaty parcel locker ID
function baselinker_insert_shop_order($object, $request, $create)
{
	if (!$create)
	{
		return;
	}

	$locker_id = false;

	if (!empty($request['meta_data']))
	{
		foreach ($request['meta_data'] as $meta)
		{
			if ($meta['key'] == '_paczkomat_id' and !empty($meta['value']))
			{
				$locker_id = $meta['value'];
				break;
			}
		}
	}

	if (!$locker_id)
	{
		return;
	}

	wp_insert_post(array(
		'post_type' => 'shipment',
		'post_status' => 'fs-new',
		'post_parent' => $object->get_id(),
		'meta_input' => array(
			'_paczkomat_id' => $locker_id,
			'_integration' => 'paczkomaty',
		),
	));
}

// search orders by the number stored in a meta field
function baselinker_query_by_order_number($args, $request)
{
	if (isset($request['order_number']) and (!empty(intval($request['order_number'])) or $request['order_number'] == '%'))
	{
		$args['meta_key'] = isset($request['order_number_meta']) ? $request['order_number_meta'] : '_order_number';

		if ($request['order_number'] == '%')
		{
			$args['meta_value'] = '';
			$args['compare'] = 'LIKE';
		}
		else
		{
			$args['meta_value'] = intval($request['order_number']);
		}
	}

	return $args;
}

// compile a list of all shipping methods available in the store
function baselinker_shipping_methods($data)
{
	$result = array();
	$api_methods = WC()->shipping->get_shipping_methods();
	$ext_methods = array();

	foreach ($api_methods as $id => $m)
	{
		if ($m->enabled == 'yes')
		{
			if ($id == 'flexible_shipping')
			{
				if ($rates = get_option('flexible_shipping_rates'))
				{
					foreach ($rates as $rid => $rate)
					{
						$ext_methods["$id:$rid"] = $m->method_title . ' - ' . $rate['title'];
					}
				}

			}

			$ext_methods[$id] = $m->method_title;
		}
	}


	return $ext_methods;
}

// check for custom order statuses
function baselinker_additional_order_statuses($data)
{
	if ($statuses = get_option('wcj_orders_custom_statuses_array'))
	{
		return $statuses;
	}

	return array();
}

// retrieve a list of products matching given search criteria
function baselinker_product_list($data)
{
	$products = array();
	$page = 1;
	$cutoff_limit = 9999999;
	$args = array('status' => 'publish', 'limit' => 100, 'paginate' => true, 'orderby' => 'name', 'order' => 'ASC');

	if (isset($data['limit']) and (int)$data['limit'] > 0)
	{
		$cutoff_limit = (int)$data['limit'];

		if ($cutoff_limit < $args['limit'])
		{
			$args['limit'] = $cutoff_limit;
		}
	}

	if (!empty($data['cutoff']))
	{
		$cutoff_limit = (int)$data['cutoff'];
	}

	if (isset($data['order']) and preg_match('/^(asc|desc)$/i', $data['order']))
	{
		$args['order'] = strtoupper($data['order']);
	}

	if (isset($data['orderby']) and preg_match('/^(ID|name|none|date|modified)$/', $data['orderby']))
	{
		$args['orderby'] = $data['orderby'];
	}
	else
	{
		$args['orderby'] = 'ID';
		$args['order'] = 'desc';
	}

	if (isset($data['offset']))
	{
		$page = ceil(((int)$data['offset']+1)/$args['limit']);
		unset($data['offset']);
	}

	if (isset($data['lang']) and preg_match('/^\w+/', $data['lang']))
	{
		$args['lang'] = $data['lang'];
	}

	if (isset($data['exclude']) and preg_match('/^\d[\d,]*$/', $data['exclude']))
	{
		$args['exclude'] = explode(',', $data['exclude']);
	}

	if (isset($data['include']) and preg_match('/^\d[\d,]*$/', $data['include']))
	{
		$args['include'] = explode(',', $data['include']);
	}

	if (isset($data['type']) and in_array($data['type'], ['external', 'grouped', 'simple', 'variable']))
	{
		$args['type'] = $data['type'];
	}

	if (isset($data['parent']))
	{
		$args['parent'] = (int)$data['parent'];
	}

	if (isset($data['status']))
	{
		$args['status'] = preg_replace('/[^\w\s]/', '', $data['status']);
	}

	if (isset($data['qty_fld']) and $data['qty_fld'] == 'stock_quantity')
	{
		unset($data['qty_fld']);
	}

	if (isset($data['category_id']) and (int)$data['category_id'] > 0)
	{
		foreach (baselinker_category_list($data) as $cat)
		{
			if ($cat->term_id == $data['category_id'])
			{
				$args['category'] = $cat->slug;
				break;
			}
		}
	}

	if (!empty($data['min_stock']))
	{
		$args['min_stock'] = $data['min_stock'];
	}

	if (!empty($data['max_stock']))
	{
		$args['max_stock'] = $data['max_stock'];
	}

	if (!empty($data['min_price']))
	{
		$args['min_price'] = $data['min_price'];
	}

	if (!empty($data['max_price']))
	{
		$args['max_price'] = $data['max_price'];
	}

	if (!empty($data['with_variants']))
	{
		$atts = array();

		foreach (wc_get_attribute_taxonomies() as $att)
		{
			$atts['pa_' . $att->attribute_name] = $att->attribute_id;
		}
	}
		
	do {
		$args['page'] = $page;
		$res = wc_get_products($args);

		if (!is_object($res) and !isset($res->products))
		{
			break;
		}

		foreach ($res->products as $prod)
		{
			unset($variations);

			if (!$prod->get_parent_id() or (isset($data['type']) and $data['type'] == 'variation'))
			{
				$attributes = array();

				if ($prod->get_type() != 'variation')
				{
					foreach ($prod->get_attributes() as $attr)
					{
						if ($attr->is_taxonomy())
						{
							$tobj = $attr->get_taxonomy_object();
							$attributes[] = array('name' => (is_object($tobj) and !empty($tobj->attribute_label)) ? $tobj->attribute_label : $attr->get_taxonomy(), 'options' => wc_get_product_terms($prod->get_id(), $attr->get_name(), array('fields' => 'names'))
	);
						}
						else
						{
							$attributes[] = array('name' => $attr->get_name(), 'options' => $attr->get_options());
						}
					}
				}

				$quantity = 0;

				if ($prod->get_stock_status() == 'instock')
				{
					if (!empty($data['qty_fld']))
					{
						$full_data = $prod->get_data();

						if (isset($full_data[$data['qty_fld']]))
						{
							$quantity = (int)$full_data[$data['qty_fld']];
						}
						else
						{
							foreach ($data['meta_data'] as $meta)
							{
								if ($meta['key'] == $data['qty_fld'])
								{
									$quantity = (int)$meta['value'];
								}
							}
						}
					}
					// collate individual variant quantities into the parent product quantity
					elseif ($prod->get_type() == 'variable')
					{
						if ($variations = $prod->get_available_variations())
						{
							foreach ($variations as $v)
							{
								if ($v['is_in_stock'] and !empty($v['max_qty']))
								{
									$quantity += (int)$v['max_qty'];
								}
							}
						}
					}
					else
					{
						$quantity = $prod->get_manage_stock() ? (int)$prod->get_stock_quantity() : 1;
					}
				}
				
				$products[$prod->get_id()] = array(
					'name' => $prod->get_title(),
					'sku' => $prod->get_sku(),
					'price' => $prod->get_price(),
					'quantity' => $quantity,
					'regular_price' => $prod->get_regular_price(),
					'tax_class' => $prod->get_tax_class(),
					'meta_data' => $prod->get_meta_data(),
					'attributes' => $attributes,
					'baselinker_variations' => array(),
				);

				if (!empty($data['with_variants']) and $prod->get_type() == 'variable')
				{
					if ($variation_ids = $prod->get_children())
					{
						while ($variations_subset = array_splice($variation_ids, 0, 100))
						{
							$prod_variations = baselinker_product_list(array('include' => implode(',', $variations_subset), 'type' => 'variation', 'limit' => $args['limit']));

							foreach ($prod_variations as $vid => $v)
							{
								unset($v['baselinker_variations']);
								$products[$prod->get_id()]['baselinker_variations'][$vid] = $v;
							}
						}
					}
				}
			}

			if (count($products) >= $cutoff_limit)
			{
				return $products;
			}
		}
	} while ($page++ < $res->max_num_pages);

	return $products;
}

// manipulating product data before passing it to BaseLinker
function baselinker_prepare_product($response, $object, $request)
{
	$variations = array();
	static $atts;

	// translating multi-lingual attributes not automatically translated by WPML
	if (isset($response->data['lang']) and is_array($response->data['attributes']))
	{
		foreach ($response->data['attributes'] as $i => $a)
		{
			$response->data['attributes'][$i]['name'] = str_replace('taxonomy singular name: ', '', apply_filters('wpml_translate_single_string', 'taxonomy singular name: '.$a['name'], 'WordPress', 'taxonomy singular name: '.$a['name'], $response->data['lang']));
		}
	}

	if (isset($response->data['variations']) and !empty($response->data['variations']))
	{
		// building a table mapping attribute names to their IDs
		if (!isset($atts))
		{
			$atts = array();

			foreach (wc_get_attribute_taxonomies() as $att)
			{
				$atts['pa_' . $att->attribute_name] = $att->attribute_id;
			}
		}

		foreach ($response->data['variations'] as $variation_id)
		{
			if ($variation = new WC_Product_Variation($variation_id))
			{
				$vimage = wp_get_attachment_image_src(get_post_thumbnail_id($variation_id), 'full', false);
				$vimage = isset($vimage[0]) ? $vimage[0] : '';
				$attributes = $variation->get_attributes();

				foreach ($attributes as $name => $val)
				{
					$name_orig = $name;

					if ($term = get_term_by('slug', $val, $name))
					{
						$val = $term->name;

						$s = get_taxonomies(array('name' => $term->taxonomy), 'objects');

						if (isset($s[$name]))
						{
							$name = $s[$name]->label;
						}
					}

					$attributes[] = array('id' => isset($atts[$name_orig]) ? $atts[$name_orig] : '-1', 'name' => $name, 'option' => $val);
					unset($attributes[$name_orig]);
				}

				$vmeta = get_post_meta($variation_id);

				foreach ($vmeta as $meta_key => $meta_value)
				{
					$vmeta[] = array('key' => $meta_key, 'value' => implode('|', $meta_value));
					unset($vmeta[$meta_key]);
				}

				$dimensions = array();

				if (($dim = (int)$variation->get_width()) > 0)
				{
					$dimensions['width'] = $dim;
				}

				if (($dim = (int)$variation->get_length()) > 0)
				{
					$dimensions['length'] = $dim;
				}

				if (($dim = (int)$variation->get_height()) > 0)
				{
					$dimensions['height'] = $dim;
				}

				$variations[] = array(
					'id' => $variation_id,
					'sku' => $variation->get_sku(),
					'in_stock' => $variation->is_in_stock(),
					'stock_quantity' => (string)$variation->get_stock_quantity(),
					'price'  => (float)$variation->get_price(),
					'regular_price' => (float)$variation->get_regular_price(),
					'sale_price'  => (float)$variation->get_sale_price(),
					'description' => $variation->get_description(),
					'visible'  => (bool)$variation->is_visible(),
					'manage_stock'  => (bool)$variation->get_manage_stock(),
					'purchasable'  => (bool)$variation->is_purchasable(),
					'on_sale'  => (bool)$variation->is_on_sale(),
					'image' => array('id' => $vimage ? -1 : 0, 'src' => $vimage),
					'attributes' => $attributes,
					'weight' => (string)$variation->get_weight(),
					'dimensions' => $dimensions,
					'meta_data' => $vmeta,
				);
			}
		}
	}
	
	$response->data['baselinker_variations'] = $variations;
	$response->data['baselinker_prod_version'] = '1.0.25';

	return $response;
}

// complete list of product categories
function baselinker_category_list($data)
{
	$categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));

	return $categories;
}

// filtering products by the occurence of the given phrase in their names
function baselinker_name_search($search, $wp_query)
{
	global $wpdb;

	if (!empty($wp_query->query_vars['search_terms']))
	{
		$qv = $wp_query->query_vars;
		$new_search = array();
		
		foreach ($qv['search_terms'] as $term)
		{
			$new_search[] = $wpdb->prepare("$wpdb->posts.post_title LIKE %s", '%' . $wpdb->esc_like($term) . '%');
		}

		$search = (empty($search) ? '' : "$search AND ") . implode(' AND ', $new_search);
	}

	return $search;
}

// modifications to the default /products endpoint
function baselinker_product_object_query($args, $request)
{
	// full text search
	$find = $request->get_param('search');

	if (isset($find) and !empty($find))
	{
		$args['s'] = esc_attr($find);
	}

	// searching by EAN
	$find_ean = $request->get_param('search_ean');
	$find_ean_meta = $request->get_param('search_ean_meta');

	if (isset($find_ean) and isset($find_ean_meta) and !empty($find_ean_meta))
	{
		$args['meta_key'] = $find_ean_meta;
		$args['meta_value'] = $find_ean;
	}

	// categories excluded in baselinker
	if ($cats_exclude = $request->get_param('categories_exclude'))
	{
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'product_cat',
				'terms' => explode(',', $cats_exclude),
				'field' => 'term_id',
				'operator' => 'NOT IN',
			),
		);
	}

	// apply filter
	add_filter('posts_search', 'baselinker_name_search', 100, 2);
	return $args;
}

function baselinker_authenticate()
{
	$auth = new WC_REST_Authentication();
	return $auth->authenticate(false) ? true : false;
}

function baselinker_custom_query_vars($query, $query_vars)
{
	// quantity brackets
	if (!empty($query_vars['min_stock']) or !empty($query_vars['max_stock']))
	{
		$min_stock = $query_vars['min_stock'] ?? null;
		$max_stock = $query_vars['max_stock'] ?? null;

		$query['meta_query'][] = [
			'key' => '_stock',
			'value' => [isset($min_stock) ? (int)$min_stock : 0, isset($max_stock) ? (int)$max_stock : 99999999],
			'compare' => 'BETWEEN',
			'type' => 'numeric',
		];
	}

	// price brackets
	if (!empty($query_vars['min_price']) or !empty($query_vars['max_price']))
	{
		$min_price = $query_vars['min_price'] ?? null;
		$max_price = $query_vars['max_price'] ?? null;

		$query['meta_query'][] = [
			'key' => '_price',
			'value' => [isset($min_price) ? (float)$min_price : 0, isset($max_price) ? (float)$max_price : 99999999],
			'compare' => 'BETWEEN',
			'type' => 'numeric',
		];
	}

	return $query;
}


// defining additional REST API endpoints
add_action('rest_api_init', function() {
	register_rest_route('bl/v2', '/shipping_methods/', array('methods' => 'GET', 'callback' => 'baselinker_shipping_methods', 'permission_callback' => '__return_true'));
	register_rest_route('wc-bl/v2', '/product_list/', array('methods' => 'GET', 'callback' => 'baselinker_product_list', 'permission_callback' => 'baselinker_authenticate'));
	register_rest_route('wc-bl/v2', '/category_list/', array('methods' => 'GET', 'callback' => 'baselinker_category_list', 'permission_callback' => 'baselinker_authenticate'));
	register_rest_route('bl/v2', '/additional_order_statuses/', array('methods' => 'GET', 'callback' => 'baselinker_additional_order_statuses', 'permission_callback' => '__return_true'));
	register_rest_route('bl/v2', '/version/', array('methods' => 'GET', 'callback' => 'baselinker_version', 'permission_callback' => '__return_true'));
});


add_action('before_woocommerce_init', function() {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class))
	{
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('plugins_loaded', function() {
	load_plugin_textdomain('baselinker-woo', FALSE, basename(dirname(__FILE__)) . '/languages/');
});

add_filter('woocommerce_rest_prepare_shop_order_object', 'baselinker_prepare_shop_order', 10, 3);
add_filter('woocommerce_rest_insert_shop_order_object', 'baselinker_insert_shop_order', 10, 3);
add_filter('woocommerce_rest_shop_order_object_query', 'baselinker_query_by_order_number', 10, 2);
add_filter('woocommerce_rest_prepare_product_object', 'baselinker_prepare_product', 20, 3);
add_filter('woocommerce_rest_product_object_query', 'baselinker_product_object_query', 10, 2);
add_filter('woocommerce_product_data_store_cpt_get_products_query', 'baselinker_custom_query_vars', 10, 2 );
?>
