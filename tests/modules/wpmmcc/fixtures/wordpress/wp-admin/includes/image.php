<?php
/**
 * TEST SHIM ONLY — do not confuse with WordPress core.
 *
 * In the private standalone test harness ABSPATH points at this fixture,
 * so the REST media controller's
 * `require_once ABSPATH . 'wp-admin/includes/image.php'` resolves HERE.
 * All real media helpers are already provided by the file.php shim, so
 * this file only guarantees the include succeeds; a production
 * environment loads the genuine WordPress core file instead.
 *
 * @package WPMMCC\Tests\Shim
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/file.php';
