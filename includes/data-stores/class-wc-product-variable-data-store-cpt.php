<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Variable Product Data Store: Stored in CPT.
 *
 * @version  2.7.0
 * @category Class
 * @author   WooThemes
 */
class WC_Product_Variable_Data_Store_CPT extends WC_Product_Data_Store_CPT implements WC_Object_Data_Store_Interface, WC_Product_Variable_Data_Store_Interface {

	/**
	 * Cached & hashed prices array for child variations.
	 *
	 * @var array
	 */
	private $prices_array = array();

	/**
	 * Read product data.
	 *
	 * @since 2.7.0
	 */
	protected function read_product_data( &$product ) {
		parent::read_product_data( $product );
		$this->read_children( $product );

		// Set directly since individual data needs changed at the WC_Product_Variation level -- these datasets just pull.
		$this->read_price_data( $product );
	 	$this->read_price_data( $product, true );
		$this->read_variation_attributes( $product );
	}

	/**
	 * Loads variation child IDs.
	 * @param  WC_Product
	 * @param  bool $force_read True to bypass the transient.
	 * @return WC_Product
	 */
	public function read_children( &$product, $force_read = false ) {
		$children_transient_name = 'wc_product_children_' . $product->get_id();
		$children                = get_transient( $children_transient_name );

		if ( empty( $children ) || ! is_array( $children ) || ! isset( $children['all'] ) || ! isset( $children['visible'] ) || $force_read ) {
			$all_args = $visible_only_args = array(
				'post_parent' => $product->get_id(),
				'post_type'   => 'product_variation',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'fields'      => 'ids',
				'post_status' => 'publish',
				'numberposts' => -1,
			);
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$visible_only_args['tax_query'][] = array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => 'outofstock',
					'operator' => 'NOT IN',
				);
			}
			$children['all']     = get_posts( apply_filters( 'woocommerce_variable_children_args', $all_args, $product, false ) );
			$children['visible'] = get_posts( apply_filters( 'woocommerce_variable_children_args', $visible_only_args, $product, true ) );

