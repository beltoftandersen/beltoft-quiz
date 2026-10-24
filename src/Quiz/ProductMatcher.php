<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Applies Product Finder boost/exclude rules to selected answers.
 */
class ProductMatcher {

	/**
	 * @return array{ids:int[],scores:array<int,int>,reasons:array<int,string[]>,excluded:int[]}
	 */
	public static function match( array $config, array $answers, string $result_id ): array {
		$scores   = [];
		$order    = [];
		$reasons  = [];
		$excluded = [];

		foreach ( $config['questions'] as $question ) {
			$selected = array_map( 'strval', (array) ( $answers[ $question['id'] ] ?? [] ) );
			foreach ( $question['answers'] as $answer ) {
				if ( ! in_array( $answer['id'], $selected, true ) ) {
					continue;
				}
				$effects = (array) ( $answer['effects'] ?? [] );
				if ( ! $effects ) {
					foreach ( (array) ( $answer['products'] ?? [] ) as $product_id ) {
						$effects[] = [ 'target' => 'product', 'product_id' => $product_id, 'action' => 'boost', 'weight' => 1 ];
					}
				}
				foreach ( $effects as $effect ) {
					foreach ( self::effect_product_ids( $effect ) as $product_id ) {
						if ( 'exclude' === ( $effect['action'] ?? '' ) ) {
							$excluded[ $product_id ] = true;
							continue;
						}
						if ( ! isset( $scores[ $product_id ] ) ) {
							$scores[ $product_id ] = 0;
							$order[ $product_id ]  = count( $order );
						}
						$scores[ $product_id ] += max( 1, (int) ( $effect['weight'] ?? 1 ) );
						$reasons[ $product_id ][] = (string) $answer['text'];
					}
				}
			}
		}

		foreach ( array_keys( $excluded ) as $product_id ) {
			unset( $scores[ $product_id ], $reasons[ $product_id ] );
		}
		$ids = array_keys( $scores );
		usort( $ids, static function ( $a, $b ) use ( $scores, $order ) {
			return [ -$scores[ $a ], $order[ $a ] ] <=> [ -$scores[ $b ], $order[ $b ] ];
		} );

		if ( ! $ids ) {
			foreach ( self::fallback_ids( $config, $result_id ) as $product_id ) {
				if ( ! isset( $excluded[ $product_id ] ) ) {
					$ids[] = $product_id;
				}
			}
		}

		$max = max( 1, (int) ( $config['settings']['max_products'] ?? 3 ) );
		return [
			'ids'      => array_slice( $ids, 0, $max ),
			'scores'   => $scores,
			'reasons'  => array_map( 'array_values', array_map( 'array_unique', $reasons ) ),
			'excluded' => array_map( 'intval', array_keys( $excluded ) ),
		];
	}

	/** @return int[] */
	private static function effect_product_ids( array $effect ): array {
		if ( 'product' === ( $effect['target'] ?? 'product' ) ) {
			$product_id = absint( $effect['product_id'] ?? 0 );
			return $product_id && self::is_eligible( $product_id ) ? [ $product_id ] : [];
		}
		$taxonomy = sanitize_key( (string) ( $effect['taxonomy'] ?? '' ) );
		$term_id  = absint( $effect['term_id'] ?? 0 );
		if ( ! $term_id || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		static $term_cache = [];
		$cache_key = $taxonomy . ':' . $term_id;
		if ( isset( $term_cache[ $cache_key ] ) ) {
			return $term_cache[ $cache_key ];
		}
		$ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
				'tax_query'      => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [ $term_id ] ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$term_cache[ $cache_key ] = array_values( array_filter( array_map( 'intval', $ids ), [ __CLASS__, 'is_eligible' ] ) );
		return $term_cache[ $cache_key ];
	}

	/**
	 * A recommendation must be publicly visible and in stock.
	 */
	public static function is_eligible( int $product_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'publish' === get_post_status( $product_id );
		}
		$product = wc_get_product( $product_id );
		return $product
			&& 'publish' === $product->get_status()
			&& 'hidden' !== $product->get_catalog_visibility()
			&& $product->is_in_stock();
	}

	/** @return int[] */
	private static function fallback_ids( array $config, string $result_id ): array {
		foreach ( $config['results'] as $result ) {
			if ( $result['id'] === $result_id ) {
				return array_values( array_filter( array_map( 'intval', (array) ( $result['product_ids'] ?? ( ! empty( $result['product_id'] ) ? [ $result['product_id'] ] : [] ) ) ), [ __CLASS__, 'is_eligible' ] ) );
			}
		}
		return [];
	}
}
