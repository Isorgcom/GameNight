<?php
/**
 * Timer BETA layout CRUD. Deliberately its own endpoint so timer_dl.php is
 * untouched by the BETA work; deleting the timer_beta.* files plus this one
 * removes the feature (the timer_layouts table just sits empty).
 *
 * Scope rules mirror timer_themes in timer_dl.php: everyone sees global
 * layouts, their own, and their leagues'; creating a global layout takes an
 * admin; attaching to a league takes that league's owner/manager; editing or
 * deleting takes the creator, the league's owner/manager, or an admin.
 *
 * pk_layout_sanitize() is the trust boundary: layouts are user-authored JSON
 * rendered into style properties on every viewer's screen, so everything is
 * whitelisted — node types, style keys, enums — numbers are clamped, colour
 * strings are pattern-checked with url()/expression() rejected outright, and
 * the tree is capped in depth and node count. Cell TEXT is intentionally
 * permissive: the renderer only ever assigns it via textContent.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_poker_helpers.php';

header('Content-Type: application/json');
$db = get_db();
$current = current_user();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ── Read a display's own layout by remote key ──────────────────────────────
 * A screen opened by scanning the QR code has to render the layout that game
 * is bound to, and it had two ways to fail: the endpoint refused it outright
 * with no login, and even signed in, get_layout below only returns layouts the
 * CALLER owns (or a global, or one of their league's). A player who scanned it
 * is neither, so both an anonymous screen and a signed-in guest fell back to a
 * built-in and showed the wrong display.
 *
 * The key already authorises viewing that timer, and this returns only the
 * layout that timer is currently showing — it cannot be pointed at any other
 * row, and it answers with the layout alone: no owner, no scope, no editable
 * flag, nothing that would let a viewer act on it. */
if ($action === 'get_layout' && ($rk = trim((string)($_GET['key'] ?? ''))) !== '') {
    $q = $db->prepare('SELECT tl.id, tl.name, tl.layout
                       FROM timer_state ts JOIN timer_layouts tl ON tl.id = ts.layout_id
                       WHERE ts.remote_key = ?');
    $q->execute([$rk]);
    $row = $q->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'name' => $row['name'],
                      'layout' => json_decode($row['layout'], true)]);
    exit;
}

if (!$current) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit; }
$isAdmin = $current['role'] === 'admin';
$uid = (int)$current['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']); exit;
}

/* ── Sanitizer ──────────────────────────────────────────────────────────── */

// Colour / gradient / border values land in element.style.*; allow hex, rgb()/
// hsl(), linear-gradient() and keywords, refuse anything that could smuggle a
// request or break out: url(), expression(), semicolons, angle brackets.
function pk_lo_style_str($v, int $max = 80): ?string {
    if (!is_string($v) || $v === '' || strlen($v) > $max) return null;
    if (preg_match('/url\s*\(|expression|javascript|@import|[;<>{}\\\\]/i', $v)) return null;
    if (!preg_match('#^[\#a-zA-Z0-9(),.%/\s_-]+$#', $v)) return null;
    return $v;
}
// Image sources are restricted to same-origin upload paths — never external
// URLs, data URIs or anything with a scheme — so a layout can't point the
// display at another host. upload.php produces exactly this shape.
function pk_lo_img($v): ?string {
    // Uploads, or the repo's own timer artwork under /img/timer_beta/ — that
    // second prefix exists so "Save as copy" of an artwork built-in (PCF)
    // keeps its backgrounds instead of the sanitizer stripping them. Nothing
    // user-writable lives there; both prefixes are same-origin, closed lists.
    if (!is_string($v)) return null;
    if (preg_match('#^/uploads/timer_layouts/[A-Za-z0-9._-]{1,120}$#', $v)) return $v;
    if (preg_match('#^/img/timer_beta/[a-z0-9._-]{1,80}$#', $v)) return $v;
    return null;
}
function pk_lo_num($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    return max($min, min($max, (float)$v));
}

/* Validates a condition expression against the SAME grammar the renderer
 * compiles ("bigBlind > 10000 and not onBreak"). The string is stored verbatim
 * once it parses; it is never evaluated here. Identifiers are whitelisted, so
 * nothing outside the renderer's value registry can be smuggled into a layout
 * — a name the display would refuse is refused at save, where the author can
 * still see the error. */
