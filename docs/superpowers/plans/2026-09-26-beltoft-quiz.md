# Beltoft Quiz Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A lightweight free WordPress plugin where a shop owner builds score or outcome quizzes in wp-admin and visitors take them through a shortcode, with optional email capture, WooCommerce product results, gift card rewards, entries export and analytics.

**Architecture:** One post type `bgq_quiz` holding the whole quiz as validated JSON in `_bgq_config`; one custom table `bgq_attempts`; grading only on the server behind a REST endpoint protected by a signed start token; vanilla JS for the front-end player and the admin builder.

**Tech Stack:** PHP 7.4+, WordPress 5.8+ (tested 7.1), WordPress REST API, vanilla JS/CSS, WP-CLI eval-file tests in the `wp_app` container, Playwright for the front end.

**Spec:** `docs/superpowers/specs/2026-09-26-beltoft-quiz-design.md`

## Global Constraints

- Slug `beltoft-quiz`, namespace `Bgq\`, prefix `bgq_`, text domain `beltoft-quiz`; every user-facing string translatable.
- Requires at least WordPress 5.8, PHP 7.4. No Composer, no build step, no framework.
- Every directory has `index.php` (`<?php // Silence is golden.`).
- No inline `<script>`/`<style>`; use `wp_add_inline_script`/`wp_add_inline_style` on registered handles.
- Direct `$wpdb` calls carry a `phpcs:ignore` with justification; table names inline as `{$wpdb->prefix}bgq_attempts` inside `prepare()`.
- Plugin Check (both modes, `--exclude-directories=tests,docs`) must show no findings before a task is done; PHP lint every file; `node --check` for JS.
- Tests: `tests/run.sh` (copy the Gift Cards harness, prefix `bgq_test_`); bootstrap blocks all mail (`remove_all_filters('pre_wp_mail')` then a `pre_wp_mail` filter returning true).
- Grading, correct answers and points never leave the server.
- Commit after each task with a plain message; no Co-Authored-By trailer.

## Review Focus

1. A visitor submits an answer id that does not belong to the question, or a question id not in the quiz: the grader must ignore it, not fatally error, and score the question as wrong (Task 3 test `unknown ids ignored`).
2. The admin saves a quiz whose results ranges leave a gap (e.g. 0–40 and 60–100) and a visitor scores 50: the result must fall back to the nearest lower range, never to "no result" (Task 3 test `gap in ranges`).
3. The timer expires while the visitor is on the email step: the server must still accept the submission within the 30 s grace, and reject after (Task 4 test `token grace`).
4. Gift Cards plugin deactivated after a quiz with reward enabled was built: submission must still return a result with `reward: null` (Task 9 test `reward without plugin`).
5. Two quick double-clicks on Submit: the second request must not create a second attempt or a second gift card (Task 4 test `duplicate submit`).

---

### Task 1: Scaffold, post type, table, test harness

**Files:**
- Create: `beltoft-quiz.php`, `uninstall.php`, `index.php`, `.gitignore`, `.distignore`, `.github/workflows/deploy-wp-org.yml`, `src/index.php`, `src/Plugin.php`, `src/Support/index.php`, `src/Support/Installer.php`, `src/Support/Options.php`, `src/Quiz/index.php`, `src/Quiz/PostType.php`, `tests/index.php`, `tests/bootstrap.php`, `tests/run.sh`, `tests/test-install.php`, `readme.txt`, `README.md`, `languages/index.php`

**Interfaces:**
- Produces: constants `BGQ_VERSION`, `BGQ_PATH`, `BGQ_URL`, `BGQ_DB_VERSION`; `Bgq\Plugin::init()`; `Bgq\Support\Installer::activate()`, `::maybe_upgrade()`, `::create_tables()`; `Bgq\Support\Options::get( $key )` with defaults `[ 'cleanup_on_uninstall' => '0' ]`; post type `bgq_quiz`; table `{prefix}bgq_attempts` with columns per spec.

