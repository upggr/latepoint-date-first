<?php
/**
 * Plugin Name:  LatePoint – Date First Booking
 * Description:  Adds a [latepoint_date_first] shortcode that lets customers pick a date, checks which services are available on that date, then opens a pre-filled LatePoint booking form for the chosen date.
 * Version:      2.0.0
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
 * after booking__services, so a custom step order passes validation.
 */
add_filter( 'latepoint_get_step_codes_with_rules', function ( array $rules ): array {
	if ( isset( $rules['booking__datepicker']['after'] ) ) {
		unset( $rules['booking__datepicker']['after'] );
	}
	return $rules;
} );

// ---------------------------------------------------------------------------
// Shortcode: [latepoint_date_first]
//
// Attributes:
//   location_id  – restrict to a specific location (default: any)
//   agent_id     – restrict to a specific agent (default: any)
//   months       – how many months of calendar to show (default: 1)
//   button_label – label on the CTA button (default: "Check Availability")
// ---------------------------------------------------------------------------

add_shortcode( 'latepoint_date_first', 'lpdf_shortcode' );

function lpdf_shortcode( $atts ): string {
	$atts = shortcode_atts( [
		'location_id'  => 0,
		'agent_id'     => 0,
		'months'       => 1,
		'button_label' => __( 'Check Availability', 'latepoint-date-first' ),
	], $atts, 'latepoint_date_first' );

	$uid = 'lpdf_' . uniqid();

	ob_start();
	?>
	<div class="lpdf-wrapper" id="<?php echo esc_attr( $uid ); ?>"
		data-location="<?php echo esc_attr( (int) $atts['location_id'] ); ?>"
		data-agent="<?php echo esc_attr( (int) $atts['agent_id'] ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'lpdf_availability' ) ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

		<div class="lpdf-calendar-wrap">
			<div class="lpdf-nav">
				<button class="lpdf-prev" aria-label="<?php esc_attr_e( 'Previous month', 'latepoint-date-first' ); ?>">&#8592;</button>
				<span class="lpdf-month-label"></span>
				<button class="lpdf-next" aria-label="<?php esc_attr_e( 'Next month', 'latepoint-date-first' ); ?>">&#8594;</button>
			</div>
			<div class="lpdf-grid"></div>
		</div>

		<div class="lpdf-result" style="display:none;">
			<p class="lpdf-availability-msg"></p>
			<div class="lpdf-booking-form"></div>
		</div>
	</div>

	<style>
	.lpdf-wrapper { font-family: inherit; max-width: 420px; }
	.lpdf-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
	.lpdf-nav button { background:none; border:1px solid #ccc; border-radius:4px; padding:4px 12px; cursor:pointer; font-size:18px; line-height:1; }
	.lpdf-nav button:hover { background:#f5f5f5; }
	.lpdf-month-label { font-weight:600; font-size:16px; }
	.lpdf-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
	.lpdf-grid .lpdf-day-name { text-align:center; font-size:11px; font-weight:600; color:#888; padding:4px 0; }
	.lpdf-grid .lpdf-day { text-align:center; padding:8px 4px; border-radius:6px; cursor:pointer; font-size:14px; border:1px solid transparent; transition:background .15s; }
	.lpdf-grid .lpdf-day:hover:not(.lpdf-past):not(.lpdf-empty) { background:#e8f4fd; border-color:#aad4f5; }
	.lpdf-grid .lpdf-day.lpdf-selected { background:#0073aa; color:#fff; border-color:#0073aa; }
	.lpdf-grid .lpdf-day.lpdf-past { color:#ccc; cursor:default; }
	.lpdf-grid .lpdf-day.lpdf-empty { cursor:default; }
	.lpdf-grid .lpdf-day.lpdf-loading { opacity:.5; cursor:wait; }
	.lpdf-availability-msg { margin:12px 0 8px; font-size:14px; }
	.lpdf-result .latepoint-book-form-wrapper { margin-top:12px; }
	</style>

	<script>
	(function(){
		var el = document.getElementById(<?php echo json_encode( $uid ); ?>);
		if (!el) return;

		var today     = new Date(); today.setHours(0,0,0,0);
		var cursor    = new Date(today.getFullYear(), today.getMonth(), 1);
		var selected  = null;
		var locationId = el.dataset.location;
		var agentId    = el.dataset.agent;
		var nonce      = el.dataset.nonce;
		var ajaxUrl    = el.dataset.ajax;

		var grid       = el.querySelector('.lpdf-grid');
		var monthLabel = el.querySelector('.lpdf-month-label');
		var result     = el.querySelector('.lpdf-result');
		var msg        = el.querySelector('.lpdf-availability-msg');
		var formWrap   = el.querySelector('.lpdf-booking-form');

		var dayNames = <?php echo json_encode( array_values( (array) (function() {
			$days = [];
			for ( $i = 0; $i < 7; $i++ ) {
				$days[] = date_i18n( 'D', strtotime( "Sunday +{$i} days" ) );
			}
			return $days;
		})() ) ); ?>;

		var months = <?php echo json_encode( array_values( (array) (function() {
			$m = [];
			for ( $i = 1; $i <= 12; $i++ ) {
				$m[] = date_i18n( 'F', mktime( 0, 0, 0, $i, 1 ) );
			}
			return $m;
		})() ) ); ?>;

		function pad(n){ return n < 10 ? '0'+n : ''+n; }
		function fmtDate(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }

		function renderCalendar() {
			monthLabel.textContent = months[cursor.getMonth()] + ' ' + cursor.getFullYear();
			grid.innerHTML = '';
			dayNames.forEach(function(n){
				var h = document.createElement('div');
				h.className = 'lpdf-day-name'; h.textContent = n; grid.appendChild(h);
			});
			var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1).getDay();
			for (var i=0; i<first; i++){
				var empty = document.createElement('div');
				empty.className='lpdf-day lpdf-empty'; grid.appendChild(empty);
			}
			var days = new Date(cursor.getFullYear(), cursor.getMonth()+1, 0).getDate();
			for (var d=1; d<=days; d++){
				var day = document.createElement('div');
				day.className = 'lpdf-day';
				day.textContent = d;
				var dt = new Date(cursor.getFullYear(), cursor.getMonth(), d);
				var dtStr = fmtDate(dt);
				if (dt < today) {
					day.classList.add('lpdf-past');
				} else {
					if (selected === dtStr) day.classList.add('lpdf-selected');
					day.addEventListener('click', (function(s){ return function(){ selectDate(s); }; })(dtStr));
				}
				grid.appendChild(day);
			}
		}

		function selectDate(dateStr) {
			selected = dateStr;
			renderCalendar();
			result.style.display = 'none';
			formWrap.innerHTML   = '';
			msg.textContent      = '';

			// mark selected day as loading
			var cells = grid.querySelectorAll('.lpdf-day.lpdf-selected');
			cells.forEach(function(c){ c.classList.add('lpdf-loading'); });

			var fd = new FormData();
			fd.append('action',      'lpdf_check_availability');
			fd.append('nonce',       nonce);
			fd.append('date',        dateStr);
			fd.append('location_id', locationId);
			fd.append('agent_id',    agentId);

			fetch(ajaxUrl, { method:'POST', body:fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					cells.forEach(function(c){ c.classList.remove('lpdf-loading'); });
					result.style.display = '';
					if (data.success && data.data.count > 0) {
						msg.textContent = data.data.count + ' <?php echo esc_js( __( 'option(s) available on this date. Select below to complete your booking.', 'latepoint-date-first' ) ); ?>';
						formWrap.innerHTML = data.data.form_html;
						// re-init LatePoint widgets inside injected HTML
						if (window.LatePoint && typeof window.LatePoint.initBookingForms === 'function') {
							window.LatePoint.initBookingForms();
						} else if (typeof window.latepoint_init === 'function') {
							window.latepoint_init();
						} else {
							// trigger native DOMContentLoaded-style event LatePoint listens to
							document.dispatchEvent(new Event('latepoint_init'));
							// fallback: look for LatePoint's own initializer on the global object
							if (window.osBookingForm && typeof window.osBookingForm.init === 'function') {
								window.osBookingForm.init();
							}
						}
					} else {
						msg.textContent = '<?php echo esc_js( __( 'No availability on this date. Please try another day.', 'latepoint-date-first' ) ); ?>';
					}
				})
				.catch(function(){
					cells.forEach(function(c){ c.classList.remove('lpdf-loading'); });
					msg.textContent = '<?php echo esc_js( __( 'Could not check availability. Please try again.', 'latepoint-date-first' ) ); ?>';
					result.style.display = '';
				});
		}

		el.querySelector('.lpdf-prev').addEventListener('click', function(){
			cursor.setMonth(cursor.getMonth()-1); renderCalendar();
		});
		el.querySelector('.lpdf-next').addEventListener('click', function(){
			cursor.setMonth(cursor.getMonth()+1); renderCalendar();
		});

		renderCalendar();
	})();
	</script>
	<?php
	return ob_get_clean();
}

// ---------------------------------------------------------------------------
// AJAX handler: check availability for a given date
// Returns: { count: N, form_html: "..." }
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_lpdf_check_availability',        'lpdf_ajax_check_availability' );
add_action( 'wp_ajax_nopriv_lpdf_check_availability', 'lpdf_ajax_check_availability' );

function lpdf_ajax_check_availability(): void {
	check_ajax_referer( 'lpdf_availability', 'nonce' );

	$date_str    = sanitize_text_field( $_POST['date'] ?? '' );
	$location_id = (int) ( $_POST['location_id'] ?? 0 );
	$agent_id    = (int) ( $_POST['agent_id'] ?? 0 );

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
		wp_send_json_error( [ 'message' => 'Invalid date.' ] );
	}

	try {
		$date = new OsWpDateTime( $date_str );
	} catch ( Exception $e ) {
		wp_send_json_error( [ 'message' => 'Invalid date.' ] );
	}

	// Load all active services.
	$services = OsServiceModel::where( [ 'status' => 'active' ] )->get_results_as_models();

	$available_ids = [];
	foreach ( $services as $service ) {
		$booking_request = new \LatePoint\Misc\BookingRequest( [
			'service_id'  => $service->id,
			'agent_id'    => $agent_id ?: 0,
			'location_id' => $location_id ?: 0,
			'start_date'  => $date_str,
			'duration'    => $service->duration,
		] );
		$resources = OsResourceHelper::get_resources_grouped_by_day( $booking_request, $date, $date );
		if ( ! empty( $resources[ $date_str ] ) ) {
			$available_ids[] = (int) $service->id;
		}
	}

	$count = count( $available_ids );

	if ( $count === 0 ) {
		wp_send_json_success( [ 'count' => 0, 'form_html' => '' ] );
	}

	// Build the LatePoint booking form pre-filled with the chosen date.
	// We don't pre-select a service — the customer picks from the (already
	// rendered services step which will only show available ones via the
	// latepoint_prepare_step_vars_for_view filter below).
	$form_html = do_shortcode(
		sprintf(
			'[latepoint_book_form selected_start_date="%s" calendar_start_date="%s"%s]',
			esc_attr( $date_str ),
			esc_attr( $date_str ),
			$location_id ? ' selected_location="' . esc_attr( $location_id ) . '"' : ''
		)
	);

	wp_send_json_success( [
		'count'     => $count,
		'form_html' => $form_html,
	] );
}

// ---------------------------------------------------------------------------
// Filter the services step to only show services available on the pre-selected
// date (passed in from the booking form via selected_start_date).
// ---------------------------------------------------------------------------

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

	$date_str          = $date->format( 'Y-m-d' );
	$available_services = [];

	foreach ( $vars['services'] as $service ) {
		$booking_request = new \LatePoint\Misc\BookingRequest( [
			'service_id'  => $service->id,
			'agent_id'    => 0,
			'location_id' => $booking_object->location_id ?: 0,
			'start_date'  => $date_str,
			'duration'    => $service->duration,
		] );
		$resources = OsResourceHelper::get_resources_grouped_by_day( $booking_request, $date, $date );
		if ( ! empty( $resources[ $date_str ] ) ) {
			$available_services[] = $service;
		}
	}

	$vars['services'] = $available_services;
	return $vars;
}, 10, 4 );