function pk_lo_cond_expr(string $src): bool {
    if ($src === '' || strlen($src) > 200) return false;
    $names = ['round','level','smallblind','bigblind','ante','playersleft','playerstotal',
              'entries','buyins','rebuys','addons','eliminated','chipcount','avgstack',
              'prizepool','tables','seats','minutesleft','secondsleft','levelchange','levelup','playereliminated','playerout',
              'running','paused','onbreak','pregame','gameover','hasante','hasrebuys',
              'mobile','tablet','desktop','pc',
              // namespaced spellings (mirror of the COND_VALUES ns block)
              'blinds.small','blinds.big','blinds.ante',
              'players.left','players.total','players.entries','players.buyins',
              'players.rebuys','players.addons','players.out',
              'chips.total','chips.avg','money.pot','table.count','table.seats',
              'clock.minutes','clock.seconds',
              'true','false','and','or','not'];
    if (!preg_match_all('/\s*(<=|>=|==|!=|<>|&&|\|\||[<>=!()]|\d+(?:\.\d+)?|[a-zA-Z][a-zA-Z0-9.]*)\s*/A',
                        $src, $m) || implode('', array_map('trim', $m[0])) !== preg_replace('/\s+/', '', $src)) {
        return false;   // something un-tokenizable in there
    }
    $toks = array_map('trim', $m[0]);
    $pos = 0; $depth = 0; $n = count($toks);
    $isName = function ($t) use ($names) {
        return preg_match('/^[a-zA-Z]/', $t) && in_array(strtolower($t), $names, true);
    };
    $peek = function () use (&$pos, $toks, $n) { return $pos < $n ? $toks[$pos] : null; };
    $operand = function () use (&$operand, &$orExpr, &$pos, &$depth, $peek, $isName) {
        $t = $peek();
        if ($t === null) return false;
        if ($t === '(') {
            $pos++; if (++$depth > 10) return false;
            if (!$orExpr()) return false;
            if ($peek() !== ')') return false;
            $pos++; $depth--;
            return true;
        }
        if (preg_match('/^\d/', $t)) { $pos++; return true; }
        if (preg_match('/^[a-zA-Z]/', $t) && !in_array(strtolower($t), ['and','or','not'], true)) {
            if (!$isName($t)) return false;
            $pos++; return true;
        }
        return false;
    };
    $cmp = function () use (&$pos, $peek, $operand) {
        if (!$operand()) return false;
        $t = $peek();
        if ($t !== null && in_array($t, ['<','<=','>','>=','=','==','!=','<>'], true)) {
            $pos++;
            return $operand();
        }
        return true;
    };
    $notExpr = function () use (&$notExpr, &$pos, $peek, $cmp) {
        $t = $peek();
        if ($t !== null && ($t === '!' || strtolower($t) === 'not')) { $pos++; return $notExpr(); }
        return $cmp();
    };
    $andExpr = function () use (&$pos, $peek, $notExpr) {
        if (!$notExpr()) return false;
        while (($t = $peek()) !== null && ($t === '&&' || strtolower($t) === 'and')) {
            $pos++;
            if (!$notExpr()) return false;
        }
        return true;
    };
    $orExpr = function () use (&$pos, $peek, $andExpr) {
        if (!$andExpr()) return false;
        while (($t = $peek()) !== null && ($t === '||' || strtolower($t) === 'or')) {
            $pos++;
            if (!$andExpr()) return false;
        }
        return true;
    };
    return $orExpr() && $pos === $n && $depth === 0;
}

function pk_lo_cond($c) {
    if ($c === null || $c === '' || $c === 'always') return null;
    $states = ['always','running','paused','on_break','pre_game','has_ante','has_rebuys','game_over'];
    if (is_string($c)) {
        if (in_array($c, $states, true)) return $c;
        // Not a state name: an expression, kept verbatim when it parses.
        $t = trim($c);
        return pk_lo_cond_expr($t) ? $t : null;
    }
    if (!is_array($c)) return null;
    $out = [];
    if (isset($c['state']) && in_array($c['state'], $states, true)) $out['state'] = $c['state'];
    foreach (['hasAnte', 'hasRebuys'] as $b) if (isset($c[$b]) && is_bool($c[$b])) $out[$b] = $c[$b];
    if (isset($c['round']) && is_string($c['round']) && preg_match('/^(all|even|odd|(<=|>=|<|>|=|!=)?\d{1,3})$/', $c['round'])) $out['round'] = $c['round'];
    return $out ?: null;
}

