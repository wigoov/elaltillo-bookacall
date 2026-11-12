# El Altillo Book a Call

Modular WordPress plugin for booking calls with any number of advisors. Each advisor has a time zone, weekly schedule, unavailable dates, and their own Google Calendar connection. Visitors see appointment times in their local time zone and can add the meeting to Google, Apple, or Outlook Calendar. Sector is an optional free-text field.

## Structure

```text
includes/
  content/       post types
  advisors/      advisor profile editing and validation
  blackouts/     global and advisor unavailable dates and exceptions
  google/        OAuth, credentials, free/busy, and calendar events
  bookings/      availability, requests, REST, and admin management
  files/         private attachment storage and downloads
  frontend/      shortcode and booking form template
src/js/          form and admin JavaScript source
src/css/         Tailwind 4 utilities for the form, without Preflight
assets/          built JS/CSS and admin CSS
build.mjs        unified plugin build
```

The public form uses only Tailwind 4 utilities written in its template. The plugin compiles those utilities without Preflight or other base styles and uses the theme's font and color values. Admin screens use separate, neutral WordPress styling. The plugin owns its assets; the theme is not changed.

After changing classes or JavaScript, run `pnpm install` and `pnpm build` in this directory. One command builds both JavaScript bundles and the public CSS.

## Current features

