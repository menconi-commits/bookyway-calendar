<?php
/**
 * Plugin Name:       BookyWay Calendar
 * Plugin URI:        https://github.com/menconi-commits/bookyway-calendar
 * Description:       Show your BookyWay class schedule on your WordPress site, with available spots and a booking button. Shortcode: [bookyway_calendar]
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Menconi.IT
 * Author URI:        https://www.menconi.it
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       bookyway-calendar
 *
 * Not affiliated with or endorsed by BookyWay.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BYC_VERSION', '1.0.0' );
define( 'BYC_OPTION', 'byc_settings' );
define( 'BYC_CLOUDS_OPTION', 'byc_used_clouds' );
define( 'BYC_API_BASE', 'https://openapi.bookyway.com/public' );

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function byc_defaults() {
	return array(
		'api_key'        => '',
		'secret_key'     => '',
		'cloud_id'       => '',
		'days'           => 7,
		'cache_hours'    => 2,
		'booking_url'    => 'https://m.bookyway.com',
		'title'          => '',
		'note'           => '',
		'label_book'     => 'Book',
		'label_full'     => 'Full',
		'label_spot'     => '%d spot left',
		'label_spots'    => '%d spots left',
		'label_today'    => 'Today',
		'label_tomorrow' => 'Tomorrow',
		'label_with'     => 'with',
		'label_empty'    => 'No classes scheduled in the coming days.',
		'color_accent'   => '#4aa39a',
		'color_button'   => '#f39200',
	);
}

function byc_settings() {
	$saved = get_option( BYC_OPTION, array() );
	$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), byc_defaults() );

	// Keys can also be defined in wp-config.php (recommended).
	if ( defined( 'BYC_API_KEY' ) ) {
		$s['api_key'] = BYC_API_KEY;
	}
	if ( defined( 'BYC_SECRET_KEY' ) ) {
		$s['secret_key'] = BYC_SECRET_KEY;
	}
	return $s;
}

add_action( 'admin_menu', function () {
	add_options_page( 'BookyWay Calendar', 'BookyWay Calendar', 'manage_options', 'bookyway-calendar', 'byc_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'byc_group', BYC_OPTION, array( 'sanitize_callback' => 'byc_sanitize' ) );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=bookyway-calendar' ) ) . '">' . esc_html__( 'Settings', 'bookyway-calendar' ) . '</a>' );
	return $links;
} );

function byc_sanitize( $in ) {
	$old = get_option( BYC_OPTION, array() );
	$def = byc_defaults();
	$out = array(
		'cloud_id'    => preg_replace( '/\D/', '', $in['cloud_id'] ?? '' ),
		'days'        => max( 1, min( 14, (int) ( $in['days'] ?? 7 ) ) ),
		'cache_hours' => max( 1, min( 24, (int) ( $in['cache_hours'] ?? 2 ) ) ),
		'booking_url' => esc_url_raw( $in['booking_url'] ?? '' ),
		'title'       => sanitize_text_field( $in['title'] ?? '' ),
		'note'        => wp_kses( $in['note'] ?? '', byc_note_tags() ),
	);
	foreach ( array( 'label_book', 'label_full', 'label_spot', 'label_spots', 'label_today', 'label_tomorrow', 'label_with', 'label_empty' ) as $k ) {
		$v         = sanitize_text_field( $in[ $k ] ?? '' );
		$out[ $k ] = '' !== $v ? $v : $def[ $k ];
	}
	foreach ( array( 'color_accent', 'color_button' ) as $k ) {
		$out[ $k ] = sanitize_hex_color( $in[ $k ] ?? '' ) ?: $def[ $k ];
	}
	// Empty key field = keep the stored one.
	foreach ( array( 'api_key', 'secret_key' ) as $k ) {
		$v         = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
		$out[ $k ] = '' !== $v ? $v : ( $old[ $k ] ?? '' );
	}
	byc_flush_cache();
	return $out;
}

function byc_note_tags() {
	return array(
		'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);
}

function byc_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['byc_flush'] ) && check_admin_referer( 'byc_flush' ) ) {
		byc_flush_cache();
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Cache cleared.', 'bookyway-calendar' ) . '</p></div>';
	}
	if ( isset( $_POST['byc_test'] ) && check_admin_referer( 'byc_flush' ) ) {
		$res = byc_request( '/clouds' );
		if ( is_wp_error( $res ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>';
		} else {
			$list = array();
			foreach ( (array) ( $res['clouds'] ?? array() ) as $c ) {
				$list[] = (int) ( $c['bwCloudId'] ?? 0 ) . ' – ' . ( $c['name'] ?? '' );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Connection OK. Clouds available:', 'bookyway-calendar' ) . ' <strong>' . esc_html( implode( ', ', $list ) ) . '</strong></p></div>';
		}
	}

	$s    = byc_settings();
	$name = BYC_OPTION;

	$text = function ( $key, $label, $class = 'regular-text', $desc = '' ) use ( $s, $name ) {
		echo '<tr><th><label for="byc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" id="byc-' . esc_attr( $key ) . '" name="' . esc_attr( $name . '[' . $key . ']' ) . '" value="' . esc_attr( $s[ $key ] ) . '" class="' . esc_attr( $class ) . '">';
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	};
	$secret = function ( $key, $label, $const ) use ( $s, $name ) {
		echo '<tr><th><label for="byc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( defined( $const ) ) {
			echo '<em>' . esc_html( sprintf( __( 'Defined in wp-config.php (%s)', 'bookyway-calendar' ), $const ) ) . '</em>';
		} else {
			$ph = $s[ $key ] ? __( '•••••••• saved – leave empty to keep it', 'bookyway-calendar' ) : '';
			echo '<input type="password" id="byc-' . esc_attr( $key ) . '" name="' . esc_attr( $name . '[' . $key . ']' ) . '" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( $ph ) . '">';
		}
		echo '</td></tr>';
	};
	?>
	<div class="wrap">
		<h1>BookyWay Calendar</h1>
		<p><?php echo wp_kses_post( __( 'Add the calendar to any page with the shortcode <code>[bookyway_calendar]</code>. Options: <code>[bookyway_calendar cloud="12345" days="5" title="Our classes"]</code>', 'bookyway-calendar' ) ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'byc_group' ); ?>

			<h2><?php esc_html_e( 'BookyWay API', 'bookyway-calendar' ); ?></h2>
			<table class="form-table">
				<?php
				$secret( 'api_key', 'Bookyway-API-Key (Primary/Secondary)', 'BYC_API_KEY' );
				$secret( 'secret_key', 'BookyWay-Secret-Key', 'BYC_SECRET_KEY' );
				$text( 'cloud_id', __( 'Default cloud ID', 'bookyway-calendar' ), 'small-text', __( 'Use "Test connection" below to list your clouds.', 'bookyway-calendar' ) );
				?>
				<tr><th><?php esc_html_e( 'Days to show', 'bookyway-calendar' ); ?></th><td>
					<input type="number" min="1" max="14" name="<?php echo esc_attr( $name ); ?>[days]" value="<?php echo (int) $s['days']; ?>" class="small-text">
				</td></tr>
				<tr><th><?php esc_html_e( 'Refresh every (hours)', 'bookyway-calendar' ); ?></th><td>
					<input type="number" min="1" max="24" name="<?php echo esc_attr( $name ); ?>[cache_hours]" value="<?php echo (int) $s['cache_hours']; ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Each day shown costs one API call per refresh. 7 days every 2 hours ≈ 2,500 calls/month at most, per cloud.', 'bookyway-calendar' ); ?></p>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Appearance and texts', 'bookyway-calendar' ); ?></h2>
			<table class="form-table">
				<?php
				$text( 'title', __( 'Title', 'bookyway-calendar' ) );
				?>
				<tr><th><label for="byc-note"><?php esc_html_e( 'Note under the title', 'bookyway-calendar' ); ?></label></th><td>
					<textarea id="byc-note" name="<?php echo esc_attr( $name ); ?>[note]" rows="2" class="large-text"><?php echo esc_textarea( $s['note'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Allowed HTML: a, strong, em, br.', 'bookyway-calendar' ); ?></p>
				</td></tr>
				<?php
				$text( 'booking_url', __( 'Booking link', 'bookyway-calendar' ) );
				$text( 'label_book', __( 'Button label', 'bookyway-calendar' ) );
				$text( 'label_full', __( '"Full" label', 'bookyway-calendar' ) );
				$text( 'label_spot', __( '"1 spot left" label', 'bookyway-calendar' ), 'regular-text', __( '%d is replaced by the number.', 'bookyway-calendar' ) );
				$text( 'label_spots', __( '"N spots left" label', 'bookyway-calendar' ) );
				$text( 'label_today', __( '"Today" label', 'bookyway-calendar' ) );
				$text( 'label_tomorrow', __( '"Tomorrow" label', 'bookyway-calendar' ) );
				$text( 'label_with', __( '"with" (before the instructor name)', 'bookyway-calendar' ) );
				$text( 'label_empty', __( 'Text when there are no classes', 'bookyway-calendar' ), 'large-text' );
				?>
				<tr><th><?php esc_html_e( 'Colors', 'bookyway-calendar' ); ?></th><td>
					<label><input type="color" name="<?php echo esc_attr( $name ); ?>[color_accent]" value="<?php echo esc_attr( $s['color_accent'] ); ?>"> <?php esc_html_e( 'Accent', 'bookyway-calendar' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="color" name="<?php echo esc_attr( $name ); ?>[color_button]" value="<?php echo esc_attr( $s['color_button'] ); ?>"> <?php esc_html_e( 'Button', 'bookyway-calendar' ); ?></label>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'byc_flush' ); ?>
			<p>
				<button class="button" name="byc_test" value="1"><?php esc_html_e( 'Test connection', 'bookyway-calendar' ); ?></button>
				<button class="button" name="byc_flush" value="1"><?php esc_html_e( 'Clear cache', 'bookyway-calendar' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * API + cache
 * ---------------------------------------------------------------------- */

