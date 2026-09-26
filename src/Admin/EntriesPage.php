<?php

namespace Bgq\Admin;

use Bgq\Quiz\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Quizzes > Entries.
 */
class EntriesPage {

	const SLUG = 'bgq-entries';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . PostType::TYPE, __( 'Entries', 'beltoft-quiz' ), __( 'Entries', 'beltoft-quiz' ), 'manage_options', self::SLUG, [ __CLASS__, 'render' ] );
	}

	public static function render() {
		$table = new EntriesTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Quiz entries', 'beltoft-quiz' ); ?></h1>
			<hr class="wp-header-end" />
			<?php settings_errors( 'bgq_messages' ); ?>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search email or name', 'beltoft-quiz' ), 'bgq-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