- Unlimited advisor profiles in WordPress. Visitors may enter a sector on each booking.
- Weekly availability per advisor in an IANA time zone, such as `Europe/Lisbon`.
- Visual daily hours editor, searchable time zones, and global or advisor unavailable dates, including ranges and annual single-day holidays. An advisor or administrator can allow that advisor to work on selected global unavailable dates.
- Appointment times converted to the visitor's browser time zone; visitors can switch time zone.
- Booking rules: call length, minimum notice, booking window and an optional break between calls. Site defaults live under **Advisors → Settings → Booking defaults** (30 min, 12 hours, 60 days, 0 min break); an advisor field left empty follows the default, a filled one overrides it. Overlap checks use each booking's stored length, so changing the call length never double-books an existing call. Overlapping weekly ranges are merged on save.
- Administrator-only management: only users with `manage_options` can create, edit or delete advisor profiles; bookings cannot be added by hand. Only Subscriber accounts not linked to another advisor can be linked to a profile (filter `elab_advisor_account_roles`).
- The advisor screen shows whether the advisor is bookable and why not, and after saving lists every correction made (invalid or reversed ranges, merged overlaps, out-of-range values, invalid dates or email).
- Advisors list has a **Booking calendar** column (Bookable / Hidden with the reason). Each advisor screen previews the next 7 days of bookable times exactly as the public calendar computes them.
- **Booking requests** lists when (advisor and visitor local time), visitor, advisor, status and language; filters by Upcoming/Past/All, status and advisor; upcoming calls are sorted soonest first. Quick Edit is removed. The booking screen shows advisor, visitor and UTC times and asks for confirmation before a cancellation.
- **Start times every** (site default or per advisor): 0 offers one start per call length; e.g. 15 offers 9:00, 9:15, 9:30… for 30-minute calls without overlapping bookings.
- **Advisors → System status** checks HTTPS, the booking page, proxy/rate-limit IPs, WP-Cron, mail delivery (with a test email button), failed booking emails, Google credentials and redirect URI (with a live calendar test), each advisor's bookability, private storage, retention and the privacy policy page.
- **Privacy:** **Settings → Privacy and retention** deletes bookings and their documents a set number of months after the call (daily WP-Cron; 0 keeps them). The WordPress **Export/Erase Personal Data** tools include bookings; an upcoming call still in Google Calendar is kept until it is cancelled. Suggested privacy policy text is added to the WordPress policy guide.
- **Bot protection:** Cloudflare Turnstile (Settings → Security). With keys saved, every booking needs a passed check, verified server-side against this site's hostname; Cloudflare's test keys work for staging.
- **Real visitor IP:** choose Direct, Cloudflare (CF-Connecting-IP only from Cloudflare ranges) or your own proxy list (right-most untrusted X-Forwarded-For/X-Real-IP), so rate limits cannot be spoofed or shared.
- **Privacy consent** is required on the form and stored with the booking (time and policy URL).
- **Cache-safe:** the public calendar sends no REST nonce or cookies, and API responses are `no-store`, so LiteSpeed or any page cache can cache the page.
- **Visitor self-service:** each booking has a private link (emailed and shown on the confirmation) to review or cancel the call. Cancelling deletes the Google event and notifies the advisor. Private pages are never cached, indexed or sent as a referrer; attempts are rate-limited.
- **Visitor as guest:** a booking creates the event in the advisor's primary calendar with the visitor as the only attendee; Google emails the invitation, so the visitor sets up nothing. The title names the visitor and advisor, the description carries the length, language and private cancel link (never sector, message or files), and guests cannot invite others, see other guests or edit the event.
- **Google connection hardening:** PKCE and `login_hint` on sign-in; a revoked or expired grant (`invalid_grant`, e.g. External apps in Testing after 7 days) marks the advisor "reconnect required", hides them from the calendar and emails the advisor and admin; disconnecting revokes the token at Google. Define `ELAB_ENCRYPTION_KEY` (32+ characters) in `wp-config.php` so stored secrets survive a salt rotation.
- **Private link without secrets in URLs:** emailed links are exchanged server-side for an HttpOnly cookie and redirected to a token-free URL, so analytics (Clarity, Site Kit), logs and referrers never see the token.
- **Google Meet** links on confirmed calls (on by default, Settings → Google Calendar).
- **Attachment scanning:** define `ELAB_CLAMD_SOCKET` (Unix socket or host:port) to scan uploads with ClamAV, or hook `elab_scan_attachment`; a configured scanner that is unreachable rejects files.
- **Spanish (es_CO)** translation of everything visitors see and receive, loaded by TranslatePress on `/es/`.
- Meeting language chosen by the visitor (Spanish by default; filter `elab_meeting_languages`), stored on the booking and sent to the advisor.
- Visual booking calendar: a month view highlights days with open times; picking a day lists its times grouped by morning, afternoon and evening. Times from every bookable advisor are combined. When several advisors are free at the same time, the visitor can pick one or leave **First available**, which assigns the advisor with the fewest upcoming calls.
- Google free/busy responses are cached for two minutes and invalidated when the plugin creates or cancels an event; every booking re-checks Google live, inside a per-advisor lock, before it is saved.
- OAuth credentials saved under **Advisors → Settings → Google Calendar** (Settings is split into General, Google Calendar, Security and Privacy tabs), with a Google Calendar API setup guide. Both fields are masked; the Client Secret is encrypted before it is stored in WordPress options. Leaving either field blank when editing keeps its saved value.
- The advisor's verified Google account email must match **Advisor Google email**. The plugin checks access to the primary calendar before marking it connected. Existing connections from earlier versions must be authorized again so the account can be verified.
- Google OAuth requests `openid`, `email`, `calendar.events`, and `calendar.freebusy`. It does not request access to the advisor's Gmail mailbox. Advisor emails are sent by WordPress through `wp_mail()`.
- When Google is configured, each advisor authorizes their own account. The plugin checks `freeBusy`, creates an event in the advisor's calendar, and invites the visitor by email. The visitor can open it in Google Calendar or download an `.ics` file for Apple or Outlook Calendar.
- Weekly hours are availability rules, not Google Calendar events. After connection and whenever these rules or date exceptions change, the advisor receives an email summary. Each confirmed booking creates a calendar event and sends the advisor a separate email. Email delivery depends on the site's WordPress mail configuration; failures are shown in booking or advisor admin screens.
- When Google is not configured, the plugin retains the pending email request flow.
- PDF, Word, and Excel attachments, up to three files of 10 MB each. New files are stored outside the WordPress web root and can be downloaded only by administrators.
- Public booking requests have a hidden spam field and basic IP/email rate limits. Slot queries also have an IP rate limit.

Google credentials are not included in the code. A real connection requires the company's OAuth project and authorization from each advisor.

## Installation and use