function pk_lo_cell($cell, array &$err): ?array {
    if (!is_array($cell)) { $err[] = 'cell must be an object'; return null; }
    $out = [];
    $text = $cell['text'] ?? '';
    if (!is_string($text) || strlen($text) > 2000) { $err[] = 'cell text too long'; return null; }
    // Strip control characters except newline; the renderer splits on \n.
    $out['text'] = preg_replace('/[^\P{C}\n]/u', '', $text);
    if (isset($cell['size'])) { $n = pk_lo_num($cell['size'], 0.5, 40); if ($n !== null) $out['size'] = $n; }
    // Chip-legend disc size, stated apart from the text size.
    if (isset($cell['chipSize'])) { $n = pk_lo_num($cell['chipSize'], 0.5, 30); if ($n !== null) $out['chipSize'] = $n; }
    foreach (['fit', 'bold', 'clockColors'] as $b) if (!empty($cell[$b])) $out[$b] = true;
    foreach (['color', 'bg', 'border'] as $k) if (isset($cell[$k])) { $v = pk_lo_style_str($cell[$k]); if ($v !== null) $out[$k] = $v; }
    foreach (['pad', 'spacing'] as $k) if (isset($cell[$k])) { $v = pk_lo_style_str($cell[$k], 32); if ($v !== null) $out[$k] = $v; }
    if (isset($cell['align']) && in_array($cell['align'], ['left', 'center', 'right'], true)) $out['align'] = $cell['align'];
    // Shared-style reference; refs to names the layout doesn't define are
    // pruned by pk_layout_sanitize once the styles map is known.
    if (isset($cell['style']) && is_string($cell['style']) && preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/', $cell['style'])) $out['style'] = $cell['style'];
    if (isset($cell['image'])) { $im = pk_lo_img($cell['image']); if ($im !== null) { $out['image'] = $im;
        if (isset($cell['imageFit']) && in_array($cell['imageFit'], ['contain', 'cover'], true)) $out['imageFit'] = $cell['imageFit']; } }
    // QR target. An ENUM, never a URL: a layout is a shareable document, so
    // letting the author supply the payload would turn any shared layout into a
    // phishing primitive aimed at a wall of screens. The renderer resolves the
    // target against the session's own remote_key; nothing from this file
    // reaches the scanner.
    if (isset($cell['qr']) && in_array($cell['qr'], ['display'], true)) $out['qr'] = $cell['qr'];
    // Chip legend. A flag, not content: the denominations come from the game,
    // never from the layout file.
    if (!empty($cell['chips'])) $out['chips'] = true;
    // Final-table seat map. `table` pins one table; absent = the busiest.
    if (!empty($cell['seats'])) {
        $out['seats'] = true;
        if (isset($cell['table'])) { $tn = pk_lo_num($cell['table'], 1, 50); if ($tn !== null) $out['table'] = (int)$tn; }
    }
    if (isset($cell['when'])) { $w = pk_lo_cond($cell['when']); if ($w !== null) $out['when'] = $w; }
    // Per-element styling: a map of element name -> {color, bold, scale}, so
    // one element inside a cell's line can differ from the rest. Names are
    // identifier-shaped; values ride the same rules as every other style.
    if (isset($cell['elStyles']) && is_array($cell['elStyles'])) {
        $es = []; $n = 0;
        foreach ($cell['elStyles'] as $k => $v) {
            if (++$n > 10) break;
            if (!is_string($k) || !preg_match('/^[a-zA-Z][a-zA-Z0-9.]{0,39}$/', $k) || !is_array($v)) continue;
            $e = [];
            if (isset($v['color'])) { $c = pk_lo_style_str($v['color'], 40); if ($c !== null) $e['color'] = $c; }
            if (isset($v['bold']) && is_bool($v['bold'])) $e['bold'] = $v['bold'];
            if (isset($v['scale'])) { $sc = pk_lo_num($v['scale'], 0.2, 4); if ($sc !== null) $e['scale'] = $sc; }
            if ($e) $es[$k] = $e;
        }
        if ($es) $out['elStyles'] = $es;
    }
    // The box's own background image: plate art that moves with the box.
    if (isset($cell['bgImage'])) { $v = pk_lo_img($cell['bgImage']); if ($v !== null) $out['bgImage'] = $v; }
    if (isset($cell['bgImageFit']) && in_array($cell['bgImageFit'], ['stretch', 'cover', 'contain'], true)) $out['bgImageFit'] = $cell['bgImageFit'];
    if (isset($cell['opacity'])) { $n = pk_lo_num($cell['opacity'], 0, 1); if ($n !== null) $out['opacity'] = $n; }
    if (isset($cell['variants']) && is_array($cell['variants'])) {
        $vs = [];
        foreach (array_slice($cell['variants'], 0, 12) as $v) {
            if (!is_array($v)) continue;
            $vo = [];
            $vc = pk_lo_cond($v['when'] ?? null);
            if ($vc !== null) $vo['when'] = $vc;
            // Only the emphasis props may vary; a variant can never reflow layout.
            if (isset($v['text']) && is_string($v['text']) && strlen($v['text']) <= 2000) $vo['text'] = preg_replace('/[^\P{C}\n]/u', '', $v['text']);
            foreach (['color', 'bg'] as $k) if (isset($v[$k])) { $cv = pk_lo_style_str($v[$k]); if ($cv !== null) $vo[$k] = $cv; }
            if (!empty($v['bold'])) $vo['bold'] = true;
            if (isset($v['opacity'])) { $n = pk_lo_num($v['opacity'], 0, 1); if ($n !== null) $vo['opacity'] = $n; }
            if ($vo) $vs[] = $vo;
        }
        if ($vs) $out['variants'] = $vs;
    }
    return $out;
}

function pk_lo_node($node, int $depth, int &$count, array &$err): ?array {
    if ($depth > 8) { $err[] = 'tree too deep'; return null; }
    if (++$count > 200) { $err[] = 'too many nodes'; return null; }
    if (!is_array($node)) { $err[] = 'node must be an object'; return null; }

    $kinds = array_intersect(['row', 'col', 'cell'], array_keys($node));
    if (count($kinds) !== 1) { $err[] = 'node needs exactly one of row/col/cell'; return null; }
    $kind = array_values($kinds)[0];
    $out = [];

    if ($kind === 'cell') {
        $c = pk_lo_cell($node['cell'], $err);
        if ($c === null) return null;
        $out['cell'] = $c;
    } else {
        if (!is_array($node[$kind])) { $err[] = "$kind must be a list"; return null; }
        $kids = [];
        foreach ($node[$kind] as $child) {
            $k = pk_lo_node($child, $depth + 1, $count, $err);
            if ($k === null) return null;
            $kids[] = $k;
        }
        $out[$kind] = $kids;
        if (isset($node['gap'])) { $v = pk_lo_style_str($node['gap'], 32); if ($v !== null) $out['gap'] = $v; }
        if (isset($node['justify']) && in_array($node['justify'], ['flex-start', 'center', 'flex-end', 'space-between', 'space-around'], true)) $out['justify'] = $node['justify'];
        if (isset($node['bgImage'])) { $v = pk_lo_img($node['bgImage']); if ($v !== null) $out['bgImage'] = $v; }
        if (isset($node['bgImageFit']) && in_array($node['bgImageFit'], ['stretch', 'cover', 'contain'], true)) $out['bgImageFit'] = $node['bgImageFit'];
        // A container's `when` hides the whole row/column (cells keep theirs
        // inside the cell spec, handled by pk_lo_cell).
        if (isset($node['when'])) { $w = pk_lo_cond($node['when']); if ($w !== null) $out['when'] = $w; }
    }
    // Shared box props (both containers and cell nodes carry these).
    if (isset($node['weight'])) { $n = pk_lo_num($node['weight'], 0, 50); if ($n !== null) $out['weight'] = $n; }
    foreach (['pad' => 32, 'bg' => 80, 'border' => 80] as $k => $max) {
        if (isset($node[$k])) { $v = pk_lo_style_str($node[$k], $max); if ($v !== null) $out[$k] = $v; }
    }
    return $out;
}

function pk_lo_bg($bg): ?array {
    if (!is_array($bg)) return null;
    $out = [];
    if (isset($bg['color'])) { $v = pk_lo_style_str($bg['color']); if ($v !== null) $out['color'] = $v; }
    if (isset($bg['gradient']) && is_array($bg['gradient']) && count($bg['gradient']) === 2) {
        $a = pk_lo_style_str($bg['gradient'][0]); $b = pk_lo_style_str($bg['gradient'][1]);
        if ($a !== null && $b !== null) $out['gradient'] = [$a, $b];
    }
    if (isset($bg['image'])) { $im = pk_lo_img($bg['image']); if ($im !== null) { $out['image'] = $im;
        if (isset($bg['imageFit']) && in_array($bg['imageFit'], ['cover', 'contain', 'stretch'], true)) $out['imageFit'] = $bg['imageFit']; } }
    return $out ?: null;
}

// One screen: name, optional display condition, background, and root tree.
function pk_lo_screen($scr, array &$err): ?array {
    if (!is_array($scr) || !isset($scr['root'])) { $err[] = 'screen needs a root'; return null; }
    $count = 0;
    $root = pk_lo_node($scr['root'], 0, $count, $err);
    if ($root === null) return null;
    $out = ['root' => $root];
    if (isset($scr['name']) && is_string($scr['name'])) $out['name'] = mb_substr(trim($scr['name']), 0, 40);
    $w = pk_lo_cond($scr['when'] ?? null);
    if ($w !== null) $out['when'] = $w;
    $bg = pk_lo_bg($scr['bg'] ?? null);
    if ($bg !== null) $out['bg'] = $bg;
    // Rotation dwell in whole seconds; floor of 2s so a bad value can never
    // strobe the display, cap of 1h. Absent/invalid = screen doesn't rotate.
    if (isset($scr['cycle']) && is_numeric($scr['cycle'])) {
        $c = (int)round((float)$scr['cycle']);
        if ($c >= 2 && $c <= 3600) $out['cycle'] = $c;
    }
    return $out;
}

// Shared named styles: name → visual-prop map. Same validators as cell props,
// so a style can never carry anything a cell couldn't. Visual props only.
function pk_lo_styles($styles): array {
    if (!is_array($styles)) return [];
    $out = [];
    $n = 0;
    foreach ($styles as $name => $st) {
        if (++$n > 20) break;
        if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/', $name)) continue;
        if (!is_array($st)) continue;
        $s = [];
        if (isset($st['size'])) { $v = pk_lo_num($st['size'], 0.5, 40); if ($v !== null) $s['size'] = $v; }
        foreach (['fit', 'bold'] as $b) if (!empty($st[$b])) $s[$b] = true;
        foreach (['color', 'bg'] as $k) if (isset($st[$k])) { $v = pk_lo_style_str($st[$k]); if ($v !== null) $s[$k] = $v; }
        foreach (['pad', 'spacing'] as $k) if (isset($st[$k])) { $v = pk_lo_style_str($st[$k], 32); if ($v !== null) $s[$k] = $v; }
        if (isset($st['align']) && in_array($st['align'], ['left', 'center', 'right'], true)) $s['align'] = $st['align'];
        if (isset($st['opacity'])) { $v = pk_lo_num($st['opacity'], 0, 1); if ($v !== null) $s['opacity'] = $v; }
        if ($s) $out[$name] = $s;
    }
    return $out;
}

// Drop style refs that point at names the (sanitized) styles map doesn't have,
// so a stored layout never carries a dangling reference.
function pk_lo_prune_style_refs(array &$node, array $valid): void {
    if (isset($node['cell']) && is_array($node['cell']) && isset($node['cell']['style'])
        && !isset($valid[$node['cell']['style']])) unset($node['cell']['style']);
    foreach (['row', 'col'] as $kids) {
        if (isset($node[$kids]) && is_array($node[$kids])) {
            foreach ($node[$kids] as &$c) if (is_array($c)) pk_lo_prune_style_refs($c, $valid);
            unset($c);
        }
    }
}


/* One trigger: {when, do:[actions], cooldown?, once?}. Actions whitelisted by
 * key; unknown keys are stripped rather than stored to fail on a display. */
function pk_lo_trigger($t, array $screenNames): ?array {
    if (!is_array($t)) return null;
    $w = pk_lo_cond($t['when'] ?? null);
    if ($w === null) return null;            // a trigger with no condition never fires
    $acts = [];
    foreach (array_slice((array)($t['do'] ?? []), 0, 5) as $a) {
        if (!is_array($a)) continue;
        $act = [];
        if (isset($a['sound']) && is_string($a['sound'])) {
            $v = $a['sound'];
            $presets = ['buzzer','chime','casino','horn','countdown','double','descending','five3s','tick','pulse','chirp','gentle'];
            if (preg_match('/^preset:([a-z0-9]+)$/', $v, $m) && in_array($m[1], $presets, true)) $act['sound'] = $v;
            elseif (preg_match('#^/uploads/timer_sounds/[A-Za-z0-9._-]{1,160}$#', $v)) $act['sound'] = $v;
        }
        if (isset($a['takeover']) && is_string($a['takeover']) && in_array($a['takeover'], $screenNames, true)) {
            $act['takeover'] = $a['takeover'];
            $secs = pk_lo_num($a['seconds'] ?? 8, 1, 120);
            $act['seconds'] = (int)($secs ?? 8);
        }
        if (!empty($a['flash'])) $act['flash'] = 'screen';
        if (isset($a['announce']) && is_string($a['announce']) && $a['announce'] !== '') {
            $act['announce'] = mb_substr(preg_replace('/[^\P{C}\n]/u', '', $a['announce']), 0, 200);
        }
        if ($act) $acts[] = $act;
    }
    if (!$acts) return null;                 // all-stripped trigger is nothing
    $outT = ['when' => $w, 'do' => $acts];
    $cd = pk_lo_num($t['cooldown'] ?? null, 0, 3600);
    if ($cd !== null && $cd > 0) $outT['cooldown'] = (int)$cd;
    if (!empty($t['once'])) $outT['once'] = true;
    return $outT;
}

function pk_lo_apply_triggers(array &$out, array $rawTriggers): void {
    if (!$rawTriggers) return;
    $names = array_map(function ($s) { return $s['name'] ?? ''; }, $out['screens'] ?? []);
    $ts = [];
    foreach ($rawTriggers as $t) {
        $c = pk_lo_trigger($t, $names);
        if ($c !== null) $ts[] = $c;
    }
    if ($ts) $out['triggers'] = $ts;
}

function pk_layout_sanitize($doc, array &$err): ?array {
    if (is_string($doc)) $doc = json_decode($doc, true);
    if (!is_array($doc)) { $err[] = 'not a JSON object'; return null; }
    if (strlen(json_encode($doc)) > 131072) { $err[] = 'layout too large'; return null; }
    $out = ['v' => 1];

    $sharedStyles = pk_lo_styles($doc['styles'] ?? null);
    if ($sharedStyles) $out['styles'] = $sharedStyles;

    // A layout drawn around background artwork has to keep the shape it was
    // drawn at, or the picture and the text can never agree: the picture gets
    // cropped to fill the screen while the text spreads across all of it.
    // Locking the ratio letterboxes both together instead.
    if (isset($doc['aspect'])) { $n = pk_lo_num($doc['aspect'], 0.4, 4.0); if ($n !== null) $out['aspect'] = $n; }

    // Triggers: fire actions when a condition BECOMES true. Same `when`
    // grammar as everything else; actions whitelisted by key; sounds may only
    // be a known preset or our own sound uploads; a takeover must name a
    // screen this layout actually has (validated after screens are built,
    // below). Classic's update_sounds stores raw values — not copied here.
    $rawTriggers = (isset($doc['triggers']) && is_array($doc['triggers'])) ? array_slice($doc['triggers'], 0, 20) : [];

    // Layout-level custom elements: a name->plain-text map. Names are
    // identifier-safe (so they can appear as <name> in cell text); values are
    // plain text only (never HTML — the renderer assigns them via textContent).
    if (isset($doc['customElements']) && is_array($doc['customElements'])) {
        $ce = [];
        $n = 0;
        foreach ($doc['customElements'] as $k => $v) {
            if (++$n > 30) break;
            if (!is_string($k) || !preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,39}$/', $k)) continue;
            if (!is_string($v) || strlen($v) > 500) continue;
            $ce[$k] = preg_replace('/[^\P{C}\n]/u', '', $v);
        }
        if ($ce) $out['customElements'] = $ce;
    }

    // Multi-screen form (break screens etc.) or single-screen shorthand.
    if (isset($doc['screens']) && is_array($doc['screens'])) {
        $screens = [];
        foreach (array_slice($doc['screens'], 0, 6) as $scr) {
            $s = pk_lo_screen($scr, $err);
            if ($s === null) return null;
            $screens[] = $s;
        }
        if (!$screens) { $err[] = 'no valid screens'; return null; }
        foreach ($screens as &$s) pk_lo_prune_style_refs($s['root'], $sharedStyles);
        unset($s);
        $out['screens'] = $screens;
        pk_lo_apply_triggers($out, $rawTriggers);
        return $out;
    }

    $bg = pk_lo_bg($doc['bg'] ?? null);
    if ($bg !== null) $out['bg'] = $bg;
    if (!isset($doc['root'])) { $err[] = 'missing root'; return null; }
    $count = 0;
    $root = pk_lo_node($doc['root'], 0, $count, $err);
    if ($root === null) return null;
    pk_lo_prune_style_refs($root, $sharedStyles);
    $out['root'] = $root;
    $out['screens'] = $out['screens'] ?? [];   // shorthand: no named screens for takeovers
    pk_lo_apply_triggers($out, $rawTriggers);
    unset($out['screens']);
    return $out;
}

/* Who may modify this stored layout row? Mirrors delete_theme in timer_dl.php. */
function pk_lo_may_modify(PDO $db, array $row, int $uid, bool $isAdmin): bool {
    if ($isAdmin) return true;
    if ((int)$row['is_global']) return false;
    $lid = (int)($row['league_id'] ?? 0);
    if ($lid > 0) {
        $q = $db->prepare('SELECT role FROM league_members WHERE league_id = ? AND user_id = ?');
        $q->execute([$lid, $uid]);
        return in_array($q->fetchColumn() ?: '', ['owner', 'manager'], true);
    }
    return (int)$row['created_by'] === $uid;
}

/* ── Image lifecycle ────────────────────────────────────────────────────── */

// Every /uploads/timer_layouts/... file a layout references (screen backgrounds
// + image cells), as a set of basenames.
function pk_lo_image_names($layout): array {
    $names = [];
    $scan = function ($node) use (&$scan, &$names) {
        if (!is_array($node)) return;
        foreach (['image', 'bgImage'] as $k) {
            if (isset($node[$k]) && is_string($node[$k]) && preg_match('#^/uploads/timer_layouts/([A-Za-z0-9._-]{1,120})$#', $node[$k], $m)) {
                $names[$m[1]] = true;
            }
        }
        if (isset($node['bg']) && is_array($node['bg'])) $scan($node['bg']);
        if (isset($node['cell']) && is_array($node['cell'])) $scan($node['cell']);
        foreach (['row', 'col', 'screens'] as $kids) {
            if (isset($node[$kids]) && is_array($node[$kids])) foreach ($node[$kids] as $c) $scan($c);
        }
        if (isset($node['root'])) $scan($node['root']);
    };
    $scan(is_string($layout) ? json_decode($layout, true) : $layout);
    return $names;
}

// Every /uploads/timer_sounds/... file a layout's triggers reference.
function pk_lo_sound_names($layout): array {
    $doc = is_string($layout) ? json_decode($layout, true) : $layout;
    $names = [];
    foreach (($doc['triggers'] ?? []) as $t) {
        foreach (($t['do'] ?? []) as $a) {
            if (isset($a['sound']) && is_string($a['sound'])
                && preg_match('#^/uploads/timer_sounds/([A-Za-z0-9._-]{1,160})$#', $a['sound'], $m)) {
                $names[$m[1]] = true;
            }
        }
    }
    return $names;
}

function pk_lo_gc_sounds(PDO $db, array $candidateNames, int $exceptId): void {
    if (!$candidateNames) return;
    $rows = $db->prepare('SELECT layout FROM timer_layouts WHERE id != ?');
    $rows->execute([$exceptId]);
    $stillUsed = [];
    foreach ($rows as $r) {
        foreach (pk_lo_sound_names($r['layout']) as $n => $_) $stillUsed[$n] = true;
    }
    foreach ($candidateNames as $n => $_) {
        if (isset($stillUsed[$n])) continue;
        if (!preg_match('/^[A-Za-z0-9._-]{1,160}$/', $n)) continue;
        @unlink(__DIR__ . '/uploads/timer_sounds/' . $n);
    }
}

// Delete timer-layout image files that are no longer referenced by ANY layout
// (excluding $exceptId, the row being updated/deleted). Only ever unlinks inside
// /uploads/timer_layouts/, and only names that pass the strict pattern.
function pk_lo_gc_images(PDO $db, array $candidateNames, int $exceptId): void {
    if (!$candidateNames) return;
    $rows = $db->prepare('SELECT layout FROM timer_layouts WHERE id != ?');
    $rows->execute([$exceptId]);
    $stillUsed = [];
    foreach ($rows as $r) {
        foreach (pk_lo_image_names($r['layout']) as $n => $_) $stillUsed[$n] = true;
    }
    foreach (array_keys($candidateNames) as $name) {
        if (isset($stillUsed[$name])) continue;                 // another layout keeps it
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name)) continue;   // paranoia
        $path = __DIR__ . '/uploads/timer_layouts/' . $name;
        if (is_file($path)) @unlink($path);
    }
}

