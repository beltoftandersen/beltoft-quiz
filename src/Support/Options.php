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
			'newsletter_enabled'   => '0',
			'newsletter_label'     => '',
			'newsletter_url'       => '',
			'newsletter_list_id'   => '',
			'newsletter_api_key'   => '',
			'newsletter_source'    => 'quiz',
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

		$out['newsletter_enabled'] = ! empty( $input['newsletter_enabled'] ) ? '1' : '0';
		$out['newsletter_label']   = isset( $input['newsletter_label'] ) ? sanitize_text_field( (string) $input['newsletter_label'] ) : '';
		$url                       = isset( $input['newsletter_url'] ) ? esc_url_raw( trim( (string) $input['newsletter_url'] ), [ 'https', 'http' ] ) : '';
		$out['newsletter_url']     = $url;
		$out['newsletter_list_id'] = isset( $input['newsletter_list_id'] ) ? sanitize_text_field( (string) $input['newsletter_list_id'] ) : '';
		// An empty key field keeps the saved key (the field is never printed back).
		$key                       = isset( $input['newsletter_api_key'] ) ? trim( sanitize_text_field( (string) $input['newsletter_api_key'] ) ) : '';
		$out['newsletter_api_key'] = '' !== $key ? $key : (string) self::get( 'newsletter_api_key' );
		$source                    = isset( $input['newsletter_source'] ) ? sanitize_key( (string) $input['newsletter_source'] ) : '';
		$out['newsletter_source']  = '' !== $source ? $source : 'quiz';
		if ( ! empty( $input['newsletter_api_key_clear'] ) ) {
			$out['newsletter_api_key'] = '';
		}

		self::$cache = null;

		return $out;
	}
}
