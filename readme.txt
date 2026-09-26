=== Beltoft Quiz ===
Contributors: christian198521, beltoftnet
Tags: quiz, survey, lead generation, product finder, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build score and outcome quizzes, capture leads, recommend products and reward with gift cards. Lightweight, no framework.

== Description ==

Beltoft Quiz lets you build quizzes in wp-admin and place them anywhere with a shortcode.

* **Score quizzes** with right and wrong answers, a pass mark and results by score range.
* **Outcome quizzes** where answers add points to results, e.g. "which perfume suits you".
* **Lead capture**: ask for name and email before the result, with a consent checkbox.
* **Entries** in admin with search, filters and CSV export.
* **Analytics**: attempts, average score, pass rate, outcome distribution, per-question correct rate.
* **WooCommerce**: a result can be a product, shown with price and an Add to cart button.
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

With WooCommerce active, pick a product on a result. The result screen shows the product with its price and an Add to cart button.

= How does the gift card reward work? =

With Beltoft Gift Cards active, enable the reward on a quiz and choose the amount and when to send it: always, when the quiz is passed, or for chosen results. The card is emailed to the address the visitor entered, or to the logged-in user's email.

= Is visitor data covered by the privacy tools? =

Yes. Entries are included in WordPress's personal data export and erasure by email, and a suggested privacy policy text is provided.

== Changelog ==

= 1.0.0 =
* Initial release.
