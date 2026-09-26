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
