<?php
// Helpers for the tournament-timer theme system.
// Themes live in `timer_themes` (id + JSON properties blob); timer_state.theme_id points at one.
// Default fallback is whichever row has is_default=1, or the hardcoded values below if none.

function timer_theme_defaults(): array {
    return [
        'background' => ['type'=>'color','color'=>'#0f172a','gradient'=>['from'=>'#0f172a','to'=>'#1e293b','angle'=>180],'image_url'=>''],
        'elements'   => [
            'event_name'   => ['visible'=>true,'color'=>'#ffffff','scale'=>1.0],
            'player_count' => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'pool_total'   => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'level_label'  => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'blinds'       => ['visible'=>true,'color'=>'#ffffff','scale'=>1.0],
            'clock'        => ['visible'=>true,'color_green'=>'#22c55e','color_yellow'=>'#fbbf24','color_red'=>'#ef4444','scale'=>1.0,'warning_seconds'=>120,'critical_seconds'=>30,'variant'=>'text','radial_segments'=>12,'radial_thickness'=>0.12,'radial_direction'=>'ccw'],
            'paused_label' => ['visible'=>true,'color'=>'#fbbf24','scale'=>1.0],
            'next_level'   => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'avg_stack'    => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'payouts'      => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'qr'           => ['visible'=>true,'scale'=>1.0],
            'image'        => ['visible'=>false,'url'=>'','scale'=>1.0],
            'rebuys'        => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'chips_in_play' => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'next_break'    => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'ends_at'       => ['visible'=>true,'color'=>'#94a3b8','scale'=>1.0],
            'streaming'     => ['visible'=>false,'scale'=>1.0,'url'=>''],
        ],
        'tray' => ['bg_color'=>'#1e293b','button_color'=>'#e2e8f0','accent_color'=>'#2563eb'],
    ];
}

