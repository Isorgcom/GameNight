# GameNight App — Product Roadmap

> **Current version:** v0.01567
> Last updated: April 2026

This roadmap outlines the planned development path for GameNight, a self-hosted PHP/SQLite web app for organizing game nights and group events. Milestones are organized by theme and priority, with near-term focus on unblocking SMS messaging and stabilizing WhatsApp, followed by wider public release readiness, and longer-term feature expansion.

---

## v0.018 — SMS Unblocked
*Theme: Get SMS working in production for real users*

The biggest blocker right now is 10DLC carrier registration. This milestone is entirely focused on getting SMS into a reliable, production-ready state.

**10DLC Guidance & Registration Support**
- Add an in-app 10DLC registration wizard with step-by-step instructions for each supported provider (Twilio, Vonage, Telnyx, Plivo)
- Surface clear status indicators showing whether the instance is in "pending approval," "approved," or "unregistered" state
- Document the full 10DLC process in the admin panel, including typical timelines (2–6 weeks), required business information, and what to expect from carriers
- Add a fallback warning banner when SMS is configured but not yet carrier-approved, so admins understand why messages may not be delivering

**SMS Reliability & Delivery Feedback**
- Implement delivery status webhooks for all four providers (delivered, failed, undelivered)
- Show delivery status per-message in the admin panel (sent, delivered, failed, carrier-filtered)
- Add retry logic for transient failures with configurable retry count and backoff
- Surface per-user SMS opt-out/opt-in status so admins can see who is reachable

**Error Handling & Diagnostics**
- Improve SMS error messages — distinguish between configuration errors, provider API errors, and carrier filtering
- Add an SMS test tool in the admin panel (send a test message to a specific number)
- Log SMS send attempts with timestamps, provider response codes, and failure reasons
- Alert admins when a provider API key is invalid or account has insufficient credits

---

## v0.020 — WhatsApp Stability & Two-Way Messaging
*Theme: Make WhatsApp a first-class, reliable messaging channel*

**WhatsApp Hardening**
- Move WhatsApp out of alpha — stabilize webhook handling and message queueing
- Handle Meta Cloud API rate limits gracefully with queuing and backoff
- Add delivery/read receipt tracking per message
- Support WhatsApp message templates for RSVP confirmations and reminders (required by Meta for business-initiated messages)
- Add a WhatsApp configuration health check in the admin panel (webhook verification, phone number status, template approval status)

**Two-Way WhatsApp RSVP**
- Extend two-way RSVP (YES/NO/MAYBE) to WhatsApp, matching the existing SMS behavior
- Handle freeform replies gracefully with a "Did you mean...?" prompt
- Support unsubscribe/stop requests via WhatsApp reply

**Messaging Channel Management**
- Allow users to set a preferred contact channel (SMS, WhatsApp, email) in their profile
- Admin view showing per-user channel preferences and reachability status
- Unified message log across SMS and WhatsApp in the admin panel

---

## v0.025 — Public Release Readiness
*Theme: Make GameNight safe and usable for strangers, not just one friend group*

**Multi-Instance & Isolation**
- Ensure all configuration is fully environment-variable driven (no hardcoded assumptions about a single deployment)
- Add instance naming/branding fields (app name, logo, color scheme) with easy setup during install
- Validate that Docker Compose setup works cleanly on a fresh VPS with no prior state

**Registration & Access Controls**
- Add configurable registration modes: open registration, invite-only, admin-approval required, closed
- Admin-controlled invitation links with optional expiry and use limits
- User self-service: password reset, email change, notification preferences
- Admin ability to deactivate users without deleting their history

**Onboarding Experience**
- First-run setup wizard: SMTP, optional SMS/WhatsApp, branding, admin account creation
- Post-install checklist in the admin panel (email verified, at least one test event created, etc.)
- Sample/demo event pre-populated on fresh installs (with option to delete)

**Security Hardening**
- Rate limiting on login, registration, and RSVP endpoints
- CSRF protection audit across all forms
- Review and document session management and cookie security settings
- Add optional two-factor authentication (TOTP) for admin accounts

---

## v0.030 — Documentation & Developer Experience
*Theme: Make it easy to self-host, contribute to, and trust*

**End-User Documentation**
- Public-facing docs site (or well-structured GitHub Wiki) covering installation, 10DLC walkthrough, WhatsApp setup, SMTP examples, and upgrade guides

**Admin Documentation**
- In-app contextual help tooltips on complex configuration fields
- Links to relevant docs from admin panel sections

**Developer Experience**
- Contributing guide (CONTRIBUTING.md) with local dev setup instructions
- Basic automated test suite for core flows (auth, RSVP, event creation)
- GitHub Actions CI for linting and basic smoke tests

**Upgrade & Migration Path**
- Database migration runner (simple version-tracked SQL migrations)
- Pre-upgrade backup reminder and export tool

---

## v0.040 — Feature Expansion: Notifications & Engagement
*Theme: Keep people engaged without requiring them to log in*

**Push Notifications (PWA)**
- Progressive Web App manifest and service worker for installability on mobile
- Web push notifications for event reminders, RSVP confirmations, and new posts
- Per-user opt-in for push notifications

**Smarter Reminders**
- Configurable reminder schedule per event (1 week, 1 day, 2 hours before)
- Reminder channels configurable per user (email, SMS, WhatsApp, push)

**Calendar Integrations**
- "Add to Google Calendar / Apple Calendar / Outlook" links on event pages and emails
- ICS/iCal feed per user
- Optional CalDAV export endpoint

---

## v0.1 — Wider Public Launch
*Theme: Stable, polished, and ready for a broader audience*

**Polish & UX**
- Accessibility audit (keyboard nav, ARIA labels, color contrast)
- Dark mode support
- Event sharing via public link (admin-controlled)
- RSVP guest count (+1, bring N guests)

**Performance & Reliability**
- Background job queue for sending messages
- Health check endpoint for uptime monitoring
- SQLite WAL mode by default; optional PostgreSQL for higher-traffic instances

**Ecosystem**
- Optional outbound webhooks (on new RSVP, new event, etc.)
- REST API (read-only to start)

---

## Backlog / Under Consideration

- Game library integration (BoardGameGeek)
- Polls & voting on date/time options
- Waitlist support for capacity-limited events
- Multi-language / i18n
- Native mobile apps (lower priority given PWA path)
- Multi-tenancy / SaaS mode
- Payment integration (Stripe) for events with a cover charge

---

## Version Summary

| Version | Theme | Status |
|---------|-------|--------|
| v0.015–v0.017x | Auth, calendar, RSVP, posts, SMS/WhatsApp alpha, UX improvements | ✅ In Progress |
| v0.018 | SMS unblocked (10DLC guidance, delivery tracking) | 🔜 Next |
| v0.020 | WhatsApp stability & two-way messaging | 🔜 Planned |
| v0.025 | Public release readiness | 🔜 Planned |
| v0.030 | Documentation & developer experience | 🔜 Planned |
| v0.040 | Push notifications, reminders, calendar integrations | 🔜 Planned |
| v0.1 | Public launch polish, performance, API | 🔜 Future |

---

*This roadmap is aspirational and subject to change based on user feedback, provider policy changes (especially around 10DLC and Meta's WhatsApp API), and available development time.*