/* ── Actions ────────────────────────────────────────────────────────────── */

if ($action === 'get_layouts') {
    $stmt = $db->prepare(
        'SELECT tl.id, tl.name, tl.is_global, tl.created_by, tl.league_id, l.name AS league_name
         FROM timer_layouts tl
         LEFT JOIN leagues l ON l.id = tl.league_id
         WHERE tl.is_global = 1
            OR tl.created_by = ?
            OR tl.league_id IN (SELECT league_id FROM league_members WHERE user_id = ?)
         ORDER BY tl.is_global DESC, LOWER(tl.name)');
    $stmt->execute([$uid, $uid]);
    echo json_encode(['ok' => true, 'layouts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'get_layout') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT * FROM timer_layouts WHERE id = ? AND (is_global = 1 OR created_by = ?
            OR league_id IN (SELECT league_id FROM league_members WHERE user_id = ?))');
    $stmt->execute([$id, $uid, $uid]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'name' => $row['name'],
        'is_global' => (int)$row['is_global'], 'league_id' => $row['league_id'] ? (int)$row['league_id'] : null,
        'created_by' => (int)$row['created_by'],
        'layout' => json_decode($row['layout'], true),
        'editable' => pk_lo_may_modify($db, $row, $uid, $isAdmin)]);
    exit;
}

// ─── GET: list_images ──────────────────────────────────────
// The images this user can already point a layout at: their OWN uploads
// (filenames carry the uploader's id — u<id>_<hash>) plus the app's shipped
// timer art (/img/timer_beta). Nobody is shown another user's uploads: the
// URL would work if guessed (static files), but the picker does not browse
// other people's libraries for them.
// ─── POST: upload_sound ────────────────────────────────────
// Trigger audio. Deliberately NOT the classic timer's upload path: that one
// drops unvalidated files flat in /uploads with no cap and no GC. This one
// mirrors upload_image — byte-sniffed MIME, provenance filename, its own
// folder so lifecycle GC can sweep it, and the shared per-user daily cap.
if ($action === 'upload_sound') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
    if (empty($_FILES['sound']) || $_FILES['sound']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded.']); exit;
    }
    $file = $_FILES['sound'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $exts = ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
             'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg',
             'audio/webm' => 'webm', 'audio/aac' => 'aac'];
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($exts[$mime])) { echo json_encode(['ok' => false, 'error' => 'Only MP3, M4A, WAV, OGG, WebM and AAC audio is allowed.']); exit; }
    if ($file['size'] > 5 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'File too large (max 5 MB).']); exit; }
    if (!$isAdmin) {
        $capQ = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action LIKE 'uploaded image:%' AND created_at > datetime('now', '-1 day')");
        $capQ->execute([$uid]);
        if ((int)$capQ->fetchColumn() >= MAX_UPLOADS_PER_DAY) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Daily upload limit reached — try again tomorrow.']); exit; }
    }
    $dir = __DIR__ . '/uploads/timer_sounds/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Storage unavailable.']); exit; }
    $name = 'u' . $uid . '_' . bin2hex(random_bytes(16)) . '.' . $exts[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to save file.']); exit; }
    db_log_activity($uid, 'uploaded image: timer sound ' . $name);   // same counter the cap reads
    echo json_encode(['ok' => true, 'url' => '/uploads/timer_sounds/' . $name]);
    exit;
}

