# Changelog

All notable changes to GameNight are documented here.

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
