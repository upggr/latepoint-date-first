<?php
/**
 * Plugin Name:  LatePoint – Date First Booking
 * Description:  Adds a custom booking modal triggered by .latepoint-date-first buttons. Guides the user through category → sub-category → date → available service, then fires the native LatePoint booking modal pre-filled.
 * Version:      3.1.0
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
			<div id="lpdf-header">
				<p id="lpdf-title"><?php esc_html_e( 'Make a Reservation', 'latepoint-date-first' ); ?></p>
				<button id="lpdf-close" aria-label="<?php esc_attr_e( 'Close', 'latepoint-date-first' ); ?>">&times;</button>
			</div>
			<div id="lpdf-breadcrumb"></div>
			<div id="lpdf-body"></div>
		</div>
	</div>

	<style>
	/* ── Overlay ── */
	#lpdf-overlay {
		position: fixed; inset: 0;
		background: rgba(35,31,32,.7);
		z-index: 99998;
		display: flex; align-items: center; justify-content: center;
		padding: 16px;
	}

	/* ── Modal shell ── */
	#lpdf-modal {
		background: #fffbf3;
		border-radius: 4px;
		width: min(540px, 100%);
		max-height: 90vh;
		overflow-y: auto;
		position: relative;
		box-shadow: 0 24px 80px rgba(35,31,32,.35);
		font-family: 'Quattrocento Sans', sans-serif;
		color: #3B2F2F;
	}

	/* ── Header bar ── */
	#lpdf-header {
		display: flex; align-items: center; justify-content: space-between;
		padding: 28px 28px 0;
		border-bottom: 1px solid #e8dfd0;
		padding-bottom: 20px;
	}
	#lpdf-title {
		font-family: 'Quattrocento', serif;
		font-size: 22px; font-weight: 400; color: #231F20; margin: 0;
		letter-spacing: .04em;
	}
	#lpdf-close {
		background: none; border: none; cursor: pointer;
		width: 32px; height: 32px; border-radius: 50%;
		display: flex; align-items: center; justify-content: center;
		font-size: 22px; color: #C3B59B; transition: color .15s;
		flex-shrink: 0; line-height: 1;
	}
	#lpdf-close:hover { color: #231F20; }

	/* ── Breadcrumb ── */
	#lpdf-breadcrumb {
		display: flex; flex-wrap: wrap; align-items: center; gap: 2px;
		padding: 12px 28px 0;
		font-size: 11px; min-height: 26px;
		letter-spacing: .06em; text-transform: uppercase;
	}
	.lpdf-bc-item {
		cursor: pointer; color: #C3B59B;
		padding: 2px 4px;
		transition: color .1s;
	}
	.lpdf-bc-item:hover { color: #231F20; }
	.lpdf-bc-sep { color: #ddd; padding: 0 2px; }
	.lpdf-bc-current { color: #bbb; cursor: default; padding: 2px 4px; }

	/* ── Body ── */
	#lpdf-body { padding: 16px 28px 28px; }

	/* ── List buttons (categories & services) ── */
	.lpdf-list { display: flex; flex-direction: column; gap: 6px; }
	.lpdf-list-btn, .lpdf-service-btn {
		display: flex; align-items: center; justify-content: space-between;
		width: 100%; padding: 16px 18px;
		border-radius: 2px; cursor: pointer; text-align: left;
		font-family: 'Quattrocento Sans', sans-serif; font-size: 14px;
		border: 1px solid #e8dfd0; background: #fff;
		transition: border-color .15s, background .15s;
		letter-spacing: .02em;
	}
	.lpdf-list-btn:hover   { border-color: #C3B59B; background: #fdf8f0; }
	.lpdf-service-btn:hover { border-color: #C3B59B; background: #fdf8f0; }
	.lpdf-btn-label { font-weight: 400; color: #3B2F2F; }
	.lpdf-btn-meta  { font-size: 12px; color: #C3B59B; margin-left: 12px; white-space: nowrap; }
	.lpdf-btn-arrow { color: #C3B59B; font-size: 14px; margin-left: 8px; }

	/* ── Calendar ── */
	.lpdf-cal-nav {
		display: flex; align-items: center; justify-content: space-between;
		margin-bottom: 16px;
	}
	.lpdf-cal-nav-btn {
		background: none; border: 1px solid #e8dfd0; border-radius: 2px;
		width: 34px; height: 34px; cursor: pointer; font-size: 16px;
		display: flex; align-items: center; justify-content: center;
		color: #C3B59B; transition: border-color .15s, color .15s;
	}
	.lpdf-cal-nav-btn:hover { border-color: #C3B59B; color: #231F20; }
	.lpdf-cal-month {
		font-family: 'Quattrocento', serif;
		font-weight: 400; font-size: 16px; color: #231F20;
		letter-spacing: .08em; text-transform: uppercase;
	}

	.lpdf-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 3px; }
	.lpdf-dn {
		text-align: center; font-size: 10px; font-weight: 400;
		color: #C3B59B; padding: 6px 0; text-transform: uppercase; letter-spacing: .08em;
	}
	.lpdf-day {
		text-align: center; padding: 9px 2px;
		border-radius: 2px; font-size: 13px; cursor: pointer;
		border: 1px solid transparent; color: #3B2F2F;
		transition: background .12s, border-color .12s, color .12s;
	}
	.lpdf-day:hover:not(.lpdf-past):not(.lpdf-empty):not(.lpdf-unavail):not(.lpdf-loading) {
		background: #fdf8f0; border-color: #C3B59B;
	}
	.lpdf-day.lpdf-selected {
		background: #231F20; color: #fffbf3; border-color: #231F20; font-weight: 700;
	}
	.lpdf-day.lpdf-past    { color: #ddd; cursor: default; }
	.lpdf-day.lpdf-empty   { cursor: default; }
	.lpdf-day.lpdf-loading { color: #ddd; cursor: default; }
	.lpdf-day.lpdf-unavail { color: #ddd; cursor: default; text-decoration: line-through; }

	/* ── Services section ── */
	.lpdf-services-wrap { margin-top: 20px; }
	.lpdf-services-label {
		font-size: 10px; font-weight: 400; text-transform: uppercase;
		letter-spacing: .1em; color: #C3B59B; margin: 0 0 10px;
	}
	.lpdf-service-name { font-weight: 400; color: #3B2F2F; }
	.lpdf-service-meta { font-size: 12px; color: #C3B59B; margin-left: 12px; }

	/* ── States ── */
	.lpdf-no-avail {
		font-size: 13px; color: #C3B59B; margin: 8px 0 0;
		padding: 16px 0; text-align: center; letter-spacing: .02em;
	}
	.lpdf-spinner {
		text-align: center; padding: 28px 0;
		font-size: 12px; color: #C3B59B; letter-spacing: .08em; text-transform: uppercase;
	}
	.lpdf-back-btn {
		display: block; width: 100%; margin-top: 8px;
		padding: 13px 16px; border-radius: 2px; cursor: pointer;
		text-align: center; font-family: 'Quattrocento Sans', sans-serif; font-size: 12px;
		border: 1px solid #e8dfd0; background: transparent; color: #C3B59B;
		transition: border-color .15s, color .15s;
		letter-spacing: .06em; text-transform: uppercase;
	}
	.lpdf-back-btn:hover { border-color: #C3B59B; color: #231F20; }
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
		var availableDatesCache = {}; // "YYYY-MM|catId" → Set of "YYYY-MM-DD"

		// data-* keys on the Elementor widget wrapper that belong to Elementor, not LatePoint
		var SKIP_ATTRS = { id: 1, elementType: 1, eType: 1, settings: 1, widgetType: 1 };

		function resetState() {
			state = { triggerAttrs: {}, locationId: 0, crumbs: [], categoryId: null, singleServiceId: null, date: null };
		}

		// ---- Open / close ----

		function open(trigger) {
			resetState();
			// Collect all data-* attributes from the trigger, skipping Elementor internals.
			var ds = trigger.dataset;
			var attrs = {};
			for (var key in ds) {
				if (!SKIP_ATTRS[key]) attrs[key] = ds[key];
			}
			state.triggerAttrs = attrs;
			// Convenience aliases used by AJAX requests
			state.locationId = attrs.selectedLocation || attrs.location || 0;
			overlay.style.display = 'flex';
			document.body.style.overflow = 'hidden';
			showCategories(0); // start at root
		}

		// Apply all stored trigger attributes to a LatePoint booking button.
		// Skips keys that our plugin controls (service, date, time, source-id).
		var OWN_ATTRS = { selectedService: 1, selectedStartDate: 1, calendarStartDate: 1, selectedStartTime: 1, sourceId: 1 };
		function applyTriggerAttrs(btn) {
			var attrs = state.triggerAttrs;
			for (var key in attrs) {
				if (!OWN_ATTRS[key]) {
					// convert camelCase dataset key back to kebab-case attribute name
					var attrName = 'data-' + key.replace(/([A-Z])/g, function(c){ return '-' + c.toLowerCase(); });
					btn.setAttribute(attrName, attrs[key]);
				}
			}
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

		// Auto-close our modal when LatePoint opens its own lightbox
		new MutationObserver(function(){
			if (document.body.classList.contains('latepoint-lightbox-active')) {
				close();
			}
		}).observe(document.body, { attributes: true, attributeFilter: ['class'] });

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
				btn.innerHTML = '<span class="lpdf-btn-label">' + escHtml(cat.name) + '</span><span class="lpdf-btn-arrow">›</span>';
				if (cat.type === 'service') {
					// Uncategorized service (e.g. Restaurant Experience) — fire LatePoint directly.
					// No intermediate date step needed; LatePoint shows its own full date+time picker.
					btn.className += ' os_trigger_booking';
					btn.setAttribute('data-selected-service', cat.id);
					btn.setAttribute('data-source-id', 'lpdf-svc-' + cat.id);
					applyTriggerAttrs(btn);
					// LatePoint's delegated os_trigger_booking handler fires on click.
					// Our MutationObserver auto-closes this modal when LatePoint opens.
				} else {
					btn.addEventListener('click', function(){
						state.categoryId = cat.id;
						state.singleServiceId = null;
						pushCrumb(cat.name, function(){ state.categoryId = cat.id; state.singleServiceId = null; showCategories(cat.id); });
						// Check if this category has children; showCategories will fall through to date picker if not
						showCategories(cat.id);
					});
				}
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

		// Fetch open dates for the current calCursor month (cached).
		function getAvailableDatesForMonth(today, label, grid) {
			var monthKey = calCursor.getFullYear() + '-' + pad(calCursor.getMonth() + 1) + '|' + (state.categoryId || 0) + '|' + (state.singleServiceId || 0);
			if (availableDatesCache[monthKey] !== undefined) {
				fillCalendar(today, label, grid, availableDatesCache[monthKey]);
				return;
			}
			// Mark all non-past days as loading while we fetch
			fillCalendar(today, label, grid, null);

			var fd = new FormData();
			fd.append('action',      'lpdf_get_available_dates');
			fd.append('nonce',       NONCE);
			fd.append('year',        calCursor.getFullYear());
			fd.append('month',       calCursor.getMonth() + 1);
			fd.append('category_id', state.categoryId    || 0);
			fd.append('service_id',  state.singleServiceId || 0);
			fd.append('location_id', state.locationId    || 0);
			fd.append('agent_id',    state.triggerAttrs.selectedAgent || 0);

			fetch(AJAX_URL, { method: 'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					var openDates = null;
					if (data.success && data.data.dates) {
						openDates = {};
						data.data.dates.forEach(function(d){ openDates[d] = true; });
					}
					availableDatesCache[monthKey] = openDates;
					fillCalendar(today, label, grid, openDates);
				})
				.catch(function(){
					fillCalendar(today, label, grid, null); // on error, allow all dates
				});
		}

		function renderCalendar(today) {
			var wrap = document.createElement('div');

			var nav = document.createElement('div');
			nav.className = 'lpdf-cal-nav';

			var prev = document.createElement('button');
			prev.className = 'lpdf-cal-nav-btn'; prev.type = 'button'; prev.textContent = '‹';
			prev.addEventListener('click', function(){
				calCursor.setMonth(calCursor.getMonth()-1); rebuildCalendar(today, wrap);
			});

			var next = document.createElement('button');
			next.className = 'lpdf-cal-nav-btn'; next.type = 'button'; next.textContent = '›';
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

			getAvailableDatesForMonth(today, label, grid);
		}

		function rebuildCalendar(today, wrap) {
			var label = wrap.querySelector('.lpdf-cal-month');
			var grid  = wrap.querySelector('.lpdf-grid');
			getAvailableDatesForMonth(today, label, grid);
		}

		// openDates: null = unknown/all clickable, object = map of available date strings
		function fillCalendar(today, label, grid, openDates) {
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
				} else if (openDates === null) {
					// Still loading — show loading state
					day.classList.add('lpdf-loading');
				} else if (openDates && !openDates[dtStr]) {
					// Known to be unavailable
					day.classList.add('lpdf-unavail');
					day.title = <?php echo json_encode( __( 'Not available', 'latepoint-date-first' ) ); ?>;
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
			fd.append('category_id', state.categoryId    || 0);
			fd.append('service_id',  state.singleServiceId || 0);
			fd.append('location_id', state.locationId    || 0);
			fd.append('agent_id',    state.triggerAttrs.selectedAgent || 0);

			fetch(AJAX_URL, { method:'POST', body:fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					sw.innerHTML = '';
					if (!data.success || !data.data.services.length) {
						var noAvailP = document.createElement('p');
						noAvailP.className = 'lpdf-no-avail';
						noAvailP.textContent = NO_AVAIL;
						sw.appendChild(noAvailP);
						// "Try another date" — deselects day, stays on calendar
						var tryDateBtn = document.createElement('button');
						tryDateBtn.type = 'button';
						tryDateBtn.className = 'lpdf-back-btn';
						tryDateBtn.textContent = <?php echo json_encode( __( '← Try another date', 'latepoint-date-first' ) ); ?>;
						tryDateBtn.addEventListener('click', function(){
							sw.remove();
							body.querySelectorAll('.lpdf-day.lpdf-selected').forEach(function(c){ c.classList.remove('lpdf-selected'); });
							state.date = null;
						});
						sw.appendChild(tryDateBtn);
						// "Change category" — goes back via breadcrumb (click second-to-last crumb if any)
						if (state.crumbs.length > 1) {
							var tryCatBtn = document.createElement('button');
							tryCatBtn.type = 'button';
							tryCatBtn.className = 'lpdf-back-btn';
							tryCatBtn.textContent = <?php echo json_encode( __( '← Change category', 'latepoint-date-first' ) ); ?>;
							tryCatBtn.addEventListener('click', function(){
								var prevCrumb = state.crumbs[state.crumbs.length - 2];
								if (prevCrumb) {
									state.crumbs = state.crumbs.slice(0, state.crumbs.length - 2);
									renderBreadcrumb();
									prevCrumb.action();
								}
							});
							sw.appendChild(tryCatBtn);
						}
						sw.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
						return;
					}
					var label = document.createElement('p');
					label.className = 'lpdf-services-label';
					label.textContent = <?php echo json_encode( __( 'Available options', 'latepoint-date-first' ) ); ?>;
					sw.appendChild(label);
					var list = document.createElement('div');
					list.className = 'lpdf-list';
					data.data.services.forEach(function(svc){
						var btn = document.createElement('button');
						btn.className = 'lpdf-service-btn os_trigger_booking';
						btn.setAttribute('data-selected-service',    svc.id);
						btn.setAttribute('data-selected-start-date', dateStr);
						btn.setAttribute('data-calendar-start-date', dateStr);
						btn.setAttribute('data-source-id', 'lpdf-' + dateStr + '-' + svc.id);
						// If only one timeslot exists, pre-select it to skip the datepicker step
						if (svc.start_time !== null && svc.start_time !== undefined) {
							btn.setAttribute('data-selected-start-time', svc.start_time);
						}
						applyTriggerAttrs(btn);
						btn.innerHTML =
							'<span class="lpdf-service-name">' + escHtml(svc.name) + '</span>' +
							(svc.price ? '<span class="lpdf-service-meta">' + escHtml(svc.price) + '</span>' : '');
						list.appendChild(btn);
					});
					sw.appendChild(list);
					// Scroll down to services once loaded
					sw.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
		return [ 'id' => (int) $r->id, 'name' => $r->name, 'type' => 'category' ];
	}, $rows );

	// At root level (parent_id=0), also include active services with no category
	// so they appear as direct options alongside top-level categories.
	if ( ! $parent_id ) {
		$uncategorized = $wpdb->get_results(
			"SELECT id, name FROM {$wpdb->prefix}latepoint_services
			 WHERE status = 'active' AND (category_id = 0 OR category_id IS NULL)
			 ORDER BY order_number ASC"
		);
		foreach ( $uncategorized as $svc ) {
			$categories[] = [ 'id' => (int) $svc->id, 'name' => $svc->name, 'type' => 'service' ];
		}
	}

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
	$service_id  = (int) ( $_POST['service_id']  ?? 0 );
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
	if ( $service_id ) {
		$where['id'] = $service_id; // single uncategorized service
	} elseif ( $category_id ) {
		$where['category_id'] = $category_id;
	}
	$services = ( new OsServiceModel() )->where( $where )->get_results_as_models();

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
		if ( empty( $resources[ $date_str ] ) ) {
			continue;
		}

		// A resource object is always returned even for closed/blocked days.
		// Use get_ordered_booking_slots_from_resources to determine real availability:
		// it returns slots only when work_time_periods are set (i.e., the day is open).
		$slots = OsResourceHelper::get_ordered_booking_slots_from_resources( $resources[ $date_str ] );
		// Filter to slots that can actually accommodate at least 1 attendee (excludes fully booked slots).
		$slots = array_filter( $slots, function ( $s ) { return $s->can_accomodate( 1 ); } );
		if ( empty( $slots ) ) {
			continue; // day is closed, blocked, or fully booked for this service
		}

		$unique_times = array_unique( array_map( function ( $s ) { return $s->start_time; }, $slots ) );

		$available[] = [
			'id'         => (int) $service->id,
			'name'       => $service->name,
			'price'      => method_exists( $service, 'get_price_formatted' ) ? $service->get_price_formatted() : '',
			// Pass start_time when there is exactly one unique slot so LatePoint skips
			// the datepicker step entirely (requires both selected_start_date + selected_start_time).
			'start_time' => count( $unique_times ) === 1 ? (int) reset( $unique_times ) : null,
		];
	}

	wp_send_json_success( [ 'services' => $available ] );
}

// ---------------------------------------------------------------------------
// AJAX: get available dates for a given month + category
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_lpdf_get_available_dates',        'lpdf_ajax_get_available_dates' );
add_action( 'wp_ajax_nopriv_lpdf_get_available_dates', 'lpdf_ajax_get_available_dates' );

function lpdf_ajax_get_available_dates(): void {
	check_ajax_referer( 'lpdf_availability', 'nonce' );

	$year        = (int) ( $_POST['year']        ?? date( 'Y' ) );
	$month       = (int) ( $_POST['month']       ?? date( 'n' ) );
	$category_id = (int) ( $_POST['category_id'] ?? 0 );
	$service_id  = (int) ( $_POST['service_id']  ?? 0 );
	$location_id = (int) ( $_POST['location_id'] ?? 0 );
	$agent_id    = (int) ( $_POST['agent_id']    ?? 0 );

	if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
		wp_send_json_error( [ 'message' => 'Invalid month.' ] );
	}

	$where = [ 'status' => 'active' ];
	if ( $service_id ) {
		$where['id'] = $service_id;
	} elseif ( $category_id ) {
		$where['category_id'] = $category_id;
	}
	$services = ( new OsServiceModel() )->where( $where )->get_results_as_models();

	if ( empty( $services ) ) {
		wp_send_json_success( [ 'dates' => [] ] );
	}

	// Iterate every day in the month and check if at least one service is bookable.
	$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
	$open_dates    = [];

	for ( $day = 1; $day <= $days_in_month; $day++ ) {
		$date_str = sprintf( '%04d-%02d-%02d', $year, $month, $day );

		try {
			$date = new OsWpDateTime( $date_str );
		} catch ( Exception $e ) {
			continue;
		}

		foreach ( $services as $service ) {
			$booking_request = new \LatePoint\Misc\BookingRequest( [
				'service_id'  => $service->id,
				'agent_id'    => $agent_id,
				'location_id' => $location_id,
				'start_date'  => $date_str,
				'duration'    => $service->duration,
			] );
			$resources = OsResourceHelper::get_resources_grouped_by_day( $booking_request, $date, $date );
			if ( empty( $resources[ $date_str ] ) ) {
				continue;
			}
			$slots = OsResourceHelper::get_ordered_booking_slots_from_resources( $resources[ $date_str ] );
			$slots = array_filter( $slots, function ( $s ) { return $s->can_accomodate( 1 ); } );
			if ( ! empty( $slots ) ) {
				$open_dates[] = $date_str;
				break; // at least one service available on this day — move to next day
			}
		}
	}

	wp_send_json_success( [ 'dates' => $open_dates ] );
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