// ─── GET: list_sounds ──────────────────────────────────────
// The user's own uploaded sounds; presets are client-side and cost nothing.
if ($action === 'list_sounds') {
    $out = [];
    $dir = __DIR__ . '/uploads/timer_sounds';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (!preg_match('/^u' . $uid . '_[a-f0-9]+\.(mp3|m4a|wav|ogg|webm|aac)$/i', $f)) continue;
            $out[] = ['url' => '/uploads/timer_sounds/' . $f, 'ts' => (int)@filemtime("$dir/$f")];
        }
    }
    usort($out, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    echo json_encode(['ok' => true, 'sounds' => array_slice($out, 0, 60)]);
    exit;
}

if ($action === 'list_images') {
    $out = [];
    $dir = __DIR__ . '/uploads/timer_layouts';
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (!preg_match('/^u' . $uid . '_[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$/i', $f)) continue;
            $out[] = ['url' => '/uploads/timer_layouts/' . $f, 'mine' => true,
                      'ts' => (int)@filemtime("$dir/$f")];
        }
    }
    $shipped = __DIR__ . '/img/timer_beta';
    if (is_dir($shipped)) {
        foreach (scandir($shipped) as $f) {
            if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $f)) continue;
            $out[] = ['url' => '/img/timer_beta/' . $f, 'mine' => false,
                      'ts' => (int)@filemtime("$shipped/$f")];
        }
    }
    // Newest of the user's own first; shipped art after.
    usort($out, function ($a, $b) {
        if ($a['mine'] !== $b['mine']) return $a['mine'] ? -1 : 1;
        return $b['ts'] <=> $a['ts'];
    });
    echo json_encode(['ok' => true, 'images' => array_slice($out, 0, 120)]);
    exit;
}

