<?php

namespace Bgq\Admin;

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;
use Bgq\Quiz\PostType;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries (attempts) list.
 */
class EntriesTable extends \WP_List_Table {

	const PER_PAGE = 25;

	/** @var array<int,string> quiz id => title */
	private $quiz_titles = [];

	public function __construct() {
		parent::__construct( [ 'singular' => 'bgq_entry', 'plural' => 'bgq_entries', 'ajax' => false ] );
	}

	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'beltoft-quiz' ),
			'quiz'       => __( 'Quiz', 'beltoft-quiz' ),
			'name'       => __( 'Name', 'beltoft-quiz' ),
			'email'      => __( 'Email', 'beltoft-quiz' ),
			'result'     => __( 'Score / result', 'beltoft-quiz' ),
			'duration'   => __( 'Duration', 'beltoft-quiz' ),
		];
	}

	public function get_sortable_columns() {
		return [ 'created_at' => [ 'created_at', true ], 'result' => [ 'score', false ] ];
	}

	public function get_bulk_actions() {
		return [ 'delete' => __( 'Delete', 'beltoft-quiz' ) ];
	}

	/**
	 * Current filter values from the request (read-only list display).
	 */
	public static function filters(): array {
		// Read-only list filters; no state is changed from these values.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$quiz_id = isset( $_GET['quiz_id'] ) ? absint( wp_unslash( $_GET['quiz_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';

		return [ 'quiz_id' => $quiz_id, 'search' => $search, 'orderby' => $orderby, 'order' => $order ];
	}

	public function prepare_items() {
		$this->process_bulk_action();

		$f    = self::filters();
		$page = max( 1, $this->get_pagenum() );
		$args = [ 'quiz_id' => $f['quiz_id'], 'search' => $f['search'], 'orderby' => $f['orderby'], 'order' => $f['order'], 'page' => $page, 'per_page' => self::PER_PAGE ];

		$this->items = Attempts::query( $args );
		$total       = Attempts::count( $args );

		$ids = array_unique( array_map( function ( $row ) { return (int) $row->quiz_id; }, $this->items ) );
		foreach ( $ids as $qid ) {
			$this->quiz_titles[ $qid ] = get_the_title( $qid );
		}

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
		$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => self::PER_PAGE, 'total_pages' => (int) ceil( $total / self::PER_PAGE ) ] );
	}

	/**
	 * Bulk delete with nonce and capability checks.
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-' . $this->_args['plural'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = isset( $_REQUEST['entry'] ) ? array_map( 'absint', (array) $_REQUEST['entry'] ) : [];
		foreach ( $ids as $id ) {
			Attempts::delete( $id );
		}
		if ( $ids ) {
			/* translators: %d: number of entries */
			add_settings_error( 'bgq_messages', 'bgq_deleted', sprintf( _n( '%d entry deleted.', '%d entries deleted.', count( $ids ), 'beltoft-quiz' ), count( $ids ) ), 'success' );
		}
	}

	public function column_cb( $item ) {
		return '<input type="checkbox" name="entry[]" value="' . (int) $item->id . '" />';
	}

	public function column_created_at( $item ) {
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at . ' UTC' ) ) );
	}

	public function column_quiz( $item ) {
		$title = $this->quiz_titles[ (int) $item->quiz_id ] ?? '#' . (int) $item->quiz_id;
		return '<a href="' . esc_url( get_edit_post_link( (int) $item->quiz_id ) ) . '">' . esc_html( $title ) . '</a>';
	}

	public function column_name( $item ) {
		return esc_html( $item->name ?: '—' );
	}

	public function column_email( $item ) {
		return $item->email ? '<a href="mailto:' . esc_attr( $item->email ) . '">' . esc_html( $item->email ) . '</a>' : '—';
	}

	public function column_result( $item ) {
		$config = Config::load( (int) $item->quiz_id );
		$title  = '';
		foreach ( $config['results'] as $r ) {
			if ( $r['id'] === $item->result_id ) {
				$title = $r['title'];
			}
		}
		if ( 'score' === $config['mode'] ) {
			/* translators: 1: score percent, 2: correct, 3: total */
			return esc_html( sprintf( __( '%1$s%% (%2$s/%3$s)', 'beltoft-quiz' ), round( (float) $item->score ), (int) $item->correct_count, (int) $item->total_count ) ) . ( $title ? ' · ' . esc_html( $title ) : '' );
		}
		return esc_html( $title ?: $item->result_id );
	}

	public function column_duration( $item ) {
		$s = (int) $item->duration_seconds;
		return esc_html( sprintf( '%d:%02d', floor( $s / 60 ), $s % 60 ) );
	}

	/**
	 * Quiz filter dropdown above the table.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$f       = self::filters();
		$quizzes = get_posts( [ 'post_type' => PostType::TYPE, 'post_status' => [ 'publish', 'draft' ], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="bgq-filter-quiz">' . esc_html__( 'Filter by quiz', 'beltoft-quiz' ) . '</label>';
		echo '<select name="quiz_id" id="bgq-filter-quiz"><option value="0">' . esc_html__( 'All quizzes', 'beltoft-quiz' ) . '</option>';
		foreach ( $quizzes as $q ) {
			echo '<option value="' . (int) $q->ID . '"' . selected( $f['quiz_id'], $q->ID, false ) . '>' . esc_html( $q->post_title ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'beltoft-quiz' ), '', 'filter_action', false );
		echo ' <a class="button" href="' . esc_url( Export::url( $f['quiz_id'] ) ) . '">' . esc_html__( 'Export CSV', 'beltoft-quiz' ) . '</a>';
		echo '</div>';
	}

	public function no_items() {
		esc_html_e( 'No entries yet.', 'beltoft-quiz' );
	}
}