- [ ] **Step 1: Write the failing test** `tests/test-install.php`

```php
<?php
require_once __DIR__ . '/bootstrap.php';
global $wpdb;
bgq_assert( post_type_exists( 'bgq_quiz' ), 'post type registered' );
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bgq_attempts", 0 );
foreach ( [ 'id', 'quiz_id', 'user_id', 'email', 'name', 'score', 'correct_count', 'total_count', 'result_id', 'answers', 'ip_hash', 'duration_seconds', 'gift_card_id', 'created_at' ] as $c ) {
	bgq_assert( in_array( $c, $cols, true ), "column $c exists" );
}
bgq_assert_eq( BGQ_DB_VERSION, get_option( 'bgq_db_version' ), 'db version stored' );
bgq_assert_eq( '0', Bgq\Support\Options::get( 'cleanup_on_uninstall' ), 'options default' );
```

- [ ] **Step 2: Copy the harness.** `cp ../beltoft-gift-cards/tests/bootstrap.php tests/` then `sed -i 's/bgcw_test_/bgq_test_/g; s/bgcw_assert/bgq_assert/g' tests/bootstrap.php`; `cp ../beltoft-gift-cards/tests/run.sh tests/` and set `PLUGIN=/var/www/html/wp-content/plugins/beltoft-quiz`. Run `tests/run.sh`; expected: fatal, plugin not active.

- [ ] **Step 3: Main file** `beltoft-quiz.php`

```php
<?php
/**
 * Plugin Name:       Beltoft Quiz
 * Plugin URI:        https://wordpress.org/plugins/beltoft-quiz/
 * Description:       Build score and outcome quizzes, capture leads, recommend products and reward with gift cards. Lightweight, no framework.
 * Version:           1.0.0
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

define( 'BGQ_VERSION', '1.0.0' );
define( 'BGQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'BGQ_URL', plugin_dir_url( __FILE__ ) );
define( 'BGQ_BASENAME', plugin_basename( __FILE__ ) );
define( 'BGQ_DB_VERSION', '1.0' );

register_activation_hook( __FILE__, [ 'Bgq\\Support\\Installer', 'activate' ] );

add_action( 'plugins_loaded', [ 'Bgq\\Plugin', 'init' ] );
```

- [ ] **Step 4: Installer** `src/Support/Installer.php`

```php
<?php
namespace Bgq\Support;

defined( 'ABSPATH' ) || exit;

class Installer {
	const DB_VERSION_KEY = 'bgq_db_version';

	public static function activate() {
		self::create_tables();
		if ( false === get_option( Options::OPTION ) ) {
			add_option( Options::OPTION, Options::defaults(), '', false );
		}
		update_option( self::DB_VERSION_KEY, BGQ_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( version_compare( get_option( self::DB_VERSION_KEY, '0' ), BGQ_DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_KEY, BGQ_DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$wpdb->prefix}bgq_attempts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(255) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			score decimal(5,2) NOT NULL DEFAULT 0.00,
			correct_count int(11) NOT NULL DEFAULT 0,
			total_count int(11) NOT NULL DEFAULT 0,
			result_id varchar(64) NOT NULL DEFAULT '',
			answers longtext,
			ip_hash char(64) NOT NULL DEFAULT '',
			duration_seconds int(11) NOT NULL DEFAULT 0,
			gift_card_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id),
			KEY quiz_user (quiz_id, user_id),
			KEY quiz_ip (quiz_id, ip_hash),
			KEY quiz_email (quiz_id, email(100))
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
```

- [ ] **Step 5: Options** `src/Support/Options.php` (same shape as Gift Cards: `OPTION = 'bgq_options'`, `defaults()`, `get()`, `invalidate_cache()`, `sanitize()` handling only `cleanup_on_uninstall` as bool).

