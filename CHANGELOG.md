# Changelog

All notable changes to GameNight are documented here.

---

## [v0.01800] — 2026-04-05

### Added
- **Per-event manager role.** Admins and event creators can grant invited users "Manager" access via a toggle in the invite pane. Managers can edit the event, manage invites, see contact details, and access the poker check-in page — without needing admin privileges.
- **Native time picker.** Replaced the 3-dropdown time selector (hour/minute/AM-PM) with a single `<input type="time">` on all devices. Triggers the native OS spinner on mobile and tablet.
- **Auto-fill current time.** New events default the time field to the current time instead of leaving it blank.

### Changed
- **iPad/tablet support.** All mobile touch optimizations now activate at 1024px (was 640px), covering iPads and tablets.
- **Touch-friendly calendar buttons.** Edit pencil and "+" add buttons are now always visible on touch devices (were hover-only and invisible on mobile).
- **Single-tap invite on mobile/tablet.** Invite and remove users with one tap (was double-click). Green "+" and red "x" indicators show on available and invited users.
- **Larger touch targets site-wide.** Buttons, inputs, selects, and checkboxes enlarged on poker check-in, admin settings, and my events pages. Input fonts bumped to 16px to prevent iOS auto-zoom.

---

## [v0.01700] — 2026-04-05

### Added
- **Mobile GUI overhaul.** Mobile devices now get an optimized experience with full-screen content, full-screen modal takeovers, and a collapsed nav bar by default.
- **Mobile detection in auth.php.** `$_is_mobile` flag is now available globally to all pages for conditional rendering.
- **Banner as nav collapse toggle.** The site logo (banner.png) replaces the ▲ arrow as the collapse/expand button in the nav bar. Header banner scales down to fit the collapsed bar.

### Changed
- **Full-screen modals on mobile.** All modals (calendar events, admin settings, posts, poker check-in) now take over the entire screen on mobile instead of floating as popout cards. Solid white background, no overlay bleed-through.
- **Edge-to-edge content on mobile.** Removed horizontal padding from all content wrappers (`.dash-wrap`, `.hero`, `.features`, `.page-layout`) at the 640px breakpoint so content fills the full screen width.
- **Edit event form mobile layout.** Header fields (color, title, date, time, duration) now wrap properly on small screens with larger touch targets (44px minimum). Invite panes stack vertically. Action buttons are full-width.
- **Nav bar positioning.** Collapse toggle moved to far left in both expanded and collapsed states. Nav bar padding reduced for tighter layout.

---

## [v0.01600] — 2026-04-05

### Added
- **Poker game check-in/management screen.** New full-screen dashboard (`/checkin.php`) for event creators and admins to manage poker game nights. Accessible via "Manage Game" button on poker events.
- **Tournament mode.** Track player check-ins, fixed buy-ins, rebuys, add-ons, table assignments, eliminations with finish positions, and percentage-based payout structure.
- **Cash game mode.** Flexible per-player buy-in amounts (add/subtract/edit directly), cash-out tracking, and automatic profit/loss calculation per player.
- **RSVP integration on check-in screen.** All event invitees are shown with their RSVP status. RSVP can be edited directly from the check-in page and syncs back to the event. RSVP=No rows are struck through with controls disabled.
- **Walk-in player support.** Add players not on the original invite list directly from the check-in screen.
- **Per-player notes.** Add notes to any player via a modal dialog.
- **Game lifecycle management.** Sessions progress through Setup → Active → Finished with status controls in the header.
- **Poker Game toggle on events.** Sliding yes/no toggle on event create/edit form (defaults to on). "Manage Game" button only appears on events marked as poker games.
- **Collapsible navigation bar.** Click the ▲ button to collapse the nav to just the hamburger menu, maximizing screen space. State persists across pages via localStorage.
- **RSVP Yes filter.** Filter button on check-in screen to show only players who RSVP'd yes.
- **Game settings panel.** Configure buy-in/rebuy/add-on amounts, rebuys allowed, max rebuys, add-ons allowed, starting chips, number of tables, and payout structure. Switch between tournament and cash game types.

### Fixed
- **Payout percentages can no longer exceed 100%.** Client-side and server-side validation blocks saving if payout structure totals over 100%.

### Changed
- **Sliding toggle switches replace checkboxes.** "Poker Game" and "Don't Notify" on the event form now use sliding yes/no toggles instead of plain checkboxes.

---

## [v0.01567] — 2026-04-04