1. Activate **El Altillo Book a Call** under Plugins.
2. Create and publish each advisor profile. Set **Advisor Google email** to the exact Google account the advisor will authorize, then set their time zone, meeting length, and weekly hours. Add unavailable dates where needed. To let an advisor connect their own Google Calendar, create a WordPress **Subscriber** account and assign it in **Advisor WordPress account**.
3. Set shared closures under **Advisors → Unavailable dates**. To let an advisor work on a shared closure, select **Work that day** in the advisor profile or in the advisor's **Users → Profile** screen.
4. Follow the Google Cloud instructions under **Advisors → Settings** and save the OAuth credentials there. The page displays the exact redirect URI to register.
5. Insert `[elaltillo_bookacall]` into the Appointment page.
6. Review bookings under **Advisors → Booking requests**. With Google connected, bookings are confirmed when the calendar event is created.

## First production Google trial

1. Deploy the plugin and activate it on the HTTPS production site. The local **Book a Call Test** page and its two demo advisors live in the local database; they are not part of the plugin package.
2. Confirm that the server can write to a private directory outside its document root. By default the plugin uses `dirname(ABSPATH) . '/elaltillo-bookacall-private'`. If that location is unavailable, define `ELAB_PRIVATE_UPLOAD_DIR` in `wp-config.php` with an absolute writable path outside the document root. Uploads fail closed when the directory is unavailable or public.
3. Configure a transactional mail service for WordPress and verify delivery to a real advisor and visitor address. `wp_mail()` returning success confirms submission to the mailer, not inbox delivery.
4. Create the company's Google Cloud project, enable the Calendar API, configure OAuth consent, and create a **Web application** client. Register the exact production redirect URI shown under **Advisors → Settings**. Save the Client ID and Client Secret there. In an External/Testing app, add each advisor as a test user.
5. Create real advisor profiles with the exact Google email, time zone, hours and a WordPress account. Each advisor connects their own account under **Users → Profile**. Only connected advisors are offered for online booking while Google is configured.
6. On a separate production test page, add `[elaltillo_bookacall]`. With two real advisors, check local time conversion, Google busy events, an event invitation, cancellation, and file upload/download. Confirm the invitation in the visitor's inbox and the event in the advisor's calendar. The automated smoke check uses simulated Google responses and does not replace this live check.
7. Replace the existing Appointment iframe with the shortcode only after the live trial succeeds. Set a privacy notice and retention process for booking records and attachments before inviting real visitors.

Google may reject a `.local` OAuth redirect. External apps in Testing can issue refresh tokens that expire after seven days; plan to move the OAuth app to production and complete any Google verification needed for sustained use.

### Visitor flow

1. Pick a highlighted day and a time in the visitor's time zone, optionally a preferred advisor, then enter name and email. Sector, message and documents are optional.
2. Before saving, the server checks weekly hours, unavailable dates, existing bookings, and Google Calendar busy periods again.
3. With Google configured, the event is created in the advisor's calendar and Google sends an invitation to the visitor. The confirmation screen offers Google Calendar and `.ics` options. The plugin creates a calendar event; it does not currently create a video meeting link.

### Advisor flow

1. Create a profile for each advisor, with no fixed limit. Set a photo, description, email, time zone, and weekly hours.
2. An administrator assigns a WordPress Subscriber account to the profile. The advisor connects the Google account matching their configured email and chooses any global unavailable dates on which they will work, without permission to edit other profiles. The plugin checks the verified account email and asks only for `calendar.app.created` and `calendar.settings.readonly`: it creates its own "El Altillo · Citas" calendar, reads the advisor's Calendar settings (their time zone, which then follows Google and is checked hourly; the WordPress field becomes read-only), and cannot read or change any other calendar, event, contact or busy time of the advisor. Reconnecting the same account through the same Google app reuses its calendar, also after Disconnect. Each live test asks Google (`tokeninfo`, token sent in the request body) which permissions the access really carries and flags anything broader; reconnecting a stale or over-broad connection revokes the old grant first. If the advisor deletes the site's calendar, it is created again (by the 15-minute sync, the next booking or **Test Google now**, at most once every 6 hours) and its upcoming confirmed calls are put back in the background, 25 at a time, nearest first, with their Meet links; past calls stay only in WordPress, and the advisor and administrator are emailed. Event and Meet titles are written in the meeting language. Every 15 minutes (or at once with **Test Google now**) the site brings Google's changes back: an event the advisor deletes cancels the call, frees the time and emails the visitor and the advisor; an event the advisor moves gives the call its new time and emails the visitor; an invitation the visitor declines cancels the call and tells the advisor; an accepted invitation is shown on the booking. Only advisors whose Google connection works appear in the booking calendar: when access is removed, expires or no longer matches, the advisor is hidden at once (calls already booked are kept) and emailed a personal reconnect link that needs no WordPress login. Administrators can email that link from each advisor screen (**Email a connection link**); it lasts 14 days and works once. WP-Cron runs on site visits; for a reliable 15-minute cadence, trigger `wp-cron.php` from a server cron. Refresh tokens are encrypted in the WordPress database; access tokens are cached temporarily.
3. Global closures hide dates except those that the advisor explicitly allows. Personal unavailable dates always take priority. Weekly hours and Google busy events still apply. The advisor receives email summaries when availability changes. New appointments become private booking records and events in the advisor's primary calendar, with a booking email to the advisor.

