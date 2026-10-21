<?php
/** @var WP_Post[] $advisors Published advisors. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form data-elab-booking enctype="multipart/form-data" class="mx-auto my-8 grid w-full max-w-3xl gap-6 text-secondary">
	<div data-elab-message role="status" aria-live="polite" class="hidden border border-secondary bg-primary p-4 text-secondary"></div>
	<div data-elab-calendar-actions class="hidden flex flex-wrap gap-4 font-secondary text-sm"></div>
	<fieldset class="grid gap-4"><legend class="mb-4 font-secondary text-sm"><?php esc_html_e( 'Choose who you would like to speak with', 'elaltillo-bookacall' ); ?></legend>
		<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
		<?php foreach ( $advisors as $advisor ) :
			$zone = get_post_meta( $advisor->ID, '_elab_timezone', true ) ?: 'UTC';
			$photo = get_the_post_thumbnail_url( $advisor, 'thumbnail' );
			?>
			<label data-elab-advisor-card class="relative grid cursor-pointer gap-3 rounded-lg border border-secondary bg-primary p-4 text-secondary transition-colors hover:bg-accent">
				<input class="absolute opacity-0" type="radio" name="advisor" value="<?php echo esc_attr( $advisor->ID ); ?>" required>
				<span class="flex items-center gap-4">
					<?php if ( $photo ) : ?><img src="<?php echo esc_url( $photo ); ?>" alt="" class="h-10 w-10 rounded-full object-cover" loading="lazy"><?php else : ?><span aria-hidden="true" class="flex h-10 w-10 items-center justify-center rounded-full bg-accent font-primary text-xl"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( get_the_title( $advisor ), 0, 1 ) : substr( get_the_title( $advisor ), 0, 1 ) ); ?></span><?php endif; ?>
					<span class="grid gap-1"><strong class="font-primary text-lg"><?php echo esc_html( get_the_title( $advisor ) ); ?></strong><span class="font-secondary text-xs"><?php echo esc_html( str_replace( '_', ' ', $zone ) ); ?></span></span>
				</span>
				<?php if ( has_excerpt( $advisor ) ) : ?><span class="font-primary text-sm"><?php echo esc_html( get_the_excerpt( $advisor ) ); ?></span><?php endif; ?>
				<span class="font-secondary text-xs"><?php esc_html_e( 'View available times', 'elaltillo-bookacall' ); ?> →</span>
			</label>
		<?php endforeach; ?>
		</div>
		<?php if ( ! $advisors ) : ?><p><?php esc_html_e( 'No advisors are available yet.', 'elaltillo-bookacall' ); ?></p><?php endif; ?>
	</fieldset>
	<?php if ( $advisors ) : ?>
	<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Your sector (optional)', 'elaltillo-bookacall' ); ?> <input type="text" name="sector" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. architecture, education', 'elaltillo-bookacall' ); ?>" class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2"></label>
	<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
		<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Date', 'elaltillo-bookacall' ); ?> <input type="date" name="date" required class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2"></label>
		<input type="hidden" name="visitor_timezone" value="UTC">
		<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Available time (your local time)', 'elaltillo-bookacall' ); ?> <select name="slot" required disabled class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50"><option value=""><?php esc_html_e( 'Choose advisor and date first', 'elaltillo-bookacall' ); ?></option></select></label>
	</div>
	<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
		<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Your name', 'elaltillo-bookacall' ); ?> <input type="text" name="name" autocomplete="name" required maxlength="120" class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2"></label>
		<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Email', 'elaltillo-bookacall' ); ?> <input type="email" name="email" autocomplete="email" required maxlength="190" class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2"></label>
	</div>
	<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Tell us about your project', 'elaltillo-bookacall' ); ?> <textarea name="message" rows="4" maxlength="5000" class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary focus-visible:outline-2 focus-visible:outline-offset-2"></textarea></label>
	<label class="grid gap-2 font-secondary text-sm"><?php esc_html_e( 'Attachments (PDF, Word or Excel; max 3 files, 10 MB each)', 'elaltillo-bookacall' ); ?> <input type="file" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx" multiple class="w-full rounded-lg border border-secondary bg-transparent px-4 py-3 font-primary text-base text-secondary file:mr-4 file:rounded-full file:border-0 file:bg-accent file:px-4 file:py-2 file:font-secondary file:text-secondary"></label>
	<button type="submit" class="w-fit cursor-pointer rounded-full border border-secondary bg-accent px-6 py-3 font-secondary text-secondary transition-colors hover:bg-secondary hover:text-primary"><?php esc_html_e( 'Request a call', 'elaltillo-bookacall' ); ?></button>
	<p class="text-sm opacity-75"><?php if ( ELAB_Google_Calendar::configured() ) { esc_html_e( 'Your time is confirmed when the calendar invitation is sent to your email.', 'elaltillo-bookacall' ); } else { esc_html_e( 'This is a booking request. The time is confirmed by email after the advisor reviews it.', 'elaltillo-bookacall' ); } ?></p>
	<?php endif; ?>
</form>