### Fixed
- **Event creators could not open their own events.** `vDeleteId` was accessed without a null guard, crashing `viewEvent()` when the viewer was the event owner. Null guards added throughout.
- **RSVP owner dropdowns not showing on first open.** `renderInvitesPanel` was called before `window._calCanManage` was set, so static badges rendered instead of dropdowns on first open.
- **`ALLOW_MAYBE` undefined for non-owner users.** Moved to a global constant so `renderInvitesPanel` can use it for all users.
- **Color picker click listener crashed for non-creator users.** `eColorDotWrap` only exists in the edit modal; added null check to prevent TypeError on every click for users without event creation rights.

---

## [v0.01566] — 2026-04-04

### Added
- **Admins and event owners can edit invitee RSVP status.** The invite list in the event view modal now shows inline RSVP dropdowns (instead of static badges) for admins and the event creator. Changes save instantly. Regular invitees still see static badges.

### Changed
- **"Notify by email" checkbox inverted to "Don't Notify".** Notifications now send by default when creating or editing events. Check "Don't Notify" to suppress all emails. Editing an event now also notifies existing invitees by default (previously required opt-in).
- **Live RSVP refresh extended to all users.** The 4-second auto-refresh of the invite list in the event view modal previously only ran for admins; it now runs for all users including guests.

---

## [v0.01565] — 2026-04-04

### Changed
- **Revamped Add/Edit Event modal.** New layout with a header row (color circle with floating swatch picker, title, date defaulting to today, time dropdowns, duration dropdown), a dual-pane invite panel (All Users / Invited with double-click to add/remove), and a bottom row with description textarea on the left and Custom Invitee + Save/Cancel buttons on the right. Time entry replaced with Hour/Min/AM-PM dropdowns; duration replaced with presets (15 min – 8 hrs).

---

## [v0.01564] — 2026-04-04

### Fixed
- **Password reset links always appeared expired.** Token expiry was stored using PHP's local timezone (`date()`) but compared against SQLite's `datetime('now')` which is UTC, causing every reset link to fail immediately. Fixed by using `gmdate()` so the expiry is stored in UTC.

---

## [v0.01558] — 2026-04-04

### Added
- **Live RSVP updates for admins.** When an admin has an event modal open, the invite list now automatically refreshes every 4 seconds via a background poll (`event_invites_dl.php`). RSVP status changes made by any user are reflected in the admin's view without a page reload. Polling starts when the modal opens and stops when it closes.

---

## [v0.01557] — 2026-04-04

### Added
- **Event edit link in admin Events grid.** Each row in the Site Settings → Events spreadsheet now has a ▶ button that opens the event's calendar modal in a new tab, letting admins view and edit the full event details.

---

## [v0.01556] — 2026-04-03

### Removed
- **Recurring events.** Recurrence fields (`recurrence`, `recurrence_end`),
  the Recurrence dropdown in the create/edit modal, the per-occurrence invite
  scope toggle, the "Delete this date" occurrence button, and the "Recurring"
  badge on My Events have all been removed. All event queries now use a simple
  date-overlap filter. `build_event_by_date` simplified to single-pass;
  `load_exceptions` stubbed out. Admin Manage Events grid drops the Recurrence
  and Recur End columns.

---

## [v0.01555] — 2026-04-03

### Added
- **Manage Events tab in Site Settings.** Admins can now view and edit all
  events from a full-width spreadsheet-style grid under Site Settings → Events.
  Every cell is directly editable — title, dates, times, and recurrence — with
  changes saving automatically via AJAX and a "Saved" toast confirming each
  update. Recurrence is a dropdown select; all other fields are inline
  text/date/time inputs. The grid horizontally scrolls and breaks out of the
  960 px container so no columns are clipped. A "Manage Events" shortcut button
  was also added to the admin dashboard.

---

## [v0.01554] — 2026-04-03

### Added
- **Database Admin tool.** pla-ng (phpLiteAdmin fork, PHP 8 compatible) is now
  available at `/phpadmin/`. Access is gated behind the GameNight admin session —
  non-admins are redirected to login. The tool is downloaded at container startup
  by `docker-entrypoint.sh` and is not stored in the repo. A "Database Admin"
  button was added to the admin dashboard for quick access.

---

## [v0.01553] — 2026-04-03

