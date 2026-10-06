# Help Page Screenshots

Drop annotated screenshots here, sized roughly 1200px wide. Pages render fine without them (broken-image icon only), so capture at your leisure.

## help-hosts.php expects:
- `leagues-create.png` — `/leagues.php` Create League form, filled in and not submitted
- `contacts-add.png` — `/contacts.php` with the Add a contact form filled in and a few contacts listed
- `event-create.png` — `/event_edit.php` (the Add Event page) with title, date and venue filled and nobody invited, so the toolbar shows the single *Add Event* button
- `event-invite.png` — the same page's guest list with two *+ Add Name* rows filled and *Save without sending* / *Save & Send Invites* showing
- `event-settings.png` — the toolbar with the *Guest options* menu open above the poker and reminders rows

Those three are retaken together by `~/qa-headless/help_hosts_event_shots.js`
(NewHostTest on dev, nothing saved); the May originals showed the calendar's
old modal.
- `event-rsvps.png` — the event page's Invites panel with a mix of answers, Resend on the unanswered, and a Declined section (event 237 on dev, RSVPs set to a mix and invitations marked sent first)
- `setup-editor.png` — Manage Game's Setup editor on the Game tab: header, the five tabs, the preset bar, the first fields

`leagues-create`, `contacts-add`, `event-rsvps` and `setup-editor` are taken
together by `~/qa-headless/help_hosts_roster_shots.js` (NewHostTest2,
NewHostTest and JamesTest on dev).
- `checkin-start.png` — `/checkin.php` check-in dashboard after a session is started (Setup, the List / Table / Log / Payouts / Chop strip, player rows, prize pool)
- `timer-choice.png` — the one-time "Try the Tournament Timer" prompt a host sees on first opening a tournament's check-in (first-time copy: taken on a game still on Classic)
- `blind-structure.png` — Setup → Blinds: the level grid with a break
- `timer-running.png` — the Tournament Timer's Default Layout on a live game, clock running, control tray showing

The four above are retaken together by `~/qa-headless/help_hosts_shots.js`
(JamesTest, events 237 and 247 on dev); the older pictures on this page are
hand-captured.

## help-timer.php expects:
All taken on dev by `~/qa-headless/help_timer_shots.js` (PCF loaded, JamesTest
signed in; the display shot needs a tournament session bound to PCF, the
script uses event 237). Re-run it after an editor change rather than
re-cropping by hand.
- `timer-pcf.jpg` / `timer-pcf-wide.jpg` — the PCF built-in at 16:9 and ultrawide
- `timer-bind.png` — the "Use this layout for …" bar above the editor on a game's Setup → Timer pane
- `timer-controls.jpg` — a live display with the host's control tray showing
- `timer-phone.png` — the Default Layout's Phone screen on an iPhone-sized viewport
- `timer-editor.jpg` — the whole editor, PCF loaded, clock cell selected
- `timer-toolbar.png` / `timer-statebar.png` — the toolbar strip; the preview-state chips + device switch
- `timer-padding.png` — the Blinds plate with the padding bands shown
- `timer-cellmenu.png` — the "Use a … instead" part of a cell's right-click menu
- `timer-video-preview.jpg` / `timer-video.png` — a YouTube feed in a cell; the video cell's inspector with the ducking panel
- `timer-ante-off.jpg` / `timer-ante-on.jpg` / `timer-elstyles.png` — the element-styles ante example
- `timer-expression.png` — a condition field with a parsed expression and the values list
- `timer-variants.png` — the PCF clock's Variants panel (Paused → red)
- `timer-screens.png` — the screen tabs, condition and rotation fields
- `timer-finaltable.jpg` — PCF's Final Table screen with the seat map
- `timer-styles.png` / `timer-customel.png` — the Shared styles and Custom elements panels
- `timer-triggers.png` — the Triggers pane with the level-change chime expanded

## help-guests.php expects:
- `rsvp-page.png` — `/rsvp.php?token=...&r=yes` as a guest sees it: the event card and the Confirm button (a GET only renders; nothing is written until the POST)
- `walkin-qr.png` — `/walkin.php?event_id=...&token=...` registration form after scanning the QR, name and contact fields visible
- `register.png` — `/register.php` sign-up form

Taken logged out by `~/qa-headless/help_guests_shots.js` (phone width for the
two link flows, desktop for sign-up). It needs an invitee's `rsvp_token` and
the event's `walkin_token` in the environment; on dev, event 237 carries a
walk-in token and its invitees have tokens. Give the event a future
`start_date` and the creator (JamesTest, normally UTC) the site timezone
before shooting, and put both back after, or the card reads oddly.

When recapturing later (UI changes), keep the same filenames so the pages don't need editing. The pages append each file's modification time to its URL (`?v=<mtime>`), so a retaken picture is fetched fresh even though `/img/help/` is cached as immutable for a year; without that stamp a browser that had seen the old picture kept it.
