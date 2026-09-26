<?php

namespace Bgq;

use Bgq\Support\Installer;
use Bgq\Quiz\PostType;
use Bgq\Rest\AttemptsController;
use Bgq\Rest\QuizConfigController;
use Bgq\Admin\Builder;
use Bgq\Admin\EntriesPage;
use Bgq\Admin\Export;
use Bgq\Admin\Analytics;
use Bgq\Integrations\GiftCards;
use Bgq\Support\Privacy;
use Bgq\Admin\SettingsPage;
use Bgq\Frontend\Shortcode;
use Bgq\Frontend\Results;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Single wiring point.
	 */
	public static function init() {
		Installer::maybe_upgrade();
		PostType::init();
		add_action( 'rest_api_init', [ AttemptsController::class, 'register' ] );
		add_action( 'rest_api_init', [ QuizConfigController::class, 'register' ] );
		Builder::init(); // Admin-only hooks; inert on the front end and in CLI.
		EntriesPage::init();
		Export::init();
		Analytics::init();
		GiftCards::init();
		Privacy::init();
		SettingsPage::init();
		Results::init();
		Shortcode::init();
	}
}
