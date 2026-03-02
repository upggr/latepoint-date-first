<?php
/**
 * Plugin Name:  LatePoint – Date First Booking
 * Description:  Reorders the LatePoint booking flow so customers pick a date before selecting a service. Only services that have availability on the chosen date are shown. Useful when every service shares the same work schedule (e.g. beach clubs, venues).
 * Version:      1.1.0
 * Author:       Ioannis Kokkinis
 * Author URI:   https://github.com/ioanniskokkinis
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-hosted updates via GitHub releases.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$lp_date_first_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/ioanniskokkinis/latepoint-date-first/',
	__FILE__,
	'latepoint-date-first'
);
$lp_date_first_updater->getVcsApi()->enableReleaseAssets();

/**
 * Remove the built-in rule that forces booking__datepicker to come
 * after booking__services, so the step order set in the LatePoint admin
 * (date → service) passes validation.
 */
add_filter( 'latepoint_get_step_codes_with_rules', function ( array $rules ): array {
	if ( isset( $rules['booking__datepicker']['after'] ) ) {
		unset( $rules['booking__datepicker']['after'] );
	}
	return $rules;
} );

/**
 * When a BookingRequest is built with no service selected (because the
 * customer hasn't reached the service step yet), inject the first active
 * service so the calendar can calculate available days and time slots.
 *
 * The injected values are never persisted – the datepicker view only
 * outputs start_date / start_time hidden fields, not service_id.
 */
add_filter( 'latepoint_create_booking_request_from_booking_model', function ( $booking_request, $booking ) {
	if ( ! empty( $booking_request->service_id ) ) {
		return $booking_request;
	}

	static $fallback = null;
	if ( $fallback === null ) {
		global $wpdb;
		$fallback = $wpdb->get_row(
			"SELECT id, duration FROM {$wpdb->prefix}latepoint_services
			 WHERE status = 'active'
			 ORDER BY order_number ASC
			 LIMIT 1"
		);
	}

	if ( $fallback ) {
		$booking_request->service_id = (int) $fallback->id;
		if ( empty( $booking_request->duration ) ) {
			$booking_request->duration = (int) $fallback->duration;
		}
	}

	return $booking_request;
}, 10, 2 );

/**
 * When the services step loads and a date has already been chosen,
 * filter the services list to only those available on that date.
 *
 * Each service gets one availability check (~3ms each). Results are not
 * cached across requests since availability changes as bookings come in.
 */
add_filter( 'latepoint_prepare_step_vars_for_view', function ( array $vars, $booking_object, $cart_object, string $step_code ): array {
	if ( $step_code !== 'booking__services' ) {
		return $vars;
	}
	if ( empty( $booking_object->start_date ) || empty( $vars['services'] ) ) {
		return $vars;
	}

	try {
		$date = new OsWpDateTime( $booking_object->start_date );
	} catch ( Exception $e ) {
		return $vars;
	}

	$available_services = [];
	foreach ( $vars['services'] as $service ) {
		$booking_request = new \LatePoint\Misc\BookingRequest( [
			'service_id'  => $service->id,
			'agent_id'    => 0,
			'location_id' => $booking_object->location_id ?: 0,
			'start_date'  => $date->format( 'Y-m-d' ),
			'duration'    => $service->duration,
		] );
		$resources = OsResourceHelper::get_resources_grouped_by_day( $booking_request, $date, $date );
		if ( ! empty( $resources[ $date->format( 'Y-m-d' ) ] ) ) {
			$available_services[] = $service;
		}
	}

	$vars['services'] = $available_services;

	return $vars;
}, 10, 4 );
