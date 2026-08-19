
# Chip Bonuses — implementation plan
Branch `GameNight-Payout-RSVPBonus` (off `main` @ v0.2106). Ships as **v0.2107**.

---

## 0. Spec vs. code — conflicts to settle before you start

Five things in the brief don't match what's actually in the tree. Decide these first; the rest of the plan assumes the resolutions given.

**0.1 There is no `save_setup` action.** `www/checkin_dl.php` has **`update_config`** (line 465, session row + `chip_set`) and **`update_payouts`** (line 1341, the per-place rows), and `saveSettings()` in `checkin.php` (line 4528) calls them in sequence. Bonus **definitions** ride `update_config`, exactly as `chip_set` does (line 589: written only when `array_key_exists('chip_set', $_POST)`).

**0.2 `rsvp = 'yes'` is not a reliable "RSVP'd early" signal — it is set by three code paths that have nothing to do with RSVPing.**

| Path | What it does | File / line |
|---|---|---|
| `toggle_buyin` | on the 0→1 branch, forces `event_invites.rsvp='yes'` **and** `poker_players.rsvp='yes'` for anyone not already yes | `checkin_dl.php:729-733` |
| `add_walkin` | forces `poker_players.rsvp='yes'` on every host-added walk-in | `checkin_dl.php:1237` |
| `update_rsvp` | host edits the dropdown | `checkin_dl.php:1450` |

Read literally, "award at buy-in to any player whose `rsvp='yes'`" awards to **everyone who ever buys in**, because buying in is what sets the flag. The bonus becomes a flat chip bump.

- **Minimum fix (do this):** snapshot the RSVP **before** the correction block. Extend the existing `$plName` SELECT at `checkin_dl.php:701` to `SELECT display_name, rsvp` and evaluate eligibility off `$plRow['rsvp']`. This makes "RSVP no → host buys them in anyway" correctly *not* award.
- **OWNER DECISION (2026-08-19): build the `rsvp_early` column.** Walk-ins must NOT qualify for an RSVP bonus — rewarding advance commitment is the point of the feature. Add `poker_players.rsvp_early INTEGER NOT NULL DEFAULT 0`, set only where a genuine RSVP happens (`sync_invitees()` from `event_invites.rsvp`, and `update_rsvp` when the host sets yes), never by `add_walkin` and never by the buy-in correction block. Auto-award reads `rsvp_early`, not `rsvp`. Line 1237 stays untouched.

- **Original residual gap (now resolved by the decision above):** a host-added walk-in still arrives with `rsvp='yes'` from `add_walkin`, so they qualify. Do **not** change line 1237 — the comment above it records a real bug it fixed (the RSVP-Yes filter hid freshly added players). If the owner wants walk-ins excluded, the clean answer is one more column, `poker_players.rsvp_early INTEGER NOT NULL DEFAULT 0`, set only by `sync_invitees()` and `update_rsvp` and never by `add_walkin` or the buy-in correction. Offer it; don't build it unprompted.

**0.3 checkin.php's `escHtml()` is attribute-safe** — unlike the global rule in SECURITY.md. `checkin.php:5106` appends a text node then additionally replaces `"` and `'`. The comment at 5100-5105 says so explicitly. Use `escHtml()` for both text and attribute positions in JS-built markup on this page. There is no `escAttr()` here; do not import one.

**0.4 checkin.php's CSS and JS are both inline** (`<style>` at line 124, `<script nonce>` blocks). The only external asset it loads is `/event_blinds.js` (line 120), which already carries `?v=`. **No cache-buster work is needed** unless you touch `www/style.css`, `pk-dispatch.js`, `pk-dialogs.js` or `event_blinds.js` — and this feature shouldn't.

**0.5 `checkin.php`'s local dispatcher is not `pk-dispatch.js`.** It is declared inline at `checkin.php:1010-1076` (`_token`, `_args`, `_dispatch`, then five delegated `document` listeners: click / change / keydown / mousedown / input). It sets `window.PK_DISPATCH_LOCAL = 1` at line 952 so the shared script stands down. Differences that matter:
- Argument tokens are `@value`, `@checked`, `@checked01`, `@prevValue`, `@dataU`, `@event`, `@self` (line 1010-1021). There is **no** `@dataset` or JSON parse.
- Per-event argument prefixes: click uses bare `data-a1..a4`; every other event prefixes (`data-change-a1`, `data-input-a1`, `data-keydown-a1`).
- `data-act-self` is the modal-backdrop pattern; `data-stop="1"` is a dispatch boundary.
- `_args` **stops at the first missing `aN`** — so you cannot skip an argument slot.

---

## 1. Schema (`www/db.php`, inside `db_init()`)

Put these immediately after the `poker_entry_tickets` block (currently ends line 783), before the `timer_state` CREATE at line 785. Same `try { … } catch (Exception $e) {}` shape as everything around it.

```php
    // ── Chip bonuses ────────────────────────────────────────────────────────
    // Per-session bonus definitions: a label and a chip amount a host can hand
    // out for anything they like (RSVP'd early, wore the gear, won a bracket
    // elsewhere). CHIPS ONLY — these never touch money or the prize pool, which
    // is why they live here rather than on poker_payouts. Session-scoped and
    // cascaded exactly like poker_payouts: a preset is where a reusable copy
    // lives, not this table.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poker_bonuses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id  INTEGER NOT NULL,
        label       TEXT    NOT NULL,
        chips       INTEGER NOT NULL DEFAULT 0,
        auto_rsvp   INTEGER NOT NULL DEFAULT 0,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES poker_sessions(id) ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pb_session ON poker_bonuses(session_id, sort_order, id)"); } catch (Exception $e) {}

    // Who holds which bonus. UNIQUE(player_id, bonus_id) is the duplicate guard:
    // award is INSERT OR IGNORE, so a double-tap or a re-run of the bulk action
    // cannot stack the same bonus twice. `auto` records provenance — undoing a
    // buy-in reverses only what the buy-in itself awarded, never a bonus the
    // host handed out by hand.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS poker_player_bonuses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        player_id   INTEGER NOT NULL,
        bonus_id    INTEGER NOT NULL,
        auto        INTEGER NOT NULL DEFAULT 0,
        awarded_by  INTEGER,
        awarded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(player_id, bonus_id),
        FOREIGN KEY (player_id) REFERENCES poker_players(id) ON DELETE CASCADE,
        FOREIGN KEY (bonus_id)  REFERENCES poker_bonuses(id)  ON DELETE CASCADE
    )"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ppb_player ON poker_player_bonuses(player_id)"); } catch (Exception $e) {}

    // Bonus definitions ride a game preset, same as chip_set (its own column
    // rather than inside game_config, for the same reason: the key-by-key
    // game_config diff can't see a JSON blob).
    try { $pdo->exec("ALTER TABLE payout_structures ADD COLUMN bonuses TEXT"); } catch (Exception $e) {}