- [ ] **Step 6: Post type** `src/Quiz/PostType.php`

```php
<?php
namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

class PostType {
	const TYPE = 'bgq_quiz';
	const META = '_bgq_config';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	public static function register() {
		register_post_type( self::TYPE, [
			'labels'       => [
				'name'          => __( 'Quizzes', 'beltoft-quiz' ),
				'singular_name' => __( 'Quiz', 'beltoft-quiz' ),
				'add_new_item'  => __( 'Add New Quiz', 'beltoft-quiz' ),
				'edit_item'     => __( 'Edit Quiz', 'beltoft-quiz' ),
			],
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-editor-help',
			'supports'     => [ 'title' ],
			'show_in_rest' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
		] );
	}
}
```

- [ ] **Step 7: Plugin bootstrap** `src/Plugin.php`: `init()` calls `Installer::maybe_upgrade()`, `PostType::init()`; later tasks add their classes here. Add `uninstall.php` (drops the table, deletes options and all `bgq_quiz` posts only when `cleanup_on_uninstall === '1'`). Add `.distignore` (`.git .github .gitignore .distignore CLAUDE.md tests docs`), `.gitignore` (`.claude/`), and the deploy workflow copied from Gift Cards with `SLUG: beltoft-quiz`.

- [ ] **Step 8: Activate and test.** `docker exec wp_app wp --allow-root --path=/var/www/html plugin activate beltoft-quiz`, then `tests/run.sh`. Expected: all pass. Lint + Plugin Check clean (`readme.txt` needs the header block and a short description; write it now with the feature list from the spec).

- [ ] **Step 9: Commit** `git add -A && git commit -m "Scaffold: post type, attempts table, test harness"`.

### Task 2: Config model (validate, sanitize, public view)

**Files:**
- Create: `src/Quiz/Config.php`, `tests/test-config.php`

**Interfaces:**
- Produces: `Config::defaults(): array`; `Config::validate( array $raw ): array|\WP_Error` (returns sanitized config; WP_Error with `errors` data keyed by field path); `Config::load( int $quiz_id ): array` (stored config merged over defaults); `Config::save( int $quiz_id, array $config ): void`; `Config::public_view( array $config ): array` (strips `correct`, `points`, `min`, `max`, `reward`); `Config::new_id( string $prefix ): string` (e.g. `q_ab12cd`).

- [ ] **Step 1: Failing test** `tests/test-config.php`

