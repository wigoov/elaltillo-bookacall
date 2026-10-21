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
- Appointment times converted to the visitor's browser time zone.
- OAuth credentials saved under **Advisors → Settings**, with a Google Calendar API setup guide. Both fields are masked; the Client Secret is encrypted before it is stored in WordPress options. Leaving either field blank when editing keeps its saved value.
- The advisor's verified Google account email must match **Advisor Google email**. The plugin checks access to the primary calendar before marking it connected. Existing connections from earlier versions must be authorized again so the account can be verified.
- Google OAuth requests `openid`, `email`, `calendar.events`, and `calendar.freebusy`. It does not request access to the advisor's Gmail mailbox. Advisor emails are sent by WordPress through `wp_mail()`.
- When Google is configured, each advisor authorizes their own account. The plugin checks `freeBusy`, creates an event in the advisor's calendar, and invites the visitor by email. The visitor can open it in Google Calendar or download an `.ics` file for Apple or Outlook Calendar.
- Weekly hours are availability rules, not Google Calendar events. After connection and whenever these rules or date exceptions change, the advisor receives an email summary. Each confirmed booking creates a calendar event and sends the advisor a separate email. Email delivery depends on the site's WordPress mail configuration; failures are shown in booking or advisor admin screens.
- When Google is not configured, the plugin retains the pending email request flow.
- PDF, Word, and Excel attachments, up to three files of 10 MB each. Files are stored in `wp-content/uploads/elaltillo-bookacall-private` and can be downloaded by authorized admins.

Google credentials are not included in the code. A real connection requires the company's OAuth project and authorization from each advisor.

## Installation and use

1. Activate **El Altillo Book a Call** under Plugins.
2. Create and publish each advisor profile. Set **Advisor Google email** to the exact Google account the advisor will authorize, then set their time zone, meeting length, and weekly hours. Add unavailable dates where needed. To let an advisor connect their own Google Calendar, create a WordPress **Subscriber** account and assign it in **Advisor WordPress account**.
3. Set shared closures under **Advisors → Unavailable dates**. To let an advisor work on a shared closure, select **Work that day** in the advisor profile or in the advisor's **Users → Profile** screen.
4. Follow the Google Cloud instructions under **Advisors → Settings** and save the OAuth credentials there. The page displays the exact redirect URI to register.
5. Insert `[elaltillo_bookacall]` into the Appointment page.
6. Review bookings under **Advisors → Booking requests**. With Google connected, bookings are confirmed when the calendar event is created.

## In-progress and pending phases

1. **In progress — activate Google.** Create the company's Google Cloud project, enable the Calendar API, configure OAuth consent, and create a **Web application** client. Register the production redirect URI shown under **Advisors → Settings**. Save the Client ID and Client Secret there. Each advisor opens **Users → Profile**, selects **Connect Google Calendar**, and authorizes the Google account that matches their advisor profile. Google may reject the `.local` development domain; authorize on a supported HTTPS domain.
2. **Pending — real advisor data and validation.** Create the real advisor profiles and check at least two time zones, existing busy events, daylight saving changes, event creation and cancellation, invitations, and Google/Apple/Outlook calendar links. Complete Google app verification if it is required before making the OAuth app available to all advisors.
3. **Pending — enable the form on Appointment.** The local Appointment page still contains a Google Appointment Scheduling iframe. Replace it with `[elaltillo_bookacall]` once Google is connected and reviewed, so current bookings are not interrupted.
4. **Pending — production preparation.** Configure transactional email, request limits and spam protection, privacy and retention rules, and server-side denial of direct access to private attachments.

### Visitor flow

1. Choose an advisor, date, and time in the visitor's time zone. Sector is optional; documents can be attached.
2. Before saving, the server checks weekly hours, unavailable dates, existing bookings, and Google Calendar busy periods again.
3. With Google configured, the event is created in the advisor's calendar and Google sends an invitation to the visitor. The confirmation screen offers Google Calendar and `.ics` options.

### Advisor flow

1. Create a profile for each advisor, with no fixed limit. Set a photo, description, email, time zone, and weekly hours.
2. An administrator assigns a WordPress Subscriber account to the profile. The advisor connects the Google account matching their configured email and chooses any global unavailable dates on which they will work, without permission to edit other profiles. The plugin checks the verified account email and primary calendar. Refresh tokens are encrypted in the WordPress database; access tokens are cached temporarily.
3. Global closures hide dates except those that the advisor explicitly allows. Personal unavailable dates always take priority. Weekly hours and Google busy events still apply. The advisor receives email summaries when availability changes. New appointments become private booking records and events in the advisor's primary calendar, with a booking email to the advisor.

## Security and deployment

Files are stored outside the public media library. The plugin creates deny rules for Apache/IIS. On Nginx, add a server rule that denies `/wp-content/uploads/elaltillo-bookacall-private/`, because Nginx does not read `.htaccess`. Confirm this protection before accepting real documents. Extension, MIME, and size limits do not replace antivirus scanning.

The Google Calendar code is implemented but needs OAuth credentials and real authorization for each advisor. Complete the pending phases and verify private file access before production.
