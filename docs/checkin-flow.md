# Check-In Process — Flow Chart

Visual reference for the GameNight poker check-in flow. Covers the host's `checkin.php` dashboard, the walk-in QR registration via `walkin.php`, and the hand-off to `timer.php`.

> Rendered as [Mermaid](https://mermaid.js.org/). GitHub, VS Code, and most markdown viewers render these natively — just open this file.

---

## 1. High-level flow (host's perspective)

```mermaid
flowchart TD
    Start([Host opens event]) --> Cal[calendar.php<br/>Click poker event]
    Cal --> CheckIn[/checkin.php?event_id=X/]

    CheckIn --> Auth{Logged in AND<br/>admin / creator / manager?}
    Auth -- No --> Deny[403 / redirect]
    Auth -- Yes --> LoadSession{poker_sessions<br/>row exists?}

    LoadSession -- No --> Setup[Setup Screen<br/>Configure: buy-in, rebuy,<br/>addon, chips, tables]
    Setup --> InitBtn[/Click 'Create Session<br/>and Import Players'/]
    InitBtn --> InitAjax[POST init_session]
    InitAjax --> CreateRows[(INSERT poker_sessions<br/>INSERT poker_players<br/>from event_invites<br/>INSERT default payouts)]
    CreateRows --> Dash

    LoadSession -- Yes --> Dash[Dashboard<br/>status = setup / active / finished]

    Dash --> Actions{{Host actions}}
    Actions --> Toggle[Check-in / Buy-in toggle]
    Actions --> AddWalkin[Add Walk-in<br/>manually]
    Actions --> RebuyAddon[Rebuys / Add-ons<br/>+/-]
    Actions --> Cash[Cash-in / Cash-out<br/>cash game only]
    Actions --> Tables[Assign / Move /<br/>Balance Tables]
    Actions --> Elim[Eliminate /<br/>Uneliminate]
    Actions --> Remove[Remove player]
    Actions --> Config[Update settings /<br/>payouts / notes]

    Toggle --> Assign[auto_assign_table]
    AddWalkin --> Assign
    Cash --> Assign
    Assign --> Dash
    RebuyAddon --> Dash
    Tables --> Dash
    Elim --> Dash
    Remove --> Dash
    Config --> Dash

    Dash --> StartGame[/Start Session<br/>status to active/]
    StartGame --> Timer[/timer.php?event_id=X/]
    Timer --> Back{Host returns<br/>to checkin.php?}
    Back -- Yes --> Dash
    Back -- No --> Finish[/status = finished/]
    Finish --> End([Game over])

    style Setup fill:#fef3c7,stroke:#f59e0b
    style Dash fill:#dbeafe,stroke:#2563eb
    style Timer fill:#dcfce7,stroke:#16a34a
    style Deny fill:#fee2e2,stroke:#dc2626
    style CreateRows fill:#ede9fe,stroke:#7c3aed
```

---

## 2. Walk-in (guest) registration flow

How a walk-up player joins a live game by scanning the QR code at the registration table.

```mermaid
flowchart TD
    Host[Host on checkin.php] --> OpenQR[/Open walkin_display.php<br/>on iPad at table/]
    OpenQR --> EnsureTok{Event has<br/>walkin_token?}
    EnsureTok -- No --> GenTok[Generate + save<br/>walkin_token]
    EnsureTok -- Yes --> ShowQR
    GenTok --> ShowQR[Show full-screen<br/>QR code]

    ShowQR -.QR scanned.-> WalkIn[/walkin.php?event_id=X&token=Y/]
    WalkIn --> Valid{Token matches<br/>events.walkin_token?}
    Valid -- No --> Err[Error page]
    Valid -- Yes --> Rate{Rate limit<br/>under 5/hr/IP?}
    Rate -- No --> Err
    Rate -- Yes --> Form[Form: display name,<br/>email, phone]

    Form --> Submit[POST form]
    Submit --> Exist{Email matches<br/>existing user?}

    Exist -- Yes --> RSVP[Upsert event_invites<br/>rsvp = yes]
    Exist -- No --> NewUser[Create soft user account<br/>INSERT users + event_invites<br/>send verification email]

    RSVP --> SessionCheck
    NewUser --> SessionCheck{poker_sessions<br/>exists for event?}

    SessionCheck -- No --> Done1[Confirmation:<br/>'You are on the list']
    SessionCheck -- Yes --> Sync[sync_invitees<br/>+ auto_assign_table]
    Sync --> Done2[Confirmation with<br/>table + seat number]

    Done1 -.appears on.-> HostDash[Host's checkin.php<br/>player list]
    Done2 -.appears on.-> HostDash

    style ShowQR fill:#fef3c7,stroke:#f59e0b
    style Form fill:#dbeafe,stroke:#2563eb
    style Sync fill:#ede9fe,stroke:#7c3aed
    style HostDash fill:#dcfce7,stroke:#16a34a
    style Err fill:#fee2e2,stroke:#dc2626
```

---

## 3. Player state machine

The states a single `poker_players` row moves through during a game.

```mermaid
stateDiagram-v2
    [*] --> Invited: INSERT from event_invites<br/>(init_session) OR walk-in

    Invited --> CheckedIn: toggle_checkin<br/>checked_in = 1
    Invited --> BoughtIn: toggle_buyin<br/>checked_in = 1<br/>bought_in = 1
    CheckedIn --> BoughtIn: toggle_buyin

    BoughtIn --> Rebuying: update_rebuys +<br/>(respects max_rebuys)
    Rebuying --> BoughtIn: playing

    BoughtIn --> AddingOn: update_addons +
    AddingOn --> BoughtIn: playing

    BoughtIn --> Seated: auto_assign_table<br/>table_number + seat_number set

    Seated --> Eliminated: eliminate_player<br/>(tournament)<br/>finish_position set
    Eliminated --> Seated: uneliminate_player<br/>(mistake correction)

    Seated --> CashedOut: set_cashout<br/>(cash game)<br/>cash_out = amount

    Invited --> Removed: remove_player<br/>removed = 1<br/>(soft delete)
    CheckedIn --> Removed
    BoughtIn --> Removed

    Eliminated --> [*]: session finished
    CashedOut --> [*]
    Removed --> [*]
```

---

## 4. Database tables touched

```mermaid
erDiagram
    events ||--o{ event_invites : "has"
    events ||--|| poker_sessions : "has one"
    poker_sessions ||--o{ poker_players : "tracks"
    poker_sessions ||--o{ poker_payouts : "pays out"
    users ||--o{ event_invites : "RSVPs via"
    users ||--o{ poker_players : "plays as"

    events {
        int id
        string title
        string walkin_token
        int created_by
    }
    event_invites {
        int event_id
        int user_id
        string rsvp
        string event_role
    }
    poker_sessions {
        int id
        int event_id
        string status "setup|active|finished"
        int buyin
        int rebuy
        int addon
        int num_tables
        string game_type
    }
    poker_players {
        int session_id
        int user_id
        int checked_in
        int bought_in
        int rebuys
        int addons
        int table_number
        int seat_number
        int eliminated
        int finish_position
        int cash_in
        int cash_out
        int removed
    }
    poker_payouts {
        int session_id
        int place
        int percent
    }
```

---

## Quick reference — host actions → backend

All host actions POST to `/checkin_dl.php` with CSRF token and an `action` parameter. Key actions:

| UI action | `action` param | Main DB effect |
|---|---|---|
| Create session + import | `init_session` | INSERT poker_sessions, bulk INSERT poker_players |
| Check-in player | `toggle_checkin` | UPDATE checked_in; auto-assign table |
| Buy-in player | `toggle_buyin` | UPDATE checked_in=1, bought_in=1; auto-assign |
| Add walk-in manually | `add_walkin` | INSERT poker_players; auto-assign |
| Rebuy +/- | `update_rebuys` | UPDATE rebuys (bounded by max_rebuys) |
| Add-on +/- | `update_addons` | UPDATE addons |
| Move to table | `set_table` / `move_player_table` | UPDATE table_number, seat_number |
| Balance tables | `break_up_table` | UPDATE num_tables; rebalance all seats |
| Eliminate | `eliminate_player` | UPDATE eliminated=1, finish_position |
| Un-eliminate | `uneliminate_player` | UPDATE eliminated=0 |
| Cash-in (cash game) | `add_cashin` / `set_cashin` | UPDATE cash_in |
| Cash-out (cash game) | `set_cashout` | UPDATE cash_out (validates against pool) |
| Update payouts | `update_payouts` | DELETE + re-INSERT poker_payouts |
| Remove player | `remove_player` | UPDATE removed=1 (soft delete) |
| Change status | `update_status` | UPDATE status: setup → active → finished |

All of these refresh the dashboard in place — no page reload, no state lost. The host can leave for `timer.php` and come back at any time; state lives in the database.
