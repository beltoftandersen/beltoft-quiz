<?php

namespace Bgq;

use Bgq\Support\Installer;
use Bgq\Quiz\PostType;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Single wiring point.
	 */
	public static function init() {
		Installer::maybe_upgrade();
		PostType::init();
	}
}
