<?php
/**
 * WPMMCC WooCommerce Adapter (WP-A-13, doc 05 §2.1).
 *
 * Implements bidirectional synchronization for WooCommerce simple & variable products,
 * master-child variations, attribute taxonomies, pricing matrices, and inventory.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;
use WPMMCC\Sync\Asset_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Woocommerce
 */
class Adapter_Woocommerce implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'woocommerce';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array( 'product', 'product_variation' );
	}

	/**
	 * Check if WooCommerce is active.
	 */
	public function is_plugin_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Export WooCommerce product data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$product_type = 'simple';
		$terms        = wp_get_post_terms( $post_id, 'product_type' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$product_type = $terms[0]->slug;
		}

		$attributes = get_post_meta( $post_id, '_product_attributes', true );
		if ( '' !== $attributes && false !== $attributes && ! is_array( $attributes ) ) {
			// G-20: corrupt attribute matrix — audit instead of silently exporting empty attributes.
			Adapter_Registry::record_parse_error(
				'woocommerce',
				$post_id,
				(string) $post->post_type,
				'_product_attributes could not be decoded into an attribute matrix',
				'outbound'
			);
		}

		$payload = array(
			'product_type' => $product_type,
			'sku'          => (string) get_post_meta( $post_id, '_sku', true ),
			'price'        => (string) get_post_meta( $post_id, '_price', true ),
			'regular_price'=> (string) get_post_meta( $post_id, '_regular_price', true ),
			'sale_price'   => (string) get_post_meta( $post_id, '_sale_price', true ),
			'stock_status' => (string) get_post_meta( $post_id, '_stock_status', true ) ?: 'instock',
			'manage_stock' => (string) get_post_meta( $post_id, '_manage_stock', true ) ?: 'no',
			'attributes'   => is_array( $attributes ) ? $attributes : array(),
			'gallery_ids'  => (string) get_post_meta( $post_id, '_product_image_gallery', true ),
			'variations'   => array(),
		);

		// If variable product, export child product_variation entities
		if ( 'variable' === $product_type ) {
			$variation_posts = get_posts(
				array(
					'post_type'   => 'product_variation',
					'post_parent' => $post_id,
					'numberposts' => -1,
					'post_status' => array( 'publish', 'private' ),
				)
			);

			foreach ( $variation_posts as $var_post ) {
				$var_meta = get_post_meta( $var_post->ID );
				$cleaned_var_meta = array();
				foreach ( $var_meta as $k => $v ) {
					if ( \WPMMCC\Sync\Write_Policy::is_local_meta( (string) $k ) ) {
						continue;
					}
					$cleaned_var_meta[ $k ] = is_array( $v ) && 1 === count( $v ) ? $v[0] : $v;
				}

				$payload['variations'][] = array(
					'slug'          => $var_post->post_name,
					'menu_order'    => $var_post->menu_order,
					'sku'           => $cleaned_var_meta['_sku'] ?? '',
					'price'         => $cleaned_var_meta['_price'] ?? '',
					'regular_price' => $cleaned_var_meta['_regular_price'] ?? '',
					'sale_price'    => $cleaned_var_meta['_sale_price'] ?? '',
					'stock_status'  => $cleaned_var_meta['_stock_status'] ?? 'instock',
					'meta_fields'   => $cleaned_var_meta,
				);
			}
		}

		return $payload;
	}

	/**
	 * Import and materialize WooCommerce product on target site.
	 *
	 * @param int   $target_post_id Target product post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		$product_type = $payload['product_type'] ?? 'simple';

		// 1. Assign product_type term
		if ( function_exists( 'wp_set_object_terms' ) && \WPMMCC\Sync\Write_Policy::allows_field( 'product_type', $context ) ) {
			wp_set_object_terms( $target_post_id, $product_type, 'product_type' );
		}

		// 2. Set pricing, SKU, and stock meta
		if ( isset( $payload['sku'] ) ) {
			update_post_meta( $target_post_id, '_sku', sanitize_text_field( $payload['sku'] ) );
		}
		if ( isset( $payload['price'] ) ) {
			update_post_meta( $target_post_id, '_price', sanitize_text_field( $payload['price'] ) );
		}
		if ( isset( $payload['regular_price'] ) ) {
			update_post_meta( $target_post_id, '_regular_price', sanitize_text_field( $payload['regular_price'] ) );
		}
		if ( isset( $payload['sale_price'] ) ) {
			update_post_meta( $target_post_id, '_sale_price', sanitize_text_field( $payload['sale_price'] ) );
		}
		if ( isset( $payload['stock_status'] ) ) {
			update_post_meta( $target_post_id, '_stock_status', sanitize_text_field( $payload['stock_status'] ) );
		}
		if ( isset( $payload['manage_stock'] ) ) {
			update_post_meta( $target_post_id, '_manage_stock', sanitize_text_field( $payload['manage_stock'] ) );
		}

		// 3. Set product attributes
		if ( ! empty( $payload['attributes'] ) && is_array( $payload['attributes'] ) ) {
			update_post_meta( $target_post_id, '_product_attributes', $payload['attributes'] );
		} elseif ( ! empty( $payload['attributes'] ) ) {
			// G-20: inbound attribute matrix is not decodable as an array.
			Adapter_Registry::record_parse_error(
				'woocommerce',
				$target_post_id,
				'product',
				'Inbound _product_attributes payload is not an array'
			);
		}

		// 4. Cascade synchronize variable product child variations
		$child_context = \WPMMCC\Sync\Write_Policy::child_context( 'variations', $context );
		if ( 'variable' === $product_type && ! empty( $payload['variations'] ) && null !== $child_context ) {
			// Get existing target variations
			$existing_vars = get_posts(
				array(
					'post_type'   => 'product_variation',
					'post_parent' => $target_post_id,
					'numberposts' => -1,
					'post_status' => 'any',
				)
			);
			$existing_by_sku = array();
			foreach ( $existing_vars as $ev ) {
				$sku = (string) get_post_meta( $ev->ID, '_sku', true );
				if ( '' !== $sku ) {
					$existing_by_sku[ $sku ] = $ev->ID;
				}
			}

			foreach ( $payload['variations'] as $v_data ) {
				$v_sku = $v_data['sku'] ?? '';
				$var_post_data = array(
					'post_title'  => 'Variation #' . $v_sku,
					'post_status' => 'publish',
					'post_type'   => 'product_variation',
					'post_parent' => $target_post_id,
					'menu_order'  => (int) ( $v_data['menu_order'] ?? 0 ),
				);

				if ( '' !== $v_sku && isset( $existing_by_sku[ $v_sku ] ) ) {
					$var_post_data['ID'] = $existing_by_sku[ $v_sku ];
					$existing           = get_post( $var_post_data['ID'] );
					if ( $existing ) {
						$var_post_data['post_status'] = $existing->post_status;
						foreach ( array( 'post_title', 'menu_order' ) as $field ) {
							if ( ! \WPMMCC\Sync\Write_Policy::allows_field( $field, $child_context ) ) {
								$var_post_data[ $field ] = $existing->$field;
							}
						}
					}
					$var_id = wp_update_post( $var_post_data );
				} else {
					$var_id = wp_insert_post( $var_post_data );
				}

				if ( ! is_wp_error( $var_id ) && $var_id > 0 ) {
					// Ingestion suppresses CDC save hooks; child identity is
					// nevertheless generated and stored by the receiver.
					if ( '' === \WPMMCC\Sync\Event_Recorder::resolve_or_create_canonical_uuid( 'post', $var_id ) ) {
						return false;
					}
					// Apply variation meta
					if ( ! empty( $v_data['meta_fields'] ) && is_array( $v_data['meta_fields'] ) ) {
						foreach ( $v_data['meta_fields'] as $mk => $mv ) {
							if ( ! \WPMMCC\Sync\Write_Policy::allows_meta( (string) $mk, $child_context ) ) {
								continue;
							}
							update_post_meta( $var_id, $mk, wp_slash( $mv ) );
						}
					}
				}
			}

			// Refresh WooCommerce variable product price cache
			delete_transient( 'wc_var_prices_' . $target_post_id );
			if ( class_exists( '\WC_Product_Variable' ) ) {
				\WC_Product_Variable::sync( $target_post_id );
			}
		}

		return true;
	}

	/**
	 * Translatable fields for WooCommerce.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'post_title',
			'post_content',
			'post_excerpt',
			'_purchase_note',
		);
	}
}
