<?php
/**
 * Visitor's private booking page: review the call and cancel it.
 *
 * @var int    $booking_id Booking shown, 0 when the link is not valid.
 * @var string $token      Private token from the link.
 * @var string $result     Outcome of a cancellation just submitted.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$elab_card = 'grid gap-6 rounded-3xl border border-secondary/15 bg-primary p-6 shadow-[0_30px_60px_-40px_rgba(40,44,51,0.45)] sm:p-10';
$elab_link = 'cursor-pointer font-secondary text-xs underline decoration-secondary/30 underline-offset-4 hover:decoration-secondary';
$elab_book = remove_query_arg( array( 'elab_booking', 'elab_token', 'elab_result' ) );
?>
<div data-elab-booking class="not-prose alignwide grid w-[min(40rem,calc(100vw-2rem))] max-w-none gap-6 self-center py-10 text-left text-secondary">
<?php if ( ! $booking_id ) : ?>
	<section class="<?php echo esc_attr( $elab_card ); ?>">
		<h2 class="font-primary text-2xl"><?php esc_html_e( 'This link is not valid', 'elaltillo-bookacall' ); ?></h2>
		<p class="font-primary text-base text-secondary/70"><?php esc_html_e( 'Open the link from the email we sent you, on this device. The booking may also have been removed.', 'elaltillo-bookacall' ); ?></p>
		<a class="<?php echo esc_attr( $elab_link ); ?> justify-self-start" href="<?php echo esc_url( $elab_book ); ?>"><?php esc_html_e( 'Book a call', 'elaltillo-bookacall' ); ?></a>
	</section>
<?php else :
	$elab_status = (string) get_post_meta( $booking_id, '_elab_status', true ) ?: 'pending';
	$elab_zone_name = (string) get_post_meta( $booking_id, '_elab_visitor_timezone', true ) ?: 'UTC';
	$elab_meet = (string) get_post_meta( $booking_id, '_elab_meet_url', true );
	$elab_labels = array( 'pending' => __( 'Waiting for confirmation', 'elaltillo-bookacall' ), 'confirmed' => __( 'Confirmed', 'elaltillo-bookacall' ), 'cancelled' => __( 'Cancelled', 'elaltillo-bookacall' ) );
	?>
	<section class="<?php echo esc_attr( $elab_card ); ?>" aria-labelledby="elab-manage-title">
		<?php if ( 'cancelled' === $result ) : ?>
			<p role="status" class="rounded-xl bg-accent px-4 py-3 font-secondary text-sm"><?php esc_html_e( 'Your call was cancelled. The advisor has been told.', 'elaltillo-bookacall' ); ?></p>
		<?php elseif ( $result ) : ?>
			<p role="alert" class="rounded-xl bg-secondary px-4 py-3 font-secondary text-sm text-primary"><?php esc_html_e( 'The call could not be cancelled right now. Please try again in a few minutes.', 'elaltillo-bookacall' ); ?></p>
		<?php endif; ?>
		<div class="grid gap-1">
			<p class="pb-2 font-secondary text-xs uppercase tracking-wider text-secondary/60"><?php esc_html_e( 'Your call', 'elaltillo-bookacall' ); ?></p>
			<h2 id="elab-manage-title" class="font-primary text-2xl first-letter:uppercase sm:text-3xl <?php echo 'cancelled' === $elab_status ? 'line-through decoration-secondary/40' : ''; ?>"><?php echo esc_html( ELAB_Booking_List_Admin::format_time( $booking_id, $elab_zone_name ) ); ?></h2>
			<p class="pt-1 font-secondary text-xs text-secondary/60"><?php echo esc_html( implode( ' · ', array_filter( array(
				sprintf( __( 'with %s', 'elaltillo-bookacall' ), get_the_title( (int) get_post_meta( $booking_id, '_elab_advisor', true ) ) ),
				sprintf( __( '%d min', 'elaltillo-bookacall' ), ELAB_Booking_Admin::duration( $booking_id ) ),
				ELAB_Booking_Service::language_label( get_post_meta( $booking_id, '_elab_language', true ) ?: ELAB_Booking_Service::DEFAULT_LANGUAGE ),
				str_replace( '_', ' ', $elab_zone_name ),
			) ) ) ); ?></p>
		</div>
		<p class="flex items-center gap-2 font-secondary text-xs"><span aria-hidden="true" class="h-2 w-2 rounded-full <?php echo esc_attr( 'confirmed' === $elab_status ? 'bg-secondary' : ( 'cancelled' === $elab_status ? 'bg-secondary/30' : 'bg-accent ring-1 ring-secondary/30' ) ); ?>"></span><?php echo esc_html( $elab_labels[ $elab_status ] ?? $elab_labels['pending'] ); ?></p>
		<?php if ( $elab_meet && 'cancelled' !== $elab_status ) : ?>
			<a href="<?php echo esc_url( $elab_meet ); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center justify-self-start rounded-full bg-secondary px-6 py-3 font-secondary text-sm text-primary transition-colors hover:bg-secondary/85"><?php esc_html_e( 'Join with Google Meet', 'elaltillo-bookacall' ); ?></a>
		<?php endif; ?>
		<?php if ( ELAB_Booking_Manage::can_cancel( $booking_id ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grid gap-4 border-t border-secondary/10 pt-6">
				<input type="hidden" name="action" value="<?php echo esc_attr( ELAB_Booking_Manage::ACTION ); ?>">
				<input type="hidden" name="booking" value="<?php echo esc_attr( $booking_id ); ?>">
				<label class="flex cursor-pointer items-start gap-3 font-secondary text-xs leading-relaxed text-secondary/70">
					<input type="checkbox" name="confirm" value="1" required class="peer sr-only">
					<span aria-hidden="true" class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-secondary/40 bg-white/70 text-[0.7rem] text-transparent transition-colors peer-checked:border-secondary peer-checked:bg-secondary peer-checked:text-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-secondary">✓</span>
					<span><?php esc_html_e( 'Yes, cancel this call. I can book a new time afterwards.', 'elaltillo-bookacall' ); ?></span>
				</label>
				<button type="submit" class="inline-flex cursor-pointer items-center justify-center justify-self-start rounded-full border border-secondary px-6 py-3 font-secondary text-sm transition-colors hover:bg-secondary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary"><?php esc_html_e( 'Cancel call', 'elaltillo-bookacall' ); ?></button>
			</form>
		<?php endif; ?>
		<a class="<?php echo esc_attr( $elab_link ); ?> justify-self-start" href="<?php echo esc_url( $elab_book ); ?>"><?php echo esc_html( 'cancelled' === $elab_status ? __( 'Book a new time', 'elaltillo-bookacall' ) : __( 'Book another call', 'elaltillo-bookacall' ) ); ?></a>
	</section>
<?php endif; ?>
</div>
