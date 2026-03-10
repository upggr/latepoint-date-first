<?php
/**
 * Plugin Name:  LatePoint – Date First Booking
 * Description:  Reorders the LatePoint booking flow so customers pick a service before selecting a date. Removes the built-in constraint that forces the datepicker to come after the service step, allowing the step order set in LatePoint admin to be respected.
 * Version:      1.2.0
 * Author:       upggr
 * Author URI:   https://github.com/upggr
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-hosted updates via GitHub releases.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$lp_date_first_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/upggr/latepoint-date-first/',
	__FILE__,
	'latepoint-date-first'
);
$lp_date_first_updater->getVcsApi()->enableReleaseAssets();

/**
 * Remove the built-in rule that forces booking__datepicker to come
 * after booking__services, so the step order set in the LatePoint admin
 * (service → date) passes validation.
 */
add_filter( 'latepoint_get_step_codes_with_rules', function ( array $rules ): array {
	if ( isset( $rules['booking__datepicker']['after'] ) ) {
		unset( $rules['booking__datepicker']['after'] );
	}
	return $rules;
} );