### Advisor portal

Advisors open `/asesores/` (or **View the booking** in each booking email) and sign in with Google, which asks only `openid email` through the same OAuth client and redirect URI. Only the verified Google email of a published advisor gets a session: a random token in an HttpOnly, Secure, SameSite cookie scoped to `admin-post.php`, stored hashed for 8 hours and ended by **Sign out**, an email change or unpublishing. Advisors see only their own calls, upcoming and the last 50 past, with the form answers, guests, Meet link and attachments in the safe viewer. Pages are not cached, indexed or framed and run no scripts. Sign-in never revokes the Google token: Google would withdraw the app's whole grant, including the calendar connection.

## Privacy and security

Public slot responses contain times only. The public form shows the advisor's title, photo, excerpt and time zone. Booking records, sector, message and attachments require administrator permissions in WordPress, or the advisor's own Google sign-in in the advisor portal (their own bookings only). Advisor emails contain the visitor's name, email and meeting time; the sector, message and files stay in the booking record, opened through the portal link. Google events are marked private and contain only a generic title, time and the visitor's attendee email. Google invitations inherently reveal the organizer's Google account to the attendee and the attendee's email to the organizer.

Visitors may attach PDF, Word (.docx), Excel (.xlsx) and PowerPoint (.pptx) files; Office files with macros, embedded programs, a missing main part or archive-bomb sizes are refused at upload. Attachments are never downloaded: administrators open them in a safe viewer. Office files are converted on the server into escaped HTML (text, tables, PNG/JPEG/GIF pictures; no links, macros or objects) on a page with no scripts, sandboxed by its Content-Security-Policy. PDFs are drawn onto canvases by the bundled PDF.js 6.4.299 (`assets/js/vendor/pdfjs`, Apache-2.0), which never runs the PDF's JavaScript; forms and links are not drawn, WebAssembly is off and the page may connect nowhere. New attachments are stored outside the WordPress web root. If an earlier installation already has files under `wp-content/uploads/elaltillo-bookacall-private`, move those files into the new private directory before upgrading and remove the old public directory. File type and size checks do not scan documents for malware.

Public endpoints: `GET /wp-json/elab/v1/availability?month=YYYY-MM&timezone=…` (open-time count per day), `GET /wp-json/elab/v1/slots?date=YYYY-MM-DD&timezone=…` (times with the advisors free at each) and `POST /wp-json/elab/v1/bookings` (`advisor` is optional). `advisor` can also narrow both GET endpoints to one advisor. Responses list advisor names and call lengths, never emails or calendar details.

The public API limits booking submissions to 12 per IP per 15 minutes and 5 per email per hour, and slot requests to 120 per IP per 10 minutes. A hidden field catches simple automated submissions. These limits are a basic abuse barrier, not a substitute for site-level bot protection.

## Validation

Run `php -l` on the PHP files and `pnpm build` before packaging. On a disposable local site with no Google credentials saved, run `wp eval-file tests/run.php` (and `pnpm test:js`). The smoke check creates temporary advisor, booking and user records; mocks Google and email; checks time zones, daylight saving, date closures, privacy permissions, event creation and cancellation; and cleans up its data. A separate HTTP multipart check is needed to validate uploaded files on the target web server.
