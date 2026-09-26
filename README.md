# Beltoft Quiz

Build score and outcome quizzes, capture leads, recommend products and reward with gift cards. Lightweight, no framework.

- Stable version: 1.0.2
- Requires: WordPress 5.8+, PHP 7.4+ (tested up to WordPress 7.1)
- Author: beltoft.net
- Text domain: beltoft-quiz

## Features

- Score quizzes with right and wrong answers, a pass mark and results by score range.
- Outcome quizzes where answers add points to results, e.g. "which perfume suits you".
- Lead capture: name and email before the result, with a consent checkbox.
- Entries in admin with search, filters and CSV export.
- Analytics: attempts, average score, pass rate, outcome distribution, per-question correct rate.
- WooCommerce: a result can be a product, shown with price and an Add to cart button.
- Gift card reward with Beltoft Gift Cards installed.
- Timer, question and answer shuffle, one attempt per visitor.
- Vanilla JavaScript, no framework, keyboard accessible.

## Usage

Build a quiz under **Quizzes > Add New** and place `[beltoft_quiz id="123"]` anywhere, including a Bricks shortcode element.

## Integrations

- **WooCommerce**: a result can be a product, shown with price and Add to cart.
- **Beltoft Gift Cards**: send a gift card always, on passing, or for chosen results.

## Privacy

Entries are included in WordPress's personal data export and erasure tools, and a suggested privacy policy text is registered.

## Changelog

### 1.0.2

- Changed: New front-end design for the quiz player: square corners, a segmented progress strip (one block per question), solid tick boxes, rectangular buttons and a single accent rule. Inherits the theme font; the accent still comes from the quiz settings.
- Changed: The timer turns red for the last ten seconds and is announced to screen readers at one minute and ten seconds instead of every second.
- Fixed: Deleting a quiz now deletes its attempts.
- Fixed: Logged-in visitors are matched by user only for the one-attempt rule, never by IP address.
- Fixed: The dashboard's "today" count follows the site timezone.
- Fixed: Default labels are no longer stored in the quiz, so they translate when the site language changes.
- Fixed: Importing a quiz JSON file validates it on the server before anything is replaced.
- Fixed: Builder saves go through wp.apiFetch, so REST nonces are refreshed on long editing sessions.
- Fixed: The builder keeps keyboard focus after adding a question, answer or result, and picker fields are labelled for screen readers.
- Fixed: The analytics quiz selector no longer relies on inline JavaScript.
- Fixed: CSV export neutralises cells starting with a tab or carriage return.

### 1.0.1

- Fixed: The quiz start token is now issued when the visitor presses Start through an uncacheable request, so page caches can no longer share one session between visitors or expire the quiz; the duplicate-submit guard is per visitor.
- Fixed: The timer counts from the moment Start is pressed, matching the server.
- Fixed: Logged-in visitors are recognised on submit (one attempt per user, reward to their account email).
- Fixed: Contributors can save a quiz but no longer publish it through the builder.
- Fixed: One gift card per email per quiz, so reloading and resubmitting cannot mint more cards.
- Fixed: After an expired or invalid session, Try again reloads the page instead of failing repeatedly.

### 1.0.0

- Initial release.