if ($action === 'save_layout') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) { echo json_encode(['ok' => false, 'error' => 'Name required (max 80 chars)']); exit; }

    $err = [];
    $clean = pk_layout_sanitize($_POST['layout'] ?? '', $err);
    if ($clean === null) { echo json_encode(['ok' => false, 'error' => 'Invalid layout: ' . implode('; ', array_slice($err, 0, 3))]); exit; }

    $is_global = $isAdmin ? (int)!empty($_POST['is_global']) : 0;
    $league_id = (int)($_POST['league_id'] ?? 0) ?: null;
    if ($league_id !== null && !$isAdmin) {
        $q = $db->prepare('SELECT role FROM league_members WHERE league_id = ? AND user_id = ?');
        $q->execute([$league_id, $uid]);
        if (!in_array($q->fetchColumn() ?: '', ['owner', 'manager'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Only a league owner or manager can attach a layout to that league']); exit;
        }
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $q = $db->prepare('SELECT * FROM timer_layouts WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
        if (!pk_lo_may_modify($db, $row, $uid, $isAdmin)) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not yours to edit — use Save as copy']); exit;
        }
        // Images the old version referenced but the new one no longer does are
        // candidates for deletion (a replaced background, a removed image cell).
        $removed = array_diff_key(pk_lo_image_names($row['layout']), pk_lo_image_names($clean));
        $removedSnd = array_diff_key(pk_lo_sound_names($row['layout']), pk_lo_sound_names($clean));
        $db->prepare("UPDATE timer_layouts SET name = ?, layout = ?, is_global = ?, league_id = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$name, json_encode($clean), $isAdmin ? $is_global : (int)$row['is_global'], $league_id, $id]);
        pk_lo_gc_images($db, $removed, $id);
        pk_lo_gc_sounds($db, $removedSnd, $id);
    } else {
        $db->prepare('INSERT INTO timer_layouts (name, created_by, is_global, league_id, layout) VALUES (?, ?, ?, ?, ?)')
           ->execute([$name, $uid, $is_global, $league_id, json_encode($clean)]);
        $id = (int)$db->lastInsertId();
    }
    db_log_activity($uid, "saved timer layout: $name (#$id)");
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete_layout') {
    $id = (int)($_POST['id'] ?? 0);
    $q = $db->prepare('SELECT * FROM timer_layouts WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }
    if (!pk_lo_may_modify($db, $row, $uid, $isAdmin)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not yours to delete']); exit;
    }
    $names = pk_lo_image_names($row['layout']);
    $db->prepare('DELETE FROM timer_layouts WHERE id = ?')->execute([$id]);
    pk_lo_gc_images($db, $names, $id);   // free its images, unless another layout keeps them
    pk_lo_gc_sounds($db, pk_lo_sound_names($row['layout']), $id);
    db_log_activity($uid, "deleted timer layout: {$row['name']} (#$id)");
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload_image') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded.']); exit;
    }
    $file = $_FILES['image'];

    // MIME from the actual bytes, not the browser.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($exts[$mime])) { echo json_encode(['ok' => false, 'error' => 'Only JPEG, PNG, GIF and WebP images are allowed.']); exit; }
    if ($file['size'] > 8 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'File too large (max 8 MB).']); exit; }
    // Must actually decode as an image (rejects a renamed non-image with a faked type).
    if (@getimagesize($file['tmp_name']) === false) { echo json_encode(['ok' => false, 'error' => 'That file is not a readable image.']); exit; }

    // Shared per-user daily cap (admins exempt), same counter as upload.php.
    if (!$isAdmin) {
        $capQ = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action LIKE 'uploaded image:%' AND created_at > datetime('now', '-1 day')");
        $capQ->execute([$uid]);
        if ((int)$capQ->fetchColumn() >= MAX_UPLOADS_PER_DAY) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Daily upload limit reached — try again tomorrow.']); exit; }
    }

    // Timer-layout images get their own folder so they are identifiable,
    // sweepable, and scoped away from every other upload on the site.
    $dir = __DIR__ . '/uploads/timer_layouts/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Storage unavailable.']); exit; }
    // Owner-keyed name: u<id>_<random>.ext. The uploader's id is baked into the
    // filename so a file's provenance is obvious and its owner is sweepable.
    $name = 'u' . $uid . '_' . bin2hex(random_bytes(16)) . '.' . $exts[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to save file.']); exit; }
    db_log_activity($uid, "uploaded image: timer_layouts/$name");
    echo json_encode(['ok' => true, 'url' => '/uploads/timer_layouts/' . $name]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
