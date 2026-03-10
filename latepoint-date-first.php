<?php
/**
 * Plugin Name:  LatePoint – Date First Booking
 * Description:  Adds a custom booking modal triggered by .latepoint-date-first buttons. Guides the user through category → sub-category → date → available service, then fires the native LatePoint booking modal pre-filled.
 * Version:      3.0.0
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
 * after booking__services.
 */
add_filter( 'latepoint_get_step_codes_with_rules', function ( array $rules ): array {
	if ( isset( $rules['booking__datepicker']['after'] ) ) {
		unset( $rules['booking__datepicker']['after'] );
	}
	return $rules;
} );

// ---------------------------------------------------------------------------
// Inject modal HTML + CSS + JS into the footer (once per page)
// ---------------------------------------------------------------------------

add_action( 'wp_footer', 'lpdf_render_modal' );

function lpdf_render_modal(): void {
	$day_names = [];
	for ( $i = 0; $i < 7; $i++ ) {
		$day_names[] = date_i18n( 'D', strtotime( "Sunday +{$i} days" ) );
	}
	$month_names = [];
	for ( $i = 1; $i <= 12; $i++ ) {
		$month_names[] = date_i18n( 'F', mktime( 0, 0, 0, $i, 1 ) );
	}

	?>
	<!-- LatePoint Date First Modal -->
	<div id="lpdf-overlay" style="display:none;" aria-modal="true" role="dialog" aria-label="<?php esc_attr_e( 'Booking', 'latepoint-date-first' ); ?>">
		<div id="lpdf-modal">
			<button id="lpdf-close" aria-label="<?php esc_attr_e( 'Close', 'latepoint-date-first' ); ?>">&times;</button>
			<div id="lpdf-breadcrumb"></div>
			<div id="lpdf-body"></div>
		</div>
	</div>

	<style>
	#lpdf-overlay {
		position:fixed; inset:0; background:rgba(0,0,0,.55);
		z-index:99999; display:flex; align-items:center; justify-content:center;
	}
	#lpdf-modal {
		background:#fff; border-radius:12px; padding:28px;
		width:min(480px,94vw); max-height:88vh; overflow-y:auto;
		position:relative; box-shadow:0 8px 40px rgba(0,0,0,.18);
	}
	#lpdf-close {
		position:absolute; top:14px; right:16px;
		background:none; border:none; font-size:22px; cursor:pointer; line-height:1; color:#666;
	}
	#lpdf-close:hover { color:#000; }
	#lpdf-breadcrumb {
		display:flex; flex-wrap:wrap; gap:4px; align-items:center;
		font-size:13px; color:#888; margin-bottom:18px; min-height:20px;
	}
	#lpdf-breadcrumb .lpdf-bc-item { cursor:pointer; color:#0073aa; }
	#lpdf-breadcrumb .lpdf-bc-item:hover { text-decoration:underline; }
	#lpdf-breadcrumb .lpdf-bc-sep { color:#ccc; }

	/* Category list */
	.lpdf-list { display:flex; flex-direction:column; gap:10px; }
	.lpdf-list-btn {
		display:flex; align-items:center; justify-content:space-between;
		width:100%; padding:14px 18px; border-radius:8px; cursor:pointer;
		border:1px solid #e0e0e0; background:#fafafa; text-align:left;
		font-size:15px; font-family:inherit; font-weight:600;
		transition:background .15s, border-color .15s;
	}
	.lpdf-list-btn:hover { background:#f0f7ff; border-color:#0073aa; }
	.lpdf-list-btn .lpdf-arrow { color:#aaa; font-size:18px; }

	/* Calendar */
	.lpdf-cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
	.lpdf-cal-nav button { background:none; border:1px solid #ccc; border-radius:4px; padding:4px 12px; cursor:pointer; font-size:18px; line-height:1; }
	.lpdf-cal-nav button:hover { background:#f5f5f5; }
	.lpdf-cal-month { font-weight:600; font-size:16px; }
	.lpdf-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
	.lpdf-grid .lpdf-dn { text-align:center; font-size:11px; font-weight:600; color:#888; padding:4px 0; }
	.lpdf-grid .lpdf-day { text-align:center; padding:9px 4px; border-radius:6px; cursor:pointer; font-size:14px; border:1px solid transparent; transition:background .15s; }
	.lpdf-grid .lpdf-day:hover:not(.lpdf-past):not(.lpdf-empty) { background:#e8f4fd; border-color:#aad4f5; }
	.lpdf-grid .lpdf-day.lpdf-selected { background:#0073aa; color:#fff; border-color:#0073aa; }
	.lpdf-grid .lpdf-day.lpdf-past { color:#ccc; cursor:default; }
	.lpdf-grid .lpdf-day.lpdf-empty { cursor:default; }
	.lpdf-grid .lpdf-day.lpdf-loading { opacity:.5; cursor:wait; }

	/* Services list */
	.lpdf-service-btn {
		display:flex; align-items:center; justify-content:space-between;
		width:100%; padding:14px 18px; border-radius:8px; cursor:pointer;
		border:1px solid #e0e0e0; background:#fafafa; text-align:left;
		font-size:14px; font-family:inherit;
		transition:background .15s, border-color .15s;
	}
	.lpdf-service-btn:hover { background:#f0fff4; border-color:#00a32a; }
	.lpdf-service-name { font-weight:600; }
	.lpdf-service-meta { font-size:12px; color:#888; white-space:nowrap; margin-left:12px; }
	.lpdf-no-avail { font-size:14px; color:#888; margin:8px 0 0; }
	.lpdf-spinner { text-align:center; padding:20px; color:#888; font-size:14px; }
	</style>

	<script>
	(function(){
		var overlay   = document.getElementById('lpdf-overlay');
		var body      = document.getElementById('lpdf-body');
		var breadcrumb = document.getElementById('lpdf-breadcrumb');
		var closeBtn  = document.getElementById('lpdf-close');

		if (!overlay) return;

		var AJAX_URL  = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var NONCE     = <?php echo json_encode( wp_create_nonce( 'lpdf_availability' ) ); ?>;
		var DAY_NAMES   = <?php echo json_encode( $day_names ); ?>;
		var MONTH_NAMES = <?php echo json_encode( $month_names ); ?>;
		var NO_AVAIL  = <?php echo json_encode( __( 'No availability on this date. Please try another day.', 'latepoint-date-first' ) ); ?>;
		var ERROR_MSG = <?php echo json_encode( __( 'Could not load availability. Please try again.', 'latepoint-date-first' ) ); ?>;

		// State
		var state = {};

		function resetState() {
			state = { locationId: 0, agentId: 0, crumbs: [], categoryId: null, date: null };
		}

		// ---- Open / close ----

		function open(trigger) {
			resetState();
			state.locationId = trigger.dataset.location || 0;
			state.agentId    = trigger.dataset.agent    || 0;
			overlay.style.display = 'flex';
			document.body.style.overflow = 'hidden';
			showCategories(0); // start at root
		}

		function close() {
			overlay.style.display = 'none';
			document.body.style.overflow = '';
			body.innerHTML = '';
			breadcrumb.innerHTML = '';
		}

		closeBtn.addEventListener('click', close);
		overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
		document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });

		// Attach to all .latepoint-date-first triggers (present + future via delegation)
		document.addEventListener('click', function(e){
			var trigger = e.target.closest('.latepoint-date-first');
			if (trigger) { e.preventDefault(); open(trigger); }
		});

		// ---- Breadcrumb ----

		function renderBreadcrumb() {
			breadcrumb.innerHTML = '';
			state.crumbs.forEach(function(crumb, i){
				if (i > 0) {
					var sep = document.createElement('span');
					sep.className = 'lpdf-bc-sep'; sep.textContent = '›';
					breadcrumb.appendChild(sep);
				}
				var item = document.createElement('span');
				item.className = 'lpdf-bc-item';
				item.textContent = crumb.label;
				item.addEventListener('click', (function(idx){ return function(){
					state.crumbs = state.crumbs.slice(0, idx + 1);
					crumb.action();
				}; })(i));
				breadcrumb.appendChild(item);
			});
		}

		function pushCrumb(label, action) {
			state.crumbs.push({ label: label, action: action });
			renderBreadcrumb();
		}

		// ---- Views ----

		function setBody(html) { body.innerHTML = html; }
		function spinner()    { setBody('<div class="lpdf-spinner">' + <?php echo json_encode( __( 'Loading…', 'latepoint-date-first' ) ); ?> + '</div>'); }

		// Show categories whose parent_id = parentId
		function showCategories(parentId) {
			spinner();
			var fd = new FormData();
			fd.append('action',    'lpdf_get_categories');
			fd.append('nonce',     NONCE);
			fd.append('parent_id', parentId);

			fetch(AJAX_URL, { method:'POST', body:fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (!data.success || !data.data.categories.length) {
						// No sub-categories — go straight to date picker
						showDatePicker();
						return;
					}
					renderCategoryList(data.data.categories);
				})
				.catch(function(){ setBody('<p class="lpdf-no-avail">' + ERROR_MSG + '</p>'); });
		}

		function renderCategoryList(cats) {
			var wrap = document.createElement('div');
			wrap.className = 'lpdf-list';
			cats.forEach(function(cat){
				var btn = document.createElement('button');
				btn.className = 'lpdf-list-btn';
				btn.innerHTML = '<span>' + escHtml(cat.name) + '</span><span class="lpdf-arrow">›</span>';
				btn.addEventListener('click', function(){
					state.categoryId = cat.id;
					pushCrumb(cat.name, function(){ state.categoryId = cat.id; showCategories(cat.id); });
					// Check if this category has children; showCategories will fall through to date picker if not
					showCategories(cat.id);
				});
				wrap.appendChild(btn);
			});
			body.innerHTML = '';
			body.appendChild(wrap);
		}

		// ---- Date picker ----

		var calCursor = null;

		function showDatePicker() {
			var today = new Date(); today.setHours(0,0,0,0);
			calCursor = new Date(today.getFullYear(), today.getMonth(), 1);
			state.date = null;

			pushCrumb(<?php echo json_encode( __( 'Pick a date', 'latepoint-date-first' ) ); ?>, function(){ showDatePicker(); });
			renderCalendar(today);
		}

		function renderCalendar(today) {
			var wrap = document.createElement('div');

			var nav = document.createElement('div');
			nav.className = 'lpdf-cal-nav';

			var prev = document.createElement('button');
			prev.textContent = '←'; prev.type = 'button';
			prev.addEventListener('click', function(){
				calCursor.setMonth(calCursor.getMonth()-1); rebuildCalendar(today, wrap);
			});

			var next = document.createElement('button');
			next.textContent = '→'; next.type = 'button';
			next.addEventListener('click', function(){
				calCursor.setMonth(calCursor.getMonth()+1); rebuildCalendar(today, wrap);
			});

			var label = document.createElement('span');
			label.className = 'lpdf-cal-month';

			nav.appendChild(prev); nav.appendChild(label); nav.appendChild(next);
			wrap.appendChild(nav);

			var grid = document.createElement('div');
			grid.className = 'lpdf-grid';
			wrap.appendChild(grid);

			body.innerHTML = '';
			body.appendChild(wrap);

			fillCalendar(today, label, grid);
		}

		function rebuildCalendar(today, wrap) {
			var label = wrap.querySelector('.lpdf-cal-month');
			var grid  = wrap.querySelector('.lpdf-grid');
			fillCalendar(today, label, grid);
		}

		function fillCalendar(today, label, grid) {
			label.textContent = MONTH_NAMES[calCursor.getMonth()] + ' ' + calCursor.getFullYear();
			grid.innerHTML = '';

			DAY_NAMES.forEach(function(n){
				var h = document.createElement('div');
				h.className = 'lpdf-dn'; h.textContent = n; grid.appendChild(h);
			});

			var first = new Date(calCursor.getFullYear(), calCursor.getMonth(), 1).getDay();
			for (var i = 0; i < first; i++){
				var e = document.createElement('div'); e.className='lpdf-day lpdf-empty'; grid.appendChild(e);
			}
			var days = new Date(calCursor.getFullYear(), calCursor.getMonth()+1, 0).getDate();
			for (var d = 1; d <= days; d++){
				var day = document.createElement('div');
				day.className = 'lpdf-day';
				day.textContent = d;
				var dt = new Date(calCursor.getFullYear(), calCursor.getMonth(), d);
				var dtStr = fmtDate(dt);
				if (dt < today) {
					day.classList.add('lpdf-past');
				} else {
					if (state.date === dtStr) day.classList.add('lpdf-selected');
					day.addEventListener('click', (function(s, el){ return function(){
						state.date = s;
						grid.querySelectorAll('.lpdf-day.lpdf-selected').forEach(function(c){ c.classList.remove('lpdf-selected'); });
						el.classList.add('lpdf-selected');
						loadAvailableServices(s);
					}; })(dtStr, day));
				}
				grid.appendChild(day);
			}
		}

		// ---- Available services ----

		function loadAvailableServices(dateStr) {
			// Show spinner below calendar (keep calendar visible)
			var existing = body.querySelector('.lpdf-services-wrap');
			if (existing) existing.remove();
			var sw = document.createElement('div');
			sw.className = 'lpdf-services-wrap';
			sw.innerHTML = '<div class="lpdf-spinner">' + <?php echo json_encode( __( 'Checking availability…', 'latepoint-date-first' ) ); ?> + '</div>';
			body.appendChild(sw);

			var fd = new FormData();
			fd.append('action',      'lpdf_get_available_services');
			fd.append('nonce',       NONCE);
			fd.append('date',        dateStr);
			fd.append('category_id', state.categoryId || 0);
			fd.append('location_id', state.locationId  || 0);
			fd.append('agent_id',    state.agentId     || 0);

			fetch(AJAX_URL, { method:'POST', body:fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					sw.innerHTML = '';
					if (!data.success || !data.data.services.length) {
						sw.innerHTML = '<p class="lpdf-no-avail">' + NO_AVAIL + '</p>';
						return;
					}
					var list = document.createElement('div');
					list.className = 'lpdf-list'; list.style.marginTop = '16px';
					data.data.services.forEach(function(svc){
						var btn = document.createElement('button');
						btn.className = 'lpdf-service-btn os_trigger_booking';
						btn.setAttribute('data-selected-service',    svc.id);
						btn.setAttribute('data-selected-start-date', dateStr);
						if (state.locationId && state.locationId !== '0') {
							btn.setAttribute('data-selected-location', state.locationId);
						}
						btn.innerHTML =
							'<span class="lpdf-service-name">' + escHtml(svc.name) + '</span>' +
							(svc.price ? '<span class="lpdf-service-meta">' + escHtml(svc.price) + '</span>' : '');
						btn.addEventListener('click', function(){ close(); });
						list.appendChild(btn);
					});
					sw.appendChild(list);
				})
				.catch(function(){ sw.innerHTML = '<p class="lpdf-no-avail">' + ERROR_MSG + '</p>'; });
		}

		// ---- Helpers ----

		function pad(n){ return n < 10 ? '0'+n : ''+n; }
		function fmtDate(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
		function escHtml(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

	})();
	</script>
	<?php
}

// ---------------------------------------------------------------------------
// AJAX: get categories by parent_id
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_lpdf_get_categories',        'lpdf_ajax_get_categories' );
add_action( 'wp_ajax_nopriv_lpdf_get_categories', 'lpdf_ajax_get_categories' );

function lpdf_ajax_get_categories(): void {
	check_ajax_referer( 'lpdf_availability', 'nonce' );

	$parent_id = (int) ( $_POST['parent_id'] ?? 0 );

	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name FROM {$wpdb->prefix}latepoint_service_categories
		 WHERE " . ( $parent_id ? "parent_id = %d" : "parent_id IS NULL OR parent_id = 0" ) . "
		 ORDER BY order_number ASC",
		...( $parent_id ? [ $parent_id ] : [] )
	) );

	$categories = array_map( function( $r ) {
		return [ 'id' => (int) $r->id, 'name' => $r->name ];
	}, $rows );

	wp_send_json_success( [ 'categories' => $categories ] );
}

// ---------------------------------------------------------------------------
// AJAX: get available services for a date + category
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_lpdf_get_available_services',        'lpdf_ajax_get_available_services' );
add_action( 'wp_ajax_nopriv_lpdf_get_available_services', 'lpdf_ajax_get_available_services' );

function lpdf_ajax_get_available_services(): void {
	check_ajax_referer( 'lpdf_availability', 'nonce' );

	$date_str    = sanitize_text_field( $_POST['date']        ?? '' );
	$category_id = (int) ( $_POST['category_id'] ?? 0 );
	$location_id = (int) ( $_POST['location_id'] ?? 0 );
	$agent_id    = (int) ( $_POST['agent_id']    ?? 0 );

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_str ) ) {
		wp_send_json_error( [ 'message' => 'Invalid date.' ] );
	}

	try {
		$date = new OsWpDateTime( $date_str );
	} catch ( Exception $e ) {
		wp_send_json_error( [ 'message' => 'Invalid date.' ] );
	}

	$where = [ 'status' => 'active' ];
	if ( $category_id ) {
		$where['category_id'] = $category_id;
	}
	$services = OsServiceModel::where( $where )->get_results_as_models();

	$available = [];
	foreach ( $services as $service ) {
		$booking_request = new \LatePoint\Misc\BookingRequest( [
			'service_id'  => $service->id,
			'agent_id'    => $agent_id,
			'location_id' => $location_id,
			'start_date'  => $date_str,
			'duration'    => $service->duration,
		] );
		$resources = OsResourceHelper::get_resources_grouped_by_day( $booking_request, $date, $date );
		if ( ! empty( $resources[ $date_str ] ) ) {
			$available[] = [
				'id'    => (int) $service->id,
				'name'  => $service->name,
				'price' => method_exists( $service, 'get_price_formatted' ) ? $service->get_price_formatted() : '',
			];
		}
	}

	wp_send_json_success( [ 'services' => $available ] );
}

// ---------------------------------------------------------------------------
// Filter the services step to only show available services when a date is
// already set (e.g. if someone uses the standard LatePoint form with a
// pre-selected date).
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

	$date_str           = $date->format( 'Y-m-d' );
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