			set_transient( $children_transient_name, $children, DAY_IN_SECONDS * 30 );
		}

		$product->set_children( wp_parse_id_list( (array) $children['all'] ) );
		$product->set_visible_children( wp_parse_id_list( (array) $children['visible'] ) );
	}

	/**
	 * Loads an array of attributes used for variations, as well as their possible values.
	 *
	 * @param WC_Product
	 */
	private function read_variation_attributes( &$product ) {
		global $wpdb;

		$variation_attributes = array();
		$attributes           = $product->get_attributes();
		$child_ids            = $product->get_children();

		if ( ! empty( $child_ids ) && ! empty( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				if ( empty( $attribute['is_variation'] ) ) {
					continue;
				}

				// Get possible values for this attribute, for only visible variations.
				$values = array_unique( $wpdb->get_col( $wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . implode( ',', array_map( 'esc_sql', $child_ids ) ) . ")",
					wc_variation_attribute_name( $attribute['name'] )
				) ) );

				// Empty value indicates that all options for given attribute are available.
				if ( in_array( '', $values ) || empty( $values ) ) {
					$values = $attribute['is_taxonomy'] ? wc_get_object_terms( $product->get_id(), $attribute['name'], 'slug' ) : wc_get_text_attributes( $attribute['value'] );
				// Get custom attributes (non taxonomy) as defined.
				} elseif ( ! $attribute['is_taxonomy'] ) {
					$text_attributes          = wc_get_text_attributes( $attribute['value'] );
					$assigned_text_attributes = $values;
					$values                   = array();

					// Pre 2.4 handling where 'slugs' were saved instead of the full text attribute
					if ( version_compare( get_post_meta( $product->get_id(), '_product_version', true ), '2.4.0', '<' ) ) {
						$assigned_text_attributes = array_map( 'sanitize_title', $assigned_text_attributes );
						foreach ( $text_attributes as $text_attribute ) {
							if ( in_array( sanitize_title( $text_attribute ), $assigned_text_attributes ) ) {
								$values[] = $text_attribute;
							}
						}
					} else {
						foreach ( $text_attributes as $text_attribute ) {
							if ( in_array( $text_attribute, $assigned_text_attributes ) ) {
								$values[] = $text_attribute;
							}
						}
					}
				}
				$variation_attributes[ $attribute['name'] ] = array_unique( $values );
			}
		}

		$product->set_variation_attributes( $variation_attributes );
	}

	/**
	 * Get an array of all sale and regular prices from all variations. This is used for example when displaying the price range at variable product level or seeing if the variable product is on sale.
	 *
	 * Can be filtered by plugins which modify costs, but otherwise will include the raw meta costs unlike get_price() which runs costs through the woocommerce_get_price filter.
	 * This is to ensure modified prices are not cached, unless intended.
	 *
	 * @since  2.7.0
	 * @param  WC_Product
	 * @param  bool $include_taxes If taxes should be calculated or not.
	 */
	private function read_price_data( &$product, $include_taxes = false ) {
		global $wp_filter;

		/**
		 * Transient name for storing prices for this product (note: Max transient length is 45)
		 * @since 2.5.0 a single transient is used per product for all prices, rather than many transients per product.
		 */
		$transient_name = 'wc_var_prices_' . $product->get_id();

		/**
		 * Create unique cache key based on the tax location (affects displayed/cached prices), product version and active price filters.
		 * DEVELOPERS should filter this hash if offering conditonal pricing to keep it unique.
		 * @var string
		 */
		$price_hash   = $include_taxes ? array( get_option( 'woocommerce_tax_display_shop', 'excl' ), WC_Tax::get_rates() ) : array( false );
		$filter_names = array( 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' );

		foreach ( $filter_names as $filter_name ) {
			if ( ! empty( $wp_filter[ $filter_name ] ) ) {
				$price_hash[ $filter_name ] = array();

				foreach ( $wp_filter[ $filter_name ] as $priority => $callbacks ) {
					$price_hash[ $filter_name ][] = array_values( wp_list_pluck( $callbacks, 'function' ) );
				}
			}
		}

		$price_hash[] = WC_Cache_Helper::get_transient_version( 'product' );
		$price_hash   = md5( json_encode( apply_filters( 'woocommerce_get_variation_prices_hash', $price_hash, $product, $include_taxes ) ) );

		/**
		 * $this->prices_array is an array of values which may have been modified from what is stored in transients - this may not match $transient_cached_prices_array.
		 * If the value has already been generated, we don't need to grab the values again so just return them. They are already filtered.
		 */
		if ( ! empty( $this->prices_array[ $price_hash ] ) ) {
			if ( $include_taxes ) {
				$product->set_variation_prices_including_taxes( $this->prices_array[ $price_hash ] );
			} else {
				$product->set_variation_prices( $this->prices_array[ $price_hash ] );
			}
		/**
		 * No locally cached value? Get the data from the transient or generate it.
		 */
		} else {
			// Get value of transient
			$transient_cached_prices_array = array_filter( (array) json_decode( strval( get_transient( $transient_name ) ), true ) );

			// If the product version has changed since the transient was last saved, reset the transient cache.
			if ( empty( $transient_cached_prices_array['version'] ) || WC_Cache_Helper::get_transient_version( 'product' ) !== $transient_cached_prices_array['version'] ) {
				$transient_cached_prices_array = array( 'version' => WC_Cache_Helper::get_transient_version( 'product' ) );
			}

			// If the prices are not stored for this hash, generate them and add to the transient.
			if ( empty( $transient_cached_prices_array[ $price_hash ] ) ) {
				$prices         = array();
				$regular_prices = array();
				$sale_prices    = array();
				$variation_ids  = $product->get_visible_children();
				foreach ( $variation_ids as $variation_id ) {
					if ( $variation = wc_get_product( $variation_id ) ) {
						$price         = apply_filters( 'woocommerce_variation_prices_price', $variation->get_price( 'edit' ), $variation, $product );
						$regular_price = apply_filters( 'woocommerce_variation_prices_regular_price', $variation->get_regular_price( 'edit' ), $variation, $product );
						$sale_price    = apply_filters( 'woocommerce_variation_prices_sale_price', $variation->get_sale_price( 'edit' ), $variation, $product );

						// Skip empty prices
						if ( '' === $price ) {
							continue;
						}

						// If sale price does not equal price, the product is not yet on sale
						if ( $sale_price === $regular_price || $sale_price !== $price ) {
							$sale_price = $regular_price;
						}

						// If we are getting prices for display, we need to account for taxes
						if ( $include_taxes ) {
							if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
								$price         = '' === $price ? ''         : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
								$regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
								$sale_price    = '' === $sale_price ? ''    : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
							} else {
								$price         = '' === $price ? ''         : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
								$regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
								$sale_price    = '' === $sale_price ? ''    : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
							}
						}

						$prices[ $variation_id ]         = wc_format_decimal( $price, wc_get_price_decimals() );
						$regular_prices[ $variation_id ] = wc_format_decimal( $regular_price, wc_get_price_decimals() );
						$sale_prices[ $variation_id ]    = wc_format_decimal( $sale_price . '.00', wc_get_price_decimals() );
					}
				}

				asort( $prices );
				asort( $regular_prices );
				asort( $sale_prices );

				$transient_cached_prices_array[ $price_hash ] = array(
					'price'         => $prices,
					'regular_price' => $regular_prices,
					'sale_price'    => $sale_prices,
				);

				set_transient( $transient_name, json_encode( $transient_cached_prices_array ), DAY_IN_SECONDS * 30 );
			}

			/**
			 * Give plugins one last chance to filter the variation prices array which has been generated and store locally to the class.
			 * This value may differ from the transient cache. It is filtered once before storing locally.
			 */
			$this->prices_array[ $price_hash ] = apply_filters( 'woocommerce_variation_prices', $transient_cached_prices_array[ $price_hash ], $product, $include_taxes );

			if ( $include_taxes ) {
				$product->set_variation_prices_including_taxes( $this->prices_array[ $price_hash ] );
			} else {
				$product->set_variation_prices( $this->prices_array[ $price_hash ] );
			}
		}
	}

	/**
	 * Does a child have a weight set?
	 *
	 * @since 2.7.0
	 * @param WC_Product
	 * @return boolean
	 */
	public function child_has_weight( $product ) {
		global $wpdb;
		$children = $product->get_visible_children( 'edit' );
		return $children ? $wpdb->get_var( "SELECT 1 FROM $wpdb->postmeta WHERE meta_key = '_weight' AND meta_value > 0 AND post_id IN ( " . implode( ',', array_map( 'absint', $children ) ) . " )" ) : false;
	}

	/**
	 * Does a child have dimensions set?
	 *
	 * @since 2.7.0
	 * @param WC_Product
	 * @return boolean
	 */
	public function child_has_dimensions( $product ) {
		global $wpdb;
		$children = $product->get_visible_children( 'edit' );
		return $children ? $wpdb->get_var( "SELECT 1 FROM $wpdb->postmeta WHERE meta_key IN ( '_length', '_width', '_height' ) AND post_id IN ( " . implode( ',', array_map( 'absint', $children ) ) . " )" ) : false;
	}

	/**
	 * Is a child in stock?
	 *
	 * @since 2.7.0
	 * @param WC_Product
	 * @return boolean
	 */
	public function child_is_in_stock( $product ) {
		global $wpdb;
		$children = $product->get_visible_children( 'edit' );
		return $children ? $wpdb->get_var( "SELECT 1 FROM $wpdb->postmeta WHERE meta_key = '_stock_status' AND meta_value = 'instock' AND post_id IN ( " . implode( ',', array_map( 'absint', $children ) ) . " )" ) : false;
	}

	/**
	 * Stock managed at the parent level - update children being managed by this product.
	 * This sync function syncs downwards (from parent to child) when the variable product is saved.
	 *
	 * @param WC_Product
	 * @since 2.7.0
	 */
	public function sync_managed_variation_stock_status( &$product ) {
		global $wpdb;

		if ( $product->get_manage_stock() ) {
			$status           = $product->get_stock_status();
			$children         = $product->get_children();
			$managed_children = $children ? array_unique( $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_manage_stock' AND meta_value != 'yes' AND post_id IN ( " . implode( ',', array_map( 'absint', $children ) ) . " )" ) ) : array();
			$changed          = false;
			foreach ( $managed_children as $managed_child ) {
				if ( update_post_meta( $managed_child, '_stock_status', $status ) ) {
					$changed = true;
				}
			}
			if ( $changed ) {
				$this->read_children( $product, true );
			}
		}
	}

	/**
	 * Sync variable product prices with children.
	 *
	 * @since 2.7.0
	 * @param WC_Product|int $product
	 */
	public function sync_price( &$product ) {
		global $wpdb;

		$children = $product->get_visible_children( 'edit' );
		$prices   = $children ? array_unique( $wpdb->get_col( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_price' AND post_id IN ( " . implode( ',', array_map( 'absint', $children ) ) . " )" ) ) : array();

		delete_post_meta( $product->get_id(), '_price' );

		if ( $prices ) {
			sort( $prices );
			// To allow sorting and filtering by multiple values, we have no choice but to store child prices in this manner.
			foreach ( $prices as $price ) {
				add_post_meta( $product->get_id(), '_price', $price, false );
			}
		}
	}

	/**
	 * Sync variable product stock status with children.
	 * Change does not persist unless saved by caller.
	 *
	 * @since 2.7.0
	 * @param WC_Product|int $product
	 */
	public function sync_stock_status( &$product ) {
		$product->set_stock_status( $product->child_is_in_stock() ? 'instock' : 'outofstock' );
	}
}