```php
<?php
require_once __DIR__ . '/bootstrap.php';
use Bgq\Quiz\Config;

$raw = [ 'mode' => 'score', 'settings' => [ 'timer' => '90', 'pass_mark' => '60', 'accent' => '#abc', 'labels' => [ 'start' => '<b>Go</b>' ] ],
	'questions' => [ [ 'id' => 'q1', 'text' => 'Capital of Portugal?', 'type' => 'single', 'answers' => [ [ 'id' => 'a1', 'text' => 'Lisbon', 'correct' => true ], [ 'id' => 'a2', 'text' => 'Porto' ] ] ] ],
	'results' => [ [ 'id' => 'r1', 'title' => 'Great', 'min' => 50, 'max' => 100 ], [ 'id' => 'r0', 'title' => 'Study', 'min' => 0, 'max' => 49 ] ] ];
$c = Config::validate( $raw );
bgq_assert( ! is_wp_error( $c ), 'valid config accepted' );
bgq_assert_eq( 90, $c['settings']['timer'], 'timer cast to int' );
bgq_assert_eq( '#aabbcc', $c['settings']['accent'], 'accent normalised to 6-digit hex' );
bgq_assert_eq( 'Go', $c['settings']['labels']['start'], 'labels stripped of tags' );
bgq_assert_eq( true, $c['questions'][0]['answers'][0]['correct'], 'correct kept' );

$bad = Config::validate( [ 'mode' => 'weird', 'questions' => [], 'results' => [] ] );
bgq_assert( is_wp_error( $bad ), 'invalid mode rejected' );
$errors = $bad->get_error_data()['errors'];
bgq_assert( isset( $errors['mode'] ) && isset( $errors['questions'] ), 'field-level errors for mode and empty questions' );

$no_correct = $raw; $no_correct['questions'][0]['answers'][0]['correct'] = false;
bgq_assert( is_wp_error( Config::validate( $no_correct ) ), 'score question without a correct answer rejected' );

$outcome = [ 'mode' => 'outcome', 'questions' => [ [ 'id' => 'q1', 'text' => 'Pick', 'type' => 'single', 'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'points' => [ 'r1' => 2, 'zzz' => 5 ] ] ] ] ], 'results' => [ [ 'id' => 'r1', 'title' => 'Result A' ] ] ];
$oc = Config::validate( $outcome );
bgq_assert( ! is_wp_error( $oc ) && [ 'r1' => 2 ] === $oc['questions'][0]['answers'][0]['points'], 'points for unknown results dropped' );

$pub = Config::public_view( $c );
bgq_assert( ! isset( $pub['questions'][0]['answers'][0]['correct'] ) && ! isset( $pub['results'][0]['min'] ) && ! isset( $pub['reward'] ), 'public view strips grading data' );

$post_id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'T', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $post_id ) { wp_delete_post( $post_id, true ); } );
Config::save( $post_id, $c );
bgq_assert_eq( 'score', Config::load( $post_id )['mode'], 'save/load round trip' );
bgq_assert( preg_match( '/^q_[a-z0-9]{6}$/', Config::new_id( 'q' ) ), 'new_id format' );
```

- [ ] **Step 2: Run, expect FAIL** (class missing).
- [ ] **Step 3: Implement** `Config`: `defaults()` per spec; `validate()` walks the structure with `sanitize_text_field` for text, `wp_kses_post` for result `text`, `absint` for ids/timer/pass_mark/image_id/product_id, `sanitize_hex_color` (expand 3-digit), `esc_url_raw` for `button_url`; ids `sanitize_key`, regenerated with `new_id()` when empty or duplicated; enforce `mode` enum, at least one question, each question ≥ 2 answers, score mode ≥ 1 correct per question, results non-empty, `min <= max`, reward amount > 0 when enabled; unknown result ids in `points` dropped. Errors collected into `[ 'questions.0.answers' => 'message' ]`. `public_view()` uses `array_map` over questions/results removing the keys listed in Interfaces.
- [ ] **Step 4: Run, expect PASS.** Lint, Plugin Check.
- [ ] **Step 5: Commit** `feat: quiz config model`.

### Task 3: Grader

**Files:**
- Create: `src/Quiz/Grader.php`, `tests/test-grader.php`

**Interfaces:**
- Produces: `Grader::grade( array $config, array $answers ): array` where `$answers = [ question_id => [ answer_id, ... ] ]` and the return is `[ 'score' => float (0–100, one decimal), 'correct_count' => int, 'total_count' => int, 'result_id' => string, 'passed' => bool|null, 'per_question' => [ qid => bool ] ]`. Outcome mode: `score` is 0, `passed` null, `result_id` = highest points (ties → first result in list order); if every total is 0 → first result.

- [ ] **Step 1: Failing test** `tests/test-grader.php`

