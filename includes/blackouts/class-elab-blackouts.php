<?php
/** Shared date exclusions for all advisors and private exceptions per advisor. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ELAB_Blackouts {
	const OPTION = 'elab_global_blackouts';
	const META = '_elab_blackouts';
	const META_GLOBAL_OVERRIDES = '_elab_global_open_rules';

	public static function sanitize( $rows ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$start = isset( $row['start'] ) && is_string( $row['start'] ) ? sanitize_text_field( $row['start'] ) : '';
			$end = isset( $row['end'] ) && is_string( $row['end'] ) ? sanitize_text_field( $row['end'] ) : '';
			$end = $end ?: $start;
			if ( ! self::valid_date( $start ) || ! self::valid_date( $end ) || $end < $start ) {
				continue;
			}
			$clean[] = array(
				'start' => $start,
				'end' => $end,
				'label' => isset( $row['label'] ) && is_string( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'annual' => $start === $end && ! empty( $row['annual'] ),
			);
		}
		return $clean;
	}

	private static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	public static function is_blocked( $advisor_id, DateTimeImmutable $advisor_day ) {
		$date = $advisor_day->format( 'Y-m-d' );
		$global = get_option( self::OPTION, array() );
		$personal = get_post_meta( $advisor_id, self::META, true );
		foreach ( ( is_array( $personal ) ? $personal : array() ) as $rule ) {
			if ( self::matches( $rule, $date ) ) {
				return true;
			}
		}
		$overrides = get_post_meta( $advisor_id, self::META_GLOBAL_OVERRIDES, true );
		$overrides = is_array( $overrides ) ? $overrides : array();
		foreach ( ( is_array( $global ) ? $global : array() ) as $rule ) {
			if ( self::matches( $rule, $date ) && ! in_array( self::rule_key( $rule ), $overrides, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function matches( $rule, $date ) {
		if ( ! is_array( $rule ) || empty( $rule['start'] ) || empty( $rule['end'] ) ) {
			return false;
		}
		if ( ! empty( $rule['annual'] ) ) {
			return substr( $date, 5 ) === substr( $rule['start'], 5 );
		}
		return $date >= $rule['start'] && $date <= $rule['end'];
	}

	public static function global_rules() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function rule_key( $rule ) {
		return hash( 'sha256', (string) ( $rule['start'] ?? '' ) . '|' . (string) ( $rule['end'] ?? '' ) . '|' . ( empty( $rule['annual'] ) ? 'once' : 'annual' ) );
	}

	public static function sanitize_override_keys( $submitted ) {
		$allowed = array();
		foreach ( self::global_rules() as $rule ) {
			$allowed[ self::rule_key( $rule ) ] = true;
		}
		$clean = array();
		foreach ( is_array( $submitted ) ? $submitted : array() as $key ) {
			if ( is_string( $key ) && isset( $allowed[ $key ] ) ) {
				$clean[ $key ] = $key;
			}
		}
		return array_values( $clean );
	}

	public static function render_global_overrides( $advisor_id, $field_name ) {
		$rules = self::global_rules();
		if ( ! $rules ) {
			echo '<p class="description">' . esc_html__( 'No global unavailable dates have been configured.', 'elaltillo-bookacall' ) . '</p>';
			return;
		}
		$saved = get_post_meta( $advisor_id, self::META_GLOBAL_OVERRIDES, true );
		$saved = is_array( $saved ) ? $saved : array();
		?>
		<p class="description"><?php esc_html_e( 'Select the global dates when this advisor will work. Regular weekly hours still apply. Personal time off and busy Google Calendar events still block slots. If global rules overlap, select every matching rule.', 'elaltillo-bookacall' ); ?></p>
		<table class="widefat striped elab-global-overrides-table"><thead><tr><th scope="col"><?php esc_html_e( 'Global date', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Work that day', 'elaltillo-bookacall' ); ?></th></tr></thead><tbody>
		<?php foreach ( $rules as $rule ) :
			$key = self::rule_key( $rule );
			$date_label = $rule['start'] === $rule['end'] ? $rule['start'] : $rule['start'] . ' — ' . $rule['end'];
			if ( ! empty( $rule['annual'] ) ) {
				$date_label .= ' · ' . __( 'every year', 'elaltillo-bookacall' );
			}
			?>
			<tr><td><?php echo esc_html( $date_label ); ?></td><td><?php echo esc_html( $rule['label'] ?: '—' ); ?></td><td><label><input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $saved, true ) ); ?>> <span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Work during %s', 'elaltillo-bookacall' ), $date_label ) ); ?></span></label></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	public static function render_editor( $rows, $prefix ) {
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$rows[] = array( 'start' => '', 'end' => '', 'label' => '', 'annual' => false );
		?>
		<div data-elab-blackouts data-elab-prefix="<?php echo esc_attr( $prefix ); ?>">
			<table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'From', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'To', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Reason (optional)', 'elaltillo-bookacall' ); ?></th><th scope="col"><?php esc_html_e( 'Every year', 'elaltillo-bookacall' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'elaltillo-bookacall' ); ?></span></th></tr></thead><tbody data-elab-blackout-rows>
			<?php foreach ( $rows as $index => $row ) : ?>
				<tr data-elab-blackout-row><td><input type="date" name="<?php echo esc_attr( $prefix . '[' . $index . '][start]' ); ?>" value="<?php echo esc_attr( $row['start'] ?? '' ); ?>" aria-label="<?php esc_attr_e( 'First unavailable date', 'elaltillo-bookacall' ); ?>"></td><td><input type="date" name="<?php echo esc_attr( $prefix . '[' . $index . '][end]' ); ?>" value="<?php echo esc_attr( $row['end'] ?? '' ); ?>" aria-label="<?php esc_attr_e( 'Last unavailable date', 'elaltillo-bookacall' ); ?>"></td><td><input type="text" name="<?php echo esc_attr( $prefix . '[' . $index . '][label]' ); ?>" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Holiday, vacation…', 'elaltillo-bookacall' ); ?>"></td><td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[' . $index . '][annual]' ); ?>" value="1" <?php checked( ! empty( $row['annual'] ) ); ?>><span class="screen-reader-text"><?php esc_html_e( 'Repeat annually', 'elaltillo-bookacall' ); ?></span></label></td><td><button type="button" class="button-link-delete" data-elab-remove-blackout aria-label="<?php esc_attr_e( 'Remove unavailable date', 'elaltillo-bookacall' ); ?>">×</button></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><button type="button" class="button button-secondary" data-elab-add-blackout>+ <?php esc_html_e( 'Add unavailable date', 'elaltillo-bookacall' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Leave the end date empty for one day. “Every year” applies only to a single day. Dates use each advisor’s local time zone.', 'elaltillo-bookacall' ); ?></p>
		</div>
		<?php
	}
}
