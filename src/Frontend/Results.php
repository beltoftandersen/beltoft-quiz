<?php

namespace Bgq\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a result config into the payload the player shows.
 */
class Results {

	public static function init() {
		add_filter( 'bgq_render_result', [ __CLASS__, 'filter_render' ], 10, 3 );
	}

	/**
	 * @param array|null $rendered Default payload.
	 * @param array      $config   Quiz config.
	 * @param array|null $result   Result config.
	 * @return array|null
	 */
	public static function filter_render( $rendered, array $config, $result ) {
		return $result ? self::render( $config, (string) $result['id'] ) : $rendered;
	}

	/**
	 * Render a result by id.
	 *
	 * @return array|null { id, title, text, image, button_label, button_url, product }
	 */
	public static function render( array $config, string $result_id ) {
		$result = null;
		foreach ( $config['results'] as $r ) {
			if ( $r['id'] === $result_id ) {
				$result = $r;
				break;
			}
		}
		if ( ! $result ) {
			return null;
		}

		return [
			'id'           => $result['id'],
			'title'        => $result['title'],
			'text'         => wp_kses_post( (string) ( $result['text'] ?? '' ) ),
			'image'        => ! empty( $result['image_id'] ) ? (string) wp_get_attachment_image_url( (int) $result['image_id'], 'large' ) : '',
			'button_label' => (string) ( $result['button_label'] ?? '' ),
			'button_url'   => (string) ( $result['button_url'] ?? '' ),
			'product'      => self::product( (int) ( $result['product_id'] ?? 0 ) ),
		];
	}

	/**
	 * WooCommerce product card data, when WooCommerce is active and the product is purchasable.
	 *
	 * @return array|null
	 */
	private static function product( int $product_id ) {
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return null;
		}
		$image_id = $product->get_image_id();

		return [
			'id'              => $product->get_id(),
			'name'            => $product->get_name(),
			'price_html'      => wp_kses_post( $product->get_price_html() ),
			'image'           => $image_id ? (string) wp_get_attachment_image_url( (int) $image_id, 'medium' ) : '',
			'url'             => (string) $product->get_permalink(),
			'add_to_cart_url' => $product->is_purchasable() && $product->is_in_stock() && 'simple' === $product->get_type()
				? add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() )
				: '',
		];
	}
}