```

`PRAGMA foreign_keys = ON` is set in `get_db()` (`db.php:86`), so both cascades are live for new rows. **`delete_league_cascade()` at `db.php:2335` needs no edit** — it deletes `poker_players` and `poker_sessions` explicitly, and both cascades fire off those. Add nothing there; adding an explicit `DELETE FROM poker_bonuses` before the session delete is harmless but redundant.

---

## 2. Helpers (`www/_poker_helpers.php`)

### 2.1 `pk_clean_bonuses($json): array` — new, put beside `pk_clean_chip_set()` (line 1148)

Mirrors that function's shape exactly: accepts a JSON string or array, returns a normalised array.

- cap at **20** rows (`array_slice`)
- `id` → `(int)`, kept only if `> 0` (a client-supplied id is *matched*, never trusted as authority — see 4.1)
- `label` → `trim()`, `mb_substr(0, 60)` (same cap as `prize_label`), rows with an empty label dropped
- `chips` → `max(0, min(100000000, (int)))`
- `auto_rsvp` → `0|1`
- `sort_order` → array index, renumbered from 0 (never trust the client's)
- returns `[['id'=>…, 'label'=>…, 'chips'=>…, 'auto_rsvp'=>…, 'sort_order'=>…], …]`

Also add `pk_bonuses_json($v): ?string` returning `json_encode()` of the clean array or `null` when empty — the `pk_chip_set_json()` twin, used by the preset path.

### 2.2 `get_bonuses($db, $session_id): array` — new, put beside `get_payouts()` (line ~688)

```php
SELECT id, label, chips, auto_rsvp, sort_order
FROM poker_bonuses WHERE session_id = ? ORDER BY sort_order, id
```

### 2.3 `pk_award_bonus()` / `pk_revoke_bonus()` — new

Single-pair primitives used by the buy-in hook, the single endpoint and the bulk endpoint, so the three cannot drift on logging or duplicate handling.

```php
// Returns true when a row was actually written (false = already held).
function pk_award_bonus(PDO $db, int $session_id, int $player_id, int $bonus_id,
                        ?int $actor_id, bool $auto, string $player_name, array $bonus): bool