function timer_resolve_theme(PDO $db, ?int $theme_id): array {
    $row = null;
    if ($theme_id) {
        $stmt = $db->prepare('SELECT properties FROM timer_themes WHERE id = ?');
        $stmt->execute([$theme_id]);
        $row = $stmt->fetch();
    }
    if (!$row) {
        $stmt = $db->prepare('SELECT properties FROM timer_themes WHERE is_default = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
    }
    if ($row) {
        $props = json_decode($row['properties'] ?? '{}', true);
        if (is_array($props) && !empty($props)) {
            return array_replace_recursive(timer_theme_defaults(), $props);
        }
    }
    return timer_theme_defaults();
}

// Strip any character that could break out of a CSS value or the surrounding
// <style> block. Leaves the chars needed for hex, rgb()/rgba()/hsl() and named
// colors intact, so legitimate themes render unchanged, while the injection
// primitives (`<` `>` `{` `}` `;` `:` quotes `/` `\`) are removed. Theme JSON is
// attacker-controllable (any logged-in user can save a personal theme), so this
// runs on every value emitted into the timer's inline <style> — see timer.php.
function timer_css_scrub($v): string {
    return preg_replace('/[^A-Za-z0-9#%(),.\s_-]/', '', (string)$v);
}

// Validate a theme background image URL to a relative uploads path; anything else
// (absolute/external URLs, breakout attempts, protocol-relative) collapses to
// empty so the `url('...')` sink cannot be escaped.
function timer_css_safe_image_url($v): string {
    $v = (string)$v;
    return preg_match('#^/?uploads/[A-Za-z0-9._/-]+$#', $v) ? $v : '';
}

// Build the CSS background value (color | gradient | image) for `.timer-body { background: ... }`.
function timer_theme_background_css(array $props): string {
    $bg = $props['background'] ?? [];
    $type = $bg['type'] ?? 'color';
    if ($type === 'gradient') {
        $from = timer_css_scrub($bg['gradient']['from'] ?? '#0f172a');
        $to   = timer_css_scrub($bg['gradient']['to']   ?? '#1e293b');
        $ang  = (int)($bg['gradient']['angle'] ?? 180);
        // Trailing solid color matters: a gradient is a background-IMAGE, and the
        // canvas beyond the root box (iPad overscroll / safe-area gutters) is filled
        // with the background-COLOR — without one those bars render white.
        return "linear-gradient({$ang}deg, {$from}, {$to}) {$to}";
    }
    $imgUrl = timer_css_safe_image_url($bg['image_url'] ?? '');
    if ($type === 'image' && $imgUrl !== '') {
        $base = timer_css_scrub($bg['color'] ?? '#0f172a'); // same white-bars rationale as the gradient case
        return "url('" . $imgUrl . "') center/cover no-repeat {$base}";
    }
    return timer_css_scrub($bg['color'] ?? '#0f172a');
}

// Emit a `:root { ... }` style block setting all the theme CSS variables based on properties.
function timer_theme_css_vars(array $props): string {
    $bgCss   = timer_theme_background_css($props);
    $el      = $props['elements'] ?? [];
    $tray    = $props['tray'] ?? [];
    $sclock  = $el['clock']['scale']        ?? 1.0;
    $sblinds = $el['blinds']['scale']       ?? 1.0;
    $slevel  = $el['level_label']['scale']  ?? 1.0;
    $snext   = $el['next_level']['scale']   ?? 1.0;
    $sevent  = $el['event_name']['scale']   ?? 1.0;
    $spaused = $el['paused_label']['scale'] ?? 1.0;

    $vars = [
        '--timer-bg'             => $bgCss,
        '--timer-event-color'    => $el['event_name']['color']   ?? '#fff',
        '--timer-stat-color'     => $el['player_count']['color'] ?? '#94a3b8',
        '--timer-level-color'    => $el['level_label']['color']  ?? '#94a3b8',
        '--timer-blinds-color'   => $el['blinds']['color']       ?? '#fff',
        '--timer-clock-green'    => $el['clock']['color_green']  ?? '#22c55e',
        '--timer-clock-yellow'   => $el['clock']['color_yellow'] ?? '#fbbf24',
        '--timer-clock-red'      => $el['clock']['color_red']    ?? '#ef4444',
        '--timer-next-color'     => $el['next_level']['color']   ?? '#94a3b8',
        '--timer-paused-color'   => $el['paused_label']['color'] ?? '#fbbf24',
        '--timer-avgstack-color' => $el['avg_stack']['color']    ?? '#94a3b8',
        '--timer-payouts-color'  => $el['payouts']['color']      ?? '#94a3b8',
        '--timer-rebuys-color'    => $el['rebuys']['color']        ?? '#94a3b8',
        '--timer-chips-color'     => $el['chips_in_play']['color'] ?? '#94a3b8',
        '--timer-nextbreak-color' => $el['next_break']['color']    ?? '#94a3b8',
        '--timer-endsat-color'    => $el['ends_at']['color']       ?? '#94a3b8',
        '--timer-tray-button-bg' => $tray['bg_color']            ?? '#1e293b',
        '--timer-tray-button-color' => $tray['button_color']     ?? '#e2e8f0',
        '--timer-accent'         => $tray['accent_color']        ?? '#2563eb',
        '--timer-event-scale'    => (string)$sevent,
        '--timer-level-scale'    => (string)$slevel,
        '--timer-blinds-scale'   => (string)$sblinds,
        '--timer-clock-scale'    => (string)$sclock,
        '--timer-clock-thickness'=> (string)($el['clock']['radial_thickness'] ?? 0.12),
        '--timer-next-scale'     => (string)$snext,
        '--timer-paused-scale'   => (string)$spaused,
    ];
    $css = ":root {\n";
    foreach ($vars as $k => $v) {
        // --timer-bg is a compound value already assembled from sanitized parts
        // (timer_theme_background_css) and legitimately contains `url('/uploads/…')`
        // with quotes and slashes, so it must not be scrubbed. Every other var is a
        // single token (color or numeric scale) that we scrub to strip any CSS/HTML
        // breakout characters before it lands in the inline <style> in timer.php.
        $safe = ($k === '--timer-bg') ? (string)$v : timer_css_scrub($v);
        $safe = str_replace(["\n", "\r"], '', $safe);
        $css .= "  {$k}: {$safe};\n";
    }
    $css .= "}\n";

    // Visibility and flex-order are JS-owned (syncVisibility / applyTheme in
    // timer.php). Emitting them here too is the parallel-path pattern that let
    // ends_at drift; the theme-pending gate on <html> covers first paint instead.

    return $css;
}
