<?php
/**
 * Invite design: how one event's invitation looks to a guest.
 *
 * A host picks a cover image, a background, an accent colour, a title font and
 * an optional effect on invite_design.php. The choice is stored as JSON in
 * events.invite_design (NULL = the standard look) and read back ONLY through
 * invite_design_decode(), which re-checks every value against the catalogue
 * below. Nothing a host types reaches CSS or markup: each part is a key from a
 * closed list, and the look itself lives in invite_look.css keyed on those
 * names. The cover is a file this code wrote, under a fixed name pattern.
 *
 * Where it shows: the share link (invite.php), a guest's personal link
 * (event.php?token=), the invitation email (cover and accent only, mail
 * clients cannot do the rest), the link preview card (the cover), and the
 * full-screen QR poster on event.php.
 *
 * Adding a background, accent or font: add the key here AND its rules in
 * invite_look.css. The two lists must agree.
 */

const INVITE_COVER_DIR       = '/uploads/invite/';
const INVITE_COVER_MAX_BYTES = 4 * 1024 * 1024;
const INVITE_COVER_MAX_SIDE  = 5000;
const INVITE_COVER_RE        = '#^/uploads/invite/e(\d+)_[a-f0-9]{16}\.(jpg|png|gif)$#';

function invite_design_catalog(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [
        // mode: which way the card's own colours go. '' follows the viewer's
        // light or dark setting, as every other page does.
        'themes' => [
            'classic'  => ['name' => 'Classic',     'mode' => ''],
            'felt'     => ['name' => 'Felt',        'mode' => 'light'],
            'velvet'   => ['name' => 'Red velvet',  'mode' => 'light'],
            'gold'     => ['name' => 'Black & gold', 'mode' => 'dark'],
            'midnight' => ['name' => 'Midnight',    'mode' => 'dark'],
            'neon'     => ['name' => 'Neon',        'mode' => 'dark'],
            'sunset'   => ['name' => 'Sunset',      'mode' => 'light'],
            'ocean'    => ['name' => 'Ocean',       'mode' => 'light'],
            'party'    => ['name' => 'Party',       'mode' => 'light'],
            'paper'    => ['name' => 'Parchment',   'mode' => 'light'],
            'slate'    => ['name' => 'Slate',       'mode' => 'dark'],
        ],
        // fill: the button colour (white text on it passes 4.5:1) and the
        // colour of links on a light card. The same values are in
        // invite_look.css; the email reads fill from here.
        'accents' => [
            'blue'   => ['name' => 'Blue',   'fill' => '#2563eb'],
            'green'  => ['name' => 'Green',  'fill' => '#15803d'],
            'red'    => ['name' => 'Red',    'fill' => '#dc2626'],
            'gold'   => ['name' => 'Gold',   'fill' => '#b45309'],
            'purple' => ['name' => 'Purple', 'fill' => '#7c3aed'],
            'teal'   => ['name' => 'Teal',   'fill' => '#0e7490'],
            'pink'   => ['name' => 'Pink',   'fill' => '#be185d'],
        ],
        // The bundled display faces (fonts.css, www/fonts/LICENSE.md).
        'fonts' => [
            'default'  => ['name' => 'Standard'],
            'playfair' => ['name' => 'Playfair'],
            'cinzel'   => ['name' => 'Cinzel'],
            'oswald'   => ['name' => 'Oswald'],
            'bebas'    => ['name' => 'Bebas'],
            'anton'    => ['name' => 'Anton'],
            'lobster'  => ['name' => 'Lobster'],
            'rye'      => ['name' => 'Rye'],
            'monoton'  => ['name' => 'Monoton'],
            'orbitron' => ['name' => 'Orbitron'],
        ],
        'effects' => [
            'none'     => ['name' => 'None'],
            'confetti' => ['name' => 'Confetti'],
            'cards'    => ['name' => 'Cards'],
            'chips'    => ['name' => 'Chips'],
            'sparkle'  => ['name' => 'Sparkle'],
        ],
        // Ready-made looks: one tap sets the four parts. The cover is the
        // host's own and is never touched by a look.
        'looks' => [
            'classic'    => ['name' => 'Classic',     'theme' => 'classic',  'accent' => 'blue',   'font' => 'default',  'effect' => 'none'],
            'pokernight' => ['name' => 'Poker night', 'theme' => 'felt',     'accent' => 'green',  'font' => 'playfair', 'effect' => 'chips'],
            'highroller' => ['name' => 'High roller', 'theme' => 'gold',     'accent' => 'gold',   'font' => 'cinzel',   'effect' => 'sparkle'],
            'saloon'     => ['name' => 'Saloon',      'theme' => 'paper',    'accent' => 'red',    'font' => 'rye',      'effect' => 'cards'],
            'casino'     => ['name' => 'Casino',      'theme' => 'velvet',   'accent' => 'red',    'font' => 'playfair', 'effect' => 'cards'],
            'neonnight'  => ['name' => 'Neon night',  'theme' => 'neon',     'accent' => 'pink',   'font' => 'monoton',  'effect' => 'sparkle'],
            'latenight'  => ['name' => 'Late night',  'theme' => 'midnight', 'accent' => 'purple', 'font' => 'bebas',    'effect' => 'sparkle'],
            'sunset'     => ['name' => 'Sunset',      'theme' => 'sunset',   'accent' => 'pink',   'font' => 'lobster',  'effect' => 'none'],
            'biggame'    => ['name' => 'Big game',    'theme' => 'ocean',    'accent' => 'teal',   'font' => 'anton',    'effect' => 'confetti'],
            'party'      => ['name' => 'Party',       'theme' => 'party',    'accent' => 'purple', 'font' => 'oswald',   'effect' => 'confetti'],
        ],
    ];
    return $c;
}

