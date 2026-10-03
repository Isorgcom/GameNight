# Help Page Screenshots

Drop annotated screenshots here, sized roughly 1200px wide. Pages render fine without them (broken-image icon only), so capture at your leisure.

## help-hosts.php expects:
- `leagues-create.png` — `/leagues.php` create-league form, filled in
- `contacts-add.png` — `/contacts.php` add-contact form (or CSV import dialog)
- `event-create.png` — `/calendar.php` event creation modal, with title + date filled
- `event-invite.png` — invite picker on a created event (showing typeahead with a couple of contacts)
- `event-settings.png` — Add/Edit Event dialog toolbar with Poker / Waitlist / Approval / Reminders toggles
- `event-rsvps.png` — event view showing the Invites list with yes/no/maybe responses
- `checkin-start.png` — `/checkin.php` check-in dashboard after a session is started (Timer / QR / Balance, prize pool)
- `blind-structure.png` — Timer "Blind Structure" editor with levels and a break
- `timer-running.png` — `/timer.php` running clock showing blinds and the countdown

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
- `rsvp-page.png` — `/rsvp.php?token=...` confirmation page as a guest sees it
- `walkin-qr.png` — `/walkin.php` registration form (after scanning QR), name/contact fields visible
- `register.png` — `/register.php` signup form

When recapturing later (UI changes), keep the same filenames so the pages don't need editing.