### Fixed
- **Calendar 500 error — cannot redeclare `build_event_by_date`.** A prior commit
  moved `build_event_by_date` and `load_exceptions` into `db.php` but did not
  remove them from `calendar.php`. PHP fataled on the duplicate declaration for
  every calendar page request. Removed the duplicate definitions from `calendar.php`
  and `calendar_dl.php`; canonical home is now `db.php`.

---

## [v0.01552] — 2026-04-03

### Added
- **My Events page.** Logged-in users can view all events they are involved in
  (invited to or created) from a dedicated page, split into Upcoming and Past
  sections. Each card shows RSVP status, date/time, and a direct calendar link.
  "My Events" appears in both the desktop nav bar and the mobile hamburger menu.
- **Per-occurrence invites and RSVP for recurring events.** Each occurrence of a
  repeating event can now have its own invite list and RSVP statuses, independent
  of other occurrences in the series.
- **Maybe RSVP toggle.** Admins can enable or disable the "Maybe" response option
  site-wide from Site Settings → General. When disabled, Maybe is removed from RSVP
  buttons, invite emails, calendar dropdowns, and the one-click RSVP endpoint.
- **Failed login logging.** Failed login attempts are recorded in the activity log
  with `critical` severity and displayed in red in the admin Logs tab.
- **ROADMAP.md** added to the repository documenting planned milestones through v0.1.

### Fixed
- **Recurring event edit modal — save button clipped.** The edit modal uses
  `overflow:hidden` and `max-height:92vh` to stay within the viewport, but the
  `<form>` element was a plain block rather than a flex container. This meant
  `flex:1` on the body and `flex-shrink:0` on the footer had no effect — when a
  recurring event's extra scope UI added enough height, the footer with the Save
  button was pushed below the clipped edge and became unreachable. Fixed by making
  the form a flex column so the footer is always pinned at the bottom.
- **Upcoming events strip overflowing the page width.** The 7-column week grid used
  `grid-template-columns: repeat(7, 1fr)` but grid items default to
  `min-width: auto`, so the browser sized each column to the longest event title
  rather than the available 1fr share. Adding `min-width: 0` to `.wk-cell` lets
  the existing `text-overflow: ellipsis` take effect and keeps the strip within
  the page.
- Maybe RSVP option was missing from invite notification emails; now included when
  the Maybe toggle is enabled.

---

## [v0.015] — 2026-04-03

### Added
- **My Events page.** Logged-in users can now see all events they are involved in
  (invited to or created) from a single page. Events are split into Upcoming
  (chronological) and Past (reverse chronological) sections. Each card shows the
  RSVP status, Organizer/Recurring badges, date/time, and a direct link to the
  event on the calendar.
- "My Events" nav link added to both the desktop nav bar and the mobile hamburger
  dropdown for all logged-in users.

### Added (v0.0153)
- Fixed guests being unable to expand post comments — the `toggleComments()` JS
  function was inside a logged-in-only `<?php if ($user): ?>` block.

### Added (v0.0152)
- **Maybe RSVP toggle.** Admins can enable or disable the "Maybe" response option
  sitewide from Site Settings → General. When disabled, Maybe is removed from RSVP
  buttons, invite emails, calendar dropdowns, and the tokenized RSVP endpoint.

### Added (v0.0151)
- **Failed login logging.** Failed login attempts are now recorded in the activity
  log with severity `critical` and displayed in red in the admin Logs tab.
- `severity` column added to `activity_log` (defaults to `info`).
- Anonymous activity logging support (`db_log_anon_activity`) for events with no
  authenticated user.

---

## [v0.015] — 2026-04-03

### Added
- **Email verification for new signups.** New users must click a verification link
  sent to their email before they can log in. The verification token expires after
  24 hours and can be resent from the login page or the post-registration screen.
- Registration page now shows a "Check Your Email" confirmation screen instead of
  immediately logging in after signup.
- Unverified users who try to log in see a clear message and a one-click resend link.

### Changed
- `auth.php` fully promoted to the verification-aware implementation (`auth_dl.php`):
  all 24 pages now share the new login/register/notification logic automatically.
- Existing accounts (created before 2026-04-01) are auto-marked as verified — no
  action required from existing users or admins.
- Mobile nav bar (Home, Calendar, etc.) is now hidden on screens ≤ 768 px. All
  navigation links are accessible through the hamburger dropdown instead, keeping
  the header clean on phones and tablets.

### Fixed
- Hamburger menu was unresponsive on mobile due to `overflow: hidden` on the nav
  container clipping the absolutely-positioned dropdown, making it open but invisible.