function invite_design_default(): array {
    return ['theme' => 'classic', 'accent' => 'blue', 'font' => 'default', 'effect' => 'none',
            'cover' => '', 'cover_w' => 0, 'cover_h' => 0];
}

/**
 * The four chosen parts, each one a key of the catalogue or the default.
 * Reads theme, accent, font and effect from $in and nothing else: the cover
 * is set by the upload alone, never by a posted value.
 */
function invite_design_parts(array $in): array {
    $cat = invite_design_catalog();
    $d   = invite_design_default();
    foreach (['theme' => 'themes', 'accent' => 'accents', 'font' => 'fonts', 'effect' => 'effects'] as $k => $list) {
        $v = $in[$k] ?? null;
        if (is_string($v) && isset($cat[$list][$v])) $d[$k] = $v;
    }
    return ['theme' => $d['theme'], 'accent' => $d['accent'], 'font' => $d['font'], 'effect' => $d['effect']];
}

/** Stored JSON (or NULL) to a complete, checked design. */
function invite_design_decode($json, int $event_id = 0): array {
    $d = invite_design_default();
    if (!is_string($json) || $json === '') return $d;
    $raw = json_decode($json, true);
    if (!is_array($raw)) return $d;
    $d = array_merge($d, invite_design_parts($raw));
    $cover = $raw['cover'] ?? '';
    // The file must be one this event's upload wrote: the id is in the name.
    if (is_string($cover) && preg_match(INVITE_COVER_RE, $cover, $m)
        && ($event_id === 0 || (int)$m[1] === $event_id)
        && is_file(__DIR__ . $cover)) {
        $d['cover']   = $cover;
        $d['cover_w'] = max(0, (int)($raw['cover_w'] ?? 0));
        $d['cover_h'] = max(0, (int)($raw['cover_h'] ?? 0));
    }
    return $d;
}

function invite_design_for_event(PDO $db, int $event_id): array {
    $s = $db->prepare('SELECT invite_design FROM events WHERE id = ?');
    $s->execute([$event_id]);
    return invite_design_decode($s->fetchColumn() ?: null, $event_id);
}

