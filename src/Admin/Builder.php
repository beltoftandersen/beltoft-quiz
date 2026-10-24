<?php

namespace Bgq\Admin;

use Bgq\Quiz\Config;
use Bgq\Quiz\PostType;
use Bgq\Frontend\Shortcode;

defined( 'ABSPATH' ) || exit;

/**
 * The quiz builder screen (replaces the post editor for bgq_quiz) and the Quizzes list tweaks.
 */
class Builder {

	public static function init() {
		add_filter( 'replace_editor', [ __CLASS__, 'replace_editor' ], 10, 2 );
		add_filter( 'manage_' . PostType::TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . PostType::TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
	}

	/**
	 * Render the builder instead of the editor.
	 *
	 * @param bool     $replace Whether to replace.
	 * @param \WP_Post $post    Post.
	 * @return bool
	 */
	public static function replace_editor( $replace, $post ) {
		if ( ! $post instanceof \WP_Post || PostType::TYPE !== $post->post_type ) {
			return $replace;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $replace;
		}
		// Only when actually loading the edit screen (the filter also runs during post creation).
		if ( ! did_action( 'load-post.php' ) && ! did_action( 'load-post-new.php' ) ) {
			return $replace;
		}

		// The filter runs more than once per request (post.php and use_block_editor_for_post()); render once.
		static $rendered = false;
		if ( $rendered ) {
			return true;
		}
		$rendered = true;

		// Core prints admin-footer.php itself once this filter returns true; only the header is ours.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		require_once ABSPATH . 'wp-admin/admin-header.php';
		self::render( $post );

		return true;
	}

	/**
	 * Builder assets and data.
	 */
	public static function enqueue() {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'bgq-admin', BGQ_URL . 'assets/css/admin.css', [], BGQ_VERSION );
		wp_enqueue_script( 'bgq-builder', BGQ_URL . 'assets/js/builder.js', [ 'wp-api-fetch' ], BGQ_VERSION, true );

		$config           = Config::load( $post->ID );
		$config['title']  = $post->post_title;
		$config['status']      = 'publish' === $post->post_status ? 'publish' : 'draft';
		$config['can_publish'] = current_user_can( 'publish_post', $post->ID );

		wp_localize_script(
			'bgq-builder',
			'bgq_builder',
			[
				'quiz_id'            => $post->ID,
				'config'             => $config,
				'rest_url'           => esc_url_raw( rest_url( 'bgq/v1/quizzes/' . $post->ID . '/config' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'shortcode'          => '[' . Shortcode::TAG . ' id="' . $post->ID . '"]',
				'gift_cards_active'  => class_exists( '\\Bgcw\\GiftCard\\GiftCardCreator' ),
				'woocommerce_active' => function_exists( 'wc_get_product' ),
				'max_products'       => \Bgq\Quiz\Config::MAX_PRODUCTS,
				'product_search_url' => function_exists( 'wc_get_product' ) ? esc_url_raw( rest_url( 'wc/store/v1/products' ) ) : '',
				'catalog_terms'      => function_exists( 'wc_get_product' ) ? self::catalog_terms( $config ) : [],
				'catalog_search_url' => function_exists( 'wc_get_product' ) ? esc_url_raw( rest_url( 'bgq/v1/quizzes/' . $post->ID . '/catalog-targets' ) ) : '',
				'list_url'           => admin_url( 'edit.php?post_type=' . PostType::TYPE ),
				'default_labels'     => Config::default_labels(),
				'i18n'               => self::i18n(),
			]
		);
	}

	/**
	 * Labels for catalog-term rules already used by the quiz. New targets are
	 * loaded through the authenticated type-ahead endpoint.
	 */
	private static function catalog_terms( array $config ): array {
		$out = [];
		foreach ( $config['questions'] as $question ) {
			foreach ( $question['answers'] as $answer ) {
				foreach ( (array) ( $answer['effects'] ?? [] ) as $effect ) {
					if ( 'term' !== ( $effect['target'] ?? '' ) ) {
						continue;
					}
					$taxonomy = sanitize_key( (string) ( $effect['taxonomy'] ?? '' ) );
					$term     = get_term( absint( $effect['term_id'] ?? 0 ), $taxonomy );
					$object   = get_taxonomy( $taxonomy );
					if ( $term && ! is_wp_error( $term ) && $object ) {
						$out[ $taxonomy . ':' . $term->term_id ] = sprintf( '%1$s: %2$s', $object->labels->singular_name, $term->name );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Strings used by the builder JS.
	 */
	private static function i18n(): array {
		return [
			'save'            => __( 'Save', 'beltoft-quiz' ),
			'saving'          => __( 'Saving…', 'beltoft-quiz' ),
			'saved'           => __( 'Saved.', 'beltoft-quiz' ),
			'save_failed'     => __( 'Could not save. Fix the errors below.', 'beltoft-quiz' ),
			'network_error'   => __( 'Network error. Please try again.', 'beltoft-quiz' ),
			'title'           => __( 'Quiz title', 'beltoft-quiz' ),
			'status'          => __( 'Status', 'beltoft-quiz' ),
			'draft'           => __( 'Draft', 'beltoft-quiz' ),
			'published'       => __( 'Published', 'beltoft-quiz' ),
			'settings'        => __( 'Settings', 'beltoft-quiz' ),
			'mode'            => __( 'Quiz type', 'beltoft-quiz' ),
			'mode_score'      => __( 'Score (right and wrong answers)', 'beltoft-quiz' ),
			'mode_outcome'    => __( 'Outcome (answers point to a result)', 'beltoft-quiz' ),
			'mode_product'    => __( 'Product Finder (recommended)', 'beltoft-quiz' ),
			'mode_score_help' => __( 'Correct answers produce a percentage. The score range selects the result.', 'beltoft-quiz' ),
			'mode_outcome_help' => __( 'Each selected answer adds points to outcomes. The outcome with the most points wins; ties use the first outcome below.', 'beltoft-quiz' ),
			'mode_product_help' => __( 'Selected answers boost or exclude products directly. The strongest matching products are recommended.', 'beltoft-quiz' ),
			'pass_mark'       => __( 'Pass mark (%)', 'beltoft-quiz' ),
			'timer'           => __( 'Time limit in seconds (0 = none)', 'beltoft-quiz' ),
			'shuffle_q'       => __( 'Shuffle questions', 'beltoft-quiz' ),
			'shuffle_a'       => __( 'Shuffle answers', 'beltoft-quiz' ),
			'one_attempt'     => __( 'One attempt per visitor', 'beltoft-quiz' ),
			'require_email'   => __( 'Ask for name and email before the result', 'beltoft-quiz' ),
			'consent_text'    => __( 'Consent text (optional, shown with a checkbox)', 'beltoft-quiz' ),
			'accent'          => __( 'Accent color', 'beltoft-quiz' ),
			'labels'          => __( 'Button labels', 'beltoft-quiz' ),
			'questions'       => __( 'Questions', 'beltoft-quiz' ),
			'add_question'    => __( 'Add question', 'beltoft-quiz' ),
			'question_text'   => __( 'Question', 'beltoft-quiz' ),
			'question_type'   => __( 'Answer type', 'beltoft-quiz' ),
			'single'          => __( 'Single choice', 'beltoft-quiz' ),
			'multiple'        => __( 'Multiple choice', 'beltoft-quiz' ),
			'answers'         => __( 'Answers', 'beltoft-quiz' ),
			'add_answer'      => __( 'Add answer', 'beltoft-quiz' ),
			'answer_text'     => __( 'Answer text', 'beltoft-quiz' ),
			'correct'         => __( 'Correct', 'beltoft-quiz' ),
			'points_for'      => __( 'Points for', 'beltoft-quiz' ),
			'image'           => __( 'Image', 'beltoft-quiz' ),
			'choose_image'    => __( 'Choose image', 'beltoft-quiz' ),
			'remove_image'    => __( 'Remove image', 'beltoft-quiz' ),
			'results'         => __( 'Results', 'beltoft-quiz' ),
			'add_result'      => __( 'Add result', 'beltoft-quiz' ),
			'result_title'    => __( 'Title', 'beltoft-quiz' ),
			'result_text'     => __( 'Text (HTML allowed)', 'beltoft-quiz' ),
			'score_range'     => __( 'Score range (%)', 'beltoft-quiz' ),
			'button_label'    => __( 'Button label', 'beltoft-quiz' ),
			'button_url'      => __( 'Button link', 'beltoft-quiz' ),
			'product'         => __( 'WooCommerce product (optional)', 'beltoft-quiz' ),
			'products'        => __( 'Products to recommend (optional)', 'beltoft-quiz' ),
			'answer_products' => __( 'Products this answer fits', 'beltoft-quiz' ),
			/* translators: %d: number of products mapped to an answer. */
			'mapped_products' => __( 'Mapped: %d', 'beltoft-quiz' ),
			'answer_products_help' => __( 'The result shows the products that fit the most of the visitor\'s answers. Leave empty to show the result\'s own products.', 'beltoft-quiz' ),
			'max_products'    => __( 'Products shown on the result', 'beltoft-quiz' ),
			'recommendation_method' => __( 'How products are recommended', 'beltoft-quiz' ),
			'recommend_by_result' => __( 'Use products from the winning result', 'beltoft-quiz' ),
			'recommend_by_answer' => __( 'Rank products matched to selected answers', 'beltoft-quiz' ),
			'recommend_by_result_help' => __( 'The quiz first chooses a result, then shows that result\'s products in the order configured.', 'beltoft-quiz' ),
			'recommend_by_answer_help' => __( 'Every selected answer gives one match to its products. Products with the most matches appear first; ties follow question and product order. If no answer matches a product, the winning result\'s products are used.', 'beltoft-quiz' ),
			'results_help_score' => __( 'Create the messages visitors see for score ranges. Products here are the recommendation or fallback for that score.', 'beltoft-quiz' ),
			'results_help_outcome' => __( 'Create outcomes first. Answers below will give points to these outcomes; the highest total wins.', 'beltoft-quiz' ),
			'questions_help_score' => __( 'Mark the correct answer or answers. You can also map answers to products when answer matching is selected.', 'beltoft-quiz' ),
			'questions_help_outcome' => __( 'For every answer, set how many points it gives each outcome. Higher numbers make that outcome more likely.', 'beltoft-quiz' ),
			/* translators: %s: the only result title. */
			'single_outcome_help' => __( 'This quiz has one result (“%s”). Every answer leads to it automatically, so no point value is needed.', 'beltoft-quiz' ),
			'questions_help_product' => __( 'For every answer, add product rules: recommend or exclude a product, category, tag, or attribute.', 'beltoft-quiz' ),
			'results_help_product' => __( 'Design the recommendation screen and choose fallback products for visitors whose answers produce no matches.', 'beltoft-quiz' ),
			'fallback_products' => __( 'Fallback products (used when no answer matches)', 'beltoft-quiz' ),
			'recommendation_map' => __( 'Recommendation map', 'beltoft-quiz' ),
			'recommendation_map_help' => __( 'This is the complete decision map. Edit the values in Questions or Results; this table updates immediately.', 'beltoft-quiz' ),
			'map_question'    => __( 'Question', 'beltoft-quiz' ),
			'map_answer'      => __( 'Answer', 'beltoft-quiz' ),
			'map_result'      => __( 'Effect on result', 'beltoft-quiz' ),
			'map_products'    => __( 'Products', 'beltoft-quiz' ),
			'map_correct'     => __( 'Correct answer', 'beltoft-quiz' ),
			'map_incorrect'   => __( 'Incorrect answer', 'beltoft-quiz' ),
			'map_no_result'   => __( 'No outcome points', 'beltoft-quiz' ),
			'map_no_products' => __( 'No products', 'beltoft-quiz' ),
			'finder_rules'    => __( 'Product rules', 'beltoft-quiz' ),
			'finder_rules_help' => __( 'Boost makes matching products more likely. Exclude removes them completely.', 'beltoft-quiz' ),
			'finder_boost'    => __( 'Recommend / boost', 'beltoft-quiz' ),
			'finder_exclude'  => __( 'Exclude', 'beltoft-quiz' ),
			'finder_strength' => __( 'Strength', 'beltoft-quiz' ),
			'finder_product'  => __( 'Specific product', 'beltoft-quiz' ),
			'finder_catalog'  => __( 'Category, tag, or attribute', 'beltoft-quiz' ),
			'finder_add_catalog' => __( 'Add catalog rule', 'beltoft-quiz' ),
			'finder_pick_catalog' => __( 'Choose a category, tag, or attribute', 'beltoft-quiz' ),
			'finder_direct'   => __( 'Ranks products directly', 'beltoft-quiz' ),
			'setup_check'     => __( 'Setup check', 'beltoft-quiz' ),
			'no_setup_warnings' => __( 'The quiz has complete mappings and is ready to test.', 'beltoft-quiz' ),
			'warning_no_rule' => __( 'has no product rule', 'beltoft-quiz' ),
			'warning_no_boost' => __( 'No answer recommends a product. Add at least one boost rule or fallback product.', 'beltoft-quiz' ),
			'warning_multiple_results' => __( 'Product Finder uses the first result screen only. Remove the extra results or switch back to an Outcome quiz.', 'beltoft-quiz' ),
			/* translators: 1: product name or ID, 2: question text. */
			'warning_unreachable' => __( '%1$s can never be recommended because every answer to “%2$s” excludes it.', 'beltoft-quiz' ),
			'preview'         => __( 'Test recommendation', 'beltoft-quiz' ),
			'preview_help'    => __( 'Choose answers below and run a test. Nothing is saved and no attempt is recorded.', 'beltoft-quiz' ),
			'run_preview'     => __( 'Run test', 'beltoft-quiz' ),
			'preview_empty'   => __( 'No products matched these answers. Add boost rules or fallback products.', 'beltoft-quiz' ),
			'preview_failed'  => __( 'The preview could not be calculated.', 'beltoft-quiz' ),
			/* translators: %s: comma-separated selected answers. */
			'recommended_because' => __( 'Because: %s', 'beltoft-quiz' ),
			'step_setup'      => __( '1. Setup', 'beltoft-quiz' ),
			'step_results'    => __( '2. Result', 'beltoft-quiz' ),
			'step_questions'  => __( '3. Questions', 'beltoft-quiz' ),
			'step_test'       => __( '4. Review & test', 'beltoft-quiz' ),
			'step_publish'    => __( '5. Publish', 'beltoft-quiz' ),
			'previous_step'   => __( 'Previous', 'beltoft-quiz' ),
			'next_step'       => __( 'Next step', 'beltoft-quiz' ),
			'product_search'  => __( 'Search products…', 'beltoft-quiz' ),
			'product_replace_search' => __( 'Search for the replacement product…', 'beltoft-quiz' ),
			'product_picker_help' => __( 'Search below to add a product. Use Edit beside a selected product to replace it.', 'beltoft-quiz' ),
			'product_search_empty' => __( 'No products found.', 'beltoft-quiz' ),
			'product_search_failed' => __( 'Product search failed. Please try again.', 'beltoft-quiz' ),
			'product_none'    => __( 'No product', 'beltoft-quiz' ),
			'edit'            => __( 'Edit', 'beltoft-quiz' ),
			'reward'          => __( 'Gift card reward', 'beltoft-quiz' ),
			'reward_enable'   => __( 'Send a gift card when the quiz is completed', 'beltoft-quiz' ),
			'reward_amount'   => __( 'Amount', 'beltoft-quiz' ),
			'reward_expiry'   => __( 'Expires after (days, 0 = never)', 'beltoft-quiz' ),
			'reward_when'     => __( 'Send when', 'beltoft-quiz' ),
			'reward_always'   => __( 'Always', 'beltoft-quiz' ),
			'reward_pass'     => __( 'The quiz is passed', 'beltoft-quiz' ),
			'reward_outcome'  => __( 'The result is one of', 'beltoft-quiz' ),
			'reward_note'     => __( 'A reward needs an email: turn on "Ask for name and email" or the visitor must be logged in.', 'beltoft-quiz' ),
			'embed'           => __( 'Embed', 'beltoft-quiz' ),
			'embed_help'      => __( 'Paste this shortcode into any page, post or builder element.', 'beltoft-quiz' ),
			'copy'            => __( 'Copy', 'beltoft-quiz' ),
			'copied'          => __( 'Copied', 'beltoft-quiz' ),
			'import_export'   => __( 'Import / Export', 'beltoft-quiz' ),
			'export'          => __( 'Export JSON', 'beltoft-quiz' ),
			'import'          => __( 'Import JSON', 'beltoft-quiz' ),
			'import_bad'      => __( 'That file is not a valid quiz export.', 'beltoft-quiz' ),
			'remove'          => __( 'Remove', 'beltoft-quiz' ),
			'move_up'         => __( 'Move up', 'beltoft-quiz' ),
			'move_down'       => __( 'Move down', 'beltoft-quiz' ),
			'back_to_list'    => __( 'Back to quizzes', 'beltoft-quiz' ),
			'unsaved'         => __( 'You have unsaved changes.', 'beltoft-quiz' ),
			'field_errors'    => __( 'Please fix these:', 'beltoft-quiz' ),
		];
	}

	/**
	 * Builder markup; the JS fills #bgq-builder.
	 */
	private static function render( \WP_Post $post ) {
		?>
		<div class="wrap bgq-builder-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( 'auto-draft' === $post->post_status ? __( 'Add New Quiz', 'beltoft-quiz' ) : __( 'Edit Quiz', 'beltoft-quiz' ) ); ?></h1>
			<hr class="wp-header-end" />
			<div id="bgq-builder" data-quiz="<?php echo esc_attr( $post->ID ); ?>">
				<p><?php esc_html_e( 'Loading the quiz builder…', 'beltoft-quiz' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Shortcode column on the Quizzes list.
	 */
	public static function columns( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['bgq_shortcode'] = __( 'Shortcode', 'beltoft-quiz' );
			}
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		if ( 'bgq_shortcode' === $column ) {
			echo '<code>[' . esc_html( Shortcode::TAG ) . ' id="' . (int) $post_id . '"]</code>';
		}
	}

	/**
	 * Drop "Quick Edit" (nothing to quick-edit on a builder-managed post).
	 */
	public static function row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && PostType::TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}
}