- Touch event bubbling caused the dropdown to open and immediately close on a single
  tap. Fixed by stopping propagation in the toggle handler.
- Replaced unreliable `DOMContentLoaded` + external-JS approach with a direct inline
  `onclick` on the button, eliminating all script-load timing issues.

---

## [v0.014] — 2026-03

### Added
- **User-created events.** Admins can now grant regular users the ability to create
  and manage their own events via a toggle in Site Settings.
- Event owners can view and edit RSVP statuses for their own invitees directly from
  the edit modal — previously only admins could do this.
- Email field shown for non-admin custom invitee rows so manually-added guests
  receive notifications.

### Fixed
- JS permission checks for user-created events were incorrectly gated on `isAdmin`
  instead of the `canCreateEvents` flag, silently breaking the feature for non-admins.
- Long event titles on the calendar caused horizontal overflow on mobile.
- Email Event Details link no longer passes through the URL shortener, which was
  breaking session state and preventing users from opening the correct event.
- Event Details button in invite emails corrected to `inline-block` so it renders
  at the right width across email clients.
- Multiple mobile header banner height fixes: banner is now capped consistently
  across portrait and landscape orientations, preventing nav overflow.

---

## [v0.013] — 2026-02

### Added
- **One-click RSVP from email.** Invited users can accept or decline directly from
  the invite email without logging in first.
- New invitees added to an existing event are automatically notified by their
  preferred contact method (email, SMS, or WhatsApp).
- Login and Sign Up links added to the RSVP confirmation page for guests who want
  to create an account.

### Changed
- Host is no longer notified when an invitee's RSVP is unchanged (reduces noise for
  all notification channels).
- Event Details link in invite emails redesigned as a full-width blue button for
  better tap targets on mobile.

---

## [v0.012] — 2026-01

### Added
- **Multi-provider SMS system** with support for Twilio, Vonage, and Plivo. Providers
  are configurable from Site Settings without touching code.
- **Two-way SMS RSVP** — invitees can reply YES/NO to accept or decline events by text.
- **WhatsApp messaging** via Meta Cloud API (alpha). Invite notifications can be routed
  to WhatsApp in addition to email and SMS.
- **URL shortener** for outbound SMS links, using is.gd (free, no API key required).
- **Password show/hide toggle** on login and registration pages, with iOS Safari fix.
- **SMS log** — admin page showing all outbound messages, raw API responses, and a
  one-click copy button. Log can be cleared from the settings page.
- **Privacy Policy and Terms & Conditions** pages added, with links in the footer.
- Inbound SMS webhook URL shown in SMS settings for easy provider configuration.

### Changed
- Admin settings reorganized: Email, SMS, and WhatsApp grouped under a single
  Communication tab.
- SMS log moved to a dedicated full-width page rather than embedded in settings.

### Fixed
- SMS log Raw column copy button was being clipped by the table.
- Outbound delivery receipt webhooks from SMS providers are now ignored (were
  flooding the log with noise).

---

## [v0.011] — 2025-12

### Added
- **Login to join** prompt for unauthenticated users viewing an event — Sign In and
  Sign Up buttons shown inline in the event view modal.
- **Auto-open event modal** after login redirect — users land directly on the event
  they were trying to view, not the home page.
- Sign Up button added next to Login in event view for guests who don't have an account.
- `register.php` now accepts a `redirect` parameter so users return to the right place
  after creating an account.

### Changed
- RSVP section moved above the Invites list in the event view modal so users see
  their own status first.
- RSVP UX overhauled: status auto-saves on selection change, status badge shown per
  invitee row, cleaner layout.
- Invites list is now scrollable showing ~5 users at a time.
- App version shown in the site footer.
- Nav banner and header now appear on the login and registration pages.

### Fixed
- Login redirect URL now correctly preserves the event open/date query parameters
  so the right event auto-opens after authentication.

---

## [v0.010] — 2025-11

### Added
- **App versioning** — version number defined in `version.php` and displayed in the
  footer.
- **Header banner** — admins can upload a wide banner image that appears in the nav
  bar, with configurable height (up to 200 px).
- **Desktop edit event modal** redesigned as a two-column layout with a searchable,
  scrollable invite checklist and per-invitee notification toggle.
- SMTP diagnostics tool added to the admin Email settings tab.

### Fixed
- SMTP settings key mismatch that prevented email from being saved correctly.
- Forced password-change flow now triggers correctly on first admin login.
- First-login credentials updated in documentation (`admin@localhost` / `admin`).