function invite_design_is_default(array $d): bool {
    return $d['theme'] === 'classic' && $d['accent'] === 'blue' && $d['font'] === 'default'
        && $d['effect'] === 'none' && $d['cover'] === '';
}

/** What to store: NULL for the standard look, so an untouched event stays NULL. */
function invite_design_encode(array $d): ?string {
    if (invite_design_is_default($d)) return null;
    $out = invite_design_parts($d);
    if (($d['cover'] ?? '') !== '') {
        $out['cover']   = $d['cover'];
        $out['cover_w'] = (int)($d['cover_w'] ?? 0);
        $out['cover_h'] = (int)($d['cover_h'] ?? 0);
    }
    return json_encode($out);
}

function invite_design_store(PDO $db, int $event_id, array $d): void {
    $db->prepare('UPDATE events SET invite_design = ? WHERE id = ?')->execute([invite_design_encode($d), $event_id]);
}

// ── Markup ────────────────────────────────────────────────────────────────────

/** For the <html> tag: pins light or dark when the background calls for it. */
function invite_design_html_attr(array $d): string {
    $mode = invite_design_catalog()['themes'][$d['theme']]['mode'] ?? '';
    return $mode !== '' ? ' data-theme="' . $mode . '"' : '';
}

/** For the <body> tag: the four parts, as the names invite_look.css keys on. */
function invite_design_body_attr(array $d): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);
    return ' data-ivd-theme="' . $e($d['theme']) . '" data-ivd-accent="' . $e($d['accent'])
         . '" data-ivd-font="' . $e($d['font']) . '" data-ivd-effect="' . $e($d['effect']) . '"';
}

function _invite_design_asset(string $file): string {
    return '/' . $file . '?v=' . rawurlencode(APP_VERSION . '.' . (@filemtime(__DIR__ . '/' . $file) ?: 0));
}

/**
 * Stylesheets for the <head>. The display fonts are only linked when one is
 * chosen ($all = the editor's preview, which switches between them live).
 */
function invite_design_head(array $d, bool $all = false): string {
    $out = '';
    if ($all || $d['font'] !== 'default') {
        $out .= '<link rel="stylesheet" href="' . htmlspecialchars(_invite_design_asset('fonts.css'), ENT_QUOTES | ENT_SUBSTITUTE) . '">' . "\n    ";
    }
    return $out . '<link rel="stylesheet" href="' . htmlspecialchars(_invite_design_asset('invite_look.css'), ENT_QUOTES | ENT_SUBSTITUTE) . '">' . "\n";
}

/** The cover, first thing in the card. $always keeps an empty holder for the preview. */
function invite_design_cover(array $d, bool $always = false): string {
    if ($d['cover'] === '') return $always ? '<div class="ivd-cover" hidden><img src="" alt=""></div>' : '';
    $wh = ($d['cover_w'] > 0 && $d['cover_h'] > 0) ? ' width="' . (int)$d['cover_w'] . '" height="' . (int)$d['cover_h'] . '"' : '';
    return '<div class="ivd-cover"><img src="' . htmlspecialchars($d['cover'], ENT_QUOTES | ENT_SUBSTITUTE) . '"' . $wh . ' alt=""></div>';
}

/** The effect script, at the end of <body>. Loaded only when there is one to play. */
function invite_design_fx(array $d, bool $always = false): string {
    if (!$always && $d['effect'] === 'none') return '';
    return '<script src="' . htmlspecialchars(_invite_design_asset('invite_fx.js'), ENT_QUOTES | ENT_SUBSTITUTE) . '" defer></script>' . "\n";
}

/** Absolute URL of the cover for a link preview card, or null. */
function invite_design_cover_abs(array $d): ?string {
    return $d['cover'] !== '' ? get_site_url() . $d['cover'] : null;
}

/**
 * The email's share of the design. Mail clients have no CSS variables, web
 * fonts or page backgrounds, so an invitation carries the cover (linked to
 * the event) and the accent on its main button.
 */
