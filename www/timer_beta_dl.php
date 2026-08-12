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
if (!$current) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit; }
$isAdmin = $current['role'] === 'admin';
$uid = (int)$current['id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

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
function pk_lo_num($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    return max($min, min($max, (float)$v));
}

function pk_lo_cond($c) {
    if ($c === null || $c === '' || $c === 'always') return null;
    $states = ['always','running','paused','on_break','pre_game','has_ante','has_rebuys','game_over'];
    if (is_string($c)) return in_array($c, $states, true) ? $c : null;
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
    foreach (['fit', 'bold', 'clockColors'] as $b) if (!empty($cell[$b])) $out[$b] = true;
    foreach (['color', 'bg', 'border'] as $k) if (isset($cell[$k])) { $v = pk_lo_style_str($cell[$k]); if ($v !== null) $out[$k] = $v; }
    foreach (['pad', 'spacing'] as $k) if (isset($cell[$k])) { $v = pk_lo_style_str($cell[$k], 32); if ($v !== null) $out[$k] = $v; }
    if (isset($cell['align']) && in_array($cell['align'], ['left', 'center', 'right'], true)) $out['align'] = $cell['align'];
    if (isset($cell['when'])) { $w = pk_lo_cond($cell['when']); if ($w !== null) $out['when'] = $w; }
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
    return $out;
}

function pk_layout_sanitize($doc, array &$err): ?array {
    if (is_string($doc)) $doc = json_decode($doc, true);
    if (!is_array($doc)) { $err[] = 'not a JSON object'; return null; }
    if (strlen(json_encode($doc)) > 131072) { $err[] = 'layout too large'; return null; }
    $out = ['v' => 1];

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
        $out['screens'] = $screens;
        return $out;
    }

    $bg = pk_lo_bg($doc['bg'] ?? null);
    if ($bg !== null) $out['bg'] = $bg;
    if (!isset($doc['root'])) { $err[] = 'missing root'; return null; }
    $count = 0;
    $root = pk_lo_node($doc['root'], 0, $count, $err);
    if ($root === null) return null;
    $out['root'] = $root;
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
        $db->prepare("UPDATE timer_layouts SET name = ?, layout = ?, is_global = ?, league_id = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$name, json_encode($clean), $isAdmin ? $is_global : (int)$row['is_global'], $league_id, $id]);
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
    $db->prepare('DELETE FROM timer_layouts WHERE id = ?')->execute([$id]);
    db_log_activity($uid, "deleted timer layout: {$row['name']} (#$id)");
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
