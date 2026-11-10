<?php
/**
 * Public booking calendar. The script fills the month grid, the times and the summaries.
 *
 * Layout rules that keep the calendar from jumping:
 * - spacing uses gap and padding only, because the theme's `.prose *` rule resets margins;
 * - the month grid always has six weeks, the times area has a fixed height, and the
 *   selection bar is always rendered, so loading or picking never changes the card size.
 *
 * @var bool $has_advisors At least one advisor can take bookings.
 * @var bool $confirm_mode Google Calendar confirms bookings immediately.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$elab_uid = wp_unique_id( 'elab-' );
$elab_field = 'w-full rounded-xl border border-secondary/25 bg-white/70 px-4 py-3 font-primary text-base text-secondary transition-[border-color,box-shadow] duration-200 placeholder:text-secondary/40 hover:border-secondary/50 focus:border-secondary focus:shadow-[0_0_0_4px_#FFFD88] focus:outline-none';
$elab_label = 'grid gap-2 font-secondary text-xs uppercase tracking-wider text-secondary/70';
$elab_primary = 'inline-flex cursor-pointer items-center justify-center gap-2 rounded-full bg-secondary px-7 py-3.5 font-secondary text-sm text-primary transition-[background-color,opacity,transform] duration-200 hover:bg-secondary/85 active:scale-[0.98] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary disabled:cursor-not-allowed disabled:opacity-30 disabled:active:scale-100';
$elab_link = 'cursor-pointer font-secondary text-xs underline decoration-secondary/30 underline-offset-4 transition-[text-decoration-color] duration-200 hover:decoration-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary';
$elab_card = 'overflow-clip rounded-3xl border border-secondary/15 bg-primary shadow-[0_30px_60px_-40px_rgba(40,44,51,0.45)]';
$elab_nav = 'flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-secondary/20 transition-[background-color,border-color,opacity] duration-200 hover:border-secondary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary disabled:cursor-default disabled:opacity-25 disabled:hover:border-secondary/20 disabled:hover:bg-transparent';
$elab_optional = '<span class="normal-case tracking-normal opacity-60">' . esc_html__( '(optional)', 'elaltillo-bookacall' ) . '</span>';
$elab_step_dot = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-current text-[0.65rem] transition-[background-color,color,border-color] duration-300';
?>
<div data-elab-booking class="not-prose alignwide grid w-[min(64rem,calc(100vw-2rem))] max-w-none scroll-mt-28 gap-6 self-center py-10 text-left text-secondary">
<?php if ( ! $has_advisors ) : ?>
	<p class="rounded-3xl border border-secondary/15 bg-primary p-8 text-center font-primary text-lg"><?php esc_html_e( 'There are no openings right now. Please check back soon.', 'elaltillo-bookacall' ); ?></p>
<?php else : ?>
	<ol class="flex flex-nowrap items-center gap-2 p-0 font-secondary text-[0.65rem] uppercase tracking-wider sm:gap-3 sm:text-xs" aria-label="<?php esc_attr_e( 'Booking steps', 'elaltillo-bookacall' ); ?>">
		<li data-elab-progress="time" class="flex items-center gap-2 whitespace-nowrap transition-opacity duration-300"><span class="<?php echo esc_attr( $elab_step_dot ); ?>">1</span><?php esc_html_e( 'Time', 'elaltillo-bookacall' ); ?></li>
		<li aria-hidden="true" class="h-px w-4 shrink-0 bg-secondary/20 sm:w-8"></li>
		<li data-elab-progress="details" class="flex items-center gap-2 whitespace-nowrap opacity-40 transition-opacity duration-300"><span class="<?php echo esc_attr( $elab_step_dot ); ?>">2</span><?php esc_html_e( 'Details', 'elaltillo-bookacall' ); ?></li>
		<li aria-hidden="true" class="h-px w-4 shrink-0 bg-secondary/20 sm:w-8"></li>
		<li data-elab-progress="done" class="flex items-center gap-2 whitespace-nowrap opacity-40 transition-opacity duration-300"><span class="<?php echo esc_attr( $elab_step_dot ); ?>">3</span><?php echo esc_html( $confirm_mode ? __( 'Booked', 'elaltillo-bookacall' ) : __( 'Sent', 'elaltillo-bookacall' ) ); ?></li>
	</ol>
	<p data-elab-live class="sr-only" aria-live="polite"></p>

	<div data-elab-stage class="relative">
	<section data-elab-step="time" aria-labelledby="<?php echo esc_attr( $elab_uid ); ?>-time" class="<?php echo esc_attr( $elab_card ); ?> grid md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
		<h2 id="<?php echo esc_attr( $elab_uid ); ?>-time" class="sr-only" tabindex="-1"><?php esc_html_e( 'Choose a time', 'elaltillo-bookacall' ); ?></h2>
		<div class="grid content-start gap-6 p-5 sm:p-8 md:border-r md:border-secondary/10">
			<div class="flex items-center justify-between gap-4">
				<p data-elab-month class="translate-y-[0.1em] whitespace-nowrap font-primary text-2xl leading-none capitalize lg:text-3xl" aria-live="polite">&nbsp;</p>
				<div class="flex gap-2">
					<button type="button" data-elab-prev class="<?php echo esc_attr( $elab_nav ); ?>"><span aria-hidden="true">←</span><span class="sr-only"><?php esc_html_e( 'Previous month', 'elaltillo-bookacall' ); ?></span></button>
					<button type="button" data-elab-next class="<?php echo esc_attr( $elab_nav ); ?>"><span aria-hidden="true">→</span><span class="sr-only"><?php esc_html_e( 'Next month', 'elaltillo-bookacall' ); ?></span></button>
				</div>
			</div>
			<div class="grid gap-2 overflow-clip">
				<div data-elab-weekdays aria-hidden="true" class="grid grid-cols-7 gap-1 text-center font-secondary text-[0.65rem] uppercase tracking-wider text-secondary/50"></div>
				<div data-elab-days role="group" class="grid grid-cols-7 gap-1"></div>
			</div>
			<p class="flex items-center gap-2 font-secondary text-xs text-secondary/60"><span aria-hidden="true" class="h-3.5 w-3.5 rounded-full shadow-[inset_0_0_0_1px_rgba(40,44,51,0.3)]"></span><?php esc_html_e( 'Days with open times', 'elaltillo-bookacall' ); ?></p>
		</div>
		<div class="flex flex-col gap-5 border-t border-secondary/10 p-5 sm:p-8 md:border-t-0 md:bg-secondary/[0.025]">
			<div class="relative grid gap-1">
				<p data-elab-day class="scroll-mt-28 font-primary text-xl first-letter:uppercase sm:text-2xl">&nbsp;</p>
				<p class="flex flex-wrap items-center gap-x-2 font-secondary text-xs text-secondary/60">
					<span data-elab-tz-label>&nbsp;</span>
					<button type="button" data-elab-tz-toggle aria-expanded="false" class="<?php echo esc_attr( $elab_link ); ?>"><?php esc_html_e( 'Change', 'elaltillo-bookacall' ); ?></button>
				</p>
				<div data-elab-tz-picker hidden role="dialog" aria-label="<?php esc_attr_e( 'Your time zone', 'elaltillo-bookacall' ); ?>" class="absolute inset-x-0 top-[calc(100%+0.5rem)] z-20 grid gap-3 rounded-2xl border border-secondary/15 bg-primary p-3 shadow-[0_24px_48px_-24px_rgba(40,44,51,0.55)]">
					<label class="sr-only" for="<?php echo esc_attr( $elab_uid ); ?>-tz-search"><?php esc_html_e( 'Search time zones', 'elaltillo-bookacall' ); ?></label>
					<input id="<?php echo esc_attr( $elab_uid ); ?>-tz-search" data-elab-tz-search type="search" role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $elab_uid ); ?>-tz-list" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Search a city or region', 'elaltillo-bookacall' ); ?>" class="<?php echo esc_attr( $elab_field ); ?> py-2.5 text-sm">
					<button type="button" data-elab-tz-detected hidden class="flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-left font-secondary text-xs text-secondary/70 transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-secondary"></button>
					<ul id="<?php echo esc_attr( $elab_uid ); ?>-tz-list" data-elab-tz-list role="listbox" aria-label="<?php esc_attr_e( 'Time zones', 'elaltillo-bookacall' ); ?>" class="grid max-h-56 list-none content-start gap-0.5 overflow-y-auto overscroll-contain p-0"></ul>
				</div>
			</div>
			<div data-elab-periods class="relative flex rounded-full border border-secondary/15 p-1 font-secondary text-xs">
				<span data-elab-period-indicator aria-hidden="true" class="absolute top-1 bottom-1 left-0 w-0 rounded-full bg-secondary opacity-0 transition-[transform,width,opacity] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"></span>
				<?php foreach ( array( 'morning' => __( 'Morning', 'elaltillo-bookacall' ), 'afternoon' => __( 'Afternoon', 'elaltillo-bookacall' ), 'evening' => __( 'Evening', 'elaltillo-bookacall' ) ) as $elab_period => $elab_period_label ) : ?>
					<button type="button" data-elab-period="<?php echo esc_attr( $elab_period ); ?>" aria-pressed="false" disabled class="relative z-10 flex flex-auto cursor-pointer items-center justify-center rounded-full px-3 py-2 transition-[color,opacity] duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary disabled:cursor-default disabled:opacity-30 aria-pressed:text-primary"><?php echo esc_html( $elab_period_label ); ?><span data-elab-period-count class="sr-only"></span></button>
				<?php endforeach; ?>
			</div>
			<div data-elab-times class="grid h-[14.75rem] grid-cols-3 content-start gap-2 overflow-y-auto overscroll-contain p-0.5 transition-opacity duration-200"></div>
			<div aria-hidden="true" class="hidden flex-1 md:block"></div>
			<div data-elab-choice class="sticky bottom-12 z-10 grid gap-4 border-t border-secondary/10 bg-primary py-4 md:static md:bg-transparent md:pt-5 md:pb-0">
				<div class="grid min-w-0 gap-1">
					<p data-elab-choice-when class="font-primary text-lg leading-tight"><?php esc_html_e( 'Pick a time', 'elaltillo-bookacall' ); ?></p>
					<p data-elab-choice-meta class="flex min-h-[1.25rem] min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 font-secondary text-xs text-secondary/60">
						<span data-elab-choice-duration></span>
						<span data-elab-choice-with hidden><?php esc_html_e( 'with', 'elaltillo-bookacall' ); ?></span>
						<span data-elab-choice-name></span>
						<span data-elab-prefer hidden class="relative inline-flex min-w-0 items-center">
							<label class="sr-only" for="<?php echo esc_attr( $elab_uid ); ?>-prefer"><?php esc_html_e( 'Prefer someone?', 'elaltillo-bookacall' ); ?></label>
							<select id="<?php echo esc_attr( $elab_uid ); ?>-prefer" data-elab-prefer-select class="max-w-[11rem] min-w-0 [field-sizing:content] cursor-pointer truncate appearance-none bg-transparent pr-4 text-secondary underline decoration-secondary/30 underline-offset-4 transition-[text-decoration-color] duration-200 hover:decoration-secondary focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary"></select>
							<span aria-hidden="true" class="pointer-events-none absolute right-0 text-[0.6rem] text-secondary">▾</span>
						</span>
					</p>
				</div>
				<button type="button" data-elab-continue disabled class="<?php echo esc_attr( $elab_primary ); ?> w-full"><?php esc_html_e( 'Continue', 'elaltillo-bookacall' ); ?> <span aria-hidden="true">→</span></button>
			</div>
		</div>
	</section>

	<form data-elab-step="details" hidden novalidate enctype="multipart/form-data" aria-labelledby="<?php echo esc_attr( $elab_uid ); ?>-details" class="<?php echo esc_attr( $elab_card ); ?> grid md:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
		<aside class="flex flex-col gap-6 bg-accent p-6 sm:p-8 md:justify-between">
			<div class="grid gap-1">
				<p class="pb-2 font-secondary text-xs uppercase tracking-wider text-secondary/70"><?php esc_html_e( 'Your call', 'elaltillo-bookacall' ); ?></p>
				<p data-elab-summary-day class="font-primary text-2xl first-letter:uppercase sm:text-3xl"></p>
				<p data-elab-summary-time class="font-primary text-xl"></p>
				<p data-elab-summary-meta class="pt-2 font-secondary text-xs text-secondary/70"></p>
			</div>
			<button type="button" data-elab-back class="<?php echo esc_attr( $elab_link ); ?> self-start">← <?php esc_html_e( 'Change time', 'elaltillo-bookacall' ); ?></button>
		</aside>
		<div class="grid gap-6 p-6 sm:p-8">
			<h2 id="<?php echo esc_attr( $elab_uid ); ?>-details" tabindex="-1" class="font-primary text-2xl outline-none"><?php esc_html_e( 'Tell us about you', 'elaltillo-bookacall' ); ?></h2>
			<label class="sr-only" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
			<div class="grid gap-5 sm:grid-cols-2">
				<label class="<?php echo esc_attr( $elab_label ); ?>"><?php esc_html_e( 'Name', 'elaltillo-bookacall' ); ?> <input type="text" name="name" autocomplete="name" required maxlength="120" class="<?php echo esc_attr( $elab_field ); ?>"></label>
				<label class="<?php echo esc_attr( $elab_label ); ?>"><?php esc_html_e( 'Email', 'elaltillo-bookacall' ); ?> <input type="email" name="email" autocomplete="email" required maxlength="190" class="<?php echo esc_attr( $elab_field ); ?>"></label>
			</div>
			<fieldset class="grid min-w-0 gap-2 border-0 p-0">
				<legend class="pb-2 font-secondary text-xs uppercase tracking-wider text-secondary/70"><?php esc_html_e( 'Meeting language', 'elaltillo-bookacall' ); ?></legend>
				<div class="flex flex-wrap gap-2">
				<?php foreach ( ELAB_Booking_Service::languages() as $elab_code => $elab_language ) : ?>
					<label class="cursor-pointer"><input type="radio" name="language" value="<?php echo esc_attr( $elab_code ); ?>" class="peer sr-only" <?php checked( ELAB_Booking_Service::DEFAULT_LANGUAGE, $elab_code ); ?>><span lang="<?php echo esc_attr( $elab_code ); ?>" class="inline-flex items-center rounded-full border border-secondary/25 px-5 py-2.5 font-secondary text-sm transition-[background-color,color,border-color] duration-200 hover:border-secondary peer-checked:border-secondary peer-checked:bg-secondary peer-checked:text-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-secondary"><?php echo esc_html( $elab_language ); ?></span></label>
				<?php endforeach; ?>
				</div>
			</fieldset>
			<label class="<?php echo esc_attr( $elab_label ); ?>"><span><?php esc_html_e( 'Sector', 'elaltillo-bookacall' ); ?> <?php echo $elab_optional; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span> <input type="text" name="sector" maxlength="120" placeholder="<?php esc_attr_e( 'e.g. architecture, education', 'elaltillo-bookacall' ); ?>" class="<?php echo esc_attr( $elab_field ); ?>"></label>
			<label class="<?php echo esc_attr( $elab_label ); ?>"><span><?php esc_html_e( 'Your project', 'elaltillo-bookacall' ); ?> <?php echo $elab_optional; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span> <textarea name="message" rows="4" maxlength="5000" placeholder="<?php esc_attr_e( 'What would you like to talk about?', 'elaltillo-bookacall' ); ?>" class="<?php echo esc_attr( $elab_field ); ?> resize-y"></textarea></label>
			<label class="grid cursor-pointer gap-1 rounded-xl border border-dashed border-secondary/30 px-4 py-4 transition-[border-color,box-shadow] duration-200 hover:border-secondary focus-within:border-secondary focus-within:shadow-[0_0_0_4px_#FFFD88]">
				<span class="font-secondary text-xs uppercase tracking-wider text-secondary/70"><?php esc_html_e( 'Attach files', 'elaltillo-bookacall' ); ?> <?php echo $elab_optional; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></span>
				<span data-elab-files class="font-primary text-sm text-secondary/70"><?php esc_html_e( 'PDF, Word or Excel · up to 3 files, 10 MB each', 'elaltillo-bookacall' ); ?></span>
				<input type="file" name="attachments[]" accept=".pdf,.doc,.docx,.xls,.xlsx" multiple class="sr-only">
			</label>
			<p data-elab-error role="alert" hidden class="rounded-xl bg-secondary px-4 py-3 font-secondary text-sm text-primary"></p>
			<div class="flex flex-wrap items-center gap-4 pt-1">
				<button type="submit" class="<?php echo esc_attr( $elab_primary ); ?>"><?php echo esc_html( $confirm_mode ? __( 'Confirm call', 'elaltillo-bookacall' ) : __( 'Request call', 'elaltillo-bookacall' ) ); ?></button>
				<p class="font-secondary text-xs text-secondary/60"><?php echo esc_html( $confirm_mode ? __( 'You will receive a calendar invitation by email.', 'elaltillo-bookacall' ) : __( 'We confirm the time by email after review.', 'elaltillo-bookacall' ) ); ?></p>
			</div>
		</div>
	</form>

	<section data-elab-step="done" hidden aria-labelledby="<?php echo esc_attr( $elab_uid ); ?>-done" class="<?php echo esc_attr( $elab_card ); ?> grid justify-items-center gap-8 px-6 py-12 text-center sm:px-12 sm:py-16">
		<span data-elab-done-mark aria-hidden="true" class="flex h-16 w-16 items-center justify-center rounded-full bg-accent font-primary text-3xl">✓</span>
		<div class="grid justify-items-center gap-2">
			<h2 id="<?php echo esc_attr( $elab_uid ); ?>-done" tabindex="-1" class="font-primary text-3xl outline-none sm:text-4xl"></h2>
			<p data-elab-done-when class="pt-2 font-primary text-xl first-letter:uppercase"></p>
			<p data-elab-done-meta class="font-secondary text-xs text-secondary/60"></p>
		</div>
		<p data-elab-done-message class="max-w-md font-primary text-base text-secondary/80"></p>
		<div data-elab-calendar-actions class="flex flex-wrap justify-center gap-3 font-secondary text-sm empty:hidden"></div>
		<button type="button" data-elab-again class="<?php echo esc_attr( $elab_link ); ?>"><?php esc_html_e( 'Book another call', 'elaltillo-bookacall' ); ?></button>
	</section>
	</div>
<?php endif; ?>
</div>
