<?php
/** Booking requests list in wp-admin: useful columns, filters and upcoming-first order. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Booking_List_Admin {
	const WHEN = array( 'upcoming', 'past', 'all' );

	public function __construct() {
		$type = ELAB_Post_Types::BOOKING;
		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable' ) );
		add_filter( 'list_table_primary_column', array( $this, 'primary_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_actions' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'query' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// The Upcoming/Past filter replaces WordPress's month dropdown, which filters by receipt date.
		add_filter( 'disable_months_dropdown', static function ( $disabled, $post_type ) { return ELAB_Post_Types::BOOKING === $post_type ? true : $disabled; }, 10, 2 );
	}

	public static function statuses() {
		return array(
			'pending'   => __( 'Pending', 'elaltillo-bookacall' ),
			'confirmed' => __( 'Confirmed', 'elaltillo-bookacall' ),
			'cancelled' => __( 'Cancelled', 'elaltillo-bookacall' ),
		);
	}

	public static function status_badge( $status ) {
		$labels = self::statuses();
		$status = isset( $labels[ $status ] ) ? $status : 'pending';
		return '<span class="elab-status elab-status--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
	}

	/** "Wed 11 Nov 2026, 14:00 WET" in the given zone, or '—'. */
	public static function format_time( $booking_id, $zone_name ) {
		$start = get_post_meta( $booking_id, '_elab_start_utc', true );
		$timestamp = $start ? strtotime( $start . ' UTC' ) : false;
		if ( ! $timestamp ) {
			return '—';
		}
		try {
			$zone = new DateTimeZone( $zone_name ?: 'UTC' );
		} catch ( Exception $exception ) {
			$zone = new DateTimeZone( 'UTC' );
		}
		return wp_date( 'D j M Y, ' . get_option( 'time_format' ) . ' T', $timestamp, $zone );
	}

	public function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( $screen && ELAB_Post_Types::BOOKING === $screen->post_type && in_array( $hook, array( 'edit.php', 'post.php' ), true ) ) {
			wp_enqueue_style( 'elab-advisor-admin', plugins_url( 'assets/css/advisor-admin.css', ELAB_FILE ), array(), ELAB_Plugin::asset_version( 'assets/css/advisor-admin.css' ) );
		}
	}

	public function columns( $columns ) {
		return array(
			'cb'            => $columns['cb'] ?? '',
			'elab_when'     => __( 'When', 'elaltillo-bookacall' ),
			'elab_visitor'  => __( 'Visitor', 'elaltillo-bookacall' ),
			'elab_advisor'  => __( 'Advisor', 'elaltillo-bookacall' ),
			'elab_status'   => __( 'Status', 'elaltillo-bookacall' ),
			'elab_language' => __( 'Language', 'elaltillo-bookacall' ),
			'elab_received' => __( 'Received', 'elaltillo-bookacall' ),
		);
	}

	public function primary_column( $default, $screen ) {
		return 'edit-' . ELAB_Post_Types::BOOKING === $screen ? 'elab_visitor' : $default;
	}

	public function sortable( $columns ) {
		$columns['elab_when'] = 'elab_when';
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		$advisor_id = (int) get_post_meta( $post_id, '_elab_advisor', true );
		switch ( $column ) {
			case 'elab_when':
				$advisor_zone = (string) get_post_meta( $advisor_id, '_elab_timezone', true );
				echo '<strong>' . esc_html( self::format_time( $post_id, $advisor_zone ) ) . '</strong><br><span class="description">' . esc_html( sprintf( __( 'Visitor: %s', 'elaltillo-bookacall' ), self::format_time( $post_id, (string) get_post_meta( $post_id, '_elab_visitor_timezone', true ) ) ) ) . '</span>';
				break;
			case 'elab_visitor':
				$name = (string) get_post_meta( $post_id, '_elab_name', true );
				printf( '<strong><a class="row-title" href="%s">%s</a></strong><br><span class="description">%s</span>', esc_url( get_edit_post_link( $post_id ) ), esc_html( $name ?: __( '(no name)', 'elaltillo-bookacall' ) ), esc_html( (string) get_post_meta( $post_id, '_elab_contact_email', true ) ) );
				break;
			case 'elab_advisor':
				echo $advisor_id ? esc_html( get_the_title( $advisor_id ) ) : '—';
				break;
			case 'elab_status':
				echo self::status_badge( (string) get_post_meta( $post_id, '_elab_status', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge().
				break;
			case 'elab_received':
				echo esc_html( get_the_date( get_option( 'date_format' ), $post_id ) ) . '<br><span class="description">' . esc_html( get_the_time( get_option( 'time_format' ), $post_id ) ) . '</span>';
				break;
			case 'elab_language':
				echo esc_html( ELAB_Booking_Service::language_label( get_post_meta( $post_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ) );
				break;
		}
	}

	/** Quick Edit would expose the raw title and post status, which carry no booking meaning. */
	public function row_actions( $actions, $post ) {
		if ( ELAB_Post_Types::BOOKING === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
		}
		return $actions;
	}

	public function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	private static function current_filter( $key, $allowed, $default = '' ) {
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	public function filters( $post_type, $which = 'top' ) {
		if ( ELAB_Post_Types::BOOKING !== $post_type || 'top' !== $which ) {
			return;
		}
		$when = self::current_filter( 'elab_when', self::WHEN, 'upcoming' );
		$status = self::current_filter( 'elab_status', array_keys( self::statuses() ) );
		$advisor = isset( $_GET['elab_advisor'] ) ? absint( $_GET['elab_advisor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$advisors = get_posts( array( 'post_type' => ELAB_Post_Types::ADVISOR, 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<label class="screen-reader-text" for="elab-filter-when">' . esc_html__( 'Filter by date', 'elaltillo-bookacall' ) . '</label><select id="elab-filter-when" name="elab_when">';
		foreach ( array( 'upcoming' => __( 'Upcoming', 'elaltillo-bookacall' ), 'past' => __( 'Past', 'elaltillo-bookacall' ), 'all' => __( 'All dates', 'elaltillo-bookacall' ) ) as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $when, $value, false ), esc_html( $label ) );
		}
		echo '</select><label class="screen-reader-text" for="elab-filter-status">' . esc_html__( 'Filter by status', 'elaltillo-bookacall' ) . '</label><select id="elab-filter-status" name="elab_status"><option value="">' . esc_html__( 'All statuses', 'elaltillo-bookacall' ) . '</option>';
		foreach ( self::statuses() as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) );
		}
		echo '</select><label class="screen-reader-text" for="elab-filter-advisor">' . esc_html__( 'Filter by advisor', 'elaltillo-bookacall' ) . '</label><select id="elab-filter-advisor" name="elab_advisor"><option value="0">' . esc_html__( 'All advisors', 'elaltillo-bookacall' ) . '</option>';
		foreach ( $advisors as $item ) {
			printf( '<option value="%d" %s>%s</option>', (int) $item->ID, selected( $advisor, (int) $item->ID, false ), esc_html( get_the_title( $item ) ) );
		}
		echo '</select>';
	}

	/** Apply the filters and sort by call time: upcoming soonest first, past most recent first. */
	public function query( WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ELAB_Post_Types::BOOKING !== $query->get( 'post_type' ) ) {
			return;
		}
		$when = self::current_filter( 'elab_when', self::WHEN, 'upcoming' );
		$status = self::current_filter( 'elab_status', array_keys( self::statuses() ) );
		$advisor = isset( $_GET['elab_advisor'] ) ? absint( $_GET['elab_advisor'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$meta = array( 'elab_start' => array( 'key' => '_elab_start_utc', 'type' => 'DATETIME' ) );
		if ( 'upcoming' === $when ) {
			$meta['elab_start'] += array( 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '>=' );
		} elseif ( 'past' === $when ) {
			$meta['elab_start'] += array( 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '<' );
		}
		if ( $status ) {
			$meta[] = array( 'key' => '_elab_status', 'value' => $status );
		}
		if ( $advisor ) {
			$meta[] = array( 'key' => '_elab_advisor', 'value' => $advisor );
		}
		$query->set( 'meta_query', $meta );
		$orderby = $query->get( 'orderby' );
		if ( ! $orderby || 'elab_when' === $orderby ) {
			$requested = strtoupper( (string) $query->get( 'order' ) );
			$order = in_array( $requested, array( 'ASC', 'DESC' ), true ) && 'elab_when' === $orderby ? $requested : ( 'past' === $when ? 'DESC' : 'ASC' );
			$query->set( 'orderby', array( 'elab_start' => $order ) );
		}
	}
}
