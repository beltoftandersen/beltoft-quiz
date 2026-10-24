<?php

namespace Bgq\Admin;

use Bgq\Quiz\PostType;
use Bgq\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Quizzes > Settings: the single global option.
 */
class SettingsPage {

	const SLUG  = 'bgq-settings';
	const GROUP = 'bgq_settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register' ] );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . PostType::TYPE, __( 'Settings', 'beltoft-quiz' ), __( 'Settings', 'beltoft-quiz' ), 'manage_options', self::SLUG, [ __CLASS__, 'render' ] );
	}

	public static function register() {
		register_setting( self::GROUP, Options::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ Options::class, 'sanitize' ], 'default' => Options::defaults() ] );
		add_settings_section( 'bgq_general', __( 'Data', 'beltoft-quiz' ), '__return_null', self::SLUG );
		add_settings_field(
			'cleanup_on_uninstall',
			__( 'Delete all data on uninstall', 'beltoft-quiz' ),
			function () {
				printf(
					'<label><input type="checkbox" name="%1$s[cleanup_on_uninstall]" value="1" %2$s /> %3$s</label>',
					esc_attr( Options::OPTION ),
					checked( Options::get( 'cleanup_on_uninstall' ), '1', false ),
					esc_html__( 'Remove quizzes, entries and settings when the plugin is deleted.', 'beltoft-quiz' )
				);
			},
			self::SLUG,
			'bgq_general'
		);

		add_settings_section(
			'bgq_newsletter',
			__( 'Newsletter sign-up', 'beltoft-quiz' ),
			function () {
				echo '<p>' . esc_html__( 'Adds an unticked checkbox to the name and email step of quizzes that ask for an email. When a visitor ticks it, their email is sent to your newsletter webhook as JSON: email, list_id, api_key and source.', 'beltoft-quiz' ) . '</p>';
			},
			self::SLUG
		);
		$opt  = Options::OPTION;
		$text = function ( $key, $type = 'text', $desc = '', $placeholder = '' ) use ( $opt ) {
			return function () use ( $key, $type, $desc, $placeholder, $opt ) {
				printf(
					'<input type="%1$s" class="regular-text" name="%2$s[%3$s]" id="bgq-%3$s" value="%4$s" placeholder="%5$s" autocomplete="off" />',
					esc_attr( $type ),
					esc_attr( $opt ),
					esc_attr( $key ),
					esc_attr( 'password' === $type ? '' : (string) Options::get( $key ) ),
					esc_attr( $placeholder )
				);
				if ( $desc ) {
					echo '<p class="description">' . esc_html( $desc ) . '</p>';
				}
			};
		};
		add_settings_field(
			'newsletter_enabled',
			__( 'Show the checkbox', 'beltoft-quiz' ),
			function () use ( $opt ) {
				printf(
					'<label><input type="checkbox" name="%1$s[newsletter_enabled]" value="1" %2$s /> %3$s</label>',
					esc_attr( $opt ),
					checked( Options::get( 'newsletter_enabled' ), '1', false ),
					esc_html__( 'Offer the newsletter on quizzes that ask for an email.', 'beltoft-quiz' )
				);
			},
			self::SLUG,
			'bgq_newsletter'
		);
		add_settings_field( 'newsletter_label', __( 'Checkbox text', 'beltoft-quiz' ), $text( 'newsletter_label', 'text', '', __( 'Send me the newsletter', 'beltoft-quiz' ) ), self::SLUG, 'bgq_newsletter', [ 'label_for' => 'bgq-newsletter_label' ] );
		add_settings_field( 'newsletter_url', __( 'Webhook URL', 'beltoft-quiz' ), $text( 'newsletter_url', 'url', '', 'https://' ), self::SLUG, 'bgq_newsletter', [ 'label_for' => 'bgq-newsletter_url' ] );
		add_settings_field( 'newsletter_list_id', __( 'List ID', 'beltoft-quiz' ), $text( 'newsletter_list_id', 'text', __( 'Sent as list_id. Leave empty if your webhook does not use lists.', 'beltoft-quiz' ) ), self::SLUG, 'bgq_newsletter', [ 'label_for' => 'bgq-newsletter_list_id' ] );
		add_settings_field(
			'newsletter_api_key',
			__( 'API key', 'beltoft-quiz' ),
			function () use ( $text, $opt ) {
				$saved = '' !== (string) Options::get( 'newsletter_api_key' );
				call_user_func( $text( 'newsletter_api_key', 'password', $saved ? __( 'A key is saved. Leave empty to keep it, or type a new one to replace it.', 'beltoft-quiz' ) : __( 'Sent as api_key.', 'beltoft-quiz' ) ) );
				if ( $saved ) {
					printf( '<label><input type="checkbox" name="%1$s[newsletter_api_key_clear]" value="1" /> %2$s</label>', esc_attr( $opt ), esc_html__( 'Remove the saved key', 'beltoft-quiz' ) );
				}
			},
			self::SLUG,
			'bgq_newsletter',
			[ 'label_for' => 'bgq-newsletter_api_key' ]
		);
		add_settings_field( 'newsletter_source', __( 'Source', 'beltoft-quiz' ), $text( 'newsletter_source', 'text', __( 'Sent as source, so you can see where the sign-up came from.', 'beltoft-quiz' ), 'quiz' ), self::SLUG, 'bgq_newsletter', [ 'label_for' => 'bgq-newsletter_source' ] );
	}

	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quiz settings', 'beltoft-quiz' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