/**
 * GET request to the BookyWay OpenAPI v2. Returns decoded array or WP_Error.
 */
function byc_request( $path ) {
	$s = byc_settings();
	if ( ! $s['api_key'] || ! $s['secret_key'] ) {
		return new WP_Error( 'byc_no_keys', __( 'API keys are missing.', 'bookyway-calendar' ) );
	}
	$resp = wp_remote_get( BYC_API_BASE . $path, array(
		'timeout' => 10,
		'headers' => array(
			'Bookyway-API-Key'    => $s['api_key'],
			'BookyWay-Secret-Key' => $s['secret_key'],
			'API-Version'         => 'v2',
		),
	) );
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $code ) {
		return new WP_Error( 'byc_http', sprintf( 'BookyWay API: HTTP %d %s', $code, wp_strip_all_tags( wp_remote_retrieve_body( $resp ) ) ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	return is_array( $data ) ? $data : new WP_Error( 'byc_json', 'BookyWay API: invalid JSON' );
}

function byc_flush_cache() {
	// delete_transient() also works with persistent object caches (Redis, Memcached).
	$clouds = (array) get_option( BYC_CLOUDS_OPTION, array() );
	$day    = new DateTimeImmutable( 'today', wp_timezone() );
	foreach ( $clouds as $cloud ) {
		for ( $i = -1; $i < 15; $i++ ) {
			delete_transient( 'byc_day_' . $cloud . '_' . $day->modify( "{$i} day" )->format( 'Ymd' ) );
		}
	}
}

/**
 * Events of one day ($date = Ymd). Returns array of events, or null on error.
 */
function byc_get_day( $cloud_id, $date ) {
	$s         = byc_settings();
	$cache_key = 'byc_day_' . $cloud_id . '_' . $date;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}

	$clouds = (array) get_option( BYC_CLOUDS_OPTION, array() );
	if ( ! in_array( $cloud_id, $clouds, true ) ) {
		$clouds[] = $cloud_id;
		update_option( BYC_CLOUDS_OPTION, $clouds, false );
	}

	// The API only accepts startDate as YYYYMMDD (e.g. 20261020).
	$data       = byc_request( '/clouds/' . rawurlencode( $cloud_id ) . '/events/daily?startDate=' . $date );
	$backup_key = 'byc_backup_' . $cloud_id . '_' . $date;

	if ( is_wp_error( $data ) ) {
		// Serve the last good copy and retry in 15 minutes.
		$backup = get_option( $backup_key, null );
		set_transient( $cache_key, is_array( $backup ) ? $backup : 'error', 15 * MINUTE_IN_SECONDS );
		return is_array( $backup ) ? $backup : null;
	}

	$events = array();
	foreach ( (array) ( $data['events'] ?? array() ) as $e ) {
		if ( ( $e['status'] ?? '' ) !== 'Public' ) {
			continue;
		}
		// Keep only public fields: booked member IDs are never stored.
		$events[] = array(
			'start'     => (string) ( $e['start'] ?? '' ),
			'startTime' => (string) ( $e['startTime'] ?? '' ),
			'endTime'   => (string) ( $e['endTime'] ?? '' ),
			'title'     => (string) ( $e['title'] ?? '' ),
			'color'     => (string) ( $e['color'] ?? '' ),
			'host'      => trim( preg_replace( '/\s+/', ' ', (string) ( $e['hostsNames'] ?? '' ) ) ),
			'subType'   => (string) ( $e['subscriptionType'] ?? '' ),
			'total'     => (int) ( $e['total'] ?? 0 ),
			'available' => (int) ( $e['available'] ?? 0 ),
		);
	}
	usort( $events, function ( $a, $b ) {
		return strcmp( $a['start'], $b['start'] );
	} );

	set_transient( $cache_key, $events, (int) $s['cache_hours'] * HOUR_IN_SECONDS );
	update_option( $backup_key, $events, false );
	return $events;
}

// Daily cleanup of backups for past days.
add_action( 'byc_cleanup', function () {
	global $wpdb;
	$rows  = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'byc\_backup\_%'" );
	$today = wp_date( 'Ymd' );
	foreach ( $rows as $name ) {
		if ( preg_match( '/_(\d{8})$/', $name, $m ) && $m[1] < $today ) {
			delete_option( $name );
		}
	}
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'byc_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'byc_cleanup' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'byc_cleanup' );
	byc_flush_cache();
} );

/* -------------------------------------------------------------------------
 * Shortcode
 * ---------------------------------------------------------------------- */

add_shortcode( 'bookyway_calendar', function ( $atts ) {
	$s    = byc_settings();
	$atts = shortcode_atts( array(
		'cloud' => $s['cloud_id'],
		'days'  => $s['days'],
		'title' => $s['title'],
		'note'  => $s['note'],
	), $atts, 'bookyway_calendar' );

	$cloud = preg_replace( '/\D/', '', $atts['cloud'] );
	$days  = max( 1, min( 14, (int) $atts['days'] ) );
	if ( ! $cloud ) {
		return current_user_can( 'manage_options' ) ? '<p><em>BookyWay Calendar: ' . esc_html__( 'set a cloud ID in Settings → BookyWay Calendar.', 'bookyway-calendar' ) . '</em></p>' : '';
	}

	wp_enqueue_style( 'bookyway-calendar', plugins_url( 'bookyway-calendar.css', __FILE__ ), array(), BYC_VERSION );

	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'today', $tz );
	$now   = new DateTimeImmutable( 'now', $tz );
	$style = '--byc-accent:' . $s['color_accent'] . ';--byc-button:' . $s['color_button'];

	$html = '<div class="byc-cal" style="' . esc_attr( $style ) . '">';
	if ( '' !== $atts['title'] ) {
		$html .= '<h2 class="byc-title">' . esc_html( $atts['title'] ) . '</h2>';
	}
	if ( '' !== $atts['note'] ) {
		$html .= '<p class="byc-note">' . wp_kses( $atts['note'], byc_note_tags() ) . '</p>';
	}

	$any = false;
	for ( $i = 0; $i < $days; $i++ ) {
		$d      = $today->modify( "+{$i} day" );
		$events = byc_get_day( $cloud, $d->format( 'Ymd' ) );
		if ( null === $events ) {
			continue;
		}
		if ( 0 === $i ) {
			// Today: hide classes that have already started (API times are local).
			$events = array_filter( $events, function ( $e ) use ( $now, $tz ) {
				try {
					return new DateTimeImmutable( $e['start'], $tz ) > $now;
				} catch ( Exception $ex ) {
					return true;
				}
			} );
		}
		if ( ! $events ) {
			continue;
		}
		$any   = true;
		$label = 0 === $i ? $s['label_today'] : ( 1 === $i ? $s['label_tomorrow'] : ucfirst( wp_date( 'l', $d->getTimestamp() ) ) );

		$html .= '<section class="byc-day">';
		$html .= '<h3 class="byc-day-title">' . esc_html( $label ) . ' <span>' . esc_html( wp_date( 'j F', $d->getTimestamp() ) ) . '</span></h3>';
		$html .= '<ul class="byc-list">';
		foreach ( $events as $e ) {
			$full   = $e['total'] > 0 && $e['available'] <= 0;
			$closed = 'Forbidden' === $e['subType'];
			$color  = preg_match( '/^#[0-9a-f]{3,8}$/i', $e['color'] ) ? $e['color'] : $s['color_accent'];

			$html .= '<li class="byc-event' . ( $full ? ' is-full' : '' ) . '" style="--byc-event:' . esc_attr( $color ) . '">';
			$html .= '<div class="byc-time">' . esc_html( $e['startTime'] ) . '<span>' . esc_html( $e['endTime'] ) . '</span></div>';
			$html .= '<div class="byc-info"><strong>' . esc_html( $e['title'] ) . '</strong>';
			if ( $e['host'] ) {
				$html .= '<span class="byc-host">' . esc_html( $s['label_with'] . ' ' . $e['host'] ) . '</span>';
			}
			$html .= '</div><div class="byc-action">';
			if ( $e['total'] > 0 ) {
				$html .= $full
					? '<span class="byc-badge byc-badge-full">' . esc_html( $s['label_full'] ) . '</span>'
					: '<span class="byc-badge">' . esc_html( sprintf( 1 === $e['available'] ? $s['label_spot'] : $s['label_spots'], $e['available'] ) ) . '</span>';
			}
			if ( ! $full && ! $closed && $s['booking_url'] ) {
				$html .= '<a class="byc-btn" href="' . esc_url( $s['booking_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['label_book'] ) . '</a>';
			}
			$html .= '</div></li>';
		}
		$html .= '</ul></section>';
	}

	if ( ! $any ) {
		$html .= '<p class="byc-empty">' . esc_html( $s['label_empty'] ) . '</p>';
	}
	return $html . '</div>';
} );
