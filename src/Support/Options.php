<?php

namespace Bgq\Support;

defined( 'ABSPATH' ) || exit;

class Options {

	const OPTION = 'bgq_options';

	/** @var array|null Request-scoped cache. */
	private static $cache = null;

	/**
	 * Defaults for every setting.
	 */
	public static function defaults() {
		return [
			'cleanup_on_uninstall' => '0',
		];
	}

	/**
	 * Get one setting or all of them.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( get_option( self::OPTION, [] ), self::defaults() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return self::$cache[ $key ] ?? null;
	}

	/**
	 * Drop the request cache.
	 */
	public static function invalidate_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$out   = [];

		$out['cleanup_on_uninstall'] = ! empty( $input['cleanup_on_uninstall'] ) ? '1' : '0';

		self::$cache = null;

		return $out;
	}
}
