<?php
/**
 * Plugin Name:       Beltoft Quiz
 * Plugin URI:        https://wordpress.org/plugins/beltoft-quiz/
 * Description:       Build score and outcome quizzes, capture leads, recommend products and reward with gift cards. Lightweight, no framework.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            beltoft.net
 * Author URI:        https://beltoft.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-quiz
 * Domain Path:       /languages/
 *
 * @package Bgq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'Bgq\\' ) !== 0 ) {
			return;
		}
		$file = plugin_dir_path( __FILE__ ) . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, 4 ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

define( 'BGQ_VERSION', '1.0.2' );
define( 'BGQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'BGQ_URL', plugin_dir_url( __FILE__ ) );
define( 'BGQ_BASENAME', plugin_basename( __FILE__ ) );
define( 'BGQ_DB_VERSION', '1.0' );

register_activation_hook( __FILE__, [ 'Bgq\\Support\\Installer', 'activate' ] );

add_action( 'plugins_loaded', [ 'Bgq\\Plugin', 'init' ] );