function invite_design_email(array $d, string $site_url, string $link): array {
    $fill  = invite_design_catalog()['accents'][$d['accent']]['fill'] ?? '#2563eb';
    $cover = '';
    if ($d['cover'] !== '') {
        $cover = '<p style="margin:0 0 16px"><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE) . '">'
               . '<img src="' . htmlspecialchars($site_url . $d['cover'], ENT_QUOTES | ENT_SUBSTITUTE) . '" alt="" width="560"'
               . ' style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:10px"></a></p>';
    }
    return ['cover' => $cover, 'accent' => $fill];
}

// ── The cover file ────────────────────────────────────────────────────────────

/** Remove a cover file. Only ever a name this code wrote. */
function invite_design_cover_unlink(string $path): void {
    if (preg_match(INVITE_COVER_RE, $path) && is_file(__DIR__ . $path)) @unlink(__DIR__ . $path);
}

/**
 * Take an uploaded cover for an event: JPEG, PNG or GIF by content (never by
 * the name it came with), inside the size limits. The browser has already
 * scaled a photo down; these limits are the backstop. Returns the stored
 * design, or ['error' => ...].
 */
function invite_design_cover_take(PDO $db, int $event_id, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return ['error' => 'No picture received. Try again.'];
    }
    if ((int)$file['size'] > INVITE_COVER_MAX_BYTES) return ['error' => 'That picture is too large (4 MB at most).'];
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $dims = @getimagesize($file['tmp_name']);
    if (!isset($exts[$mime]) || $dims === false) return ['error' => 'Use a JPEG, PNG or GIF picture.'];
    if ($dims[0] < 200 || $dims[1] < 100) return ['error' => 'That picture is too small to use as a cover.'];
    if ($dims[0] > INVITE_COVER_MAX_SIDE || $dims[1] > INVITE_COVER_MAX_SIDE) return ['error' => 'That picture is too large. Try a smaller one.'];

    $dir = __DIR__ . INVITE_COVER_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return ['error' => 'Storage unavailable.'];
    $path = INVITE_COVER_DIR . 'e' . $event_id . '_' . bin2hex(random_bytes(8)) . '.' . $exts[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . $path)) return ['error' => 'Could not save the picture.'];
    @chmod(__DIR__ . $path, 0644);

    $d   = invite_design_for_event($db, $event_id);
    $old = $d['cover'];
    $d['cover'] = $path; $d['cover_w'] = (int)$dims[0]; $d['cover_h'] = (int)$dims[1];
    invite_design_store($db, $event_id, $d);
    if ($old !== '' && $old !== $path) invite_design_cover_unlink($old);
    return $d;
}

function invite_design_cover_remove(PDO $db, int $event_id): array {
    $d = invite_design_for_event($db, $event_id);
    if ($d['cover'] !== '') invite_design_cover_unlink($d['cover']);
    $d['cover'] = ''; $d['cover_w'] = 0; $d['cover_h'] = 0;
    invite_design_store($db, $event_id, $d);
    return $d;
}

/**
 * Duplicate an event's design onto a new event. The cover is copied to a file
 * of the new event's own, so deleting either event leaves the other's intact.
 */
function invite_design_copy(PDO $db, int $from_event, int $to_event): void {
    $d = invite_design_for_event($db, $from_event);
    if (invite_design_is_default($d)) return;
    if ($d['cover'] !== '') {
        $ext  = pathinfo($d['cover'], PATHINFO_EXTENSION);
        $path = INVITE_COVER_DIR . 'e' . $to_event . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@copy(__DIR__ . $d['cover'], __DIR__ . $path)) {
            $d['cover'] = $path;
        } else {
            $d['cover'] = ''; $d['cover_w'] = 0; $d['cover_h'] = 0;
        }
    }
    invite_design_store($db, $to_event, $d);
}

/** An event is being deleted: its cover goes with it. Call before the row is removed. */
function invite_design_forget(PDO $db, int $event_id): void {
    $d = invite_design_for_event($db, $event_id);
    if ($d['cover'] !== '') invite_design_cover_unlink($d['cover']);
}
