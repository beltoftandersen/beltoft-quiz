=== Beltoft Quiz ===
Contributors: christian198521, beltoftnet
Tags: quiz, survey, lead generation, product finder, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build score and outcome quizzes, capture leads, recommend products and reward with gift cards. Lightweight, no framework.

== Description ==

Beltoft Quiz lets you build quizzes in wp-admin and place them anywhere with a shortcode.

* **Score quizzes** with right and wrong answers, a pass mark and results by score range.
* **Outcome quizzes** where answers add points to results, e.g. "which perfume suits you".
* **Product Finder** with weighted recommend/boost and exclude rules for products, categories, tags, and attributes.
* **Lead capture**: ask for name and email before the result, with a consent checkbox.
* **Entries** in admin with search, filters and CSV export.
* **Analytics**: attempts, average score, pass rate, outcome distribution, per-question correct rate.
* **WooCommerce**: recommend products from the winning result or rank products matched to selected answers.
* **Gift card reward**: with Beltoft Gift Cards installed, send a gift card on completion, on passing, or for chosen outcomes.
* Timer, question and answer shuffle, one attempt per visitor.
* Vanilla JavaScript, no framework, accessible by keyboard.

== Installation ==

1. Upload the plugin to `wp-content/plugins/` or install from a ZIP, then activate it.
2. Go to **Quizzes > Add New**, build your quiz and save.
3. Place `[beltoft_quiz id="123"]` in any page, post or builder element.

== Frequently Asked Questions ==

= Does it work with Bricks or other page builders? =

Yes. Add a shortcode element and paste the quiz shortcode shown on the Quizzes list.

= Where are the answers graded? =

On the server. Correct answers and points never reach the browser.

= How do product results work? =

With WooCommerce active, choose one of two explicit methods. A quiz can show products assigned to its winning result, or count product matches on selected answers and show the strongest matches first. The builder's recommendation map shows the complete setup.

= How does the gift card reward work? =

With Beltoft Gift Cards active, enable the reward on a quiz and choose the amount and when to send it: always, when the quiz is passed, or for chosen results. The card is emailed to the address the visitor entered, or to the logged-in user's email.

= Is visitor data covered by the privacy tools? =

Yes. Entries are included in WordPress's personal data export and erasure by email, and a suggested privacy policy text is provided.

== Changelog ==

= 1.2.2 =
* Improved answer-product editing with explicit Edit, Replace, and Remove controls.
* Clarified one-result outcome quizzes by hiding meaningless point fields.
* Added visible empty and error states to product search.

= 1.2.1 =
* Fixed server-side answer validation for missing, unknown, and multiple single-choice answers.
* Made gift-card reward claims concurrency-safe.
* Removed the 200-product category-rule limit and excluded hidden or out-of-stock products.
* Enforced Product Finder completeness during server validation.
* Preserved recommendation IDs and reasons with each attempt.
* Hardened newsletter webhooks, and cached repeated product-name lookups in the builder.

= 1.2.0 =
* Added a guided five-step quiz builder.
* Added a dedicated Product Finder quiz type with weighted recommend/boost and absolute exclude rules.
* Added category, tag, and global product-attribute targeting alongside individual products.
* Added setup warnings for unmapped answers, missing candidates, and directly unreachable products.
* Added a no-save recommendation simulator with ranked products and recommendation reasons.
* Added optional “recommended because” explanations to frontend product cards.

= 1.1.0 =
* Added an explicit product recommendation method: use the winning result's products or rank products matched to selected answers.
* Added a live recommendation map showing every answer's effect on results and products.
* Changed the builder order so results/outcomes are defined before answers point to them.
* Clarified outcome scoring, tie-breaking, product ranking, and fallback behavior in the builder.
* Preserved older quizzes by inferring their existing recommendation method without rewriting stored data.

= 1.0.2 =
* Changed: New front-end design for the quiz player: square corners, a segmented progress strip (one block per question), solid tick boxes, rectangular buttons and a single accent rule. Inherits the theme font; the accent still comes from the quiz settings.
* Changed: The timer turns red for the last ten seconds and is announced to screen readers at one minute and ten seconds instead of every second.
* Fixed: Deleting a quiz now deletes its attempts.
* Fixed: Logged-in visitors are matched by user only for the one-attempt rule, never by IP address.
* Fixed: The dashboard's "today" count follows the site timezone.
* Fixed: Default labels are no longer stored in the quiz, so they translate when the site language changes.
* Fixed: Importing a quiz JSON file validates it on the server before anything is replaced.
* Fixed: Builder saves go through wp.apiFetch, so REST nonces are refreshed on long editing sessions.
* Fixed: The builder keeps keyboard focus after adding a question, answer or result, and picker fields are labelled for screen readers.
* Fixed: The analytics quiz selector no longer relies on inline JavaScript.
* Fixed: CSV export neutralises cells starting with a tab or carriage return.

= 1.0.1 =
* Fixed: The quiz start token is now issued when the visitor presses Start through an uncacheable request, so page caches can no longer share one session between visitors or expire the quiz; the duplicate-submit guard is per visitor.
* Fixed: The timer counts from the moment Start is pressed, matching the server.
* Fixed: Logged-in visitors are recognised on submit (one attempt per user, reward to their account email).
* Fixed: Contributors can save a quiz but no longer publish it through the builder.
* Fixed: One gift card per email per quiz, so reloading and resubmitting cannot mint more cards.
* Fixed: After an expired or invalid session, Try again reloads the page instead of failing repeatedly.

= 1.0.0 =
* Initial release.