```
- `INSERT OR IGNORE INTO poker_player_bonuses (player_id, bonus_id, auto, awarded_by) VALUES (?,?,?,?)`
- log **only** when `$stmt->rowCount() > 0`, so a no-op award writes no log line
- `pk_log($db, $session_id, $actor_id, 'bonus_award', $player_id, $player_name, null, ...)`

Revoke is the mirror: `DELETE … WHERE player_id = ? AND bonus_id = ?`, log `'bonus_revoke'` when `rowCount() > 0`.

> **Trap — `amount` must be `null`.** `poker_session_log.amount` is **cents**, and `renderLog()` in `checkin.php:4916` prints it through `formatMoney()`. Passing 500 chips as the amount renders "+$5.00" in the ledger. Chips go in `detail`, amount stays `null`.

### 2.4 `calc_pool()` — the chip math (`_poker_helpers.php:350-450`)

Add a guarded aggregate next to the existing `ticket_withheld` block (line 400-408, which is already `try`/`catch`-wrapped for pre-migration DBs — follow it):

```php
$bonus_chips = 0;
try {
    $bq = $db->prepare('SELECT COALESCE(SUM(b.chips), 0)
                        FROM poker_player_bonuses pb
                        JOIN poker_bonuses b  ON b.id = pb.bonus_id
                        JOIN poker_players  p  ON p.id = pb.player_id
                        WHERE b.session_id = ? AND p.session_id = ?
                          AND p.removed = 0 AND p.bought_in = 1');
    $bq->execute([$session_id, $session_id]);
    $bonus_chips = (int)$bq->fetchColumn();
} catch (Exception $e) { /* pre-migration DB */ }
```

Compute it inside the `else` (tournament) branch only — a cash game has no chip count. Then:

- add `'bonus_chips' => $bonus_chips,` to the return array
- extend the `chips_in_play` expression at **line 445-448**:

```php
'chips_in_play'  => ($s['game_type'] === 'tournament')
    ? ((int)$r['total_buyins'] + (int)$r['total_rebuys']) * (int)($s['starting_chips'] ?? 0)
      + (int)$r['total_addons'] * (int)($s['addon_chips'] ?? 0)
      + $bonus_chips
    : 0,
```

**This is the only place chip math changes.** Verified downstream:
- `timer_dl.php:169` builds its payload with `calc_pool()`. `timer.js:292/317-318` reads `POOL.chips_in_play`; `timer_beta.js:2029-2036` reads `j.pool.chips_in_play` for both `chipCount` and `avgStack`. Timer BETA does **not** compute chips separately — it fetches the same `timer_dl.php` payload. Both displays update with no client change.
- `checkin.php` never renders `chips_in_play` at all (grep is empty), so there is nothing to update on the console. Mention this in the changelog so nobody hunts for it.
- Avg Stack is `chips_in_play / still_playing`. Bonus chips held by a player who has since been eliminated still count in the numerator — same as their starting stack does. Consistent; leave it.

The `removed = 0` filter is what makes player removal work: soft-deleting a player (`checkin_dl.php:1322`) drops their chips out of the count with no junction-row cleanup.

### 2.5 `get_players()` — carry the badge data (line 497)

Add two derived columns to the existing SELECT so the roster arrives in one round-trip and the desktop/mobile render paths share one source:

```sql
COALESCE((SELECT SUM(b.chips) FROM poker_player_bonuses pb
          JOIN poker_bonuses b ON b.id = pb.bonus_id
          WHERE pb.player_id = pp.id), 0) AS bonus_chips,
(SELECT GROUP_CONCAT(pb.bonus_id) FROM poker_player_bonuses pb
 WHERE pb.player_id = pp.id) AS bonus_ids
```

Wrapping the whole `get_players()` query in try/catch is not the pattern here; instead ship the migration in the same release (it's `db_init()`, it runs on first request) and accept the hard dependency, as `get_payouts()` does for its own columns.

### 2.6 `pk_preset_is_modified()` — preset drift (line 1051)

Add a **section 7** after the chip-set compare at line 1111, using the same *unconditional* reasoning the chip set uses (every pre-existing preset has NULL here, and adding bonuses to an old preset is exactly what a host will want to do — a guarded compare would leave "Save preset" permanently disabled):

```php
    // 7. Bonus definitions. Same unconditional rule as the chip set above, and
    //    for the same reason: every preset predating this feature carries NULL,
    //    and adding bonuses to one is the whole point. ids are stripped before
    //    comparing — they are session rows, not preset content.
    if (pk_bonus_fingerprint(pk_clean_bonuses($struct['bonuses'] ?? null))
        !== pk_bonus_fingerprint(get_bonuses($db, $session_id))) return true;
```

`pk_bonus_fingerprint(array $rows): array` — a tiny helper next to `pk_preset_places()` that maps each row to `[label, (int)chips, (int)auto_rsvp]` in order, dropping `id`/`sort_order`.

---

## 3. Setup editor — the strip toggle and the list editor (`www/checkin.php`)

### 3.1 State

Beside `var CHIP_SET` / `loadChipSet()` (line ~2848):

```js
var BONUSES = [];        // server truth, from get_session
var BONUS_DEFS = [];     // the editor's working copy (mirrors CHIP_SET's role)
function loadBonusDefs() {
    BONUS_DEFS = (BONUSES || []).map(function (b) {
        return { id: parseInt(b.id) || 0, label: b.label || '',
                 chips: parseInt(b.chips) || 0, auto_rsvp: parseInt(b.auto_rsvp) ? 1 : 0 };
    });
}
```

Call `loadBonusDefs()` wherever `loadChipSet()` is called (in `loadSession()`'s success path).

### 3.2 The chip in the strip — `renderPayoutsPane()`, `checkin.php:2611-2621`

Add one button after Prizes, before the league-gated Jackpots branch, character-for-character in the existing idiom:

```js
h += '<button type="button" class="pk-reward-chip' + (REWARDS_UI.bonus ? ' on' : '') + '" id="chip_bonus" data-act="toggleReward" data-a1="bonus">🪙 Chip bonuses</button>';
```

Its body goes with the other `pk-reward-body` blocks (after the Prizes body, line 2645):

```js
h += '<div class="pk-reward-body' + (REWARDS_UI.bonus ? ' on' : '') + '" id="rewardBody_bonus">';
h += '<div style="font-size:.78rem;color:#64748b;margin-bottom:.5rem">Extra starting chips for anything you like &mdash; RSVP&rsquo;d early, wearing the gear, won a bracket somewhere else. <b>Chips only</b>: bonuses never touch the money or the prize pool. A player can hold any number of them, and they are one-off &mdash; a rebuy does not repeat them.</div>';
h += '<div id="bonusRows"></div>';
h += '<div style="display:flex;gap:.5rem;margin-top:.3rem;flex-wrap:wrap"><button type="button" data-act="addBonusRow">+ Add bonus</button></div>';
h += '</div>';
```

`renderBonusRows()` fills `#bonusRows` after the pane mounts — same treatment `renderChipRows()` gets in `refreshSettingsView()` (line 2342): add a `renderBonusRows();` call right beside it, and one in `openSettings()`'s render path.

### 3.3 `REWARDS_UI` + `toggleReward()`

- `checkin.php:2237` — add `bonus: false` to the initialiser.
- `initRewardsUI()` (line 2238) — add `REWARDS_UI.bonus = BONUS_DEFS.length > 0;`. Deriving from `BONUS_DEFS` (the editor copy) rather than `BONUSES` (server truth) is deliberate: it is what lets a freshly toggled-on strip with one blank seeded row survive `refreshSettingsView()`.
- `toggleReward()` (line 2969) — the function is generic, so it already flips `chip_bonus` and `rewardBody_bonus` by id with no edit. Add two clauses:
  - in the `if (!on)` branch: `if (key === 'bonus') { BONUS_DEFS = []; renderBonusRows(); }`
  - in the `else` branch: `if (key === 'bonus') { if (!BONUS_DEFS.length) addBonusRow(); else renderBonusRows(); }`

### 3.4 Row markup + handlers

Follow the `payoutRowHtml()` idiom (line 2676) — an innerHTML string carrying `data-act*` attributes — rather than `renderChipRows()`'s DOM+`addEventListener` build, because these controls should be visible to the double-dispatch sweep.

```js
function renderBonusRows() {
    var box = document.getElementById('bonusRows');
    if (!box) return;
    if (!BONUS_DEFS.length) {
        box.innerHTML = '<div style="font-size:.8rem;color:#94a3b8;padding:.3rem 0">No bonuses yet &mdash; add one.</div>';
        return;
    }
    var h = '';
    for (var i = 0; i < BONUS_DEFS.length; i++) {
        var b = BONUS_DEFS[i];
        h += '<div class="row" style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;margin-bottom:.25rem">'
           + '<input type="text" class="bonus-label" maxlength="60" placeholder="e.g. RSVP&rsquo;d early" value="' + escHtml(b.label) + '" data-act-input="setBonusLabel" data-input-a1="' + i + '" data-input-a2="@value" style="flex:2;min-width:140px">'
           + '<input type="number" class="bonus-chips" min="0" step="25" value="' + (parseInt(b.chips) || 0) + '" title="Extra starting chips" data-act-input="setBonusChips" data-input-a1="' + i + '" data-input-a2="@value" style="flex:1;min-width:80px;max-width:140px">'
           + '<label class="pk-subbox-check" style="font-size:.75rem" title="Awarded automatically when you buy in a player who RSVP&rsquo;d yes"><input type="checkbox" class="bonus-auto"' + (b.auto_rsvp ? ' checked' : '') + ' data-act-change="setBonusAuto" data-change-a1="' + i + '" data-change-a2="@checked01"> auto on RSVP yes</label>'
           + '<button type="button" data-act="removeBonusRow" data-a1="' + i + '" style="color:#ef4444;background:transparent;border:none;cursor:pointer;font-size:1rem;flex-shrink:0">&times;</button>'
           + '</div>';
    }
    box.innerHTML = h;
}
function setBonusLabel(i, v) { if (BONUS_DEFS[i]) BONUS_DEFS[i].label = v; }
function setBonusChips(i, v) { if (BONUS_DEFS[i]) BONUS_DEFS[i].chips = Math.max(0, parseInt(v) || 0); }
function setBonusAuto(i, v)  { if (BONUS_DEFS[i]) BONUS_DEFS[i].auto_rsvp = parseInt(v) ? 1 : 0; }
function addBonusRow()       { BONUS_DEFS.push({ id: 0, label: '', chips: 0, auto_rsvp: 0 }); renderBonusRows(); markSettingsDirty(); }
function removeBonusRow(i)   { BONUS_DEFS.splice(i, 1); renderBonusRows(); markSettingsDirty(); }
```

Notes that matter:
- **Index-based args are safe because every mutation that changes indices re-renders.** `removeBonusRow` splices then re-renders, so the remaining rows get fresh `data-a1` values. This is the same closure-over-`i` contract `renderChipRows()` relies on.
- **`data-input-a1` / `data-change-a1`, not `data-a1`.** The per-event prefix is mandatory here (`_args`, line 1027-1039) — the row's label input would otherwise collide with the click namespace. SECURITY.md records this exact bug (v0.2073 → fixed v0.2075).
- **No `markSettingsDirty()` calls in the three setters.** The delegated dirty tracker at `checkin.php:2529-2534` already fires on any `input`/`change` inside `.pk-sv-inline`. `addBonusRow`/`removeBonusRow` do need explicit calls (they are clicks that mutate state without an input event) — same as `addPayoutRow` (line 2695).
- All six functions are **top-level declarations**, so they resolve on `window` even when the bonus strip is toggled off and no row is rendered. That is the double-dispatch sweep's requirement.

### 3.5 Save — `saveSettings()` (line 4528)

Inside the `if (… === 'tournament')` block, beside `data.chip_set` (line 4557):

```js
data.bonuses = JSON.stringify(BONUS_DEFS.filter(function (b) { return (b.label || '').trim() !== ''; }));
```

And in the `update_config` success callback (line 4567), alongside `PAYOUTS = j.payouts || PAYOUTS;`:

```js
if (j.bonuses) { BONUSES = j.bonuses; loadBonusDefs(); }
```

Blank-labelled rows are dropped client-side and again server-side — a host who adds a row and changes their mind should not get a validation error.

---

## 4. Endpoints (`www/checkin_dl.php`)

Everything below sits **after** the shared POST + `csrf_verify()` gate at line 373-382, so it inherits both. Authorization is `verify_event_access()` (which is already `can_manage_event()` — `_poker_helpers.php:307`) or `is_owner_or_manager()`, matching whichever neighbour you sit next to.

### 4.1 Definitions — extend `update_config` (line 465)

Immediately after the `chip_set` block (line 589-592), and guarded the same way:

```php
    // Bonus definitions. Written only when the request carries the field — an
    // older client, or any caller that predates this, must not silently erase a
    // set the host entered. Rows are matched by id so a player's awards survive
    // an edit; an id not in this session is ignored, never trusted.
    if (array_key_exists('bonuses', $_POST)) {
        $res = pk_save_bonuses($db, $session_id, $_POST['bonuses'],
                               (int)$current['id'], !empty($_POST['bonus_force']));
        if (!$res['ok']) { echo json_encode($res); exit; }
    }
```

`pk_save_bonuses()` goes in `_poker_helpers.php` next to `pk_clean_bonuses()`:

1. `$want = pk_clean_bonuses($raw)`; `$have = get_bonuses($db, $session_id)` keyed by id.
2. **Match by id, scoped to this session.** For each `$want` row with `id > 0`: if that id is not in `$have`, treat it as a new row (drop the id). This is the authorization boundary — a client cannot repoint another session's bonus by guessing an id.
3. **UPDATE** matched rows (`label`, `chips`, `auto_rsvp`, `sort_order`). If `chips` changed **and** the bonus already has holders, `pk_log(… 'bonus_edit' …)` — see 5.6.
4. **INSERT** unmatched rows.
5. **DELETE** `$have` ids absent from `$want`, but **first** count holders:
   ```php
   SELECT COUNT(*) FROM poker_player_bonuses WHERE bonus_id = ?
   ```
   If `> 0` and `!$force`, return `['ok'=>false, 'error'=>'…', 'bonus_confirm'=>[['label'=>…, 'holders'=>N], …]]`. If forced, delete (the `ON DELETE CASCADE` takes the junction rows) and write one `'bonus_delete'` log line per bonus recording the label, the chips and the holder count.
6. Return `['ok'=>true]`.

Then add `'bonuses' => get_bonuses($db, $session_id),` to `update_config`'s response array (line 638-651). `calc_pool()` is already called there, so the pool comes back re-summed for free.

**Client side of the force flow:** `saveSettings()`'s `onError` callback (line 4620) checks for `errJ.bonus_confirm` and, if present, raises `pkConfirm()` naming each label and holder count ("Remove *RSVP'd early*? 6 players hold it and will lose 500 chips each."), then re-posts with `bonus_force=1`. Never a native `confirm`.

### 4.2 Auto-award at buy-in — `toggle_buyin` (line 691)

Two edits.

**(a) Snapshot the RSVP before it is corrected.** Line 701:
```php
$plName = $db->prepare('SELECT display_name, rsvp FROM poker_players WHERE id = ?');
…
$rsvp_at_buyin = (string)($plRow['rsvp'] ?? '');
```

**(b) On the 0→1 branch only** — after `auto_assign_table()` and the `'buyin'` log at line 736-738, before the ticket block:

```php
        // Auto-award: every bonus flagged auto_rsvp goes to a player who had
        // RSVP'd yes BEFORE this buy-in. The snapshot matters — the block above
        // corrects a no/blank RSVP to yes when the host takes someone's money,
        // so reading it here would award to everybody.
        if ($rsvp_at_buyin === 'yes') {
            try {
                $abq = $db->prepare('SELECT id, label, chips FROM poker_bonuses WHERE session_id = ? AND auto_rsvp = 1 ORDER BY sort_order, id');
                $abq->execute([(int)$session['id']]);
                foreach ($abq->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    pk_award_bonus($db, (int)$session['id'], $player_id, (int)$b['id'],
                                   (int)$current['id'], true, $pname, $b);
                }
            } catch (Exception $e) { /* pre-migration DB */ }
        }
```

The `elseif ($set_only)` branch at line 789 stays a pure no-op — **awards happen only on a real 0→1 transition**, so re-running bulk Buy In cannot re-award. `INSERT OR IGNORE` is the second belt.

**(c) Un-buy reversal** — in the `else` branch at line 792, after the `'unbuyin'` log (line 796) and beside the ticket un-apply that follows it (which is the model for this):

```php
        // Reverse only what the buy-in itself awarded. A bonus the host handed
        // out by hand is theirs to take back by hand — undoing a mis-tapped
        // buy-in must not quietly strip it.
        try {
            $rb = $db->prepare('SELECT pb.bonus_id, b.label, b.chips
                                FROM poker_player_bonuses pb JOIN poker_bonuses b ON b.id = pb.bonus_id
                                WHERE pb.player_id = ? AND pb.auto = 1');
            $rb->execute([$player_id]);
            foreach ($rb->fetchAll(PDO::FETCH_ASSOC) as $b) {
                pk_revoke_bonus($db, (int)$session['id'], $player_id, (int)$b['bonus_id'],
                                (int)$current['id'], $pname, $b, 'buy-in reversed');
            }
        } catch (Exception $e) { /* pre-migration DB */ }
```

Add `'players' => get_players($db, $session['id']),` to `toggle_buyin`'s response (line 819-823) so the badge repaints — the bare `SELECT *` player row it currently returns has no `bonus_chips` column. (`add_walkin` already sets this precedent, line 1249-1254, with a comment explaining exactly this class of bug.)

### 4.3 `award_bonus` — new, single pair

Put it beside `toggle_bounty` / `toggle_jackpot` (line 2178-2227), whose shape it copies:

```php
if ($action === 'award_bonus') {
    $player_id = (int)($_POST['player_id'] ?? 0);
    $bonus_id  = (int)($_POST['bonus_id'] ?? 0);
    $on        = !empty($_POST['on']);
    $session = get_session_from_player($db, $player_id);
    if (!$session) { echo json_encode(['ok' => false, 'error' => 'Player not found']); exit; }
    verify_event_access($db, $session['event_id'], $current, $isAdmin);
    // The bonus must belong to THIS session — the pairing is the authorization.
    $bq = $db->prepare('SELECT id, label, chips FROM poker_bonuses WHERE id = ? AND session_id = ?');
    $bq->execute([$bonus_id, (int)$session['id']]);
    $b = $bq->fetch(PDO::FETCH_ASSOC);
    if (!$b) { echo json_encode(['ok' => false, 'error' => 'Bonus not found for this game.']); exit; }
    …name lookup, then pk_award_bonus() / pk_revoke_bonus() with $auto = false…
    echo json_encode(['ok' => true, 'players' => get_players($db, (int)$session['id']),
                      'pool' => calc_pool($db, (int)$session['id'])]);
    exit;
}
```

The `WHERE id = ? AND session_id = ?` pairing is what stops a manager of event A awarding a bonus that belongs to event B.

### 4.4 `award_bonus_bulk` — new

Same file, next to `award_bonus`. Takes `player_ids[]` and one `bonus_id` plus `on`.

- Resolve the session **from the bonus**, then `verify_event_access()` once.
- `SELECT id, display_name FROM poker_players WHERE id IN (…) AND session_id = ? AND removed = 0` — the session filter is the authorization for the whole list; ids that don't come back are silently skipped (they're not in this game).
- Cap the list at, say, 200 ids to bound the placeholder string.
- Wrap the loop in one `beginTransaction()` / `commit()`. One `pk_log` per player that actually changed (via `pk_award_bonus`'s `rowCount()` guard), so re-running the bulk action writes nothing.
- Respond `['ok'=>true, 'changed'=>N, 'players'=>…, 'pool'=>…]`.

One request rather than `bulkAction()`'s serial fan-out (line 4330-4384) — a bonus toggle is idempotent and cheap, so there is no reason to pay N round-trips or to inherit that function's per-request failure list.

### 4.5 `get_session` — deliver the definitions

`checkin_dl.php:58-68`: add `'bonuses' => get_bonuses($db, $session['id']),` to the payload. Also add it to `init_session`'s response (line 454-460) and `load_payout_structure`'s (line 2037-2043).

---

## 5. Check-in console (`www/checkin.php`)

### 5.1 The award dialog

Add one more `.pk-modal-overlay` alongside the existing ten, after `#winnerModal` (line 848-857). Reuse the page's own pattern exactly — `data-act-self` backdrop close, `.pk-modal-actions` footer. **No native dialogs anywhere; `pkAlert`/`pkConfirm` only.**

```html
<!-- Chip bonus award dialog: per-player, or bulk for the current selection -->
<div class="pk-modal-overlay" id="bonusModal" data-act-self="closeBonusModal">
    <div class="pk-modal">
        <h3 id="bonusModalTitle">Chip bonuses</h3>
        <div class="pk-log-sub" id="bonusModalSub"></div>
        <div id="bonusModalList"></div>
        <div class="pk-modal-actions">
            <button class="pk-save" data-act="closeBonusModal">Done</button>
        </div>
    </div>
</div>
```

The list has no Save button: each checkbox commits immediately, like the jackpot/bounty ticks on the roster. Behaviour:

```js
var BONUS_MODAL_PIDS = [];      // one id, or the bulk selection

function openBonusModal(pid) { BONUS_MODAL_PIDS = [parseInt(pid)]; _openBonusModal(); }
function bulkBonus() {
    BONUS_MODAL_PIDS = Array.from(document.querySelectorAll('.pk-player-cb:checked'))
                            .map(function (cb) { return parseInt(cb.value); });
    if (!BONUS_MODAL_PIDS.length) return;
    _openBonusModal();
}
function closeBonusModal() { document.getElementById('bonusModal').classList.remove('open'); }
```

`_openBonusModal()` sets the title (player name, or "N players selected"), sets the sub-line to "Chips only — these never change the money or the prize pool.", calls `renderBonusModalList()`, and adds `.open`. If `BONUSES` is empty it renders a link-style button running `openSettings('payouts')` instead of an empty list.

`renderBonusModalList()` builds one row per definition:

```js
h += '<label class="pk-mobile-row" style="cursor:pointer">'
   + '<input type="checkbox" class="pk-check"' + (state === 'all' ? ' checked' : '')
   + ' data-act-change="bonusModalToggle" data-change-a1="' + b.id + '" data-change-a2="@checked01">'
   + '<span style="flex:1">' + escHtml(b.label) + '</span>'
   + '<span style="color:#7c3aed;font-weight:700;font-size:.8rem">+' + (parseInt(b.chips)||0).toLocaleString() + '</span>'
   + '</label>';
```

For the bulk case, compute `state` as `'all' | 'none' | 'some'` from each selected player's `bonus_ids`, and set `.indeterminate = true` on the `'some'` boxes in a small post-render pass (indeterminate is not an attribute; it has to be assigned). Ticking a `'some'` box awards to everyone in the selection.

`bonusModalToggle(bonusId, on)` posts `award_bonus` (single pid) or `award_bonus_bulk` (many), then `PLAYERS = j.players; POOL = j.pool; refreshUI(); renderBonusModalList();`.

### 5.2 The row control and badge

**Badge** — extend `rewardChips()` (line 1153) and `rewardText()` (line 1166), the two helpers explicitly documented as "ONE helper shared by the desktop table and the mobile cards so the dual render paths can't drift":

```js
    var bc = parseInt(p.bonus_chips) || 0;
    if (bc > 0) h += ' <span style="color:#7c3aed;font-weight:700;font-size:.72rem" title="Chip bonuses (extra starting chips)">🪙 +' + bc.toLocaleString() + '</span>';
```

`rewardChips()` is called in three places (lines 1730, 1736, 1739) covering eliminated / winner / playing. A player who is on the roster but not bought in shows no status chips at all (line 1741) — leave that alone; the bonus only counts once they're in.

**Control** — add a button to the Actions cell, next to Notes (`checkin.php:1776`), gated on there being any definitions:

```js
if (isTourney() && BONUSES.length) h += '<button class="pk-act-btn" title="Chip bonuses — extra starting chips" data-act="openBonusModal" data-a1="' + p.id + '">🪙 Bonus</button>';
```

and the mobile twin in `renderMobileCards()`'s `.pk-mobile-actions` block (line 1910-1919).

**Do not add a table column.** `renderPlayerRows()` computes a `colspan` for pending rows (line 1666) and an empty-state `cols` (line 1783-1785) by hand-summing conditional columns; a new one means editing both arithmetic expressions and is a well-shaped place to introduce an off-by-one. A badge plus an action button avoids it entirely.

### 5.3 Bulk bar

`renderViewContent()`, line 1477-1483. One button, inside the `isTourney()` block, after Eliminate:

```js
h += '<button title="Award or revoke a chip bonus for the selected players" data-act="bulkBonus">🪙 Bonus</button>';
```

Note it does **not** use `data-act="bulkAction"` — it opens the dialog rather than firing a per-player request.

### 5.4 Log labels and styling

- `logTagLabel()` (line 4871): add `bonus_award:'Bonus'`, `bonus_revoke:'Bonus −'`, `bonus_edit:'Bonus Edit'`, `bonus_delete:'Bonus Del'`.
- CSS beside line 4966-ish in the `<style>` block (after `.pk-log-tag.t-jackpot`):
  ```css
  .pk-log-tag.t-bonus_award,.pk-log-tag.t-bonus_edit{background:#ede9fe;color:#5b21b6}
  .pk-log-tag.t-bonus_revoke,.pk-log-tag.t-bonus_delete{background:#f1f5f9;color:#64748b}
  ```
- **Do not** add the bonus types to `PK_LEDGER_TYPES` (line 4885 / `_poker_helpers.php:973`). The comment there is explicit: only money rows can be cleared, because clearing a non-money row would strike the sentence and leave the effect. Bonuses are revoked from the dialog, not voided from the ledger.

### 5.5 Help text

`#helpModal` (line 814-833): one `<h4>Chip bonuses</h4>` paragraph after "Rebuys & Add-ons". Also extend the preset help copy at line 3299, which enumerates what a preset stores — add bonus definitions to that sentence.

### 5.6 Session-log wording

| `event_type` | `amount` | `detail` |
|---|---|---|
| `bonus_award` (auto) | `null` | `Chip bonus (auto, RSVP yes) — RSVP'd early, +500 chips` |
| `bonus_award` (manual) | `null` | `Chip bonus awarded — Wearing gear, +250 chips` |
| `bonus_revoke` | `null` | `Chip bonus revoked — RSVP'd early, −500 chips` |
| `bonus_revoke` (buy-in undo) | `null` | `Chip bonus reversed (buy-in reversed) — RSVP'd early, −500 chips` |
| `bonus_edit` | `null` | `Chip bonus "RSVP'd early" changed 500 → 750 chips (6 players hold it)` — `player_id`/`player_name` NULL |
| `bonus_delete` | `null` | `Chip bonus "Vegas group trip" deleted — 1,000 chips, 4 players affected` — `player_id`/`player_name` NULL |

`pk_log()` already strips control chars (`_poker_helpers.php:949`), so a host-typed label cannot inject a line break into the log.

---

## 6. Preset round-trip

Presets are `payout_structures` + `payout_structure_places`, with side-cars for the things that don't fit the places table: `game_config` (JSON, whitelisted int keys), `blind_levels`, `timer_config`, `chip_set`. **Bonuses get their own `bonuses` TEXT column**, for the reason recorded at `_poker_helpers.php:1099-1102`: `game_config`'s diff is key-by-key over ints and cannot see a JSON blob.

**Four edits, mirroring `chip_set` line for line:**

1. **`buildPresetFormData()`** — `checkin.php:3616`, beside the `chip_set` append at line 3647:
   ```js
   fd.append('bonuses', JSON.stringify(BONUS_DEFS.filter(function (b) { return (b.label || '').trim() !== ''; })));
   ```
   What-you-see-is-what-you-save, from the editor's array, not the session. This one function feeds both "Save As…" and "Update preset" (line 3613-3615 explains why), so both round-trip automatically.

2. **`save_payout_structure`** — `checkin_dl.php:1714`, beside the chip-set block at line 1811-1820:
   ```php
   $bonuses = null;
   if (isset($_POST['bonuses'])) {
       $bonuses = pk_bonuses_json($_POST['bonuses']);
   } elseif ($snap_sid) {
       $bonuses = pk_bonuses_json(get_bonuses($db, $snap_sid));
   }
   ```
   Then add `$bonuses` to the "nothing to save" guard at line 1841-1843 (`&& $bonuses === null`), to the UPDATE at line 1860, and to the INSERT at line 1870. `pk_bonuses_json` strips `id` before encoding — a preset must not carry another session's row ids.

3. **`load_payout_structure`** — `checkin_dl.php:1893`, beside the chip-set apply at line 2012-2015:
   ```php
   // Bonus definitions travel with the preset, copy-on-write as this session's
   // own rows. NULL means the preset predates the feature, which must leave
   // the game's own bonuses alone rather than clearing them.
   if (!empty($struct['bonuses'])) {
       pk_load_bonuses($db, $session_id, $struct['bonuses'], (int)$current['id']);
   }
   ```
   `pk_load_bonuses()` is deliberately **not** `pk_save_bonuses()`. Loading a preset is a replace, but it must not silently strip chips from players mid-game. Rule:
   - Match incoming preset rows to existing session rows **by label** (case-insensitive, trimmed). A match keeps its `id`, so its holders keep their bonus, and only `chips` / `auto_rsvp` / `sort_order` update.
   - Unmatched preset rows are inserted.
   - Existing session rows with no match in the preset **are deleted if they have no holders**, and **kept if they do** (appended after the preset's rows in sort order) — with one `bonus_keep` note?  No: keep it simpler and log nothing for the no-holder deletes, and one `'bonus_delete'`-style line is wrong here since nothing was deleted. Emit a single `pk_log(… 'bonus_edit' …)` reading `Preset "X" loaded — N bonus definitions applied, M kept because players already hold them.` That is one line that explains an otherwise confusing editor state.
   
   Add `'bonuses' => get_bonuses($db, $session_id),` to the response (line 2037-2043) and have `loadPayoutStructure()` in `checkin.php:3498` assign `BONUSES = j.bonuses || []; loadBonusDefs();` before it re-renders.

4. **Drift** — `pk_preset_is_modified()` section 7, as specced in §2.6.

---

## 7. Edge-case decisions — the summary table

| Case | Decision | Why |
|---|---|---|
| **Buy-in** | Award every `auto_rsvp` bonus, on the **0→1 transition only**, using the RSVP **snapshotted before** `toggle_buyin`'s own RSVP correction. | The correction (line 729-733) sets `rsvp='yes'` on the way in; reading it after would award to everyone. |
| **Bulk Buy In (`set=1`)** on an already-bought-in player | No-op, no award. | Matches the existing `elseif ($set_only)` contract. Re-running bulk Buy In is explicitly designed to be safe. |
| **Duplicate protection** | `UNIQUE(player_id, bonus_id)` + `INSERT OR IGNORE`, and log only when `rowCount() > 0`. | Two guards; the second also keeps the log clean when nothing changed. |
| **Buy-in undo** | Revoke only rows with `auto = 1`. Manual awards survive. | Symmetric with the ticket un-apply that sits ten lines away (line 798-814). A host's deliberate award is not the buy-in's to take back. |
| **Player removal** | Soft delete (`removed = 1`); junction rows **kept**. Chip math excludes them via `p.removed = 0`. | Removal is already reversible here — `sync_invitees()` un-removes on re-RSVP (`_poker_helpers.php:467-481`) and `add_walkin` un-removes (line 1220-1223). Deleting the awards would make that reversal lossy. |
| **RSVP flipped after buy-in** | Nothing happens, either way. `update_rsvp` neither awards nor revokes. | The bonus was earned at the moment money was taken. A host correcting the dropdown afterwards is fixing a record, not re-running the rules. The dialog is right there for a deliberate change. Say so in the auto-checkbox's `title`. |
| **Walk-in with no RSVP** | No auto-award — `rsvp` is NULL/blank. | Correct by construction. **But** see §0.2: `add_walkin` sets `rsvp='yes'`, so a host-added walk-in *does* qualify. Flag it. |
| **Rebuy / re-entry** | Never re-awarded. | Bonus chips are a one-off entry perk, not a per-entry grant. Note it in the pane's help copy. |
| **Eliminate / uneliminate** | No effect. Chips stay in `chips_in_play`. | Consistent with the existing formula, which never removes an eliminated player's starting stack either. |
| **Definition deleted mid-game** | Refuse, unless the host confirms. `pk_save_bonuses()` returns `bonus_confirm` naming each label and holder count; the client raises `pkConfirm()` and re-posts with `bonus_force=1`. Forced delete cascades and writes a `bonus_delete` log line. | Same instinct as the league-move-with-jackpot-money refusal at `checkin_dl.php:523-529`: never silently move or destroy something a player already holds. |
| **Chip amount edited mid-game** | Retroactive — every holder is re-valued, because the junction stores only the pair and `chips` is read live. Logged as `bonus_edit` when holders exist. | The overwhelmingly common case is fixing a typo, and a denormalised copy on the junction would make a typo unfixable without revoking and re-awarding per player. The log line is what explains the chip-count jump on the timer. |
| **Cash games** | Bonuses are hidden entirely. The strip section already carries `style="display:none"` when `isCash()` (line 2610), and `calc_pool` only adds `bonus_chips` on the tournament branch. | A cash game has no chip count. |

---

## 8. Ordered build sequence

1. **`www/db.php`** — the two CREATEs, two indexes, the `payout_structures.bonuses` ALTER. Restart nothing; `db_init()` runs on first request.
2. **`www/_poker_helpers.php`** — `pk_clean_bonuses`, `pk_bonuses_json`, `get_bonuses`, `pk_award_bonus`, `pk_revoke_bonus`, `pk_save_bonuses`, `pk_load_bonuses`, `pk_bonus_fingerprint`; then `calc_pool()` and `get_players()`; then section 7 of `pk_preset_is_modified()`.
3. **`www/checkin_dl.php`** — `get_session` payload; `update_config` (definitions + response); `toggle_buyin` (snapshot, auto-award, undo-revoke, `players` in the response); `award_bonus`; `award_bonus_bulk`; `save_payout_structure`; `load_payout_structure`.
   *Checkpoint:* the whole feature is now testable through `curl`/`fetch` with no UI. Verify `chips_in_play` moves.
4. **`www/checkin.php` — Setup editor**: `BONUSES`/`BONUS_DEFS`/`loadBonusDefs`, `REWARDS_UI.bonus`, the strip chip, the reward body, `renderBonusRows` + its six handlers, the `refreshSettingsView()` call, `saveSettings()` payload, the `bonus_confirm` re-post.
5. **`www/checkin.php` — console**: `#bonusModal` markup, `openBonusModal`/`bulkBonus`/`_openBonusModal`/`renderBonusModalList`/`bonusModalToggle`/`closeBonusModal`, the badge in `rewardChips`/`rewardText`, the row button (desktop + mobile), the bulk-bar button, `logTagLabel` + tag CSS, help copy.
6. **`www/checkin.php` — presets**: `buildPresetFormData()`, `loadPayoutStructure()` assignment, the preset help sentence at line 3299.
7. **Mirror every edited file to `/home/bryce/Claude/GameNight-dev/`** at the same relative path, per the per-edit rule in CLAUDE.md. Never bulk-rsync.
8. **Sweeps** (all six — this touches `db.php` and `_poker_helpers.php`, so the blast radius is every poker page):
   ```bash
   docker exec gamenight-dev sh -c 'find /var/www/html -name "*.php" | while read f; do php -l "$f" 2>&1 | grep -v "^No syntax errors"; done'
   cd ~/qa-headless && node inline_js_sweep.js
   grep -rnE "addslashes\(htmlspecialchars\(|on[a-z]+=\"[^\"]*<\?=[[:space:]]*json_encode|value=\"'\+[a-zA-Z_]" www/ --include=*.php | grep -v "escAttr(\|escHtml("
   grep -rnE 'data-[a-z0-9-]+="[^"]*""|data-[a-z0-9-]+="[^"]*&lt;\?=|data-[a-z0-9-]+="[^"]*" \+ |data-[a-z0-9-]+="<\?= *htmlspecialchars\(json_encode' www/ --include=*.php
   # double-dispatch needs an admin session — promote, run, demote (SECURITY.md 1c)
   docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"admin\" WHERE id=269");'
   cd ~/qa-headless && node double_dispatch_sweep.js
   docker exec gamenight-dev php -r '(new PDO("sqlite:/var/db/app.db"))->exec("UPDATE users SET role=\"user\" WHERE id=269");'
   node checkin_dispatch.js && node checkin_args.js && node checkin_real.js
   node preset_state_check.js && node chip_preset_check.js && node beta_check.js
   ```
   `checkin_args.js` is the one that reports `calls: 2` — it is the specific test that caught the v0.2077 double-dispatch release. Run it.
9. **User verifies at http://localhost:8080.** Then bump `www/version.php` to `0.2107`, add the `## [v0.2107]` block to `CHANGELOG.md` (Added / Changed, long-form, naming `poker_bonuses`, `poker_player_bonuses`, `calc_pool()`, `update_config`, `award_bonus`, `award_bonus_bulk`, and the "chips only, never money" rule), and stage all three with the code. PR → squash → tag `v0.2107`.

---

## 9. Manual / Playwright test checklist

Model new scripts on `~/qa-headless/chip_preset_check.js` — it logs in as `JamesTest`, opens `#setupBtn`, drives the editor through `page.evaluate()` on the page's own globals, and asserts server state by re-fetching `checkin_dl.php`. Suggested name: `bonus_check.js` (definitions + award + chip math) and `bonus_preset_check.js` (round-trip).

**Definitions editor**
1. The 🪙 chip appears in the strip for a tournament and is **absent** for a cash game (`#cfgRewardsSection` is `display:none`).
2. Toggling on reveals `#rewardBody_bonus` **and seeds one blank row**; toggling off empties `BONUS_DEFS`; the state survives `refreshSettingsView()` (open Setup → switch to Blinds → back to Payouts).
3. Add three rows, Save, reload the page: labels, chips and auto flags all come back, in order.
4. Edit a label to `Bracket "winner" <b>&</b> friend`, save, reload — renders as text in the editor **and** in the award dialog, no broken markup. Then re-run sweep 3 (the declarative-markup grep) on the tree.
5. Delete a row nobody holds → saves clean, no dialog.

**Auto-award**
6. Player A `rsvp='yes'`, an `auto_rsvp` bonus of 500 exists → Buy In → A holds it, one `bonus_award` log line reading "(auto, RSVP yes)", `POOL.chips_in_play` rises by exactly 500.
7. Player B `rsvp='no'` → Buy In → **no** award (the server still corrects B's RSVP to yes — assert both). This is the regression that §0.2's snapshot fix exists for; assert it explicitly.
8. Un-buy A → the auto bonus is revoked, one `bonus_revoke` line, chips fall by 500. Re-buy → awarded again, exactly once.
9. Manually award a second (non-auto) bonus to A, then un-buy → **only** the auto one is revoked.
10. Bulk Buy In over a selection containing A (already in) → A's log gains no new bonus row; chips unchanged.
11. Flip A's RSVP to `no` after buy-in → nothing changes.

**Dialog and bulk**
12. Row 🪙 button opens `#bonusModal`; ticking a box awards and the badge updates without a reload; unticking revokes.
13. Select three players → 🪙 Bonus in the bulk bar → a bonus held by one of them shows **indeterminate**; ticking it awards to all three in one request; `changed` counts only the two that actually changed.
14. Re-tick the same box → no new log lines (the `rowCount()` guard).
15. Every dialog on the page is a `.pk-modal-overlay`, never `window.confirm` — assert by stubbing `window.confirm`/`alert` to throw and running the flow.

**Chip math**
16. `chips_in_play` = `(buyins + rebuys) × starting + addons × addon_chips + Σ bonus chips over bought-in, non-removed holders`. Assert against a hand-computed number.
17. Remove a bonus holder → chips fall; re-add them (walk-in with the same name un-removes) → chips return.
18. Classic timer: `POOL.chips_in_play` in `/timer_dl.php?action=get_state&session_id=…` matches, and `#chipsInPlayValue` renders it.
19. Timer BETA: the same payload drives `S.chipCount` and `S.avgStack`; assert `avgStack === round(chips_in_play / still_playing)`.
20. Cash game: `bonus_chips` is 0 and `chips_in_play` is 0.

**Definition edits mid-game**
21. Change a held bonus 500 → 750, save → every holder re-values, `chips_in_play` moves by `250 × holders`, one `bonus_edit` log line.
22. Delete a held bonus → save returns `ok:false` with `bonus_confirm`; the client raises `pkConfirm`; confirming re-posts with `bonus_force=1`, the rows cascade away, chips fall, one `bonus_delete` line. **Cancelling leaves the definition intact.**

**Presets**
23. Define bonuses → "Save As…" → load onto a **second** event → the definitions arrive, ids are fresh, and nobody holds anything.
24. Preset bar: change **only** a bonus label → the bar reads MODIFIED and "Save preset" enables (this is the `pk_preset_is_modified()` section-7 path; it is the exact failure `chip_preset_check.js` was written for on the chip set).
25. "Update preset" writes it back; loading it elsewhere brings the new definitions.
26. **Legacy preset** (`bonuses` NULL) → loading it leaves the game's own bonuses untouched, and the bar does not read MODIFIED purely because both sides clean to `[]`.
27. Load a preset into a game mid-play where a player holds a bonus whose label is absent from the preset → that definition is **kept**, the holder keeps their chips, and one log line says why.

**Authorization**
28. As a user who cannot manage the event: `award_bonus`, `award_bonus_bulk` and `update_config` with `bonuses` all return 403 (`authz_smoke.js` is the place for this).
29. `award_bonus` with a `bonus_id` belonging to a different session → "Bonus not found for this game", no write.
30. GET `award_bonus` → 405; POST without `csrf_token` → 403.

---

### Critical Files for Implementation
- `/home/bryce/Claude/GameNight/www/db.php` — `db_init()` around lines 476-500 and 764-785 (the two CREATEs, indexes, `payout_structures.bonuses` ALTER)
- `/home/bryce/Claude/GameNight/www/_poker_helpers.php` — `calc_pool()` 350-450 (esp. 445-448), `get_players()` 497, `pk_log()` 947, `pk_preset_is_modified()` 1051-1114, `pk_clean_chip_set()` 1148 (the template for `pk_clean_bonuses`)
- `/home/bryce/Claude/GameNight/www/checkin_dl.php` — `get_session` 28-70, `update_config` 465-653, `toggle_buyin` 691-825, `save_payout_structure` 1714-1889, `load_payout_structure` 1893-2045, `toggle_bounty`/`toggle_jackpot` 2178-2227 (the shape for the new actions)
- `/home/bryce/Claude/GameNight/www/checkin.php` — modals 684-857, local dispatcher 1010-1076, `rewardChips`/`rewardText` 1153-1176, roster rows 1630-1789 + mobile 1791-1930, bulk bar 1475-1484, `REWARDS_UI` 2237-2244, `renderPayoutsPane` 2606-2674, `toggleReward` 2969-2998, `buildPresetFormData` 3616-3661, `bulkAction` 4330-4384, `saveSettings` 4528-4625, `logTagLabel` 4871-4888, `escHtml` 5106
- `/home/bryce/Claude/GameNight/www/timer_dl.php` — line 169, the single `calc_pool()` call that feeds both the classic timer and Timer BETA (verification only; no edit expected)