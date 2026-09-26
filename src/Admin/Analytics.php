<?php

namespace Bgq\Admin;

use Bgq\Quiz\Config;
use Bgq\Quiz\PostType;
use Bgq\Quiz\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Quizzes > Analytics.
 */
class Analytics {

	const SLUG = 'bgq-analytics';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . PostType::TYPE, __( 'Analytics', 'beltoft-quiz' ), __( 'Analytics', 'beltoft-quiz' ), 'manage_options', self::SLUG, [ __CLASS__, 'render' ] );
	}

	public static function render() {
		$quizzes = get_posts( [ 'post_type' => PostType::TYPE, 'post_status' => [ 'publish', 'draft' ], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selector.
		$quiz_id = isset( $_GET['quiz_id'] ) ? absint( wp_unslash( $_GET['quiz_id'] ) ) : ( $quizzes ? (int) $quizzes[0]->ID : 0 );
		wp_enqueue_style( 'bgq-admin', BGQ_URL . 'assets/css/admin.css', [], BGQ_VERSION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quiz analytics', 'beltoft-quiz' ); ?></h1>
			<form method="get" class="bgq-analytics-filter">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="bgq-analytics-quiz"><?php esc_html_e( 'Quiz', 'beltoft-quiz' ); ?></label>
				<select name="quiz_id" id="bgq-analytics-quiz" onchange="this.form.submit()">
					<?php foreach ( $quizzes as $q ) : ?>
						<option value="<?php echo (int) $q->ID; ?>" <?php selected( $quiz_id, $q->ID ); ?>><?php echo esc_html( $q->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Show', 'beltoft-quiz' ), 'secondary', '', false ); ?></noscript>
			</form>
			<?php
			if ( ! $quiz_id ) {
				echo '<p>' . esc_html__( 'Create a quiz first.', 'beltoft-quiz' ) . '</p></div>';
				return;
			}
			$stats  = Stats::for_quiz( $quiz_id );
			$config = Config::load( $quiz_id );
			$titles = [];
			foreach ( $config['results'] as $r ) {
				$titles[ $r['id'] ] = $r['title'];
			}
			?>
			<div class="bgq-stats">
				<?php
				self::tile( __( 'Attempts', 'beltoft-quiz' ), (string) $stats['attempts'] );
				self::tile( __( 'Today', 'beltoft-quiz' ), (string) $stats['today'] );
				self::tile( __( 'Last 7 days', 'beltoft-quiz' ), (string) $stats['last7'] );
				self::tile( __( 'Last 30 days', 'beltoft-quiz' ), (string) $stats['last30'] );
				if ( 'score' === $config['mode'] ) {
					self::tile( __( 'Average score', 'beltoft-quiz' ), null === $stats['avg_score'] ? '—' : $stats['avg_score'] . '%' );
					self::tile( __( 'Pass rate', 'beltoft-quiz' ), null === $stats['pass_rate'] ? '—' : $stats['pass_rate'] . '%' );
				}
				?>
			</div>

			<h2><?php echo 'score' === $config['mode'] ? esc_html__( 'Results', 'beltoft-quiz' ) : esc_html__( 'Outcomes', 'beltoft-quiz' ); ?></h2>
			<?php self::bars( $stats['outcomes'], $titles, $stats['attempts'] ); ?>

			<?php if ( 'score' === $config['mode'] && $stats['questions'] ) : ?>
				<h2><?php esc_html_e( 'Correct answers per question', 'beltoft-quiz' ); ?></h2>
				<?php
				$labels = [];
				foreach ( $config['questions'] as $q ) {
					$labels[ $q['id'] ] = $q['text'];
				}
				self::bars( $stats['questions'], $labels, 100, true );
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function tile( string $label, string $value ) {
		echo '<div class="bgq-tile"><span class="bgq-tile__value">' . esc_html( $value ) . '</span><span class="bgq-tile__label">' . esc_html( $label ) . '</span></div>';
	}

	/**
	 * Plain HTML bar rows.
	 *
	 * @param array $values  id => number.
	 * @param array $labels  id => label.
	 * @param float $max     Value that means 100% width.
	 * @param bool  $percent Whether values are percentages.
	 */
	private static function bars( array $values, array $labels, $max, bool $percent = false ) {
		if ( ! $values ) {
			echo '<p>' . esc_html__( 'No data yet.', 'beltoft-quiz' ) . '</p>';
			return;
		}
		echo '<div class="bgq-bars">';
		foreach ( $values as $id => $value ) {
			$width = $max > 0 ? min( 100, round( $value / $max * 100 ) ) : 0;
			echo '<div class="bgq-bar-row"><span class="bgq-bar-row__label">' . esc_html( $labels[ $id ] ?? $id ) . '</span>';
			echo '<span class="bgq-bar"><span class="bgq-bar__fill" style="width:' . (int) $width . '%"></span></span>';
			echo '<span class="bgq-bar-row__value">' . esc_html( $percent ? $value . '%' : $value ) . '</span></div>';
		}
		echo '</div>';
	}
}
