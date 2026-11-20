# Game Night Documentation

A complete guide to setting up, configuring, and using Game Night — your self-hosted platform for organizing game nights, poker tournaments, and group events.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Deployment](#deployment)
- [First-Time Setup](#first-time-setup)
- [Admin Guide](#admin-guide)
  - [Dashboard](#dashboard)
  - [General Settings](#general-settings)
  - [Appearance](#appearance)
  - [Users](#users)
  - [Events](#events)
  - [Email Configuration](#email-configuration)
  - [SMS Configuration](#sms-configuration)
  - [WhatsApp Configuration](#whatsapp-configuration)
  - [Activity Logs](#activity-logs)
  - [Connected Apps](#connected-apps)
- [Calendar & Events](#calendar--events)
  - [Creating Events](#creating-events)
  - [Recurring Events](#recurring-events)
  - [Inviting People](#inviting-people)
  - [Event Managers](#event-managers)
  - [RSVP System](#rsvp-system)
- [Poker Game Management](#poker-game-management)
  - [Setting Up a Game](#setting-up-a-poker-game)
  - [Tournament Mode](#tournament-mode)
  - [Cash Game Mode](#cash-game-mode)
  - [Managing Players](#managing-players)
  - [Online Tables (FinalTable)](#online-tables-finaltable)
- [Posts & Announcements](#posts--announcements)
- [Comments](#comments)
- [User Guide](#user-guide)
  - [Registration & Login](#registration--login)
  - [Profile Settings](#profile-settings)
  - [My Events](#my-events)
  - [RSVP from Email/SMS](#rsvp-from-emailsms)
- [Notifications](#notifications)
  - [How Notifications Work](#how-notifications-work)
  - [Reminder Schedule](#reminder-schedule)
  - [SMS RSVP Replies](#sms-rsvp-replies)
- [Cron Setup](#cron-setup)
- [API for Sister Sites](#api-for-sister-sites)
  - [Scopes](#scopes)
  - [Issuing a Key](#issuing-a-key)
  - [Authentication](#api-authentication)
  - [Endpoints](#api-endpoints)
  - [Response Shape](#api-response-shape)
  - [Errors](#api-errors)
  - [Caching](#api-caching)
  - [Examples](#api-examples)
  - [Revoking a Key](#api-revoking-a-key)
  - [Connected Apps: Sign-In Bridge](#connected-apps-sign-in-bridge)
- [Connecting Claude (MCP)](#connecting-claude-mcp)
- [Personal API (v2)](#personal-api-v2)
  - [Tokens](#tokens)
  - [Calling it](#calling-it)
  - [Conventions](#conventions)
  - [Who can see what](#who-can-see-what)
  - [Endpoints](#endpoints)
  - [Reference: reading](#reference-reading)
  - [Reference: contacts](#reference-contacts)
  - [Reference: changing](#reference-changing)
  - [Reference: running a game](#reference-running-a-game)
  - [A worked example](#a-worked-example)
  - [Errors](#errors)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

```bash
# Clone and start
git clone https://github.com/Isorgcom/GameNight.git
cd GameNight
cp config/config.example.php config/config.php
docker compose up -d --build
```

Open your browser to the server's address. Log in with:
- **Username:** `admin@localhost`
- **Password:** `admin`

You'll be prompted to change your password immediately.

---

## Deployment

### Requirements

- Docker and Docker Compose
- A server with ports 80/443 available (or a reverse proxy like Nginx Proxy Manager)

### Docker Compose

```bash
# Start the application
docker compose up -d --build

# Stop
docker compose down

# Update after pulling new code
docker compose down && docker compose up -d --build
```

#### The `.env` file

Secrets the containers need sit in a `.env` file beside `docker-compose.yml`, which git ignores. Since v1.39.0 `docker compose` refuses to start without the last two:

```bash
WAHA_WEBHOOK_TOKEN=<long random string>       # lets /wa_webhook.php trust messages from the WhatsApp gateway
WAHA_API_KEY=<long random string>             # the gateway's API key; both containers read it
WAHA_DASHBOARD_PASSWORD=<long random string>  # the gateway's own dashboard (user admin)
```

Make each with `openssl rand -hex 24`. After changing one, recreate the containers with `docker compose up -d` (no rebuild needed). The WhatsApp link to your phone is kept.

### Important Directories

| Path | Purpose |
|------|---------|
| `www/` | Web root (served by Apache) |
| `config/config.php` | Database path configuration (gitignored) |
| `db/` | SQLite database storage |
| `www/uploads/` | Uploaded images and banners |

### File Permissions

The `db/` and `www/uploads/` directories must be writable by the web server (`www-data`). If you get HTTP 500 errors on a fresh deploy, check ownership:

```bash
chown -R www-data:www-data db/ www/uploads/
```

### Reverse Proxy

The container connects to the `npm_default` Docker network for use with Nginx Proxy Manager. Configure your proxy host to point to the container.

**Banning scanners at the proxy.** The `fail2ban/` directory holds a filter and a jail for Nginx Proxy Manager's access logs: an address that draws 20 answers of 403 or 404 inside two minutes (a script guessing paths) is banned for a day in Docker's `DOCKER-USER` chain, which is where a ban has to go for traffic to a published Docker port. `server-prep.sh` installs it on a fresh host; `fail2ban/README.md` has the steps for an existing one, including the ignore list that keeps your own address safe.

---

## First-Time Setup

After your first login as `admin`:

1. **Change your password** — You'll be forced to do this on first login.
2. **Set your site name** — Go to Admin > General and give your site a name.
3. **Set the timezone** — Important for event times to display correctly.
4. **Upload a banner** — Go to Admin > Appearance to add your logo and header image.
5. **Enable notifications** — Admin > General > Notifications > "Enable Notifications" toggle. Off by default.
6. **Configure email** — Admin > Email tab. Set up SMTP so invite emails and reminders work.
7. **Invite your friends** — Have them register, or create accounts in Admin > Users.
8. **Create your first event** — Open the Calendar, click "+", and set up a game night.
9. **Delete the welcome post** — The default post on the home page can be removed or unpinned once you're ready.

---

## Admin Guide

Access admin features at **Admin** in the navigation bar (admin users only).

### Dashboard

Overview of your site: total users, total events, total posts, and recent activity.

### Changing another user's account

An admin setting someone's password, changing their username, email, phone, role or verified flag, resetting their two-factor, or deleting accounts is asked for their **own** password first, then not again for 10 minutes. On the Users list, a **Confirm it's you** button opens that window; an inline edit asks when it needs to.

### General Settings

General is in six sections, switched with the same sliding control as Account Settings: **Site**, **Features**, **Notifications**, **Security**, **Public pages** and **Timer**. Each section has its own Save, which saves only that section, and the page comes back on the section you saved. A section can be linked to: `/admin_settings.php?tab=general#notifications`.

| Section | Setting | Default | Description |
|---------|---------|---------|-------------|
| Site | Site Name | Game Night | Displayed in the nav bar and emails |
| Site | Site URL | (blank) | Used in notification links. Set this to your public URL |
| Site | Timezone | UTC | All event times are displayed in this timezone |
| Features | Allow Registration | On | When off, the signup page is disabled |
| Features | Allow User Events | Off | When on, non-admin users can create and manage their own events |
| Features | Enable Calendar | On | Show/hide the calendar page and nav link |
| Features | Allow Maybe RSVP | On | When off, only Yes/No RSVP options are available |
| Notifications | Enable Notifications | Off | Master switch for all email/SMS/WhatsApp notifications |
| Notifications | Site default reminders | 2 days and 12 hours before | The reminders every new event starts with; the host changes them on the event |
| Notifications | Messages per event per day | 10 | Host messages and polls one event may send its guests in 24 hours, from the site, a connected app or a text command. 0 = no limit |
| Notifications | Messages per host per day | 25 | The same count for one person across all their events. 0 = no limit |
| Notifications | Limit guests reached per host per day | On, 1,000 | Counts people rather than sends: a message to 80 guests uses 80. Its own switch and number |
| Notifications | Extra browser-push services | (blank) | Push service hosts beyond Google, Mozilla, Microsoft and Apple |
| Security | Security alerts | On | Tell the admins when the sign-up honeypot is hit, a sign-up is posted by a client that never ran the page, the sign-up or resend-verification limit refuses someone, verification mail or codes are withheld by the per-account cap, an opted-out address is used, sign-in is locked after failed attempts, or sign-up posts in one hour reach the burst alarm. One message per kind per hour; every hit is in the Logs tab regardless |
| Security | Text security alerts to | (blank) | A mobile number that receives the alerts as a text, through the SMS provider, even while Enable Notifications is off. Blank: each admin is notified the way their Preferred Contact says. Admins see every alert in their in-app inbox either way |
| Security | Sign-up burst alarm | 10 | Sign-up posts in one hour, counted across every address and including refused ones, at which the admins get a security alert saying how many posts came from how many addresses. Catches a bot that paces each address under the per-address limit. 0 turns it off |
| Public pages | Show Upcoming Events | On | Show/hide the upcoming events section on the landing page |
| Public pages | Show Landing Page | Off | Logged-out visitors see the landing page instead of the posts feed |
| Public pages | Donation URL and message | (blank) | A donation banner on the home page and a footer link |
| Timer | Allowed video stream hosts | (blank) | Extra hosts the timer's stream panel may embed |
| Timer | Google Cast app ID | (blank) | Turns on the timer's Cast button |

#### Search & Sharing

The landing page (shown to logged-out visitors when **Show Landing Page** is on) is the one page search engines are meant to find, and these settings control what they show for it. All of them live in Admin → Settings → General → Public pages.

| Setting | Default | Description |
|---------|---------|-------------|
| Search title | Free Poker Tournament Timer & League Manager \| *Site Name* | The landing page `<title>` and the headline in search results (about 60 characters show in full) |
| Search description | (a fixed sentence about the timer, RSVPs and leagues) | The snippet under the headline and the text on shared links, up to 160 characters |
| Google Search Console verification | (blank) | Paste the token or the whole `<meta>` tag from Search Console's HTML-tag method; emitted on the landing page only |
| Bing Webmaster Tools verification | (blank) | The `msvalidate.01` token; or import the site into Bing from Search Console and leave this blank |
| Share Image | (blank, falls back to the header banner) | The 1200×630 picture shown when a link to the site is shared; uploads are checked for the card shape |

What the site does on its own: every public page carries a description, canonical URL and Open Graph tags; the landing page carries JSON-LD (`Organization`, `WebSite`, `SoftwareApplication`); `/sitemap.xml` lists the landing page, the three guides, the league directory, sign-up, terms and privacy with last-modified dates; `robots.txt` keeps the logged-in app out of crawlers; the login page is `noindex`.

**League pages and search engines.** A league's public page (`/league/<slug>`) is reachable by link the moment the owner turns it on, but it is *not* listed by search engines until the owner also ticks **Let search engines list this page** in the league's settings. Until then the page carries `noindex,follow` and stays out of the sitemap. Once listed it gains structured data for the league and its upcoming events. The directory at `/league` (names and counts only) is always indexable.

To get into Google after a deploy: Search Console → Add property (URL prefix `https://your-site/`) → HTML tag → paste the token above → Verify; then Sitemaps → submit `https://your-site/sitemap.xml`. Bing Webmaster Tools can import the property from Search Console in one step.

### Appearance

- **Header Banner** — Upload a wide image displayed across the top of every page. Adjust the display height with the slider.
- **Page Banner (Logo)** — Upload a smaller logo shown in the navigation bar.

Supported formats: JPEG, PNG. Max size: 4 MB.

### Users

- **View all users** in an inline-editable grid. Change username, email, phone, role, preferred contact, and notes directly in the table.
- **Create users** manually with a username, email, and password.
- **Import users** from a CSV file. Imported users get a temporary password and must change it on first login.
- **Export users** to CSV.
- **Bulk actions** — Select multiple users to change their role or delete them.
- **Edit a user** — Click the edit icon to open a full edit page with all fields.
- The last admin account cannot be demoted or deleted (safety guard).

### Events

View and manage all events in the system. Inline-edit titles, dates, and times. Delete events you no longer need.

### Email Configuration

Configure SMTP to enable email notifications:

| Field | Example |
|-------|---------|
| SMTP Host | smtp.gmail.com |
| SMTP Port | 587 |
| SMTP User | you@gmail.com |
| SMTP Password | your-app-password |
| From Address | noreply@yourdomain.com |
| From Name | Game Night |
| Encryption | TLS |

Use the **Send Test Email** button to verify your configuration. Test emails bypass the global notification toggle so you can test even when notifications are off.

### SMS Configuration

Choose from four SMS providers:

- **Twilio** — Account SID, Auth Token, From Number
- **Plivo** — Auth ID, Auth Token, From Number
- **Telnyx** — API Key, From Number
- **Vonage (Nexmo)** — API Key, API Secret, From Number

Configure your provider credentials and use **Send Test SMS** to verify.

**URL Shortener:** Enable is.gd link shortening to keep SMS messages concise.

### WhatsApp Configuration

Set up the Meta Business API for WhatsApp notifications:

- Phone Number ID
- Access Token
- Verify Token (for webhook verification)

Configure the webhook URL in your Meta Business dashboard to point to `https://yoursite.com/wa_webhook.php`.

### Activity Logs

View a chronological log of all actions: logins, event changes, RSVP updates, admin actions, and more. Use **Clear Logs** to wipe the history.

### Connected Apps

A connected app is a separate site (FinalTable, the online tournament table, is the first) that lets people sign in with their Game Night account instead of creating another one. The app sends the browser to `/connect.php`, the normal Game Night login runs (verification gate, two-factor, lockouts, all unchanged), the member confirms once, and the browser returns to the app with a short-lived signed token carrying the username and, when they have one, the path to their profile photo. Passwords, emails and phone numbers never leave Game Night.

**Site Settings → Connected Apps** is where an admin:

- Registers an app: a **slug** (2-32 lowercase letters, digits, hyphens; it becomes the token audience), a **name** shown on the confirm card, and the **base URL** players use to reach the app. The base URL is the only place a token will ever be sent: scheme, host and port must match exactly, so `http://` and `https://` are different apps and so are two ports.
- Enables, disables or removes an app. A disabled or removed app's sign-in links get a 404 page on Game Night, and nothing is shared.
- Sees the **public signing key** and its id. FinalTable fetches the key itself when its operator enters this site's address on its Operator page; the `.env` snippet here is for a headless setup. The private half never leaves the server; it is generated on first visit and stored encrypted.
- **Regenerates** the keypair. Every connected app must then be given the new public key before its sign-in works again, so this is a deliberate act behind a confirmation.
- Pastes the app's **game key**, for games played there. FinalTable's administrator makes it on FinalTable's Admin page under *GameNight* (**Make a key**, shown once); it goes in the app's *Game key* field here and is kept encrypted, shown afterwards only as *Set* or *Not set*. **Test** proves the address and the key with two requests that make nothing: a wrong key comes back with FinalTable's own sentence. Once an app has a key, a poker tournament can be marked *Online at* it (see [Online Tables](#online-tables-finaltable)). Clearing the key stops new tables being set up there; games already running are unaffected.

Each sign-in is written to the activity log as `sso_login app=<slug>`, and the app's row shows its last sign-in. See [Connected Apps: Sign-In Bridge](#connected-apps-sign-in-bridge) under the API section for the wire contract.

---

## Calendar & Events

The calendar is the heart of Game Night. It defaults to **Week view** with a toggle to switch to Month view. On a phone the week fits the screen and scrolls with the page, with the day header staying put under the nav.

### Creating Events

1. Click the **+** button on any calendar date, or the **+ Add Event** button.
2. Fill in the details:
   - **Title** (required)
   - **Date** (required)
   - **Start Time** — Defaults to the current time. Uses native time picker on all devices.
   - **Duration** — Auto-calculates end time.
   - **Color** — Pick from 7 colors to categorize events.
   - **Description** (optional)
   - **Poker Game** toggle — Enables the poker check-in dashboard for this event.
   - **Don't Notify** toggle — Suppress invite notifications for this save.
3. Add invitees (see below).
4. Click **Add Event**.

### Recurring Events

When creating or editing an event, set a recurrence pattern:

- **Daily** — Every day
- **Weekly** — Same day each week
- **Monthly** — Same date each month
- **Yearly** — Same date each year

Set an optional end date for the recurrence. Individual occurrences can be deleted without affecting the series.

### Inviting People

The edit modal has a dual-pane invite system:

- **Left pane:** All registered users. Search by name, email, or phone.
- **Right pane:** Invited users for this event.

On desktop, double-click to move users between panes. On mobile/tablet, single-tap works.

You can also add **custom invitees** (people without accounts) by clicking "+ Custom Invitee" and entering a username and email.

### Event Managers

Admins and event creators can grant **Manager** access to invited users:

- Toggle the purple **Mgr** switch next to any invitee's name.
- Managers can edit the event, manage invites, see contact details, and access the poker check-in page.
- Managers cannot create new events or grant manager access to others.

### RSVP System

Invitees can respond to events in several ways:

- **From the calendar** — Click an event and use the RSVP dropdown.
- **From email/SMS** — Click the one-click RSVP link (no login required).
- **From the event view** — Use the RSVP panel.
- **Self-signup** — Logged-in users can join events they weren't originally invited to.

RSVP options: **Yes**, **No**, and optionally **Maybe** (configurable in admin settings).

Event creators are notified when someone changes their RSVP.

### Share an invite link

For people the host has no contact details for, the event page's **Share invite** button (upcoming events, hosts only) opens a panel with a link to `/invite.php?e=<id>&t=<token>`:

- **Share…** opens the phone's share sheet (AirDrop, Messages, WhatsApp and the rest). Where there is no share sheet, the button copies the link.
- **Show QR** fills the screen with a QR code for the link and keeps the screen awake while it is up. The code is drawn in the site's style, rounded modules in navy with blue finder corners, like every QR code the site draws; the site icon (Admin → Settings → Site Icon / Logo) sits in the middle, and the code is made with extra error correction so it still scans.
- **People who join need my OK** (on by default): people who join through the link wait in Pending Approval. With it off they go straight on the list, up to the event's capacity, then onto the waiting list. The event's own *requires approval* setting still applies.
- **Seats left** on the panel, the QR card and the invite page count a seat as taken only by a guest who has answered Yes. An invited guest who has not answered, or said Maybe, does not hold one, so inviting more people than seats does not make the link read as full. The host's own waiting list in the editor keeps its rule: a guest placed beyond capacity there moves up when an invited guest says No.
- **Make a new link** / **Turn link off**: the old link then says it is no longer active. People who already joined stay on the list. The walk-up QR uses its own token and is not affected.

The invite page shows the title, date and time, the venue's name, the host and how many seats are left, but **not the street address**, since a link can be forwarded to anyone. The address reaches a guest in their confirmation (or approval notice) through their own event link. A signed-out visitor gives a name and an email or phone. An account found by that address is always Pending, so nobody can confirm a spot for someone else by typing their email. A signed-in visitor joins with one tap. Ten sign-ups per address per hour, and 100 per event per day. Guests who joined this way are tagged *via link* in the host's Invites panel.

---

## Poker Game Management

Toggle **Poker Game** on any event to unlock the check-in dashboard at `/checkin.php`.

### Setting Up a Poker Game

1. Create an event with the **Poker Game** toggle on.
2. Click **Manage Game** from the event view.
3. Choose your game type: **Tournament** or **Cash Game**.
4. Configure the session:
   - Buy-in amount
   - Rebuy amount and whether rebuys are allowed
   - Add-on amount and whether add-ons are allowed
   - Starting chips (tournament)
   - Number of tables
5. Click **Create Session**.

### Tournament Mode

- **Check-in** players as they arrive.
- **Buy-in** tracks who has paid.
- **Rebuys and Add-ons** — Increment/decrement counters per player.
- **Table Assignment** — Assign players to numbered tables.
- **Elimination** — Mark players as eliminated (records finish position).
- **Payouts** — Configure percentage-based payout structure (e.g., 1st: 50%, 2nd: 30%, 3rd: 20%). Payout amounts auto-calculate from the total pool.

### Cash Game Mode

- **Cash In** — Track how much each player brings to the table. Add or subtract amounts.
- **Cash Out** — Record what each player leaves with.
- **Profit/Loss** — Auto-calculated per player (cash out minus cash in).
- **On a phone** — The Table Manager (the 📱 button in the Manage Game header, or `table_manager.php?event_id=…`) enters every amount on a keypad: tap the bills as they are handed over (+$5 to +$100 add up), tap a multiple of the buy-in (1× to 4×), or type it. The button names the amount before you confirm ("Add $40"); Cash out opens empty and takes $0 for a bust.

### Managing Players

- All event invitees auto-appear in the player list with their RSVP status.
- **Walk-ins** — Add players who weren't on the invite list.
- **Notes** — Add per-player notes (e.g., "owes $20 from last time").
- **Filters** — Filter the list by status: All, RSVP Yes, Checked In, Playing, Eliminated.
- **RSVP sync** — Changing RSVP on the check-in page syncs back to the event.

### Game Settings

Click **Settings** during an active game to adjust:
- Game type, buy-in/rebuy/addon amounts
- Rebuy and add-on toggles
- Max rebuys
- Starting chips
- Number of tables
- Payout structure (add/remove places, adjust percentages)

### Online Tables (FinalTable)

A poker **tournament** can be played on FinalTable, the online table, instead of in a room. It needs a connected app with a game key (see [Connected Apps](#connected-apps)); the event editor's poker bar then offers **Played: In person / Online at FinalTable**, and once Online is chosen, beside it, **Game** and **Betting**. The games are FinalTable's: Hold'em, Omaha, Omaha Hi-Lo, Crazy Pineapple, Seven-Card Stud, Stud Hi-Lo, Razz, Five-Card Draw, and HORSE, which plays five of them in turn, one a level. Each comes up at the betting it is usually played at - Hold'em no-limit, Omaha pot-limit, the stud games and HORSE fixed-limit - and you can pick another. An event that never chose plays Hold'em no-limit, as every table did before there was a choice. The pick is named under *Played online at FinalTable* on the event page and in the seat invitation; once the table exists, the page says what FinalTable actually made. Cash games stay in person.

**Setting the table up.** The event page of an online event carries a *Played online at FinalTable* section. Before the table exists, the organiser (and any event manager) sees what will be sent and a **Set up the table on FinalTable** button:

- **Who goes.** The organiser hosts the table, whether or not they are on their own guest list, and every *approved* invitee who has a Game Night account goes with them. FinalTable seats people by account, so an invitee without one is listed as *not sent*; give them an account and re-invite them. The guest list is the door: a listed member who signs in at FinalTable with their Game Night account walks straight in, and nobody else can.
- **What goes.** The event's title and start time, the game and the betting, the session's blind schedule (from *Blinds & timer display*; the site's default preset when the game has none; FinalTable's own Standard structure when there is neither, or when the blinds are in cents, since FinalTable plays whole chips), starting chips (1000, 2000, 5000 or 10000 there), seats per table (eight at most; seven at a stud table or HORSE, whose rounds include stud), the buy-in, add-ons, and re-entry for the first three levels when rebuys are allowed. Where FinalTable makes something other than what was sent - the stack size, the seats, the game - the organiser is told when the button is pressed, and the note is kept in the game log.
- **When.** FinalTable takes a game up to seven days ahead; the page says when to come back for one further out. A start in the past, or an all-day event, means the host starts the clock by hand.

Pressing the button makes the game, tells everyone on the guest list where to play (a notification with the join link, through their usual channels, under the *changes* category) and turns the section into the table's status: registering with its start, then the level and what it posts the way the game does - the blinds and ante, or a stud game's ante, bring-in and bets, and in HORSE which game the level is playing - the time to the next level, players left, and whether the host has paused. **Play at FinalTable** is the join link for people with a seat, **Watch** the rail link for anyone who can see the event; both have a copy button.

**Watching is public, on purpose.** The rail link seats nobody and anyone who has it may pass it on — the invitation email says so, and everyone on the guest list gets it there. Somebody watching sees the field by name, the chip counts and the table, the same as a player who has busted; they never see anyone's cards. *Hide guest list* covers the guest list on the event page, not the rail: if you do not want a night watched, do not hand the link out.

**Driving the table.** Managers get **Start now** (before the scheduled time; two people must be seated), **Pause** and **Resume**, **Take out of play** beside any seated player (their seat goes, they are told; during a hand it waits for the hand to end), and **Cancel the game**, which sends everyone back to the lobby and keeps the places so far. FinalTable refuses what its own host could not do, in its own words, and the page shows the sentence.

**The record of the night.** FinalTable reports back as the game runs, and this side writes what an in-person game would have written by hand: every bust-out becomes a finish position (provisional until late registration and re-entry close; the final standings are the last word), a re-entry clears it and counts as a rebuy, and the ending records every place, marks who actually played as bought in, sets the session to *finished* and computes payouts, points, bounties and entry tickets from **this event's payout structure**, exactly as Manage Game's *Finish* does. FinalTable's own prize figures are kept for the status line only. The league table picks the event up like any other. A cancelled game leaves the session as it stood. For an online game, let FinalTable keep the book: hand edits in Manage Game are overwritten by the standings when the game ends.

**Afterwards.** Once a game has finished or been called off, the organiser can set the table up again; the old game's stragglers are ignored. Deleting an event with a live table calls the game off first. Removing a member from a league, or deleting their account, signs them out of every FinalTable that has a key.

---

## Posts & Announcements

Posts appear on the landing page as a news feed / bulletin board.

### Creating Posts (Admin)

1. Go to **Admin > Posts** or click **New Post**.
2. Enter a title and use the rich text editor for content.
3. Upload images directly in the editor (drag & drop or toolbar button).
4. Optionally set a custom date/time.
5. Publish the post.

### Post Features

- **Pin** — Pinned posts stay at the top of the feed.
- **Hide** — Hidden posts are invisible to users but not deleted.
- **Edit** — Change title, content, date, or pin status.
- **Delete** — Permanently removes the post and its uploaded images.
- **Bulk Delete** — Select multiple posts and delete them at once.

---

## Comments

Both posts and events support comments.

- Any logged-in user can leave a comment.
- Users can edit or delete their own comments.
- Admins can edit or delete any comment and use bulk delete.
- Comments are plain text (max 2,000 characters).

---

## User Guide

### Registration & Login

1. Go to the site and click **Sign Up**.
2. Choose a username (3-30 characters, letters/numbers/underscores).
3. Enter your email, optional phone number, and a password (8+ characters).
4. Check your email for a verification link. Click it to activate your account.
   The email also carries a stop link for anyone who receives it without having signed up: it blocks further sign-up mail to that address (and removes the unverified account), and the same page can allow the address again later. A sign-up that is never verified is removed on its own after 7 days.
5. Log in with your email and password.

### Profile Settings

Go to **Settings** (gear icon or nav menu) to:

- Update your username, email, or phone number. Changing any of these asks for your current password. A new email takes effect only after you use the confirmation link sent to it (the old address is told a change was requested), and a changed phone number must be verified again before SMS two-factor will text it.
- Change your password. This signs out every other device and browser where you were logged in; a password reset does the same.
- Turn two-factor authentication on or off and regenerate recovery codes (your password is asked for each), and delete your account (type DELETE and your password). Turning two-factor on signs out your other devices and disconnects connected apps; they sign in again with a code.
- Set your **preferred contact method**: Email, SMS, WhatsApp, Both (email + SMS), or None. A new account starts on SMS whenever a phone number is on file, email otherwise.

### My Events

The **My Events** page shows all events you're invited to or created, split into Upcoming and Past. Each event shows your RSVP status and quick links to the calendar view.

### RSVP from Email/SMS

When you're invited to an event, you'll receive a notification via your preferred contact method. The message includes a one-click RSVP link that works without logging in. After responding, you can change your mind using the alternate response buttons on the confirmation page.

---

## Notifications

### How Notifications Work

Notifications are sent via the user's preferred contact method (email, SMS, WhatsApp, or both). The admin must enable notifications globally in **Admin > General > Notifications > Enable Notifications**.

Notification triggers:
- **Event invite** — When you're added to an event.
- **RSVP change** — Event creator is notified when someone RSVPs.
- **Reminders** — Automated reminders before events (requires cron setup).
- **Password reset** — Email with reset link.
- **Email verification** — Confirmation link on registration.

Hosts can see every send and reply for an event under **More → Message history** on the event page: a timeline of send runs and replies (reminders still to go out on top, notices to the hosts folded away), or one thread per guest, with a Failed-only filter. The log keeps 90 days.

### Reminder Schedule

When cron is configured, each event's reminders are sent automatically. The host sets them in the event editor under **Send reminders**: one row per reminder, each a number and a unit (minutes, hours, days or weeks) before the start, up to six, up to 28 days out. A new event starts with the site default, **2 days** and **12 hours** before, which the admin changes under **Settings → General → Event Reminders** with the same rows. An event whose rows have all been removed sends none; the **Reminders** switch on the event turns them off altogether.

Each reminder is sent only once per user per event occurrence (tracked in the database).

Reminders go to every approved guest on the list who has not answered No. A guest without an account is reached at the email or phone on their invite, the same way their invitation was. Guests awaiting approval or waitlisted get none, a guest who said No is skipped when the reminder is due (an answer changed back to Yes or Maybe before then still gets it), and a guest removed or denied loses any reminders already queued for them.

### SMS RSVP Replies

If you have SMS configured, users can reply to invitation texts with **YES**, **NO**, or **MAYBE** to update their RSVP. The system matches the reply to the user's phone number and updates their most recent pending invite.

---

## Cron Setup

Automated reminders require a cron job that calls the reminder endpoint.

1. In **Admin > General**, find or generate your **Cron Token**.
2. Set up a cron job on your server:

```bash
# Run every 15 minutes
*/15 * * * * curl -s -H "X-Cron-Token: YOUR_CRON_TOKEN" "https://yoursite.com/cron.php" > /dev/null 2>&1
```

Or use the Docker container's built-in timer if configured in `docker-compose.yml`.

Besides reminders, each run drains queued notifications, processes RSVP deadlines, prunes expired tokens and old logs, and removes self-registered accounts that never verified within 7 days (`UNVERIFIED_ACCOUNT_DAYS`; accounts with anything attached, or made at the door or through the API, are left alone).

---

## API for Sister Sites

GameNight exposes a small JSON API at `/api/v1/` so a separate website (for example, a poker league's main marketing site) can pull league data, or push new users into a league, without copy-pasting. Each API key is bound to **one league** at issuance and is restricted to that league's data — keys cannot read or write across leagues.

The API was designed for a single trusted server-to-server consumer model (one shared key in the consumer's config). It is not an OAuth provider and does not have per-end-user tokens.

> **Looking for an API for yourself rather than for a website?** This section is `/api/v1`, where a key belongs to a league. The [Personal API (v2)](#personal-api-v2) is the one where a token belongs to you, sees every league and event you can, and can run your events and reach your guests. An assistant or a script of your own wants v2.

### Scopes

Every key carries a scope:

- **`read`** (default) — GET endpoints only.
- **`read,write`** — In addition to read, allows write endpoints such as `POST /users`.

Old keys minted before the scope system shipped are migrated to `read`, so they cannot exercise write endpoints until they are re-minted with write access.

### Issuing a Key

1. As the **owner** of a league, navigate to that league's page (`/league.php?id=N`) and click the **API** tab. Site admins can also access this tab on any league. Managers cannot issue keys — issuing a key exposes the league's data outside the platform, which is an owner-level decision.
2. Type a label that describes who's getting the key (for example `westside-poker sister site`).
3. Pick a scope: **Read-only** (default) or **Read + write (create users)**. Hand out the smallest scope that does the job — a read-only key that leaks cannot create accounts in your league.
4. Click **Mint key**.
5. The plaintext key is displayed exactly once in a green box. Copy it now and store it in the consumer's server config. The key is hashed (SHA-256) at rest; once you leave the page you cannot recover the plaintext.
6. The key appears in the table below the form with scope, status, created date, and last-used date. You can revoke it at any time.

Site admins can see every key across every league via **Admin Settings → API Keys**. That page is read-only and lets admins revoke any key for abuse response, but admins cannot mint keys on behalf of league owners.

### API Authentication

Pass the key in the `Authorization` header:

```bash
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/league
```

If your client cannot set headers, you can pass the key as a `?key=` query parameter instead. This works the same way but the key may show up in server logs and referer headers, so the header approach is preferred:

```bash
curl 'https://your-site.com/api/v1/league?key=YOUR_KEY'
```

Either way, the request must be over HTTPS in production.

### API Endpoints

The base path `/api/v1` (no trailing slash needed) returns a discovery document describing the available endpoints — useful for human exploration; no key required. All other endpoints require a key.

#### `GET /api/v1/league`

League summary.

```json
{
  "ok": true,
  "data": {
    "id": 6,
    "name": "Kipling Poker",
    "description": "Friendly home game, every other Saturday.",
    "member_count": 13,
    "created_at": "2026-04-17 02:36:29"
  }
}
```

#### `GET /api/v1/members`

Roster. Personal contact info (emails, phones) is **never** returned.

```json
{
  "ok": true,
  "data": [
    { "member_id": 1,  "user_id": 6,    "display_name": "Bryce",   "known_as": "Bryce K.", "role": "owner",   "pending": false, "joined_at": "2026-04-17 02:36:29", "invited_at": null,                  "invited_by_username": null },
    { "member_id": 4,  "user_id": 12,   "display_name": "brad",    "role": "manager", "pending": false, "joined_at": "2026-04-17 13:43:25", "invited_at": null,                  "invited_by_username": null },
    { "member_id": 17, "user_id": null, "display_name": "Crystal", "role": "member",  "pending": true,  "joined_at": "2026-04-25 23:06:29", "invited_at": "2026-04-25 23:06:29", "invited_by_username": "Bryce" }
  ]
}
```

`known_as` is the name the league's owner or manager gave the member on the Members tab (null when none); `display_name` stays the account's screen name.

`pending: true` means the person was invited by email or phone but has not yet created an account; for those rows `user_id` is `null`. Registered members get an integer `user_id` that you can pass back to write endpoints (e.g. as an invitee on `POST /events/{id}/invites`). The display name is their `username` if they have an account, otherwise the contact name on the invite.

`member_id` (the league_members PK) is on every row and is the only stable identifier for pending contacts. Pass it to `PATCH /api/v1/pending-contacts/{member_id}` and `DELETE /api/v1/pending-contacts/{member_id}` to edit or remove pending rows. `invited_at` and `invited_by_username` are typically populated only on pending rows.

#### `GET /api/v1/members/{user_id}`

Single league-member by `user_id`. Same shape as a list-item from `GET /members`. `pending` is always `false` because pending contacts have no `user_id` and aren't addressable through this endpoint. Returns `404 member_not_found` if the user_id isn't a member of this league.

```json
{
  "ok": true,
  "data": {
    "member_id": 4,
    "user_id": 12,
    "display_name": "brad",
    "role": "manager",
    "pending": false,
    "joined_at": "2026-04-17 13:43:25",
    "invited_at": null,
    "invited_by_username": null
  }
}
```

#### `PATCH /api/v1/members/{user_id}`

**Requires the `write` scope.** Promote or demote a registered league member's role.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `league_role` | string, required | One of `member`, `manager`. `owner` is **not** settable via the API — privilege transfer is UI-only (use the in-app "transfer ownership" flow). |

Idempotent: if the member's role already matches the requested value, the response returns `role_changed: false` and no DB write happens.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "league_id": 6,
    "user_id": 12,
    "league_role": "manager",
    "role_changed": true
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid body, unknown field, `cannot_set_owner_via_api` (you tried to promote to owner), or `cannot_demote_owner` (the target is currently owner). |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `member_not_found` — the user_id isn't a member of this league, or the membership row is a pending contact (no registered account yet — call `POST /users` first). |
| `429` | Rate limit exceeded — 60 updates per hour per key. |

**Example:**

```bash
# Promote a member to manager
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"league_role":"manager"}' \
     https://your-site.com/api/v1/members/12

# Demote a manager back to plain member
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"league_role":"member"}' \
     https://your-site.com/api/v1/members/12
```

#### `DELETE /api/v1/members/{user_id}`

**Requires the `write` scope.** Removes a user from the bound league.

The user account is **not** deleted — only their `league_members` row is dropped. Their RSVPs to existing events, their per-event manager roles (`event_invites.event_role='manager'`), authored posts, and memberships in other leagues all remain intact. The user can be re-added later via `POST /api/v1/users` with the same email/phone (idempotent) or by an admin in the league UI.

The removed user is notified by their preferred channel: subject `"Removed from {league_name}"`, body `"You were removed from the league \"{league_name}\"."`. The notification is best-effort — a failed SMS or email does **not** roll back the removal. The response's `notification_sent` field reflects whether the send succeeded.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "league_id": 9,
    "user_id": 240,
    "removed": true,
    "notification_sent": true
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | `cannot_remove_owner` — the league owner cannot be removed via the API. Use the in-app **Transfer ownership** flow first; the new owner can then be removed normally. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `member_not_found` — the user_id isn't a member of this league (also returned for users in other leagues, to avoid confirming their existence). |
| `429` | Rate limit exceeded — 60 removals per hour per key. |

**Examples:**

```bash
# Remove a member
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/members/240

# Re-add them later (idempotent on email/phone)
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Brenda","email":"brenda@example.com"}' \
     https://your-site.com/api/v1/users
```

#### `PATCH /api/v1/pending-contacts/{member_id}`

**Requires the `write` scope.** Edit a **pending contact** — a `league_members` row where `user_id IS NULL`, i.e. someone who was invited by email or phone but hasn't claimed an account yet. The display name, email, and phone for those rows live on the `league_members` table itself, so editing them is genuinely league-scoped — no cross-league effects, no rewriting a real user's login.

To address pending rows, use `member_id` from `GET /api/v1/members`. Registered members (rows with a non-null `user_id`) cannot be edited here — use `PATCH /api/v1/members/{user_id}` for those.

**Request body** (JSON, at least one field required):

| Field | Type | Notes |
|---|---|---|
| `display_name` | string, optional | Trimmed; max 200 chars. Cannot be empty after trim. |
| `email` | string, optional | Validated and lowercased before storage. Pass an empty string to clear. Must be unique among pending contacts in the league. |
| `phone` | string, optional | Normalized to `XXX-XXX-XXXX` for US 10-digit numbers. Pass an empty string to clear. |

The row must keep at least one of `email` or `phone` after the edit; clearing both returns `400 must_keep_email_or_phone`.

> **Invite token regeneration:** When `email` or `phone` changes, the row's `invite_token` is automatically regenerated. The old `/league_invite.php?token=...` link dies immediately. The new token is included in the response as `invite_token` so sister sites can re-deliver the invite. Old tokens are never echoed back. No-op edits (where every field already matches the stored value) leave the token alone.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "member_id": 17,
    "fields_changed": ["email"],
    "token_regenerated": true,
    "invite_token": "7b3f9a1c4e5d6f8a2b1c0d9e8f7a6b5c"
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid body, unknown field, bad email format, `must_keep_email_or_phone`, `email_already_pending`, or `not_a_pending_contact` (the row is a registered member, not a pending contact). |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `pending_contact_not_found` — the member_id doesn't exist or belongs to a different league. |
| `429` | Rate limit exceeded — 60 updates per hour per key. |

**Example:**

```bash
# Fix a typo in a pending contact's email — the invite link regenerates
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"email":"crystal@example.com"}' \
     https://your-site.com/api/v1/pending-contacts/17
```

#### `DELETE /api/v1/pending-contacts/{member_id}`

**Requires the `write` scope.** Hard-delete a pending-contact row. Silent — pending contacts have no account to notify, and the contact info might be why you're deleting in the first place.

Registered rows are not addressable here even if you happen to pass their `member_id`; the response is the same `404 pending_contact_not_found` so the API doesn't confirm row types.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "member_id": 17,
    "deleted": true
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `pending_contact_not_found` — the member_id doesn't exist, belongs to a different league, or addresses a registered member (use `DELETE /members/{user_id}` for those). |
| `429` | Rate limit exceeded — 60 deletions per hour per key. |

**Example:**

```bash
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/pending-contacts/17
```

#### `GET /api/v1/events?from=YYYY-MM-DD&to=YYYY-MM-DD`

> **Breaking change in v0.19208.** This endpoint used to return `start_date` / `start_time` / `end_date` / `end_time` as local-time strings in the league's display timezone. It now returns ISO-8601 UTC instants in `start_at` / `end_at`. Sister sites no longer need to know the league's timezone to display events correctly.

Events for the league within a date window. RSVP counts only include approved invites.

| Query param | Default | Notes |
|---|---|---|
| `from` | today (in the league's timezone) | Inclusive |
| `to` | `from + 90 days` | Inclusive; window is capped at 366 days |
| `include_cancelled` | off | Set to `1` to include events a host cancelled. Left out by default, so a schedule built from this list never shows a game that is off. |

Every event carries `cancelled_at`: `null`, or the UTC instant the host cancelled it. A cancelled event is kept on the site (so it can be duplicated) but takes no changes: `PATCH` on it and any invite write answer **409** `event_cancelled`. `DELETE` still removes it, and tells nobody again.

```json
{
  "ok": true,
  "data": {
    "from": "2026-04-28",
    "to": "2026-07-27",
    "count": 2,
    "events": [
      {
        "id": 67,
        "title": "Kipling poker 17th",
        "description": "",
        "start_at": "2026-05-17T20:00:00Z",
        "end_at":   "2026-05-18T02:00:00Z",
        "color": "#2563eb",
        "is_poker": true,
        "rsvp_yes_count": 5,
        "rsvp_no_count": 1,
        "rsvp_maybe_count": 0,
        "cancelled_at": null,
        "created_at": "2026-04-26T20:06:26Z"
      }
    ]
  }
}
```

`start_at` and `end_at` are ISO-8601 UTC instants ending in `Z`. **All-day events** (events scheduled with a date but no time) return a date-only string (`"2026-05-17"`) in the same field instead of a full instant — this is how callers can tell the two apart. `end_at` is `null` for events without a configured end. `created_at` is also UTC.

#### `GET /api/v1/events/{id}`

Single event by id. Useful when you have just an id (e.g. stored after `POST /events`) and don't want to pull a date window and filter client-side.

Same shape as a list-item from `GET /events`, plus `league_id` and `visibility` for symmetry with the `POST /events` response. Returns 404 `event_not_found` if the id doesn't exist or belongs to a different league (the API doesn't confirm existence of resources outside the bound league).

```json
{
  "ok": true,
  "data": {
    "id": 67,
    "title": "Kipling poker 17th",
    "description": "",
    "start_at": "2026-05-17T20:00:00Z",
    "end_at":   "2026-05-18T02:00:00Z",
    "color": "#2563eb",
    "is_poker": true,
    "league_id": 6,
    "visibility": "league",
    "rsvp_yes_count": 5,
    "rsvp_no_count": 1,
    "rsvp_maybe_count": 0,
    "cancelled_at": null,
    "created_at": "2026-04-26T20:06:26Z"
  }
}
```

#### `GET /api/v1/events/{id}/invites`

Invitee list with RSVP state. Sort order matches the calendar UI (`COALESCE(sort_order, 999999), username`). Personal contact info (`email`, `phone`, `rsvp_token`) is never returned.

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "count": 3,
    "invitees": [
      { "user_id": 6,    "display_name": "bryce",   "rsvp": "yes",   "approval_status": "approved",   "event_role": "manager" },
      { "user_id": 12,   "display_name": "brad",    "rsvp": null,    "approval_status": "approved",   "event_role": "invitee" },
      { "user_id": null, "display_name": "crystal", "rsvp": "maybe", "approval_status": "waitlisted", "event_role": "invitee" }
    ]
  }
}
```

- `user_id` is `null` for custom invitees that were added by email/phone without a registered account. Sister sites usually only need the rows where `user_id` is non-null (those round-trip to `GET /members`).
- `rsvp` is `null` until the invitee responds.
- `approval_status` is one of `approved`, `pending`, `waitlisted`, `denied`.
- `event_role` is `invitee` or `manager`.

Per-occurrence override rows from the legacy recurring-events feature are filtered out — only base invites surface.

#### `GET /api/v1/posts?limit=20&offset=0`

League posts (announcements / news). Excludes hidden posts, drafts, future-scheduled posts, and the league's rules post. Sorted by pinned, then created_at descending.

| Query param | Default | Notes |
|---|---|---|
| `limit` | 20 | Max 50 |
| `offset` | 0 | For pagination |

```json
{
  "ok": true,
  "data": {
    "total": 12,
    "limit": 20,
    "offset": 0,
    "count": 2,
    "posts": [
      {
        "id": 7,
        "title": "90min Turbo Blinds",
        "content_html": "<h1>8-Player 90-Minute Tournament</h1>...",
        "author_display_name": "Bryce",
        "created_at": "2026-04-27 20:39:53",
        "share_url": "https://your-site.com/post_public.php?token=5de463f570b59b21da4d67c1351fb4ad"
      }
    ]
  }
}
```

`content_html` is sanitized HTML (the same pipeline used when posts render in the UI). Posts that have a public share link include `share_url`; posts without sharing enabled omit that field.

#### `GET /api/v1/posts/{id}`

Single post by id. Same shape as a list-item from `GET /posts`. Same visibility filters: hidden posts, future-scheduled posts, and the rules post all return `404 post_not_found` (use `GET /rules` for the rules post specifically).

```json
{
  "ok": true,
  "data": {
    "id": 7,
    "title": "90min Turbo Blinds",
    "content_html": "<h1>8-Player 90-Minute Tournament</h1>...",
    "author_display_name": "Bryce",
    "created_at": "2026-04-27 20:39:53",
    "share_url": "https://your-site.com/post_public.php?token=5de463f570b59b21da4d67c1351fb4ad"
  }
}
```

`share_url` is included only when the post has a public share token configured.

#### `POST /api/v1/posts`

**Requires the `write` scope.** Creates a post in the API key's league. Author is set to the league owner. Content is sanitized through the same pipeline the in-app editor uses (script tags, event handlers, untrusted iframes, etc. stripped before storage).

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `title` | string, required | Max 200 chars. |
| `content` | string, required | Sanitized HTML; must be non-empty after sanitization. |
| `pinned` | boolean, optional | Default `false`. Pinned posts sort above unpinned. |
| `hidden` | boolean, optional | Default `false`. Hidden posts are not returned by `GET /posts` or `GET /posts/{id}`. |
| `published_at` | string, optional | ISO-8601 UTC instant (`"2026-05-17T20:00:00Z"`). Defaults to the server's current UTC time. **Future values create a scheduled post** — the row is in the database but the existing `GET /posts` filter (`created_at <= now`) keeps it hidden until the scheduled time arrives. |

> **Locked fields:** `is_rules_post`, `share_token`, and `make_public` are not settable via the API. Promoting a post to the league's rules post and minting a public share link both stay UI-only operations. Sending any of those fields returns `400`.

**Successful response** (HTTP 200): same shape as `GET /posts/{id}`, plus `pinned` and `hidden`.

```json
{
  "ok": true,
  "data": {
    "id": 23,
    "title": "Saturday rotation update",
    "content_html": "<p>We're moving start time to 7:30...</p>",
    "author_display_name": "bryce",
    "created_at": "2026-05-02 14:32:01",
    "pinned": false,
    "hidden": false
  }
}
```

#### `PATCH /api/v1/posts/{id}`

**Requires the `write` scope.** Partial update of an existing post. Only fields present in the body are touched.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `title` | string, optional | Max 200 chars. |
| `content` | string, optional | Sanitized on write. |
| `pinned` | boolean, optional | |
| `hidden` | boolean, optional | |

> **Locked fields:** `is_rules_post`, `share_token`, `make_public`, and `published_at` are rejected with `400`. Retroactive publish-date edits create a confusing audit story; the others remain UI-only.

Empty body or all-fields-unchanged returns `400 no_fields_to_update`.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "id": 23,
    "title": "Saturday rotation update",
    "content_html": "<p>Updated body...</p>",
    "author_display_name": "bryce",
    "created_at": "2026-05-02 14:32:01",
    "pinned": true,
    "hidden": false,
    "fields_changed": ["content", "pinned"]
  }
}
```

`fields_changed` lists the columns that actually moved. If you POST a field whose value already matches the stored value, it does not appear here.

**Examples:**

```bash
# Pin an existing post
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"pinned":true}' \
     https://your-site.com/api/v1/posts/23

# Hide a post (soft-removes it from feeds without deleting)
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"hidden":true}' \
     https://your-site.com/api/v1/posts/23
```

#### `DELETE /api/v1/posts/{id}`

**Requires the `write` scope.** Hard-deletes a post in the API key's league. Cascades to comments (rows in the `comments` table where `type='post'` and `content_id` matches). Wrapped in a transaction; partial failures roll back cleanly.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "post_id": 23,
    "deleted": true,
    "comments_deleted": 4
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `post_not_found` — the id doesn't exist or belongs to a different league. |
| `429` | Rate limit exceeded — 60 deletions per hour per key. |

**Example:**

```bash
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/posts/23
```

#### `GET /api/v1/rules`

The league's rules post. The rules post is a special post (one per league at most) that lives behind a dedicated UI button in-app and is excluded from `/api/v1/posts`; this endpoint is the way to read it.

```json
{
  "ok": true,
  "data": {
    "rules": {
      "id": 42,
      "title": "House Rules",
      "content_html": "<h2>Buy-in</h2><p>...</p>",
      "author_display_name": "Bryce",
      "created_at": "2025-11-12 03:14:00"
    }
  }
}
```

When the league has not configured a rules post yet, `rules` is `null`:

```json
{ "ok": true, "data": { "rules": null } }
```

`content_html` is sanitized HTML, same pipeline as `/posts`. Hidden rules posts are treated as absent.

#### `POST /api/v1/users`

**Requires the `write` scope.** Creates a user and adds them to the key's league. Mirrors the walk-in registration flow: a soft account is created with `must_change_password=1` and `email_verified=0`, and (unless suppressed) a verification email or SMS is sent so the new user can later set a password and sign in.

The endpoint is **idempotent on email/phone** — replaying the same request body returns the existing `user_id`, ensures league membership, and skips the verification send. Sister sites can retry safely without creating duplicate accounts.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `display_name` | string, required | Used to derive a username when `username` is omitted. |
| `email` | string, optional | At least one of `email` or `phone` is required. |
| `phone` | string, optional | Normalized to `XXX-XXX-XXXX` for US numbers; international numbers stored as entered. |
| `username` | string, optional | 3–30 chars, letters/numbers/underscores. If omitted, derived from `display_name` with a numeric suffix on collision. |
| `verification_method` | string, optional | One of `email`, `sms`, `whatsapp`, `none`. One-shot — used only at signup. Default: `email` if email provided, else `sms`. Use `none` if your site handles onboarding itself. |
| `preferred_contact` | string, optional | One of `email`, `sms`, `whatsapp`, `both`, `none`. Sets the user's ongoing notification channel (the same setting they'd pick on `/settings.php`). Default: `sms` when a phone is given (`whatsapp` when that is the `verification_method`), else `email`. **Ignored on existing-user replays** so a leaked write key cannot mute or re-route real accounts. |

`verification_method` is consulted only at signup; `preferred_contact` is what the system reads every time it sends a notification afterwards. They can differ — e.g. verify by SMS but prefer email going forward.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "user_id": 245,
    "username": "API_Test",
    "created": true,
    "league_member_added": true,
    "verification_sent": true,
    "preferred_contact": "email",
    "preferred_contact_updated": true
  }
}
```

- `created` is `true` when a new user row was inserted, `false` when an existing user with that email or phone was found.
- `league_member_added` is `true` when the user was newly added to this key's league, `false` when they were already a member.
- `verification_sent` is `false` for existing-user replays and when `verification_method=none`.
- `preferred_contact` echoes the resolved value (caller-supplied or default). For existing-user replays, it's the user's current stored preference.
- `preferred_contact_updated` is `true` only when a new user was created. Always `false` on replays — preferences on existing accounts are intentionally not overwritten.

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid request body (missing `display_name`, no email or phone, malformed values, unknown `verification_method` or `preferred_contact`). |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `409` | `username_taken` (when caller passed an explicit `username` that's in use) or `contact_taken` (UNIQUE constraint race on email or phone). |
| `429` | Rate limit exceeded — 60 successful creations per hour per key. |

**Examples:**

```bash
# Create with email
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Alice","email":"alice@example.com"}' \
     https://your-site.com/api/v1/users

# Create with phone (sends SMS code)
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Bob","phone":"281-555-1234","verification_method":"sms"}' \
     https://your-site.com/api/v1/users

# Suppress verification — your site handles onboarding
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"display_name":"Carol","email":"carol@example.com","verification_method":"none"}' \
     https://your-site.com/api/v1/users
```

#### `POST /api/v1/events`

**Requires the `write` scope.** Creates an event in the API key's league. Visibility is forced to `'league'`; `league_id` is implicit. The event's creator is set to the league owner so the event has a real manager. A walk-in token is generated immediately and returned as `walkin_url` so sister sites can show a QR right away. Side effects mirror the in-app calendar form: optional poker_sessions row, invitee inserts (always approved), beyond-capacity poker invitees marked waitlisted, reminder notifications queued.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `title` | string, required | Max 200 chars. |
| `start_at` | string, required | ISO-8601 UTC instant (`"2026-05-17T20:00:00Z"`) **or** a date-only string (`"2026-05-17"`) for all-day events. |
| `end_at` | string, optional | Same format as `start_at`. |
| `description` | string, optional | Plain text. |
| `color` | hex string, optional | One of `#2563eb`, `#16a34a`, `#dc2626`, `#d97706`, `#7c3aed`, `#0891b2`, `#db2777`. Default `#2563eb`. |
| `is_poker` | boolean, optional | Default `false`. When `true`, a `poker_sessions` row is auto-created and the waitlist applies. |
| `requires_approval` | boolean, optional | Default `false`. Gates self-signups via walk-in / RSVP; on a league event a walk-in joins the league only once approved. Does **not** affect API-supplied `invitees` (those are always approved). |
| `rsvp_deadline_hours` | integer, optional | Hours before `start_at` when RSVPs lock. |
| `waitlist_enabled` | boolean, optional | Default `true`. Only meaningful when `is_poker=true`. |
| `reminders_enabled` | boolean, optional | Default `true`. |
| `reminder_offsets` | array of integers, optional | Minutes before `start_at` for each reminder send, each 1..40320 (28 days), at most 6. Left out: the site default (typically `[2880, 720]` = 48h and 12h). An empty array: no reminders. |
| `poker_buyin` | number, optional | Dollars (e.g. `20.00`). Used only when `is_poker=true`. |
| `poker_tables` | integer, optional | Default 1. |
| `poker_seats` | integer, optional | Default 8. Capacity = `poker_tables * poker_seats`. |
| `poker_game_type` | string, optional | `'tournament'` or `'cash'`. Default `'tournament'`. |
| `invitees` | array, optional | Each entry: `{user_id: int, manager?: bool}`. Each `user_id` must already be a member of this league (call `POST /users` first to create + add them). All inserted with `approval_status='approved'`. Capped at 200. |

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "title": "Kipling poker 17th",
    "start_at": "2026-05-17T20:00:00Z",
    "end_at": "2026-05-18T02:00:00Z",
    "league_id": 6,
    "visibility": "league",
    "is_poker": true,
    "walkin_url": "https://your-site.com/walkin.php?event_id=67&token=ABCD1234...",
    "invitees_added": 2,
    "created_at": "2026-04-30T17:23:11Z"
  }
}
```

- `walkin_url` is the public registration link (the same URL the in-app QR code generates). You can show it as a QR on a check-in screen, share it in your event description, or print it.
- `invitees_added` counts the rows actually inserted. If `is_poker=true` and `waitlist_enabled=true`, invitees beyond `poker_tables * poker_seats` are inserted with `approval_status='waitlisted'` (still counted in `invitees_added`).

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid request body. Examples: missing `title`, unparseable `start_at`, unknown `color`, a `recurrence` value (repeating events are not supported), invitee user_id not in this league. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | The league bound to the key was deleted. |
| `405` | Method not allowed. |
| `429` | Rate limit exceeded — 60 successful event creations per hour per key. |

**Examples:**

```bash
# Single-evening poker night
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{
       "title": "Friday $20 NLH",
       "start_at": "2026-05-22T23:00:00Z",
       "end_at":   "2026-05-23T03:00:00Z",
       "is_poker": true,
       "poker_buyin": 20,
       "poker_tables": 2,
       "poker_seats": 8
     }' \
     https://your-site.com/api/v1/events

# All-day calendar marker, no poker
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"title":"League holiday","start_at":"2026-12-25"}' \
     https://your-site.com/api/v1/events

# With invitees (must already be league members; POST /users first if not)
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{
       "title": "Members-only tourney",
       "start_at": "2026-06-01T00:00:00Z",
       "is_poker": true,
       "invitees": [
         {"user_id": 12},
         {"user_id": 34, "manager": true}
       ]
     }' \
     https://your-site.com/api/v1/events
```

#### `PATCH /api/v1/events/{id}`

**Requires the `write` scope.** Partial update of an existing event. Only fields present in the body are touched — omit a key to leave it unchanged.

Accepts the same field shape as `POST /events`, with these exceptions:
- **`invitees` is not supported** — use `POST /api/v1/events/{id}/invites` to add invitees.
- **`league_id` and `visibility` are immutable** — events stay in the league bound to the API key.
- `recurrence` / `recurrence_end` rejected (same as POST).

When `start_at` (date or time) changes and the event is in the future, an `event_updated` notification is queued for every approved base invitee. Other field changes are silent. The reminder queue is rebuilt automatically when timing or reminder fields change. Wrapped in a transaction; partial failures roll back cleanly.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "title": "Kipling poker 17th",
    "start_at": "2026-05-17T20:00:00Z",
    "end_at": "2026-05-18T02:00:00Z",
    "is_poker": true,
    "fields_changed": ["start_date", "start_time"],
    "notifications_queued": 4
  }
}
```

`fields_changed` is the list of stored columns that actually moved. If you POST a field that already matches the stored value, it does not appear here. `notifications_queued` reports how many `event_updated` notifications were queued (0 for past events or non-timing edits).

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid body, unparseable `start_at`, unknown `color`, `no_fields_to_update` (every field in the body matches the stored value), etc. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `event_not_found` (id missing or in a different league). |
| `429` | Rate limit exceeded — 60 updates per hour per key. |

**Examples:**

```bash
# Move start time
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"start_at":"2026-05-17T21:00:00Z"}' \
     https://your-site.com/api/v1/events/67

# Polish the description (silent — no notifications)
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"description":"Bring snacks. Doors at 7."}' \
     https://your-site.com/api/v1/events/67

# Bump poker capacity
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"poker_tables":2,"poker_seats":9}' \
     https://your-site.com/api/v1/events/67
```

#### `POST /api/v1/events/{id}/invites`

**Requires the `write` scope.** Add invitees to an event after creation. Idempotent — anyone already invited is silently skipped and reported in the response.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `invitees` | array, required | Each entry: `{user_id: int, manager?: bool}`. Each `user_id` must already be a member of this league (use `GET /members` to discover ids, or `POST /users` to create new members first). Capped at 200. |

Newly-inserted rows always land `approval_status='approved'`, mirroring how the calendar UI treats creator-added invites. For poker events with `waitlist_enabled=true`, anyone added beyond `poker_tables * poker_seats` is automatically marked `waitlisted` after insert and skips the invite notification.

> **Existing invitees keep their manager flag.** The endpoint does not promote/demote on duplicate input. If you want to change someone's role on an event, that's a separate operation that isn't yet exposed.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "added": [12, 34],
    "skipped": [56],
    "waitlisted": [],
    "notifications_queued": 2
  }
}
```

- `added` lists user_ids that produced new invite rows.
- `skipped` lists user_ids that were already invited.
- `waitlisted` lists user_ids whose new invite landed `waitlisted` (poker capacity overflow).
- `notifications_queued` is the count of invite notifications fired — equals `len(added) - len(waitlisted)`.

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Empty `invitees`, malformed entries, user_ids not members of this league, or too many invitees. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `event_not_found` (id missing or in a different league). |
| `429` | Rate limit exceeded — 60 calls per hour per key. |

**Example:**

```bash
curl -X POST -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"invitees":[{"user_id":12},{"user_id":34,"manager":true}]}' \
     https://your-site.com/api/v1/events/67/invites
```

#### `DELETE /api/v1/events/{id}/invites/{user_id}`

**Requires the `write` scope.** Removes a single invitee from an event. Symmetric with `POST /events/{id}/invites`. Mirrors the calendar UI's `remove_invitee` action: for future events, queues a `cancel_event` notification to the removed user before the row is deleted; past events delete silently.

The wording of the notification is "this event has been cancelled" — the same template the full-event delete uses. It's awkward when only one user is being uninvited, but it matches what the in-app UI does today and saves adding a separate template.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "user_id": 12,
    "removed": true,
    "notifications_queued": 1
  }
}
```

`notifications_queued` is `1` for future events, `0` for past events.

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `event_not_found` (event id missing or in a different league) or `invitee_not_found` (user_id isn't currently invited). The two share the response shape but differ in the `error` field. |
| `429` | Rate limit exceeded — 60 removals per hour per key. |

**Example:**

```bash
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/events/67/invites/12
```

#### `PATCH /api/v1/events/{id}/invites/{user_id}`

**Requires the `write` scope.** Update an invitee's `rsvp` or `event_role` without removing and re-adding them. At least one of the two fields must be present in the body.

**Request body** (JSON):

| Field | Type | Notes |
|---|---|---|
| `rsvp` | string or null, optional | One of `"yes"`, `"no"`, `"maybe"`, or `null` to clear. |
| `event_role` | string, optional | `"invitee"` or `"manager"`. |

The API acts as the league owner, so the 1-hour-before-start RSVP cutoff that applies to non-admin users in the UI **does not** apply here — sister sites can record same-day RSVPs without restriction.

When `rsvp` becomes `"no"` on a poker event with `waitlist_enabled=true`, the waitlist is recomputed automatically. Any waitlisted invitees promoted to `approved` are reported in `promoted_from_waitlist`.

> **Notifications:** None are sent. The in-app UI doesn't notify either when an RSVP is recorded — sister sites that want to ping the host or the user should do so themselves.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "user_id": 12,
    "fields_changed": ["rsvp"],
    "promoted_from_waitlist": 1
  }
}
```

`fields_changed` lists the columns that actually moved. If you POST a field whose value already matches the stored value, it does not appear here. Empty body or all-fields-unchanged returns `400 no_fields_to_update`.

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `400` | Invalid body, unknown field, bad rsvp/event_role value, or `no_fields_to_update`. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `event_not_found` (event missing or in a different league) or `invitee_not_found` (user_id not currently invited to this event). |
| `429` | Rate limit exceeded — 60 updates per hour per key. |

**Examples:**

```bash
# Record an RSVP collected off-platform
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"rsvp":"yes"}' \
     https://your-site.com/api/v1/events/67/invites/12

# Promote someone to per-event manager
curl -X PATCH -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     -H 'Content-Type: application/json' \
     -d '{"event_role":"manager"}' \
     https://your-site.com/api/v1/events/67/invites/12
```

#### `DELETE /api/v1/events/{id}`

**Requires the `write` scope.** Hard-deletes an event in the API key's league. Mirrors the calendar UI's delete handler: future events queue cancel notifications to invitees before the row is destroyed, past events delete silently. Wrapped in a transaction so partial failures roll back cleanly.

The cascade clears all related rows:

| Table | What gets deleted |
|---|---|
| `pending_notifications` | All queued notifications for this event are cleared first (so dead reminders for the now-cancelled event don't fire). Fresh `cancel_event` rows are then re-queued for invitees and sent right after the transaction commits. |
| `comments` | All comments where `type='event'` and `content_id` matches |
| `event_exceptions` | Any per-occurrence overrides for this event |
| `event_invites` | All invite rows |
| `event_notifications_sent` | The dedup audit trail |
| `event_messages` | Host messages sent to the guests, with any attached PDF |
| `poker_sessions` (+ `poker_players`, `poker_payouts`, `timer_state`) | The game, its players, payouts and clock |
| `events` | The event itself |

Future-event detection: `start_date >= today` (in the league's display timezone). When true, every base invitee (rows with `occurrence_date IS NULL`) gets a `cancel_event` notification queued before the deletion runs. The `notifications_queued` field in the response tells you how many.

**Successful response** (HTTP 200):

```json
{
  "ok": true,
  "data": {
    "event_id": 67,
    "title": "Kipling poker 17th",
    "deleted": true,
    "notifications_queued": 4
  }
}
```

**Error responses:**

| HTTP code | Meaning |
|---|---|
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the `write` scope. |
| `404` | `event_not_found` — the id doesn't exist OR the event belongs to a different league. The two cases return the same response on purpose (the API does not confirm existence of resources outside this key's league). |
| `429` | Rate limit exceeded — 60 deletions per hour per key. |
| `500` | Internal error during the cascade — the transaction was rolled back; the event was not deleted. |

**Examples:**

```bash
# Cancel an upcoming event (will notify invitees)
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/events/67

# Clean up a past event (silent — no notifications)
curl -X DELETE -H 'Authorization: Bearer YOUR_WRITE_KEY' \
     https://your-site.com/api/v1/events/42
```

### API Response Shape

Every response uses the same envelope:

- **Success**: `{"ok": true, "data": ...}` — HTTP 200.
- **Error**: `{"ok": false, "error": "human-readable message"}` — HTTP 400/401/403/404/405/409/429.

This matches the shape used by every internal `_dl.php` endpoint, so if you've integrated against any of those before, the parser is the same.

### API Errors

| HTTP code | Meaning |
|---|---|
| `400` | Bad parameter or invalid request body. Examples: `from` after `to`; window over 366 days; non-hex characters in the key; missing `display_name` on `POST /users`. |
| `401` | Missing, malformed, or revoked API key. |
| `403` | API key lacks the scope required by the endpoint (e.g. a read-only key calling `POST /users`). |
| `404` | The league bound to the key was deleted (key is dead, mint a new one for a different league). |
| `405` | Method not allowed for this endpoint. |
| `409` | Conflict on `POST /users` — `username_taken` or `contact_taken`. |
| `429` | Rate limit exceeded: 120 requests per minute per key across all endpoints, or 60 successful writes per hour per key on one write endpoint. |

### API Caching

Successful `GET` responses include `Cache-Control: private, max-age=60`; writes and errors are sent with `no-store`. Consumers should cache for at least one minute. The data does not change every second; hammering the API with one request per page view is wasteful.

CORS is allowed from any origin (`Access-Control-Allow-Origin: *`), so a JavaScript client running in a browser can call the API directly. Note: putting the key in browser-side JS exposes it to anyone who views your page source. Do that only if you accept the consequences of revocation when (not if) the key leaks.

Each key is limited to 120 requests per minute across all endpoints (a `429` with `Retry-After: 30` when exceeded), and each write endpoint to 60 successful calls per hour per key. Every call is logged with the key id, IP, path, and status for 30 days. Abusive patterns will result in the key being revoked.

### API Examples

**PHP (server-side, recommended):**

```php
$key = 'YOUR_64_CHAR_HEX_KEY';
$ctx = stream_context_create([
    'http' => [
        'header' => "Authorization: Bearer $key\r\n",
        'timeout' => 5,
    ],
]);
$resp = file_get_contents('https://your-site.com/api/v1/events', false, $ctx);
$data = json_decode($resp, true);
if ($data['ok'] ?? false) {
    foreach ($data['data']['events'] as $event) {
        echo htmlspecialchars($event['title']) . "<br>";
    }
}
```

**JavaScript (browser, only if the key can be public):**

```javascript
fetch('https://your-site.com/api/v1/posts?limit=5', {
    headers: { 'Authorization': 'Bearer YOUR_KEY' }
})
.then(r => r.json())
.then(({ ok, data, error }) => {
    if (!ok) { console.error(error); return; }
    data.posts.forEach(p => console.log(p.title));
});
```

**curl one-liners** (handy for testing):

```bash
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/league
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/members
curl -H 'Authorization: Bearer YOUR_KEY' 'https://your-site.com/api/v1/events?from=2026-01-01&to=2026-12-31'
curl -H 'Authorization: Bearer YOUR_KEY' 'https://your-site.com/api/v1/posts?limit=5'
curl -H 'Authorization: Bearer YOUR_KEY' https://your-site.com/api/v1/rules
```

### API Revoking a Key

If a key leaks, click **Revoke** on its row in the league's API tab. Consumers using that key start getting `401` responses immediately. The revocation is permanent: the key's row is deleted. Its past requests stay in the request log under the old key id.

To rotate a key: mint a new key with a different label, update the consumer to use the new key, then revoke the old one. Keys do not expire on their own — rotate them on whatever cadence makes sense for your operation (annual is reasonable for a low-traffic sister site).

### Connected Apps: Sign-In Bridge

Separate from the league API keys above. A **connected app** (registered under Site Settings → Connected Apps, see the [Admin Guide](#connected-apps)) can sign a Game Night member in without holding a password: it hands the browser to Game Night and receives a signed identity token back. No API key is involved, and the only thing the app needs from Game Night is the public signing key.

**Outbound (app → Game Night), a browser navigation:**

```
GET /connect.php?app=<slug>&return=<url>&state=<nonce>
```

- `app` — the registered slug.
- `return` — where to send the browser back to. Validated origin-exact against the app's registered base URL (scheme, host, port), with the path under the registered path, no fragment, no credentials, 512 characters at most. Anything else renders an error page; Game Night never redirects to an unvalidated URL.
- `state` — `[A-Za-z0-9_-]{16,128}`, minted by the app, echoed back unchanged. It is the app's login-CSRF guard.

A guest is sent through `login.php?redirect=...` first (email/phone verification and two-factor included). A signed-in member sees one card, "Continue to *app* as *username*?", with Continue, Cancel and Switch account.

**Inbound (Game Night → app), a 302 after the confirm:**

```
<return>#gn_token=<jwt>&state=<nonce>          success
<return>#gn_error=cancelled&state=<nonce>      the member pressed Cancel
```

The token rides in the URL **fragment**, so it never reaches the app's access log or a Referer header; the app's page script reads it, scrubs it, and sends it to its own server once.

**The token** is a compact JWT, header `{"alg":"ES256","typ":"JWT","kid":"<first 16 hex of sha256(public PEM)>"}`, signature the raw 64-byte R||S. Claims:

| Claim | Value |
|---|---|
| `iss` | the site URL (Site Settings → General → Site → Site URL; falls back to the request host, so set it) |
| `aud` | the app slug |
| `sub` | the member's user id, as a string |
| `iat`, `exp` | issued at, and 120 seconds later; one hop, then it is spent |
| `jti` | 32 hex, random; the app rejects a repeat |
| `name` | the username |
| `tier` | the account tier (`Free`, `Personal`, `League`, `OriginalSupporters`); informational |
| `avatar_path` | the member's profile photo as a site-relative path (`/uploads/avatars/u<id>_<32 hex>.<ext>`), or `null` - absent when they have no photo, and absent when the file is over 512 KB |

No email, phone or password is carried. The profile photo is carried as a
path, never as image data: the app fetches it from this site the way any
visitor would, and a member with no photo sends `null`.

**The public key** is on the Connected Apps page and at:

```
GET /api/v1/sso
```

No API key. Returns `{issuer, connect_url, token: {format, claims, carried}, keys: [{kid, kty: "EC", crv: "P-256", alg: "ES256", use: "sig", pem}]}`, cacheable for five minutes. An app verifies locally: pin `alg` to ES256 (refuse `none` and HMAC before reading the signature), check the signature with the PEM, then `iss`, `aud`, `exp`/`iat` with a minute of skew, and keep `jti`s until they expire.

**Pairing FinalTable:** register it here, then in FinalTable's lobby open **Operator** (behind its admin password), enter this site's address and the slug, and it fetches the key from `/api/v1/sso` itself. The `.env` snippet on the Connected Apps page is the headless alternative. FinalTable's own `docs/DEPLOYMENT.md` has the steps from its side.

**Games on FinalTable (Game Night → app, then app → Game Night).** The other direction of the bridge, for [online tables](#online-tables-finaltable). The contract is FinalTable's `docs/API.md`; what this side does with it:

- **Outbound.** Every call carries the app's game key as `Authorization: Bearer <key>` (decrypted for the request, never logged) and reads FinalTable's `{ok, data | error}` envelope. Calls are made only by a person pressing a button: *Test* (`GET /api/status`, unkeyed, then `GET /api/games/probe`, whose 404 proves the key), *Set up the table* (`POST /api/games` with `title`, `start_at`, `game`, `limit`, `seats_per_table`, `starting_chips`, `buyin_amount`, `addon_allowed`, `reentryLevels`, `blind_levels`, `structure_name`, `invitees[{user_id, username, manager}]`, `webhook{url, secret}` and `external_id` = the event id; the answer's `settings` say what was made, and `finaltable_game_of()` reads the game and betting from there once a table exists), the controls (`POST /api/games/:id/start|pause|resume|cancel|remove`), the status poll (`GET /api/games/:id`, cached ten seconds per game), and sign-out (`POST /api/players/:user_id/sign-out`). Redirects are never followed. There is no retry queue; a refusal is shown to the person in FinalTable's words.
- **Inbound.** FinalTable POSTs each game event to `/finaltable_webhook.php`: JSON, headers `X-FinalTable-Event`, `X-FinalTable-Delivery`, `X-FinalTable-Timestamp` (milliseconds) and `X-FinalTable-Signature: sha256=<hex>`, an HMAC-SHA256 keyed with the secret this side generated for that game (48 hex characters, stored encrypted beside the game) over `<timestamp>.<body>`. The receiver takes the body once, finds the game by `game.id`, refuses a stale timestamp (five minutes) or a bad signature with 401 before reading anything else, answers 404 for a game it does not hold (FinalTable retries for a day, then gives up and logs it), and dedupes on `(game, delivery_id)` with a UNIQUE row that is the lock: a repeat answers 200 and is dropped. A heartbeat has no delivery id and skips the dedupe. A delivery this side fails to apply answers 500 so FinalTable tries again. Events handled: `tournament.started`, `tournament.level`, `tournament.paused`, `tournament.resumed`, `tournament.heartbeat`, `player.eliminated`, `player.reentered`, `tournament.completed`, `tournament.cancelled`; anything else is acknowledged and ignored.

---

## Personal API (v2)

`/api/v2` is the API for a **person**. (To use it from Claude without writing any code, see [Connecting Claude](#connecting-claude-mcp).) Where a league key on `/api/v1` belongs to a league and acts as its owner, a personal token belongs to you: it sees what you would see signed in to the site, and nothing else. It is what an AI assistant or a script of your own uses to answer "who is coming Friday", "what did everyone get paid last month" or "show me the season standings".

It can read everything you can see, create and change events and guest lists, look after a league's seasons, posts and join requests, send invitations and messages to guests, and run a poker game from setup to the final payout: buy-ins, rebuys, knockouts, seating, the clock.

### Tokens

1. Open **Settings → Security → API Tokens**.
2. Name the token for where it will live ("Claude on my laptop"), choose what it may do, and enter your password.
3. Copy the token (`gnp_` followed by 64 characters). It is shown once; only a hash of it is stored.

A token's row shows what it may do, when it was created and last used, and how many requests it has made. **Recent activity** lists its latest 25 requests: the time, the kind of request (for example `GET /events/{id}/game`), how it went, and the address it came from. Request records are kept for 30 days.

| Permission | Allows |
|---|---|
| `read` | Read your leagues, events, guest lists, games, results and standings. Every token has it. |
| `events` | Create and change events, guest lists and your contacts; add and remove seasons; post to a league. Never sends anything to anyone by itself: an action that would tell people (cancelling, deleting an upcoming event, removing a guest from an upcoming event, approving a guest, removing a league member, answering a request to join, turning reminders on) also needs `messages`, and is refused with `403 messages_permission_needed` without it. An event created or duplicated without `messages` has reminders off, and the answer's `note` says so. |
| `game` | Set up and run poker games: setup, blinds, payouts, buy-ins, rebuys and add-ons, knockouts, seating, the clock, finishing. |
| `messages` | Anything that emails or texts people: invitations, nudges, a host message, inviting someone to a league, and the actions under `events` that tell people. |
| `money` | Needed, with `game`, to put a jackpot amount on a game (the jackpot is the league's fund). Paying out and adjusting the fund through the API is not available yet. |

**Revoke** removes a token at once. Changing your password, or signing out everywhere, revokes every token on the account. Up to 10 tokens per account.

### Calling it

```bash
curl -H 'Authorization: Bearer gnp_YOUR_TOKEN' https://your-site.com/api/v2/me
```

- The token goes in the `Authorization` header only. There is no `?key=` form: a token that will be able to run a game does not belong in a URL.
- `GET /api/v2/` (with the trailing slash) lists every endpoint and needs no token.
- A league key sent to `/api/v2` is refused with `league_key_not_accepted`.
- 120 requests a minute per token; past that, `429` with `Retry-After: 30`. On top of that, 60 successful changes an hour per token on any one route.

### Conventions

- **Envelope.** `{"ok": true, "data": {...}}` on success; `{"ok": false, "error": "snake_case_code", "message": "A sentence."}` on failure. Branch on `error`; show `message`.
- **Money** is integer cents, in fields ending `_cents`. `2000` is $20.00.
- **Time** is ISO 8601 UTC (`2026-12-12T01:00:00Z`). A date with no time of day is `YYYY-MM-DD`. Query dates (`from`, `to`) are days in the site's timezone.
- **Writes** take a JSON object. A field the endpoint does not know is refused by name (`unknown_field`), so a typo cannot be silently ignored. A `PATCH` changes only what you send.
- **Asking twice does it once.** A `POST` may carry an `Idempotency-Key` header (8 to 128 letters, digits, `.`, `:`, `-`, `_`). Sent again within a day with the same key, it returns the first answer (with `Idempotent-Replay: true`) instead of creating a second event. Use one whenever a retry is possible.
- **What reaches people or cannot be undone asks first.** Cancelling or deleting an event, removing a guest from an upcoming event, messaging the guests, finishing a game and resetting the clock take two calls (v1.38.0). The first changes nothing and answers `409 confirmation_required`, whose `message` says what would happen and whose `confirm_code` is a one-time code (`cfm_` and 24 hex characters). Send the same request again with `"confirm": "<that code>"`. A code works once, for 10 minutes, for this token and this exact target; for a message it is tied to the subject, text and audience shown. A bare `"confirm": true` is no longer accepted.
- **Creating and changing never notify.** Adding a guest tells nobody. Invitations, nudges and messages are their own calls under the `messages` permission, so a token without it cannot cause a single email or text.
- **Busy means try again.** `503 busy` (with `Retry-After`) means the site was writing something else, usually notifications going out. Nothing was changed.
- **Nothing is cached.** Every answer is sent `Cache-Control: no-store`; a game changes by the second.
- **Not found means not yours.** An event or league you cannot see answers `404`, exactly as one that does not exist. `403` is reserved for something you can see but may not do (a guest asking for the host's game view).

### Who can see what

| You are | You can read |
|---|---|
| The host of an event (its creator, a co-host, or an owner or manager of its league) | Everything about it, including the game, its log and each player's ledger |
| A guest, or a member of the event's league | The event, the guest list unless the host hid it, and the game's **results** if you are in its league |
| A member of a league | The league, its roster (names and roles), standings and any player's history |
| An owner or manager of a league | Also the people added by contact who have not joined, with the contact details the league entered |
| Nobody in particular | `404` |

An account holder's email address and phone number are never returned, to anyone. The only contact details the API gives out are ones a host or league typed in by hand for someone without an account.

### Endpoints

| Method and path | What it returns |
|---|---|
| `GET /me` | The token's owner, its permissions, the site's name and timezone, and your leagues |
| `GET /me/contacts` | Your contacts. `search` matches name, email or phone. `has_account`, `user_id` and `account_name` are filled only for people you already share a league or event with |
| `GET /events`, `GET /me/events` | Events you can see. `from`, `to` (default today to +90 days, at most 366 days), `role=any\|host\|guest`, `league_id`, `include_cancelled=1` |
| `GET /events/{id}` | One event: RSVP totals, your role and answer, capacity, and a summary of its game |
| `GET /events/{id}/invites` | The guest list. The host also gets each invite's state |
| `GET /events/{id}/game` | *Host.* The whole game: setup, players, prize pool, payouts with amounts, blinds, clock, and which timer display and layout it uses |
| `GET /presets` | What a game can be set up from, as the check-in console lists it: `game_presets` (saved setups), `blind_schedules` and `timer_layouts` (the built-ins and saved ones). Each says whose it is: `site default`, `site`, `league` or `mine` |
| `GET /events/{id}/game/results` | *Host or league member.* Finishing order with what each player put in and took home |
| `GET /events/{id}/game/log` | *Host.* The activity log, newest first. `limit` (default 200, at most 500) |
| `GET /events/{id}/game/clock` | *Host.* The tournament clock and blind schedule |
| `GET /events/{id}/game/players/{pid}/ledger` | *Host.* One player's buy-in, rebuy, add-on and cash entries |
| `GET /leagues` | Your leagues, with your role in each |
| `GET /leagues/{id}` | One league, with its seasons |
| `GET /leagues/{id}/members` | The roster |
| `GET /leagues/{id}/standings` | The leaderboard. `range=7\|30\|90\|365\|ytd\|all\|custom` (with `from`, `to`) or `season_id`; `rank=score\|net\|wins\|points` |
| `GET /leagues/{id}/players/{key}` | One player's finished games. `{key}` is a user id, or `g_<name>` for a guest without an account. Same range parameters |
| `GET /leagues/{id}/posts` | The league's posts, pinned first then newest. `limit` (default 20, at most 50), `offset` |
| `GET /leagues/{id}/requests` | *Owner or manager.* People waiting for an answer on a request to join |
| `GET /events/{id}/messages` | *Host.* The messages sent to the event's guests, and the daily limit |

Changes. The permission each needs is in the second column.

| Method and path | Needs | What it does |
|---|---|---|
| `POST /me/contacts` | `events` | Add one contact, or `{"contacts": [...]}` for up to 200 |
| `PATCH /me/contacts/{contact_id}` | `events` | Change a contact. Only what you send changes |
| `DELETE /me/contacts/{contact_id}` | `events` | Remove a contact |
| `PATCH /leagues/{league_id}/members/{member_id}` | `events` | Owner or manager. A pending contact: `name`, `email`, `phone`, keeping at least an email or a phone. A member with an account: `known_as` only; their own details are theirs. Only what you send changes |
| `POST /leagues/{league_id}/members/{member_id}/resend` | `messages` | Owner or manager. Send a pending contact their invitation again, with a fresh link |
| `DELETE /leagues/{league_id}/members/{member_id}` | `events` | Owner or manager. Remove a member, or a person added by contact who has not joined. A member with an account is told; the owner cannot be removed (`409 cannot_remove_owner`) |
| `POST /events` | `events` | Create an event. See below |
| `PATCH /events/{id}` | `events` | *Host.* Change any of the create fields except `guests` |
| `POST /events/{id}/cancel` | `events` | *Host.* Call it off and keep it: every guest is told, and it can still be duplicated |
| `DELETE /events/{id}` | `events` | *Host.* Delete for good, with guest list, messages and game. Needs a one-time `confirm` code |
| `POST /events/{id}/duplicate` | `events` | *Host.* A new event copied from this one, guest list included, nobody's answer carried over. Body: `start_at` (required), `title`, `end_at` |
| `POST /events/{id}/invites` | `events` | *Host.* Add guests: `{"guests": [...]}`. Someone already listed is skipped |
| `PATCH /events/{id}/invites/{invite_id}` | `events` | *Host.* `rsvp` (`yes`, `no`, `maybe` or `null`), `role` (`invitee` or `manager` for a co-host) |
| `DELETE /events/{id}/invites/{invite_id}` | `events` | *Host.* Take a guest off the list. On an upcoming event they are told |
| `POST /events/{id}/invites/{invite_id}/approve`, `.../deny` | `events` | *Host.* Answer a guest who asked to come or is on the waitlist. Approving tells them and, for a poker game, seats them |
| `POST /events/{id}/invitations/send` | `messages` | *Host.* Invite every approved guest who has not had an invitation. Safe to repeat |
| `POST /events/{id}/invites/{invite_id}/resend` | `messages` | *Host.* Send one guest their invitation again |
| `POST /events/{id}/nudge` | `messages` | *Host.* Remind invited guests who have not answered. Once a day per guest at most |
| `POST /events/{id}/messages` | `messages` | *Host.* Message the guests. Body: `subject`, `body`, `audience` (`yes`, `yes_maybe` or `all`; default `yes`) |
| `POST /leagues/{id}/seasons` | `events` | *Owner or manager.* Body: `name`, `start_date`, `end_date` |
| `DELETE /leagues/{id}/seasons/{season_id}` | `events` | *Owner or manager.* Games and results are untouched |
| `POST /leagues/{id}/posts` | `events` | *Owner or manager.* Body: `title`, `body`. Nobody is notified |
| `POST /leagues/{id}/requests/{request_id}/approve`, `.../deny` | `events` | *Owner or manager.* The person is told either way |
| `POST /leagues/{id}/members` | `messages` | *Owner or manager.* Add someone by `name` and `email` or `phone`. They are sent an invitation |

Creating, renaming and deleting a league, and changing who manages it, are done on the site.

Running a game. Every one of these is for the host and needs the `game` permission.

| Method and path | What it does |
|---|---|
| `PUT /events/{id}/game` | Change the game's setup. Only what you send changes |
| `PUT /events/{id}/game/blinds` | Replace the blind schedule |
| `POST /events/{id}/game/preset` | Load a setup preset: `{"preset_id": 12}` from `GET /presets`. Whatever it holds of settings, payouts, bounty and jackpot, blinds and timer layout replaces the game's. A preset with a jackpot also needs the `money` permission |
| `POST /events/{id}/game/blinds/load` | Replace the blind schedule with a saved one: `{"schedule_id": 7}`. The game gets its own copy |
| `PUT /events/{id}/game/timer` | What the Timer button shows: `layout` (a built-in key: `classic`, `black_green`, `minimalist`, `two_column`, `showcase`, `pcf`, `cardroom`) or `layout_id` (a saved layout; `0` clears), and/or `classic` (`true` for Tournament Timer Classic). A layout without `classic` switches to the layout display |
| `PUT /events/{id}/game/payouts` | Replace the payout table |
| `POST /events/{id}/game/start` | Start the game |
| `POST /events/{id}/game/finish` | Finish it. Needs a one-time `confirm` code |
| `POST /events/{id}/game/reopen` | Reopen a finished game |
| `POST /events/{id}/game/players` | Add a walk-in: `{"name": "..."}` |
| `POST /events/{id}/game/players/{pid}/buy-in` | Take the buy-in |
| `DELETE /events/{id}/game/players/{pid}/buy-in` | Reverse the buy-in |
| `POST /events/{id}/game/players/{pid}/rebuy` | One rebuy |
| `DELETE /events/{id}/game/players/{pid}/rebuy` | Take one rebuy back |
| `POST /events/{id}/game/players/{pid}/add-on` | One add-on |
| `DELETE /events/{id}/game/players/{pid}/add-on` | Take one add-on back |
| `POST /events/{id}/game/add-ons` | An add-on for several players: `{"player_ids": [...]}` |
| `POST /events/{id}/game/players/{pid}/eliminate` | Knock the player out. Optional `by_player_id`, `finish_position` |
| `POST /events/{id}/game/players/{pid}/reinstate` | Undo a knockout |
| `POST /events/{id}/game/players/{pid}/cash-in` | Cash game: `{"amount_cents": 4000}` |
| `POST /events/{id}/game/players/{pid}/cash-out` | Cash game: `{"amount_cents": 6500}`, or `null` to clear |
| `PATCH /events/{id}/game/players/{pid}` | One of `{"table": 2}`, `{"table": null}`, `{"notes": "..."}` |
| `DELETE /events/{id}/game/players/{pid}` | Remove a player who has not bought in |
| `POST /events/{id}/game/tables/rebalance` | Even out the tables |
| `POST /events/{id}/game/tables/{n}/break-up` | Break up table `n` |
| `POST /events/{id}/game/ledger/{entry_id}/void` | Void one money entry |
| `POST /events/{id}/game/clock` | `{"command": "..."}` |

#### `POST /api/v2/events`

Only `title` and `start_at` are required. The event is made exactly as the editor would make it, by the same code.

| Field | Type | Notes |
|---|---|---|
| `title` | string | Up to 200 characters |
| `start_at` | string | `2026-12-12T19:00:00-06:00` (an offset or `Z` is required), or `2026-12-12` for an all-day event |
| `end_at` | string | Same forms. Optional |
| `description`, `location`, `venue_name` | string | Optional |
| `color` | string | One of `#2563eb`, `#16a34a`, `#dc2626`, `#d97706`, `#7c3aed`, `#0891b2`, `#db2777` |
| `league_id` | integer | Put it on a league's calendar. You must own or manage the league |
| `visibility` | string | `invitees_only` (default), `league` (default with a league), or `public` (site admins only) |
| `is_poker` | boolean | Sets up a game |
| `poker` | object | `game_type` (`tournament` or `cash`), `buyin_cents`, `tables`, `seats_per_table`. The rest of the game's setup comes from your last game, as on the site |
| `requires_approval`, `hide_guest_list`, `waitlist_enabled` | boolean | `waitlist_enabled` defaults to on |
| `max_guests` | integer | For an event that is not a poker game |
| `rsvp_deadline_hours` | integer | Hours before the start |
| `reminders_enabled` | boolean | Default on |
| `reminder_minutes` | array | Minutes before the start, e.g. `[1440, 120]`. Leave out for the site's schedule; `[]` for none |
| `guests` | array | Each is `{"contact_id": 7}`, `{"user_id": 12}` or `{"name": "Dave", "email": "...", "phone": "..."}`, optionally with `"role": "manager"` |

A guest given by `contact_id` is one of your contacts: if that contact has an account, the invite reaches it. Contacts say whether they have an account only for people you already share a league or event with (v1.38.0), so name a contact by its id rather than looking for its account. A guest given by `user_id` must be someone you can already name: a member of a league you are in, one of your contacts, or yourself. Anyone else answers `guest_not_found`, so an id cannot be used to find out who has an account. A typed-in name that matches an account's username is linked to that account, as the editor does.

```bash
curl -X POST https://your-site.com/api/v2/events \
  -H 'Authorization: Bearer gnp_YOUR_TOKEN' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: december-ttt-2026' \
  -d '{"title": "December Tournament", "start_at": "2026-12-12T19:00:00-06:00",
       "is_poker": true, "poker": {"buyin_cents": 4000, "tables": 2, "seats_per_table": 9},
       "guests": [{"name": "Dave", "phone": "555-010-0199"}, {"user_id": 301}]}'
```

The answer is the event, as `GET /events/{id}` returns it, with `"created": true`. No invitation has gone out; `POST /events/{id}/invitations/send` does that.

Where a site lets only its admins create events, this answers `403 events_not_allowed`, as the calendar would.

#### Host messages and their limit

`POST /events/{id}/messages` emails or texts every guest in the audience at once, and is not counted against the per-person daily notification limit. So an event may send **10 host messages in any 24 hours**, and one person **25 across all their events**, on the site, through the API, by text command and as polls alike; the next is refused with `429 host_message_limit`, and the message says which limit was reached. A third limit counts people rather than sends: the guests one person's messages and polls reach in 24 hours, **1,000** by default, with its own switch (`host_delivery_cap_enabled`, `host_delivery_daily_cap`); a message that would pass it is refused before anything is sent. A site admin changes both under **Site Settings, General, Notifications** (`host_message_daily_cap`, `host_message_sender_daily_cap`; `0` removes a limit). Resending one guest's invitation is allowed once a day through the API (three times a day on the site); the next is `429 resend_limit`. `body` may be plain text, whose line breaks are kept, or simple HTML, which is sanitised.

#### `GET /api/v2/events/{id}/game`

Like opening the check-in console, this first brings the roster level with the guest list, so someone who just said yes is in the answer. `game` is `null` when the event has no game set up yet.

```json
{
  "ok": true,
  "data": {
    "event_id": 886,
    "cancelled": false,
    "game": {
      "session": { "status": "active", "game_type": "tournament", "buyin_cents": 2000, "rebuy_cents": 1000,
                   "rebuy_allowed": true, "starting_chips": 10000, "tables": 1, "seats_per_table": 8,
                   "payout_round_cents": 500 },
      "pool": { "prize_pool_cents": 12000, "bought_in": 6, "still_playing": 4, "eliminated": 2, "rebuys": 1,
                "chips_in_play": 70000 },
      "players": [
        { "id": 1858, "user_id": null, "name": "Dave", "status": "playing", "bought_in": true, "rebuys": 1,
          "addons": 0, "table": 1, "seat": 8, "finish_position": null, "payout_cents": 0, "points": 0 }
      ],
      "payouts": [ { "place": 1, "percentage": 50, "amount_cents": 6000, "points": 10, "label": "" } ],
      "blinds": [ { "level": 1, "is_break": false, "small_blind": 50, "big_blind": 100, "ante": 0, "minutes": 15 } ],
      "clock": { "running": true, "level": 3, "seconds_remaining": 412, "current": { "level": 3 }, "next": { "level": 4 } },
      "rebuy_level": 3
    }
  }
}
```

- A player's `status` is `not_bought_in`, `playing`, `eliminated`, or `cashed_out` (cash games).
- `payouts[].amount_cents` is what each place pays from the pool as it stands, after the game's rounding (`payout_round_cents`). The amounts add up to `prize_pool_cents`.
- `clock` is `null` until the game has a timer. Reading a running clock that has run past the end of its level moves it on, as a display does.
- A `jackpot` block is added for someone who manages the league's money.

#### `GET /api/v2/events/{id}/game/results`

```json
{
  "ok": true,
  "data": {
    "event_id": 886, "title": "November Tournament", "date": "2026-11-12", "league_id": 27,
    "game_type": "tournament", "status": "finished", "final": true,
    "prize_pool_cents": 7000, "entries": 3,
    "payouts": [ { "place": 1, "percentage": 50, "amount_cents": 3500 } ],
    "players": [
      { "player_id": 2001, "user_id": 301, "name": "Sam", "known_as": null, "finish_position": 1,
        "rebuys": 0, "addons": 0, "payout_cents": 3500, "bounties_won": 0, "bounty_cash_cents": 0, "points": 10,
        "invested_cents": 2000, "winnings_cents": 3500, "net_cents": 1500 }
    ]
  }
}
```

`final` is `false` while the game is still being played; the list is then the standings so far. A cash game is ordered by `net_cents`.

#### `GET /api/v2/leagues/{id}/standings`

```json
{
  "ok": true,
  "data": {
    "league_id": 27, "range": "all", "from": null, "to": null, "season": null, "rank": "score",
    "min_games_to_qualify": 3, "games_counted": 14,
    "standings": [
      { "position": 1, "player_key": "301", "user_id": 301, "name": "Sam", "known_as": null,
        "games": 12, "tournaments": 12, "wins": 3, "in_the_money": 7, "best_finish": 1,
        "avg_finish": 3.2, "avg_score": 71.4, "points": 64,
        "invested_cents": 26000, "winnings_cents": 31500, "net_cents": 5500, "qualified": true }
    ]
  }
}
```

These are the figures on the league's **Stats** tab, from the same query. A player with fewer than `min_games_to_qualify` games is listed with `"qualified": false` and sorts after everyone who qualifies. Pass a `player_key` to `GET /leagues/{id}/players/{key}` for that player's games.

### Reference: reading

Each answer below is the `data` object; the `{"ok": true, "data": ...}` envelope is left off.

#### `GET /api/v2/me`

```json
{
  "user":  { "id": 270, "name": "Bryce", "site_admin": false, "timezone": "America/Chicago" },
  "token": { "scopes": ["read", "events", "messages"] },
  "site":  { "name": "Game Night", "url": "https://your-site.com", "timezone": "America/Chicago", "version": "1.27.0" },
  "leagues": [ { "id": 27, "name": "Northside Poker League", "my_role": "manager", "member_count": 5 } ]
}
```

The first call to make: it says who the token is, what it may do, and which leagues it can work in. `site.timezone` is the timezone query dates are read in.

#### `GET /api/v2/events` and `GET /api/v2/events/{id}`

| Query param | Default | Notes |
|---|---|---|
| `from`, `to` | today, today + 90 days | `YYYY-MM-DD`, at most 366 days apart |
| `role` | `any` | `host` for events you run, `guest` for ones you are invited to |
| `league_id` | | Only that league's events |
| `include_cancelled` | off | `1` to include events that were called off and kept |

```json
{
  "from": "2026-12-01", "to": "2026-12-31", "count": 1,
  "events": [
    { "id": 886, "title": "December Tournament", "description": "", "location": "12 Elm St", "venue_name": "",
      "start_at": "2026-12-13T01:00:00Z", "end_at": "2026-12-13T05:00:00Z", "color": "#2563eb",
      "is_poker": true, "league_id": 27, "visibility": "league",
      "requires_approval": false, "waitlist_enabled": true,
      "rsvp": { "yes": 9, "no": 2, "maybe": 1 },
      "my_role": "host", "my_rsvp": null,
      "cancelled_at": null, "created_at": "2026-11-12T19:47:15Z",
      "url": "https://your-site.com/event.php?id=886" }
  ]
}
```

`my_role` is `host`, `guest` or `viewer` (you can see it, for example through your league, but are not on the list). `GET /events/{id}` returns one such object as `event`, with these added: `capacity` (seats, or `null` for no limit), `rsvp_deadline_hours`, `guest_list_hidden`, `league_name`, and `game` (`status`, `game_type`, `buyin_cents`, `tables`, `seats_per_table`; `null` when it is not a poker event or has no game yet).

#### `GET /api/v2/events/{id}/invites`

```json
{
  "event_id": 886, "is_host": true, "count": 2,
  "invites": [
    { "invite_id": 3583, "user_id": 301, "name": "Sam", "rsvp": "yes", "role": "manager",
      "status": "approved", "has_account": true, "email": null, "phone": null, "joined_via": null },
    { "invite_id": 3584, "user_id": null, "name": "Dave", "rsvp": null, "role": "invitee",
      "status": "waitlisted", "has_account": false, "email": null, "phone": "+15550100199", "joined_via": null }
  ]
}
```

- `invite_id` is what the guest endpoints take.
- `rsvp` is `yes`, `no`, `maybe` or `null` (not answered). `role` is `invitee` or `manager` (a co-host).
- `status`, `has_account`, `email`, `phone` and `joined_via` are for the host only. `status` is `approved`, `pending` (asked to come, waiting on you), `waitlisted` or `denied`. A guest sees approved people's `name`, `rsvp` and `role`, or `403 guest_list_hidden`.
- `email` and `phone` are filled only for a guest typed in by hand; for someone with an account they are always `null`.

#### `GET /api/v2/events/{id}/messages`

```json
{
  "event_id": 886, "count": 1, "daily_limit": 3,
  "messages": [
    { "message_id": 97, "subject": "Parking", "text": "Park on Elm.\nNot in the drive.",
      "audience": "all", "sent_by": "Bryce", "has_attachment": false, "sent_at": "2026-12-10T18:02:11Z" }
  ]
}
```

#### `GET /api/v2/events/{id}/game/log`, `.../game/clock`, `.../game/players/{pid}/ledger`

The log and a player's ledger share one entry shape, newest first:

```json
{ "id": 2652, "type": "rebuy", "player_id": 1858, "player": "Dave", "amount_cents": 1000,
  "detail": "Rebuy — $10", "voided": false, "by": "Bryce", "at": "2026-12-13T02:14:09Z" }
```

`type` is what happened (`buyin`, `rebuy`, `addon`, `eliminate`, `cashin`, `cashout`, `void` and others the console writes). The ledger is the money entries for one player; `{pid}` is the player's `id` from the game. The clock answers `{"event_id", "clock", "blinds"}` with the same `clock` and `blinds` as the game.

#### `GET /api/v2/leagues`, `GET /api/v2/leagues/{id}`, `GET /api/v2/leagues/{id}/members`

```json
{ "league": { "id": 27, "name": "Northside Poker League", "description": "", "my_role": "manager",
              "member_count": 5, "approval_mode": "auto", "hidden": false,
              "public_page": "https://your-site.com/league/northside", "created_at": "2026-07-13T21:50:26Z",
              "url": "https://your-site.com/league.php?id=27",
              "seasons": [ { "id": 4, "name": "Fall 2026", "start_date": "2026-09-01", "end_date": "2026-12-31" } ] } }
```

`my_role` is `owner`, `manager` or `member` (`admin` for a site admin who is not a member). The list returns `leagues` without `seasons`.

```json
{ "league_id": 27, "count": 3,
  "members": [
    { "member_id": 365, "user_id": 301, "name": "Sam", "known_as": "Sammy", "role": "owner", "pending": false, "joined_at": "2026-07-13T21:50:26Z" },
    { "member_id": 391, "user_id": null, "name": "Pat", "known_as": null, "role": "member", "pending": true,
      "joined_at": null, "invited_at": "2026-11-17T20:58:40Z", "email": "pat@example.com", "phone": null }
  ] }
```

A `pending` member was added by contact and has not joined; only the owner and managers see those rows, with the contact details the league entered. `known_as` is the league's own name for a member, where it differs.

#### `GET /api/v2/leagues/{id}/players/{key}`

```json
{
  "league_id": 27, "player_key": "301", "name": "Sam", "known_as": null, "is_guest": false,
  "range": "all", "from": null, "to": null, "season": null,
  "totals": { "games": 12, "tournaments": 12, "wins": 3, "in_the_money": 7, "invested_cents": 26000,
              "winnings_cents": 31500, "points": 64, "net_cents": 5500, "roi_percent": 21, "avg_score": 71.4 },
  "games": [
    { "event_id": 886, "title": "December Tournament", "date": "2026-12-12", "game_type": "tournament",
      "finish_position": 1, "field_size": 9, "score": 100, "rebuys": 0, "addons": 1, "bounties_won": 2,
      "points": 10, "invested_cents": 2500, "winnings_cents": 9000, "net_cents": 6500 }
  ]
}
```

`{key}` is the `player_key` from the standings. A guest's key contains a space, so encode it: `g_dave%20smith`.

#### `GET /api/v2/leagues/{id}/posts`, `GET /api/v2/leagues/{id}/requests`

```json
{ "league_id": 27, "count": 1, "limit": 20, "offset": 0,
  "posts": [ { "id": 37, "title": "Season opener", "text": "First game March 6.\nBring a friend.",
               "html": "First game March 6.<br />\nBring a friend.", "pinned": false,
               "author": "Sam", "posted_at": "2026-11-17T21:10:02Z" } ] }
```

```json
{ "league_id": 27, "count": 1,
  "requests": [ { "request_id": 12, "user_id": 322, "name": "Jo", "message": "Let me in", "requested_at": "2026-11-16T15:00:00Z" } ] }
```

### Reference: contacts

Your contacts are your own address book: the people you invite from. Nobody else can see them, and adding someone tells nobody.

```json
{ "count": 2, "contacts": [
    { "id": 41, "name": "Sam Ortiz", "email": "sam@example.com", "phone": null, "notes": "",
      "invite_via": "email", "has_account": true, "user_id": 301, "account_name": "Sam" },
    { "id": 57, "name": "Dave Stone", "email": null, "phone": "555-010-0199", "notes": "Brings chips",
      "invite_via": "sms", "has_account": false, "user_id": null, "account_name": null } ] }
```

- `has_account` says the contact's email or phone belongs to someone registered on the site, as the **Linked** badge does on the contacts page. Their `user_id` can be used as a guest (`{"user_id": 301}`) when adding people to an event.
- `invite_via` is how an invitation reaches a contact who has no account: `email` or `sms`. Someone with an account is reached the way they chose in their own settings.
- People with accounts are listed first, then by name.

A contact needs a `name` and at least one of `email`, `phone`. One contact per email address. `POST /me/contacts` takes one contact:

```json
{ "name": "Dave Stone", "phone": "555-010-0199", "notes": "Brings chips", "invite_via": "sms" }
```

or several, which is the way to bring in a list:

```json
{ "contacts": [ { "name": "Erin Cole", "email": "erin@example.com" }, { "name": "Fay Wu", "phone": "555-010-0142" } ] }
```

```json
{ "added":   [ { "id": 58, "name": "Erin Cole", "email": "erin@example.com", "phone": null, "notes": "", "invite_via": "email" } ],
  "skipped": [ { "index": 1, "name": "Fay Wu", "message": "Already in your contacts." } ],
  "errors":  [] }
```

With a list, each contact is taken on its own: one whose email you already have is skipped, one that is not valid is reported with its position in the list, and the rest are still added. A single contact that is not valid, or is a duplicate (`409 duplicate_contact`), is simply refused. The answer to an add does not say whether the person has an account; the list does.

`PATCH /me/contacts/{contact_id}` changes only the fields sent. Send `""` to clear the email or the phone; one of the two has to remain. `DELETE` removes the contact and leaves any event they are already invited to as it is.

### Reference: changing

#### `PATCH /api/v2/events/{id}`

Takes any of the create fields except `guests`, and changes only those. The answer is the event with `"updated": true` and `"time_changed"`.

- Moving `start_at` without sending `end_at` moves the end by the same amount, so the event keeps its length. Send `"end_at": null` to remove the end.
- An end before the start is refused (`bad_field`).
- When the start moves, the event's reminders are re-timed. Guests are not otherwise told, as on the site; `time_changed: true` is your cue to message them.
- The game's buy-in, tables and seats change only if `poker` is sent. Everything else about the game is set from the game's own Setup.
- A cancelled event answers `409 event_cancelled`: duplicate it instead.

#### `POST /api/v2/events/{id}/cancel`, `DELETE /api/v2/events/{id}`, `POST /api/v2/events/{id}/duplicate`

```json
{ "event_id": 886, "title": "December Tournament", "cancelled": true, "already_cancelled": false, "guests_notified": 12 }
```

Cancelling tells everyone on the list, whatever they answered, and keeps the event, its guest list and its game so it can be duplicated. It needs the `events` permission, not `messages`: the notice is part of calling an event off, on the site and here.

```json
{ "event_id": 886, "title": "December Tournament", "deleted": true, "guests_notified": 0 }
```

Deleting removes the event with its guest list, messages, comments and game results. Guests of an event still ahead that was not already cancelled are told. The first call answers:

```json
{ "ok": false, "error": "confirmation_required",
  "message": "Deleting \"December Tournament\" removes it for good, with its guest list (12), messages, comments and any game results. It cannot be undone. To keep the record and only call it off, use cancel. Ask the person; if they agree, send the same request again with \"confirm\": \"cfm_8d1f4c2a9b7e3d60a5c4f1e2\" (good once, for 10 minutes).",
  "confirm_code": "cfm_8d1f4c2a9b7e3d60a5c4f1e2" }
```

Duplicating takes `start_at` (required), and optionally `title` and `end_at`. The copy has the original's details, game settings, reminder schedule and guest list (people who were turned down are left off), with every answer cleared, and no end unless one is sent. The answer is the new event with `"created": true` and `"copied_from"`.

#### `POST /api/v2/events/{id}/invites`

```json
{ "guests": [ { "name": "Gil" }, { "name": "Hal", "phone": "555-010-0142" }, { "user_id": 301, "role": "manager" } ] }
```

```json
{
  "event_id": 886,
  "added": [ { "invite_id": 3838, "name": "Gil", "has_account": false, "status": "approved" },
             { "invite_id": 3839, "name": "Hal", "has_account": false, "status": "waitlisted" } ],
  "already_on_list": ["Sam"], "waitlisted": 1, "invitations_sent": false
}
```

Someone already on the list is skipped, so sending the same list twice is harmless. With a waitlist, anyone past capacity (seats times tables for a poker game, `max_guests` otherwise) is added as `waitlisted`. A hand-typed guest is also added to your contacts and, for a league event, to the league's pending list, as the editor does. At most 200 guests per event.

#### `PATCH`, `DELETE /api/v2/events/{id}/invites/{invite_id}`, and `.../approve`, `.../deny`

```json
{ "event_id": 886, "invite_id": 3836, "name": "Erin", "changed": ["rsvp"], "promoted_from_waitlist": 1 }
```

- `rsvp`: `yes`, `no`, `maybe` or `null` to clear it. The host may answer for a guest at any time; a "no" can free a seat, and `promoted_from_waitlist` says how many moved up.
- `role`: `manager` makes the guest a co-host who can do everything a host can on this event. Only someone with an account can be one (`409 needs_account`).

```json
{ "event_id": 886, "invite_id": 3839, "name": "Hal", "removed": true, "guest_notified": true }
```

```json
{ "event_id": 886, "invite_id": 3839, "name": "Hal", "status": "approved", "changed": true, "seat": "Table 1, Seat 4" }
```

Approving tells the guest and, for a poker game, puts them on the roster with a seat when tables are assigned automatically. Asking for a decision that already stands answers `"changed": false`.

#### Invitations, nudges and messages

All three answer with how many were queued, and all three need the `messages` permission and refuse a cancelled event.

```json
{ "event_id": 886, "sent": 9 }
```

| Call | Who it reaches |
|---|---|
| `POST /events/{id}/invitations/send` | Every approved guest who has not been sent an invitation. `0` means everyone already has one |
| `POST /events/{id}/invites/{invite_id}/resend` | That guest, again |
| `POST /events/{id}/nudge` | Approved guests who were invited and have not answered, at most once a day each |
| `POST /events/{id}/messages` | Approved guests in the `audience`: `yes` (said yes), `yes_maybe`, or `all` |

`sent` counts messages queued, not delivered: a guest with no email or phone on file is counted and then cannot be reached. Each guest gets it by the route they chose (email, text or WhatsApp), and someone who turned that kind of notification off does not get it. With notifications switched off for the whole site, all four answer `409 notifications_off`.

```json
{ "event_id": 886, "message_id": 97, "audience": "all", "sent": 12,
  "url": "https://your-site.com/event_message.php?token=1b0e42ed31c94e0f8a7d5b6c2f3a9e10" }
```

`url` is the page a text recipient opens to read the whole message.

#### League seasons, posts, requests and members

```json
{ "league_id": 27, "season": { "id": 8, "name": "Spring 2027", "start_date": "2027-01-01", "end_date": "2027-06-30" } }
```

A season is a named window of dates. Pass its `id` to the standings as `season_id`. Deleting one removes only the window.

```json
{ "league_id": 27, "post_id": 37, "title": "Season opener", "url": "https://your-site.com/league.php?id=27&tab=posts" }
```

A post appears in the league's feed. Nobody is notified. `body` may be plain text or simple HTML.

```json
{ "league_id": 27, "request_id": 12, "name": "Jo", "status": "approved" }
```

Approving a request to join makes the person a member; either answer is sent to them. A request already answered gives `409 already_decided`.

```json
{ "league_id": 27, "name": "Pat", "result": "pending", "message_sent": true }
```

`POST /leagues/{id}/members` takes `name` and at least one of `email`, `phone`. `result` is `pending` (no account yet: added as a pending member and sent a link to sign up), `invited` (has an account: sent an invitation to accept, and joins when they do), or `already` (on the roster or already invited; nothing sent).

### Reference: running a game

The API does not have its own rules for a poker game. Each call here runs the check-in console's own action as you, so a rebuy, a knockout or a finish does exactly what pressing the button does, and anything the console would refuse is refused here in the console's words (`409 game_refused`). `{pid}` is a player's `id` from `GET /events/{id}/game`.

Unless noted, an answer is:

```json
{
  "event_id": 886, "action": "rebuy", "changed": true, "game_status": "active",
  "player": { "id": 1858, "name": "Dave", "status": "playing", "bought_in": true, "rebuys": 1, "addons": 0,
              "table": 1, "seat": 8, "finish_position": null, "payout_cents": 0 },
  "pool": { "prize_pool_cents": 17000, "bought_in": 5, "still_playing": 5, "eliminated": 0, "rebuys": 1, "chips_in_play": 60000 }
}
```

`player` is the player the call was about, in the same shape as in the game. `changed: false` means the game was already as asked (buying in someone who is in, starting a game that is running) and nothing was done.

#### `PUT /api/v2/events/{id}/game`

| Field | Type | Notes |
|---|---|---|
| `game_type` | string | `tournament` or `cash` |
| `buyin_cents`, `rebuy_cents`, `addon_cents` | integer | |
| `starting_chips`, `addon_chips` | integer | |
| `tables`, `seats_per_table` | integer | 1 to 50, 2 to 12 |
| `auto_assign_tables` | boolean | Seat players as they buy in |
| `rebuy_allowed`, `addon_allowed` | boolean | |
| `max_rebuys` | integer | `0` for no limit |
| `rebuys_included`, `addons_included` | integer | How many come with the buy-in |
| `addons_share_rebuys` | boolean | Unused included rebuys can be taken as add-ons |
| `rebuy_cutoff_level` | integer | No rebuys once the clock is past this level; `0` for none |
| `payout_round_cents` | integer | `0`, `500`, `1000` or `2000` |
| `bounty_cents`, `bounty_points`, `bounty_optional` | | A bounty on each player, taken from the buy-in or bought on the side |
| `jackpot_cents`, `jackpot_optional` | | Needs the `money` permission too, a league event, and you managing that league |

The answer carries `session`, the setup as it now stands. Changing the number of tables or seats may reseat players.

#### `PUT /api/v2/events/{id}/game/blinds`, `PUT /api/v2/events/{id}/game/payouts`

```json
{ "levels": [
    { "small_blind": 25, "big_blind": 50, "minutes": 15 },
    { "small_blind": 50, "big_blind": 100, "minutes": 15 },
    { "is_break": true, "minutes": 10 },
    { "small_blind": 100, "big_blind": 200, "ante": 25, "minutes": 15 } ] }
```

The whole schedule is replaced. It is saved as this game's own schedule, so a schedule from the library that the game was using is not changed. A clock that has not started takes the new first level. The answer is `blinds` and `clock`.

```json
{ "places": [
    { "place": 1, "percentage": 50, "points": 10 },
    { "place": 2, "percentage": 30, "points": 6 },
    { "place": 3, "percentage": 20, "points": 3, "label": "Bubble trophy" } ] }
```

The whole table is replaced. Percentages may total at most 100. `points` count toward league standings; `ticket_cents` is an entry ticket to the game's target event; `label` names a prize that is not money. The answer is `payouts`, with what each place pays from the pool as it stands.

#### Buy-ins, rebuys, add-ons

- **Buy-in.** Taking a buy-in also marks the guest as coming and approved, and seats them when tables are assigned automatically. `DELETE` reverses it.
- **Rebuy.** One call is one rebuy. A player who was knocked out comes back into the game (`"reentered": true`), and a game that had finished reopens (`"reopened": true`). When the game has a rebuy cutoff and the clock is past it, the answer is `409 rebuys_closed`; send `{"after_cutoff": true}` to record it anyway, as the console lets a host do.
- **Add-on.** One call is one add-on. `POST /game/add-ons` with `{"player_ids": [...]}` gives one to each and answers with `recorded` and `skipped`.
- **Taking one back.** `DELETE` on the same path removes one rebuy or add-on. A single wrong entry can also be struck out with `POST /game/ledger/{entry_id}/void`, using an entry's `id` from the player's ledger; it stays in the log, marked voided.

#### Knockouts

`POST .../eliminate` gives the player the next place from the bottom (with five still in, they finish 5th) unless `finish_position` says otherwise. `by_player_id` records who knocked them out, which is what pays a bounty. When one player is left they are crowned and the game finishes by itself: the answer then has `"game_status": "finished"` and `winner`, the winning player in the same shape as `player`. `POST .../reinstate` undoes a knockout and reopens a game it had finished.

#### Start, finish, reopen

Finishing records the results and payouts, issues entry tickets, banks jackpot money to the league fund and tells the winners, so it takes two calls: the first returns a one-time `confirm_code`, the second sends it as `"confirm"`. A finished game can be reopened, except once a ticket it issued has been used or jackpot money has been paid out; then the console refuses and says why.

#### Tables

`PATCH .../players/{pid}` with `{"table": 2}` seats or moves a player (a free seat is picked), `{"table": null}` unseats them. Rebalance and break-up answer with `moves`: each player moved, with `from_table` and `to_table`.

#### `POST /api/v2/events/{id}/game/clock`

| `command` | |
|---|---|
| `play`, `pause` | Start or stop. Asking for what it is already doing answers `changed: false` |
| `next`, `previous` | Move a level |
| `add_minute`, `subtract_minute` | Adjust the time left |
| `reset_level` | Back to the start of the current level |
| `undo` | Take back the last command, within ten minutes |
| `reset` | Back to level 1, paused. Needs a one-time `"confirm"` code from a first call |

The answer is the `clock`. Every screen showing the timer follows within a few seconds.

#### A cash game

Set `{"game_type": "cash"}` in the setup. Buy-in marks a player as sitting down; `cash-in` adds to what they have put on the table and `cash-out` records what they left with, which cannot be more than is left on the table. Rebuys, add-ons, knockouts, blinds, payouts and the clock belong to tournaments and answer `409 not_a_tournament`.

### A worked example

Set up next month's game, invite the usual people, and chase the stragglers a week later.

```bash
API=https://your-site.com/api/v2
AUTH='Authorization: Bearer gnp_YOUR_TOKEN'

# 1. Copy last month's game to the new date. The guest list comes with it.
curl -X POST $API/events/886/duplicate -H "$AUTH" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: january-game-2027' \
  -d '{"start_at": "2027-01-09T19:00:00-06:00", "title": "January Tournament"}'
#    -> data.event.id is the new event, say 1022

# 2. One more guest.
curl -X POST $API/events/1022/invites -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"guests": [{"name": "Gil", "phone": "555-010-0142"}]}'

# 3. Send the invitations (needs the messages permission).
curl -X POST $API/events/1022/invitations/send -H "$AUTH"

# 4. A week later: who has not answered?
curl -H "$AUTH" $API/events/1022/invites
#    -> the invites with "rsvp": null

# 5. Remind them.
curl -X POST $API/events/1022/nudge -H "$AUTH"

# 6. The day before: tell the people coming where to park.
curl -X POST $API/events/1022/messages -H "$AUTH" -H 'Content-Type: application/json' \
  -d '{"subject": "Parking", "body": "Park on Elm, not in the drive.", "audience": "yes"}'

# 7. Afterwards: how did it come out, and where does the season stand?
curl -H "$AUTH" $API/events/1022/game/results
curl -H "$AUTH" "$API/leagues/27/standings?range=ytd&rank=points"
```

### Errors

| HTTP | `error` | Meaning |
|---|---|---|
| `400` | `bad_date`, `bad_window`, `bad_range`, `bad_rank`, `bad_role`, `bad_player_key`, `bad_json`, `bad_field`, `unknown_field`, `nothing_to_change`, `too_many_guests`, `bad_idempotency_key`, `event_not_saved` | The request is wrong; `message` says how |
| `401` | `missing_token`, `invalid_token`, `token_expired`, `league_key_not_accepted` | No usable token |
| `403` | `not_event_host`, `not_league_manager`, `guest_list_hidden`, `results_not_available`, `missing_scope`, `public_needs_admin`, `events_not_allowed` | You can see it, but may not do or read this |
| `404` | `event_not_found`, `league_not_found`, `season_not_found`, `player_not_found`, `invite_not_found`, `guest_not_found`, `contact_not_found`, `member_not_found`, `request_not_found`, `entry_not_found`, `preset_not_found`, `schedule_not_found`, `layout_not_found`, `game_not_set_up`, `not_found` | It does not exist, or it is not yours to see |
| `405` | `method_not_allowed` | The path exists but not with that method |
| `409` | `not_a_poker_event`, `event_cancelled`, `confirmation_required`, `already_decided`, `duplicate_contact`, `cannot_remove_owner`, `already_member`, `schedule_empty`, `needs_account`, `notifications_off`, `game_refused`, `rebuys_closed`, `rebuy_limit`, `not_a_tournament`, `not_a_cash_game`, `not_bought_in`, `already_out`, `not_out`, `nothing_to_undo`, `already_voided`, `player_has_money_in`, `game_finished`, `game_not_finished`, `last_table` | It cannot be done as things stand; `message` says why |
| `429` | `rate_limited`, `write_limit`, `host_message_limit` | Too many requests in a minute, changes in an hour, or host messages in a day |
| `503` | `busy` | The site was busy writing. Nothing changed; ask again |

## Connecting Claude (MCP)

The site is a Model Context Protocol server. An assistant such as Claude connects to it and gets a set of tools: list my events, who has not answered, copy last month's game to a new date, take a buy-in, knock a player out, what are the standings. Each tool is the same thing the [Personal API](#personal-api-v2) does, with the same permissions, so nothing Claude can do here differs from what you could do on the site.

### Connecting by signing in

No token to copy. Claude sends you to this site, you sign in and choose what it may do, and you land back in Claude.

**In the Claude apps (claude.ai, desktop, mobile):** open **Customize**, then **Connectors**, choose **Add custom connector**, give it a name and the address `https://your-site.com/mcp`, and add it. When Claude asks you to sign in, you are brought to this site's **Connect Claude?** page.

**In Claude Code:**

```bash
claude mcp add --transport http gamenight https://your-site.com/mcp
claude mcp login gamenight
```

The **Connect** page shows three things: the account you are signed in as (with a link to switch), where your browser will be sent afterwards, and a switch for each thing the app is asking to do. Reading is always on; switch off creating and changing events, running games, or sending messages if you do not want Claude doing them. Then **Allow**.

- The address shown is where the app really is. The name is only what the app calls itself, and this site has not checked who runs it, so the page says so. For Claude's apps the address is `claude.ai`; for Claude Code it is "a program on this computer".
- You get an email each time an app is connected to your account.
- **Settings → Security → Connected Apps** lists what is connected, what each may do, when it was last used and what it has done, with a **Disconnect** button. Changing your password disconnects everything.
- Putting a jackpot amount on a game is never granted this way.

The site must be reachable over HTTPS for the Claude apps to connect, since the sign-in starts from Anthropic's servers.

### Connecting with a token

For a program that cannot open a browser to sign in, or when you want a fixed set of permissions that does not depend on a sign-in.

1. A personal token, from **Settings → Security → API Tokens**. Give it only the permissions you want the program to have. A token with just **read** can look but not touch; add **events** to let it set up events and guest lists, **messages** to let it send invitations and messages, **game** to let it run a poker game.
2. The address: `https://your-site.com/mcp` (the Settings card shows yours).

```bash
claude mcp add --transport http gamenight https://your-site.com/mcp --header "Authorization: Bearer gnp_YOUR_TOKEN"
```

Either way, ask in your own words: "what's on my game night calendar next month", "who hasn't answered for Saturday", "set up the December tournament like November's and invite the same people". `claude mcp list` shows whether it connected, and `claude mcp remove gamenight` takes it away. To share a token setup without the token in it, a project's `.mcp.json` can read it from the environment:

```json
{ "mcpServers": { "gamenight": { "type": "http", "url": "https://your-site.com/mcp",
    "headers": { "Authorization": "Bearer ${GAMENIGHT_TOKEN}" } } } }
```

Any other MCP client that speaks Streamable HTTP works the same way, by OAuth or with an `Authorization` header.

### What Claude will and will not do on its own

- **It asks before it reaches people.** The tools that send email or texts say so in capitals in their descriptions (`send_invitations`, `nudge_guests`, `message_guests`, `resend_invitation`, `add_league_member`, `resend_league_invite`), and `cancel_event` says it tells every guest. Without the **messages** permission on the token those tools are not offered at all.
- **What reaches people or cannot be undone takes two steps.** Cancelling or deleting an event, removing a guest, messaging the guests, finishing a game and resetting the clock first answer with what would happen and a one-time code; only a second call carrying that code does it, and the code fits only that action and, for a message, that exact text. Claude asks you in between.
- **Other people's words are marked.** Event descriptions, guests' notes, join-request messages and posts reach Claude under an `untrusted` key, cut to 500 characters, and Claude is told to treat them as information, never as instructions, so a note that says "cancel every event" does nothing.
- **It names people the way you do.** "Knock out Dave" works. If two players are called Dave, the tool answers with both and does nothing, so Claude has to ask you which.
- **It cannot see or do more than you can.** Someone else's event is "not found" to it.
- **Everything is on the record.** Each tool call is listed under the token's **Recent activity** in Settings, as `Claude: player_action` and so on, and a game's own log shows your name against each buy-in and knockout. Revoking the token cuts Claude off at once.

### The tools

| Permission | Tools |
|---|---|
| `read` | `whoami`, `list_events`, `get_event`, `list_guests`, `list_messages`, `list_contacts`, `get_league`, `get_standings`, `get_player_history`, `list_league_posts`, `list_join_requests`, `get_game`, `get_results`, `get_game_log`, `list_game_presets` |
| `events` | `create_event`, `update_event`, `cancel_event`, `delete_event`, `duplicate_event`, `add_guests`, `update_guest`, `remove_guest`, `decide_guest`, `add_contacts`, `update_contact`, `delete_contact`, `update_league_member`, `remove_league_member`, `add_season`, `delete_season`, `create_league_post`, `decide_join_request` |
| `messages` | `send_invitations`, `resend_invitation`, `nudge_guests`, `message_guests`, `add_league_member`, `resend_league_invite` |
| `game` | `configure_game`, `apply_game_preset`, `set_blinds`, `apply_blind_schedule`, `set_timer_layout`, `set_payouts`, `game_status`, `add_walk_in`, `player_action`, `add_on_for_players`, `manage_tables`, `void_ledger_entry`, `clock` |

`player_action` covers one thing for one player: `buy_in`, `rebuy`, `add_on` and their undos, `eliminate`, `reinstate`, `cash_in`, `cash_out`, `seat`, `unseat`, `notes`, `remove`. Putting a jackpot amount on a game is not offered as a tool; do that in the game's Setup.

### For people building a client

- **Transport:** Streamable HTTP at `/mcp`. `POST` one JSON-RPC 2.0 message; the answer is one `application/json` body (never a stream). `GET` and `DELETE` answer `405`. A batch is refused.
- **Stateless:** no session id is issued and nothing is remembered between requests.
- **Protocol versions:** `2026-07-28` (no handshake: send the version in `params._meta` and the `MCP-Protocol-Version` header; `server/discover` describes the server), and `2025-11-25`, `2025-06-18`, `2025-03-26` (open with `initialize`; `ping` is answered). Where the `MCP-Protocol-Version`, `Mcp-Method` or `Mcp-Name` header is sent it must agree with the body, or the answer is `400` with JSON-RPC error `-32020`. An unsupported version answers `-32022` with the supported list.
- **Authentication:** `Authorization: Bearer <token>` on every request: a personal token (`gnp_...`), or an access token from the sign-in (`gna_...`). Without one: `401` with `WWW-Authenticate: Bearer resource_metadata="https://your-site.com/.well-known/oauth-protected-resource"`.
- **OAuth 2.1:** the site is its own authorization server. Discovery: `/.well-known/oauth-protected-resource` (also at `.../oauth-protected-resource/mcp`) and `/.well-known/oauth-authorization-server`. Endpoints: `/oauth/authorize`, `/oauth/token`, `/oauth/register`, `/oauth/revoke`. Authorization code with PKCE `S256` only; public clients only (`token_endpoint_auth_method: none`); `resource` must be the `/mcp` address. A client is identified by a Client ID Metadata Document (an `https` URL as `client_id`, preferred) or by Dynamic Client Registration. Redirect URIs must be `https`, or `http` on the loopback, where any port matches. Access tokens last an hour; refresh tokens rotate on every use and expire after 60 days unused; a code is good for five minutes, once, and replaying one withdraws the tokens it bought. Scopes: `read`, `events`, `game`, `messages`; a request with no `scope` is offered all four and the person chooses. The authorization response carries `iss`. An access token from the sign-in is accepted at `/mcp` only, not at `/api/v2`.
- **Capabilities:** tools only. The tool list depends on the token's permissions, so `tools/list` and `server/discover` are marked `cacheScope: "private"`.
- **Results:** a tool answers with the data as `structuredContent` and as JSON text. Something the site refuses is a normal result with `isError: true`, the reason in the text and `structuredContent.error` holding the same code the API uses (`event_not_found`, `confirmation_required`, `ambiguous_name`, `game_refused`, `missing_scope`...). Only an unknown tool or a malformed call is a JSON-RPC error (`-32602`).
- **Limits:** 120 requests a minute per token, and 60 successful uses an hour of any one tool that changes something.
- **Origin:** a request with an `Origin` header from another site is refused with `403`; the site's own origin and `localhost` are accepted.

## Security

Game Night includes several security measures:

- **Password hashing** — All passwords stored with bcrypt.
- **CSRF protection** — Every form includes a CSRF token.
- **Prepared statements** — All database queries use PDO prepared statements (no SQL injection).
- **HTML sanitization** — Post content is sanitized to prevent XSS.
- **Security headers** — CSP, X-Frame-Options, X-Content-Type-Options, and more.
- **Session security** — HTTPOnly cookies, SameSite=Lax, strict session ids, session regeneration and a fresh CSRF token on login, and every session of an account ends when its password changes or is reset.
- **Account changes need the password** — changing the username, email or phone, enabling or disabling two-factor, and deleting the account all confirm the current password; a new email is confirmed from a link sent to it before it takes effect; sign-in codes are never texted to an unverified number.
- **Sign-up abuse guards and alerts** — The sign-up form carries a honeypot and refuses a post from a client that never ran the page's script (the hidden browser-timezone field arrives empty); sign-ups, resends, verification mail and codes are rate-limited per address and per account; a verification mail carries a stop link the recipient can use, and a sign-up that never verifies is removed after 7 days. Each guard that fires is a warning in the activity log, and the admins are told once an hour per kind (text or their preferred channel; Admin → Settings → General → Security alerts). A burst alarm counts sign-up posts across every address and tells the admins when an hour's total reaches the number set under General (default 10), the case the per-address guards miss. The `fail2ban/` jail bans path scanners at the proxy.
- **Last admin protection** — The last admin account cannot be demoted or deleted.
- **File upload validation** — MIME type checking on all uploads.
- **Sign-in bridge** — Connected apps receive a 120-second, single-use, ES256-signed token carrying the username and the path to the member's profile photo; the private key never leaves the server and the token travels in a URL fragment. See [Connected Apps: Sign-In Bridge](#connected-apps-sign-in-bridge).

---

## Troubleshooting

### HTTP 500 on fresh deploy
Check file permissions on the `db/` directory. It must be writable by `www-data`:
```bash
chown -R www-data:www-data db/ www/uploads/
```

### Emails not sending
1. Verify **Enable Notifications** is on in Admin > General > Notifications.
2. Check your SMTP credentials in Admin > Email.
3. Use **Send Test Email** to diagnose. Test emails bypass the notification toggle.
4. Check the Activity Log for error messages.

### SMS not sending
1. Verify your SMS provider credentials in Admin > SMS.
2. Use **Send Test SMS** to diagnose.
3. Ensure phone numbers are in the correct format (the system normalizes to E.164).

### Calendar not showing
Check Admin > General > Features > **Enable Calendar** is toggled on.

### Users can't create events
Enable **Allow Users to Create Events** in Admin > General > Features.

### Forgot admin password
If you've lost access to the admin account, you can reset the database:
```bash
# Warning: this deletes all data
rm db/gamenight.db
# Restart the app — it will recreate the database with default admin/admin
docker compose restart
```

### Event times showing wrong
Check your timezone setting in Admin > General. All times are stored in UTC and converted for display using the configured timezone.