```php
<?php
require_once __DIR__ . '/bootstrap.php';
use Bgq\Quiz\Config;
use Bgq\Quiz\Grader;

$score = Config::validate( [ 'mode' => 'score', 'settings' => [ 'pass_mark' => 50 ],
	'questions' => [
		[ 'id' => 'q1', 'text' => 'A', 'type' => 'single',   'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ],
		[ 'id' => 'q2', 'text' => 'B', 'type' => 'multiple', 'answers' => [ [ 'id' => 'c', 'text' => 'x', 'correct' => true ], [ 'id' => 'd', 'text' => 'y', 'correct' => true ], [ 'id' => 'e', 'text' => 'z' ] ] ],
	],
	'results' => [ [ 'id' => 'hi', 'title' => 'Hi', 'min' => 60, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 40 ] ] ] );

$g = Grader::grade( $score, [ 'q1' => [ 'a' ], 'q2' => [ 'c', 'd' ] ] );
bgq_assert_eq( 100.0, $g['score'], 'all correct = 100' );
bgq_assert_eq( 'hi', $g['result_id'], 'high result' );
bgq_assert_eq( true, $g['passed'], 'passed' );

$g = Grader::grade( $score, [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ] );
bgq_assert_eq( 50.0, $g['score'], 'multiple needs the full set' );
bgq_assert_eq( 'lo', $g['result_id'], 'gap in ranges: 50 falls back to nearest lower range' );
bgq_assert_eq( true, $g['passed'], 'pass mark inclusive' );

$g = Grader::grade( $score, [ 'q1' => [ 'zzz' ], 'q9' => [ 'a' ] ] );
bgq_assert_eq( 0.0, $g['score'], 'unknown ids ignored and scored wrong' );
bgq_assert_eq( 2, $g['total_count'], 'total counts all questions' );

$outcome = Config::validate( [ 'mode' => 'outcome', 'questions' => [
	[ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'points' => [ 'r1' => 2 ] ], [ 'id' => 'b', 'text' => 'y', 'points' => [ 'r2' => 2 ] ] ] ],
	[ 'id' => 'q2', 'text' => 'B', 'type' => 'single', 'answers' => [ [ 'id' => 'c', 'text' => 'x', 'points' => [ 'r2' => 1 ] ], [ 'id' => 'd', 'text' => 'y', 'points' => [ 'r1' => 1 ] ] ] ],
], 'results' => [ [ 'id' => 'r1', 'title' => 'One' ], [ 'id' => 'r2', 'title' => 'Two' ] ] ] );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ] )['result_id'], 'outcome: highest points wins (2 vs 1)' );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [ 'q1' => [ 'a' ], 'q2' => [ 'c' ], ] + [ 'q2' => [ 'c' ] ] )['result_id'], 'outcome: stable' );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [] )['result_id'], 'outcome: no answers → first result' );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [ 'q1' => [ 'b' ], 'q2' => [ 'd' ] ] )['result_id'], 'outcome: tie → first result in list' );
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** `Grader::grade()`: build lookup maps of questions/answers; score mode: per question compare sorted selected ids (filtered to known answer ids) with sorted correct ids; percentage rounded to 1 decimal; result = first result whose range contains the score, else the result with the greatest `max` below the score, else the last result. Outcome: sum points per result id; pick max, ties by list order.
- [ ] **Step 4: Run, expect PASS.** Commit `feat: grader`.

### Task 4: Attempts store, start token, REST submit

**Files:**
- Create: `src/Quiz/Token.php`, `src/Quiz/Attempts.php`, `src/Rest/index.php`, `src/Rest/AttemptsController.php`, `tests/test-attempts.php`
- Modify: `src/Plugin.php` (register REST on `rest_api_init`)

**Interfaces:**
- `Token::issue( int $quiz_id ): array` → `[ 'started_at' => int, 'seed' => string, 'hash' => string ]`; `Token::verify( int $quiz_id, array $token, int $timer ): bool` (hash equals `wp_hash( "$quiz_id|$started_at|$seed" )`, age ≤ (timer ? timer + 30 : DAY_IN_SECONDS)).
- `Attempts::insert( array $row ): int`; `Attempts::find_existing( int $quiz_id, int $user_id, string $ip_hash, string $email ): ?object`; `Attempts::ip_hash( string $ip ): string` (`hash( 'sha256', $ip . wp_salt( 'nonce' ) )`); `Attempts::get( int $id )`, `Attempts::delete( int $id )`, `Attempts::query( array $args )` (quiz_id, search, page, per_page, orderby), `Attempts::count( array $args )`.
- REST `POST /bgq/v1/attempts` body `{ quiz_id, token:{started_at,seed,hash}, answers:{}, email?, name?, consent? }` → 200 `{ result:{id,title,text,image,button_label,button_url,product:{...}|null}, score, correct_count, total_count, passed, reward:{code,amount}|null, already:bool }`; errors: `bgq_not_found` 404, `bgq_bad_token` 403, `bgq_expired` 410, `bgq_email_required` 400, `bgq_consent_required` 400, `bgq_rate_limited` 429. Rate limit: transient `bgq_rl_{ip_hash}` count, 10 per 10 min. Duplicate guard: transient `bgq_dup_{hash}` for 60 s returns the stored attempt (`already: true`).

- [ ] **Step 1: Failing test** `tests/test-attempts.php` covering: token round trip and expiry (`token grace`: timer 60, started 85 s ago → ok; 95 s ago → `bgq_expired`); submit happy path via `rest_do_request` returns score 100 and inserts a row; bad hash → 403; `require_email` without email → 400; `one_attempt` second submit returns `already: true` and no new row; `duplicate submit` (same token twice within a second) creates exactly one row; rate limit after 10 submits → 429 (use a fresh ip via filter `bgq_client_ip`).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Client IP from `REMOTE_ADDR` through filter `bgq_client_ip`. Controller: `register_rest_route( 'bgq/v1', '/attempts', [ 'methods' => 'POST', 'permission_callback' => '__return_true', 'args' => [...] ] )`. Flow: load published quiz → verify token → rate limit → dup guard → validate email/consent if required → one-attempt lookup → grade → insert row (answers filtered to known ids) → build response via `Results::render( $config, $result_id )` (Task 5 provides; for this task return the raw result array) → reward hook `apply_filters( 'bgq_attempt_reward', null, $attempt_id, $config, $grade )` (Task 9 fills it).
- [ ] **Step 4: Run, expect PASS.** Commit `feat: attempts and submit endpoint`.

### Task 5: Shortcode, player JS and CSS

**Files:**
- Create: `src/Frontend/index.php`, `src/Frontend/Shortcode.php`, `src/Frontend/Results.php`, `assets/index.php`, `assets/js/index.php`, `assets/js/quiz.js`, `assets/css/index.php`, `assets/css/quiz.css`, `tests/test-shortcode.php`, `tests/e2e/quiz.js`
- Modify: `src/Rest/AttemptsController.php` (use `Results::render`), `src/Plugin.php`

**Interfaces:**
- `Shortcode::render( $atts )` for `[beltoft_quiz id="N"]`: outputs `<div class="bgq" id="bgq-N" data-quiz="N" style="--bgq-accent:#hex">` with a `noscript` message; enqueues `bgq-quiz` script/style once and adds `bgq_data_N` (public config + token + REST URL + i18n) via `wp_add_inline_script` before the script.
- `Results::render( array $config, string $result_id ): array` → `{ id, title, text (kses'd HTML), image (URL or ''), button_label, button_url, product: { name, price_html, image, add_to_cart_url } | null }`.

- [ ] **Step 1: Failing test** `tests/test-shortcode.php`: rendering an unpublished/unknown id outputs an empty string for visitors and a notice for editors; a published quiz outputs the container with `data-quiz`, the inline data does **not** contain `"correct"` or `"points"`, and contains `"hash"`. `Results::render` with a WooCommerce product id returns `product.add_to_cart_url` ending in `?add-to-cart=ID`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement PHP**, then the player in `quiz.js` (IIFE, no dependencies): screens `start → question[i] → email? → result`; state `{ answers: {}, index, startedAt }`; timer via `setInterval`, submits automatically at 0; shuffle with a seeded PRNG (mulberry32 from the token seed) so order is reproducible; `fetch` POST to the REST URL with `Content-Type: application/json`; render errors inline; `Try again` reloads state. Accessibility per spec. CSS: mobile-first, `.bgq` scoped, accent via `var(--bgq-accent)`, progress bar, answer buttons with selected state, result card, product card, reward box. Design pass with the frontend-design skill for this file.
- [ ] **Step 4: Playwright** `tests/e2e/quiz.js` (Playwright installed in the scratchpad as for Gift Cards): create a 3-question score quiz via WP-CLI fixture (`tests/fixtures/sample-score.json`), place the shortcode on a temporary page, take the quiz at 1280 and 375 widths using keyboard only for one question, assert progress, result title, score text, no horizontal overflow, no JS errors; product result shows the Add to cart link.
- [ ] **Step 5: Run PHP tests and e2e, expect PASS.** Commit `feat: shortcode and player`.

### Task 6: Admin builder

**Files:**
- Create: `src/Admin/index.php`, `src/Admin/Builder.php`, `src/Rest/QuizConfigController.php`, `assets/js/builder.js`, `assets/css/admin.css`, `tests/test-builder-rest.php`

**Interfaces:**
- REST `POST /bgq/v1/quizzes/{id}/config` (permission `current_user_can( 'edit_post', $id )`, `X-WP-Nonce`) body = raw config → 200 sanitized config or 400 `{ errors }`. `GET` returns the stored config.
- `Builder::init()`: `replace_editor` filter for `bgq_quiz` renders the builder page (title field, Save button, sections per spec), enqueues `bgq-builder` with `wp_enqueue_media()`, localizes `bgq_builder` (quiz id, config, REST nonce/url, i18n, `gift_cards_active`, `woocommerce_active`, product search endpoint `wc/store/v1/products?search=` when WooCommerce is active).
- Shortcode shown on the Quizzes list table in a new "Shortcode" column.

- [ ] **Step 1: Failing test** `tests/test-builder-rest.php`: as admin, POST a valid config → 200 and `Config::load` matches; POST invalid → 400 with `errors.mode`; as subscriber → 403.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** controller and builder. `builder.js`: state = config; render functions per section; up/down buttons for ordering; media frame for images; result picker for points in outcome mode; per-field error display from the 400 payload; Import (file input, JSON.parse, validate via POST) / Export (download JSON). Keep under 600 lines; split into `builder.js` (state + save) and `builder-ui.js` (rendering) if larger.
- [ ] **Step 4: Manual check** in the browser (Playwright script `tests/e2e/builder.js`: log in with the cookie method from Gift Cards, create a quiz, add 2 questions and 2 results, save, reload, assert persisted).
- [ ] **Step 5: Run tests, lint, Plugin Check. Commit** `feat: quiz builder`.

### Task 7: Entries list and CSV export

**Files:**
- Create: `src/Admin/EntriesTable.php`, `src/Admin/EntriesPage.php`, `src/Admin/Export.php`, `tests/test-export.php`
- Modify: `src/Plugin.php`

**Interfaces:**
- Submenu "Entries" under Quizzes (`manage_options`), `WP_List_Table` columns date, quiz, name, email, score/result, duration; filters: quiz dropdown, search (email/name); bulk delete; "Export CSV" button → `admin-post.php?action=bgq_export&quiz_id=N&_wpnonce=`.
- `Export::csv( array $args ): string` returns CSV text (UTF-8 BOM, header row: Date, Quiz, Name, Email, Score, Correct, Total, Result, Duration (s)); `Export::handle()` streams it as `quiz-entries-{date}.csv`.

- [ ] **Step 1: Failing test**: insert 2 attempts for a quiz, `Export::csv([ 'quiz_id' => $id ])` has 3 lines, correct header, email present, a value containing a comma is quoted.
- [ ] **Step 2–4:** implement, PASS, commit `feat: entries and CSV export`.

### Task 8: Analytics

**Files:**
- Create: `src/Admin/Analytics.php`, `src/Quiz/Stats.php`, `tests/test-stats.php`

**Interfaces:**
- `Stats::for_quiz( int $quiz_id ): array` → `{ attempts, today, last7, last30, avg_score, pass_rate|null, outcomes: [ result_id => count ], questions: [ qid => correct_rate ] }` computed with 2 grouped SQL queries plus one pass over answers JSON limited to the last 1000 attempts.
- Submenu "Analytics" with a quiz selector and plain HTML bar rows (`<div class="bgq-bar" style="width:NN%">`).

- [ ] **Step 1: Failing test**: 4 attempts (scores 100, 50, 0, 100; two today) → attempts 4, today 2, avg 62.5, pass_rate 75 with pass mark 50, per-question correct rate for q1.
- [ ] **Step 2–4:** implement, PASS, commit `feat: analytics`.

### Task 9: Integrations (WooCommerce product result, gift card reward)

**Files:**
- Create: `src/Integrations/index.php`, `src/Integrations/GiftCards.php`, `tests/test-reward.php`
- Modify: `src/Frontend/Results.php` (product card already from Task 5), `src/Rest/AttemptsController.php` (apply `bgq_attempt_reward`)

**Interfaces:**
- `GiftCards::init()` hooks `bgq_attempt_reward` (priority 10, 4 args). `GiftCards::maybe_reward( $reward, int $attempt_id, array $config, array $grade )` → when `class_exists( '\Bgcw\GiftCard\GiftCardCreator' )`, reward enabled, condition met (`always`; `pass` ⇒ `$grade['passed']`; `outcome` ⇒ result id in list), attempt has an email and no `gift_card_id` yet: create the card (`source => 'promotion'`, `send_email => true`, expiry from `expiry_days`), store `gift_card_id` on the attempt, return `[ 'code' => ..., 'amount' => ... ]`.

- [ ] **Step 1: Failing test** `tests/test-reward.php`: with Gift Cards active on the dev site, a passing attempt with email gets a card whose `source` is `promotion` and `recipient_email` matches; a failing attempt gets none; calling twice for the same attempt creates one card; `reward without plugin`: temporarily filter `bgq_gift_cards_available` to false → reward null and no error.
- [ ] **Step 2–4:** implement, PASS, commit `feat: gift card reward`.

### Task 10: Privacy, i18n, readmes, release

**Files:**
- Create: `src/Support/Privacy.php`, `languages/beltoft-quiz.pot`, `languages/beltoft-quiz-pt_PT.po/.mo`, `CLAUDE.md` (ignored), settings page `src/Admin/SettingsPage.php` (single checkbox: delete data on uninstall)
- Modify: `readme.txt`, `README.md`, `uninstall.php`

- [ ] **Step 1:** Privacy: `wp_privacy_personal_data_exporters` / `_erasers` for attempts by email; `wp_add_privacy_policy_content`. Test: exporter returns the attempt for an email; eraser removes it.
- [ ] **Step 2:** `wp i18n make-pot`, translate every string to pt_PT (European Portuguese), `msgfmt --check`, zero untranslated.
- [ ] **Step 3:** readmes: features, FAQ (shortcode, Bricks placement, product results, gift card reward), changelog 1.0.0; both in sync.
- [ ] **Step 4:** Full suite, Plugin Check both modes, Playwright both scripts. Fix until clean.
- [ ] **Step 5:** `gh repo create beltoftandersen/beltoft-quiz --private --source . --push`, tag `1.0.0`, `gh release create 1.0.0`. WordPress.org submission is manual (the deploy workflow needs the SVN slug approved first); note that in the recap.

### Task 11: Code review and fixes
- [ ] Run the code-review skill at high effort on the whole repo, apply confirmed fixes, rerun suite and Plugin Check, release 1.0.1 if anything shipped changed.
