# Beltoft Quiz — Design

**Date:** 2026-09-26. **Slug:** `beltoft-quiz`. **Namespace:** `Bgq\`. **Prefix:** `bgq_`. **Text domain:** `beltoft-quiz`. Free plugin on WordPress.org, no Pro. Same conventions as Beltoft Gift Cards: hand-rolled PSR-4 autoloader (`src/`), static classes with `init()`, Plugin Check clean, `index.php` guards, readme pair, pt_PT, WP-CLI tests in `tests/`, GitHub Actions deploy on tag.

## Purpose
Shop owners build quizzes in wp-admin; visitors take them through a shortcode (placed in Bricks or any builder). Three uses, one engine: knowledge quiz (score), product/outcome finder (outcome), and lead capture (email before the result, optional gift card reward). Lightweight: no framework, vanilla JS, one post type, one custom table.

## Data model
- Post type `bgq_quiz`: `public => false`, `show_ui => true`, supports title only. Menu "Quizzes".
- Post meta `_bgq_config` (JSON, validated by `Quiz\Config`):
```
{ "mode": "score|outcome",
  "settings": { "timer": 0, "shuffle_questions": false, "shuffle_answers": false, "one_attempt": false,
                "require_email": false, "consent_text": "", "accent": "#1F4A36", "pass_mark": 50,
                "labels": { "start": "Start", "next": "Next", "submit": "See result", "retry": "Try again" } },
  "questions": [ { "id": "q1", "text": "...", "image_id": 0, "type": "single|multiple",
                   "answers": [ { "id": "a1", "text": "...", "correct": true, "points": { "o1": 2 } } ] } ],
  "results":   [ { "id": "r1", "title": "...", "text": "...", "image_id": 0, "button_label": "", "button_url": "",
                   "product_id": 0, "min": 0, "max": 49 } ],
  "reward":    { "enabled": false, "amount": 10, "expiry_days": 365, "condition": "always|pass|outcome", "outcome_ids": [] } }
```
  Score mode: `correct` on answers, `min`/`max` (% inclusive) on results, `pass_mark` %. Outcome mode: `points` per answer keyed by result id; highest total wins, ties → first result in list.
- Table `{prefix}bgq_attempts`: id, quiz_id, user_id (0 = guest), email, name, score (decimal %), correct_count, total_count, result_id, answers (JSON `{question_id: [answer_ids]}`), ip_hash (sha256 of IP + salt, for one-attempt guests), duration_seconds, gift_card_id (nullable), created_at (UTC). Indexes: quiz_id, (quiz_id, user_id), (quiz_id, ip_hash), (quiz_id, email).

## Front end
- Shortcode `[beltoft_quiz id="123"]`. Renders `<div class="bgq" data-quiz="123">` plus the public config via `wp_add_inline_script` (`bgq_data_{id}`): questions and answers **without** `correct`/`points`, results without ranges, settings, labels, and a start token.
- Vanilla JS (`assets/js/quiz.js`): start screen (title, question count, timer notice) → one question per screen (Next/Back, progress bar, optional timer countdown) → optional email/name/consent step → submit → result screen (title, text, image, button, product card with price + Add to cart link, reward notice with code). Client shuffles when enabled using the order from the server token. Keyboard accessible: answers are `<button role="radio|checkbox">`, focus managed per screen, `aria-live` for the timer and result.
- Grading is server-side only. `POST /wp-json/bgq/v1/attempts` `{ quiz_id, token, answers, email?, name?, consent? }` → `{ result: {...}, score, correct_count, total_count, reward: { code, amount } | null }`. Nonce not required for guests; abuse limited by the start token and per-IP rate limit (10 submissions / 10 min).
- Start token: `wp_hash( quiz_id . '|' . started_at . '|' . seed )` with `started_at` and `seed` in the payload. Server rejects tokens older than timer + 30 s (when timer > 0) or 24 h, and a bad hash. `seed` drives shuffling so the server can reproduce the order.
- One attempt: logged-in by user_id, guests by ip_hash and (when email is required) by email. Second attempt returns the stored result with `already: true`.
- CSS (`assets/css/quiz.css`): accent from settings via inline custom property; inherits theme fonts; mobile first.

## Admin
- Builder replaces the block editor for `bgq_quiz` (`replace_editor` → own screen). Vanilla JS builder saves JSON via `POST /wp-json/bgq/v1/quizzes/{id}/config` (capability `edit_post`, REST nonce). Sections: Mode + Settings, Questions (add, reorder with up/down buttons, image via media library, answers with correct checkbox or per-result points), Results, Reward (only when Gift Cards is active), Embed (shortcode to copy), Import/Export JSON.
- Submenu **Entries**: `WP_List_Table` of attempts (date, quiz, name/email, score or result, duration), filter by quiz, search by email, delete, and "Export CSV" (admin-post, `manage_options`, nonce).
- Submenu **Analytics**: per quiz: attempts, completions today/7d/30d, average score, pass rate (score mode), outcome distribution (outcome mode), per-question correct rate. Plain HTML bars, no chart library.
- Capability: `manage_options` for entries/analytics/export; quiz editing follows post capabilities.

## Integrations
- **WooCommerce** (optional): result with `product_id` renders name, image, price, and a link `?add-to-cart={id}` to the cart. Guarded by `function_exists( 'wc_get_product' )`.
- **Beltoft Gift Cards** (optional): reward creates a card via `\Bgcw\GiftCard\GiftCardCreator::create_manual([ 'amount', 'source' => 'promotion', 'recipient_email', 'recipient_name', 'expires_at', 'send_email' => true ])` when the attempt qualifies and an email is known (captured or logged-in user). One reward per attempt row; the code is shown on the result screen and emailed by the Gift Cards plugin. Guarded by `class_exists`.

## Privacy
Emails and IP hashes are personal data: the plugin registers a privacy policy snippet, exports/erases attempts by email through WordPress's personal data tools, and `uninstall.php` drops the table and posts when the "delete data on uninstall" option (Settings → Quizzes, the only global option) is on.

## Error handling
- Invalid config on save → 400 with field-level messages; the builder shows them inline and never persists a broken quiz.
- Submit with an unknown quiz, unpublished quiz, bad token, expired timer, or missing required email → 4xx with a translatable message; the JS shows it and offers retry.
- Reward creation failure → result still shown, reward null, error logged (`bgq` source via WooCommerce logger when available, else `error_log`).

## Testing
WP-CLI eval-file tests (`tests/run.sh`, same harness as Gift Cards, mail blocked): Config validation/sanitization and public stripping; Grader for both modes incl. ties, multiple-choice partial credit rule (a multiple question is correct only when the selected set equals the correct set); token issue/verify/expiry; attempts REST (happy path, bad token, one-attempt, email required, rate limit); CSV export content; analytics numbers; reward creation with Gift Cards mocked present. Playwright: take a 3-question score quiz at desktop and 375px, check keyboard navigation, timer, result, and a product result.

## Release
Version 1.0.0. GitHub repo `beltoftandersen/beltoft-quiz`, deploy workflow identical to Gift Cards, `.distignore` excludes tests/docs/dotfiles.
