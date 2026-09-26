<?php

namespace Bgq;

use Bgq\Support\Installer;
use Bgq\Quiz\PostType;
use Bgq\Rest\AttemptsController;
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
		Results::init();
		Shortcode::init();
	}
}
