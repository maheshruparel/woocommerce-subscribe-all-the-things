<?php
/**
 * Compatibility with other extensions.
 *
 * @class  WCS_ATT_Integrations
 * @since  1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_ATT_Integrations {

	public static $bundle_types        = array();
	public static $container_key_names = array();
	public static $child_key_names     = array();

	public static function init() {

		$bundle_type_exists = false;

		// Bundles.
		if ( class_exists( 'WC_Bundles' ) ) {
			self::$bundle_types[]        = 'bundle';
			self::$container_key_names[] = 'bundled_by';
			self::$child_key_names[]     = 'bundled_items';
			$bundle_type_exists          = true;
		}

		// Composites.
		if ( class_exists( 'WC_Composite_Products' ) ) {
			self::$bundle_types[]        = 'composite';
			self::$container_key_names[] = 'composite_parent';
			self::$child_key_names[]     = 'composite_children';
			$bundle_type_exists          = true;
		}

		// Mix n Match.
		if ( class_exists( 'WC_Mix_and_Match' ) ) {
			self::$bundle_types[]        = 'mix-and-match';
			self::$container_key_names[] = 'mnm_container';
			self::$child_key_names[]     = 'mnm_contents';
			$bundle_type_exists          = true;
		}

		if ( $bundle_type_exists ) {

			/*
			 * All types.
			 */

			// Schemes attached on bundles should not work if the bundle contains non-supported products, such as "legacy" subscription products.
			add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'get_product_bundle_schemes' ), 10, 2 );

			// Hide child cart item options.
			add_filter( 'wcsatt_show_cart_item_options', array( __CLASS__, 'hide_child_item_options' ), 10, 3 );

			// Bundled/child items inherit the active subscription scheme of their parent.
			add_filter( 'wcsatt_set_subscription_scheme_id', array( __CLASS__, 'set_child_item_subscription_scheme' ), 10, 3 );

			// Bundled cart items inherit the subscription schemes of their parent, with some modifications.
			add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'apply_child_item_subscription_schemes' ), 0 );

			// Bundled cart items inherit the subscription schemes of their parent, with some modifications (first add).
			add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'set_child_item_schemes' ), 0, 2 );

			// Add subscription details next to subtotal of per-item-priced bundle-type container cart items.
			add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'add_container_item_subtotal_subscription_details' ), 1000, 3 );

			// Hide bundle container cart item options.
			// add_filter( 'wcsatt_show_cart_item_options', array( __CLASS__, 'hide_container_item_options' ), 10, 3 );

			// Modify bundle container cart item options to include child item prices.
			add_filter( 'wcsatt_cart_item_options', array( __CLASS__, 'container_item_options' ), 10, 4 );

			/*
			 * Bundles.
			 */

			// When a forced-subscription bundle is done syncing, always set the default scheme key on the object.
			add_action( 'woocommerce_before_single_product', array( __CLASS__, 'set_forced_subscription_bundle_scheme' ), 0 );

			// Bundled products inherit the subscription schemes of their container object.
			add_action( 'wcsatt_set_product_subscription_scheme', array( __CLASS__, 'set_product_bundle_scheme' ), 10, 2 );

			/*
			 * Composites.
			 */

			// When a forced-subscription composite is done syncing, always set the default scheme key on the object.
			add_action( 'woocommerce_composite_synced', array( __CLASS__, 'set_forced_subscription_bundle_scheme' ) );

			// Products in component option class inherit the subscription schemes of their container object.
			add_action( 'woocommerce_composite_component_option', array( __CLASS__, 'set_component_option_scheme' ), 10, 3 );

			// Filter the prices of composited products loaded via ajax when the composite has a single subscription option and one-time purchases are disabled.
			// add_action( 'woocommerce_composite_products_apply_product_filters', array( __CLASS__ , 'add_composited_force_sub_price_filters' ), 10, 3 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers/API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks if the passed cart item is a supported bundle type child. Returns the container item key name if yes, or false if not.
	 *
	 * @param  array  $cart_item
	 * @return boolean|string
	 */
	public static function has_bundle_type_container( $cart_item ) {

		$container_key = false;

		foreach ( self::$container_key_names as $container_key_name ) {
			if ( ! empty( $cart_item[ $container_key_name ] ) ) {
				$container_key = $cart_item[ $container_key_name ];
				break;
			}
		}

		return $container_key;
	}

	/**
	 * Checks if the passed cart item is a supported bundle type container. Returns the child item key name if yes, or false if not.
	 *
	 * @param  array  $cart_item
	 * @return boolean|string
	 */
	public static function has_bundle_type_children( $cart_item ) {

		$child_key = false;

		foreach ( self::$child_key_names as $child_key_name ) {
			if ( ! empty( $cart_item[ $child_key_name ] ) ) {
				$child_key = $cart_item[ $child_key_name ];
				break;
			}
		}

		return $child_key;
	}

	/**
	 * Checks if the passed product is of a supported bundle type. Returns the type if yes, or false if not.
	 *
	 * @param  WC_Product  $product
	 * @return boolean|string
	 */
	public static function is_bundle_type_product( $product ) {
		return $product->is_type( self::$bundle_types );
	}

	/**
	 * True if there are sub schemes inherited from a container.
	 *
	 * @param  array  $cart_item
	 * @return boolean
	 */
	private static function has_scheme_data( $cart_item ) {

		$overrides = false;

		if ( isset( $cart_item[ 'wcsatt_data' ][ 'active_subscription_scheme' ] ) && null !== $cart_item[ 'wcsatt_data' ][ 'active_subscription_scheme' ] ) {
			$overrides = true;
		}

		return $overrides;
	}

	/**
	 * WC_Product_Bundle 'contains_sub' back-compat wrapper.
	 *
	 * @param  WC_Product_Bundle  $bundle
	 * @return boolean
	 */
	private static function bundle_contains_subscription( $bundle ) {

		if ( version_compare( WC_PB()->version, '5.0.0' ) < 0 ) {
			return $bundle->contains_sub();
		} else {
			return $bundle->contains( 'subscription' );
		}
	}

	/**
	 * WC_Product_Bundle and WC_Product_Composite 'is_priced_per_product' back-compat wrapper.
	 *
	 * @param  WC_Product  $bundle
	 * @return boolean
	 */
	private static function has_individually_priced_bundled_contents( $product ) {

		if ( 'bundle' === $product->get_type() ) {
			return version_compare( WC_PB()->version, '5.0.0' ) < 0 ? $product->is_priced_per_product() : $product->contains( 'priced_individually' );
		} elseif( 'composite' === $product->get_type() ) {
			return version_compare( WC_CP()->version, '3.7.0' ) < 0 ? $product->is_priced_per_product() : $product->contains( 'priced_individually' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sub schemes attached on a Product Bundle should not work if the bundle contains a non-convertible product, such as a "legacy" subscription.
	 *
	 * @param  array       $schemes
	 * @param  WC_Product  $product
	 * @return array
	 */
	public static function get_product_bundle_schemes( $schemes, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {
			if ( $product->is_type( 'bundle' ) && self::bundle_contains_subscription( $product ) ) {
				$schemes = array();
			} elseif ( $product->is_type( 'mix-and-match' ) && $product->is_priced_per_product() ) { // TODO: Add support for Per-Item Pricing.
				$schemes = array();
			}
		}

		return $schemes;
	}

	/**
	 * Hide bundled cart item subscription options.
	 *
	 * @param  boolean  $show
	 * @param  array    $cart_item
	 * @param  string   $cart_item_key
	 * @return boolean
	 */
	public static function hide_child_item_options( $show, $cart_item, $cart_item_key ) {

		$container_key = self::has_bundle_type_container( $cart_item );

		if ( false !== $container_key ) {
			if ( isset( WC()->cart->cart_contents[ $container_key ] ) ) {
				$container_cart_item = WC()->cart->cart_contents[ $container_key ];
				if ( self::has_scheme_data( $container_cart_item ) ) {
					$show = false;
				}
			}
		}

		return $show;
	}

	/**
	 * Bundled items inherit the active subscription scheme id of their parent.
	 *
	 * @param  string  $scheme_key
	 * @param  array   $cart_item
	 * @param  array   $cart_level_schemes
	 * @return string
	 */
	public static function set_child_item_subscription_scheme( $scheme_key, $cart_item, $cart_level_schemes ) {

		$container_key = self::has_bundle_type_container( $cart_item );

		if ( false !== $container_key && isset( WC()->cart->cart_contents[ $container_key ] ) ) {

			$container_cart_item = WC()->cart->cart_contents[ $container_key ];

			if ( self::has_scheme_data( $container_cart_item ) ) {
				$scheme_key = $container_cart_item[ 'wcsatt_data' ][ 'active_subscription_scheme' ];
			}
		}

		return $scheme_key;
	}

	/**
	 * Bundled cart items inherit the subscription schemes of their parent, with some modifications.
	 *
	 * @param  WC_Cart  $cart
	 * @return void
	 */
	public static function apply_child_item_subscription_schemes( $cart ) {

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {

			// Is it a bundled item?
			$container_key = self::has_bundle_type_container( $cart_item );

			if ( false !== $container_key && isset( WC()->cart->cart_contents[ $container_key ] ) ) {

				$container_cart_item = WC()->cart->cart_contents[ $container_key ];

				if ( self::has_scheme_data( $container_cart_item ) ) {
					self::set_bundled_product_subscription_schemes( $cart_item[ 'data' ], $container_cart_item[ 'data' ] );
				}
			}
		}
	}

	/**
	 * Copies product schemes to a child product.
	 *
	 * @param  WC_Product  $bundled_product
	 * @param  WC_Product  $container_product
	 */
	private static function set_bundled_product_subscription_schemes( $bundled_product, $container_product ) {

		$container_schemes       = WCS_ATT_Product::get_subscription_schemes( $container_product );
		$bundled_product_schemes = WCS_ATT_Product::get_subscription_schemes( $bundled_product );

		// Copy container schemes to child.
		if ( ! empty( $container_schemes ) && array_keys( $container_schemes ) !== array_keys( $bundled_product_schemes ) ) {

			$bundled_product_schemes = array();

			// Modify child object schemes: "Override" pricing mode is only applicable for container.
			foreach ( $container_schemes as $scheme_key => $scheme ) {

				$bundled_product_schemes[ $scheme_key ] = clone $scheme;
				$bundled_product_scheme                 = $bundled_product_schemes[ $scheme_key ];

				if ( $bundled_product_scheme->has_price_filter() && 'override' === $bundled_product_scheme->get_pricing_mode() ) {
					$bundled_product_scheme->set_pricing_mode( 'inherit' );
					$bundled_product_scheme->set_discount( '' );
				}
			}

			WCS_ATT_Product::set_subscription_schemes( $bundled_product, $bundled_product_schemes );
		}

		$container_scheme       = WCS_ATT_Product::get_subscription_scheme( $container_product );
		$bundled_product_scheme = WCS_ATT_Product::get_subscription_scheme( $bundled_product );

		// Set active container scheme on child.
		if ( $container_scheme !== $bundled_product_scheme ) {
			WCS_ATT_Product::set_subscription_scheme( $bundled_product, $container_scheme );
		}
	}

	/**
	 * Bundled cart items inherit the subscription schemes of their parent, with some modifications (first add).
	 *
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return array
	 */
	public static function set_child_item_schemes( $cart_item, $cart_item_key ) {

		// Is it a bundled item?
		$container_key = self::has_bundle_type_container( $cart_item );

		if ( false !== $container_key && isset( WC()->cart->cart_contents[ $container_key ] ) ) {

			$container_cart_item = WC()->cart->cart_contents[ $container_key ];

			if ( self::has_scheme_data( $container_cart_item ) ) {
				self::set_bundled_product_subscription_schemes( $cart_item[ 'data' ], $container_cart_item[ 'data' ] );
			}
		}

		return $cart_item;
	}

	/**
	 * Bundled products inherit the subscription schemes of their container object.
	 *
	 * @param  string      $scheme_key
	 * @param  WC_Product  $product
	 */
	public static function set_product_bundle_scheme( $scheme_key, $product ) {

		if ( $product->is_type( 'bundle' ) ) {

			$bundled_items = $product->get_bundled_items();

			if ( ! empty( $bundled_items ) ) {
				foreach ( $bundled_items as $bundled_item ) {

					if ( $bundled_product = $bundled_item->get_product() ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $product );
					}

					if ( $bundled_product = $bundled_item->get_product( array( 'having' => 'price', 'what' => 'min' ) ) ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $product );
					}

					if ( $bundled_product = $bundled_item->get_product( array( 'having' => 'price', 'what' => 'max' ) ) ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $product );
					}

					if ( $bundled_product = $bundled_item->get_product( array( 'having' => 'regular_price', 'what' => 'min' ) ) ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $product );
					}

					if ( $bundled_product = $bundled_item->get_product( array( 'having' => 'regular_price', 'what' => 'max' ) ) ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $product );
					}
				}
			}


		}
	}

	/**
	 * Composited products inherit the subscription schemes of their container object.
	 *
	 * @param  WC_CP_Product         $component_option
	 * @param  string                $component_id
	 * @param  WC_Product_Composite  $composite
	 */
	public static function set_component_option_scheme( $component_option, $component_id, $composite ) {

		if ( $component_option && $composite->is_synced() ) {

			if ( $product = $component_option->get_product() ) {
				self::set_bundled_product_subscription_schemes( $product, $composite );
			}

			if ( $product = $component_option->get_product( array( 'having' => 'price', 'what' => 'min' ) ) ) {
				self::set_bundled_product_subscription_schemes( $product, $composite );
			}

			if ( $product = $component_option->get_product( array( 'having' => 'price', 'what' => 'max' ) ) ) {
				self::set_bundled_product_subscription_schemes( $product, $composite );
			}

			if ( $product = $component_option->get_product( array( 'having' => 'regular_price', 'what' => 'min' ) ) ) {
				self::set_bundled_product_subscription_schemes( $product, $composite );
			}

			if ( $product = $component_option->get_product( array( 'having' => 'regular_price', 'what' => 'max' ) ) ) {
				self::set_bundled_product_subscription_schemes( $product, $composite );
			}
		}

		return $component_option;
	}

	/**
	 * Add subscription details next to subtotal of per-item-priced bundle-type container cart items.
	 *
	 * @param  string  $subtotal
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public static function add_container_item_subtotal_subscription_details( $subtotal, $cart_item, $cart_item_key ) {

		$child_key = self::has_bundle_type_children( $cart_item );

		if ( false !== $child_key && self::has_scheme_data( $cart_item ) ) {
			$subtotal = WCS_ATT_Product::get_price_string( $cart_item[ 'data' ], array(
				'price' => $subtotal
			) );
		}

		return $subtotal;
	}

	/**
	 * When a forced-subscription bundle is displayed, always set the default scheme key on the object.
	 */
	public static function set_forced_subscription_bundle_scheme() {

		global $product;

		if ( is_a( $product, 'WC_Product' ) && WCS_ATT_Product::has_subscriptions( $product ) && WCS_ATT_Product::has_forced_subscription( $product ) ) {
			WCS_ATT_Product::set_subscription_scheme( $product, WCS_ATT_Product::get_default_subscription_scheme( $product ) );
		}
	}

	/**
	 * Modify bundle container cart item subscription options to include child item prices.
	 *
	 * @param  array   $options
	 * @param  array   $subscription_schemes
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return boolean
	 */
	public static function container_item_options( $options, $subscription_schemes, $cart_item, $cart_item_key ) {

		$product                        = $cart_item[ 'data' ];
		$price_filter_exists            = WCS_ATT_Scheme_Prices::price_filter_exists( $subscription_schemes );
		$force_subscription             = WCS_ATT_Product::has_forced_subscription( $product );
		$active_subscription_scheme_key = WCS_ATT_Product::get_subscription_scheme( $product );
		$scheme_keys                    = array_merge( $force_subscription ? array() : array( false ), array_keys( $subscription_schemes ) );

		if ( $price_filter_exists ) {

			$tax_display_cart = get_option( 'woocommerce_tax_display_cart' );

			foreach ( $scheme_keys as $scheme_key ) {

				$price_key = false === $scheme_key ? '0' : $scheme_key;

				if ( 'excl' === $tax_display_cart ) {
					$bundle_price[ $price_key ] = WCS_ATT_Core_Compatibility::wc_get_price_excluding_tax( $product, array( 'price' => WCS_ATT_Product::get_price( $product, $scheme_key ) ) );
				} else {
					$bundle_price[ $price_key ] = WCS_ATT_Core_Compatibility::wc_get_price_including_tax( $product, array( 'price' => WCS_ATT_Product::get_price( $product, $scheme_key ) ) );
				}

				foreach ( WC()->cart->cart_contents as $child_key => $child_item ) {

					$container_key = self::has_bundle_type_container( $child_item );

					if ( $cart_item_key === $container_key ) {

						$child_qty = ceil( $child_item[ 'quantity' ] / $cart_item[ 'quantity' ] );

						if ( 'excl' === $tax_display_cart ) {
							$bundle_price[ $price_key ] += WCS_ATT_Core_Compatibility::wc_get_price_excluding_tax( $child_item[ 'data' ], array( 'price' => WCS_ATT_Product::get_price( $child_item[ 'data' ], $scheme_key ), 'qty' => $child_qty ) );
						} else {
							$bundle_price[ $price_key ] += WCS_ATT_Core_Compatibility::wc_get_price_including_tax( $child_item[ 'data' ], array( 'price' => WCS_ATT_Product::get_price( $child_item[ 'data' ], $scheme_key ), 'qty' => $child_qty ) );
						}
					}
				}
			}

			// Non-recurring (one-time) option.
			if ( false === $force_subscription ) {

				$options[ '0' ] = array(
					'description' => wc_price( $bundle_price[ '0' ] ),
					'selected'    => false === $active_subscription_scheme_key,
				);
			}

			// Subscription options.
			foreach ( $subscription_schemes as $subscription_scheme ) {

				$subscription_scheme_key = $subscription_scheme->get_key();

				$description = WCS_ATT_Product::get_price_string( $product, array(
					'scheme_key' => $subscription_scheme_key,
					'price'      => wc_price( $bundle_price[ $subscription_scheme_key ] )
				) );

				$options[ $subscription_scheme_key ] = array(
					'description' => $description,
					'selected'    => $active_subscription_scheme_key === $subscription_scheme_key,
				);
			}
		}

		return $options;
	}


	/**
	 * Hide bundle container cart item subscription options.
	 *
	 * @param  boolean  $show
	 * @param  array    $cart_item
	 * @param  string   $cart_item_key
	 * @return boolean
	 */
	public static function hide_container_item_options( $show, $cart_item, $cart_item_key ) {

		if ( self::has_bundle_type_children( $cart_item ) ) {
			$container = $cart_item[ 'data' ];
			if ( self::has_individually_priced_bundled_contents( $container ) ) {
				$show = false;
			}
		}

		return $show;
	}

	/**
	 * Filter the prices of composited products loaded via ajax when the composite has a single subscription option and one-time purchases are disabled.
	 *
	 * @param  WC_Product  $product
	 * @param  int         $composite_id
	 * @param  object      $composite
	 * @return void
	 */
	public static function add_composited_force_sub_price_filters( $product, $composite_id, $composite ) {

		if ( did_action( 'wc_ajax_woocommerce_show_composited_product' ) ) {
			self::maybe_add_force_sub_price_filters( $composite );
		}
	}
}

WCS_ATT_Integrations::init();
