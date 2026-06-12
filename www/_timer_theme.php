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

// Build the CSS background value (color | gradient | image) for `.timer-body { background: ... }`.
function timer_theme_background_css(array $props): string {
    $bg = $props['background'] ?? [];
    $type = $bg['type'] ?? 'color';
    if ($type === 'gradient') {
        $from = $bg['gradient']['from'] ?? '#0f172a';
        $to   = $bg['gradient']['to']   ?? '#1e293b';
        $ang  = (int)($bg['gradient']['angle'] ?? 180);
        // Trailing solid color matters: a gradient is a background-IMAGE, and the
        // canvas beyond the root box (iPad overscroll / safe-area gutters) is filled
        // with the background-COLOR — without one those bars render white.
        return "linear-gradient({$ang}deg, {$from}, {$to}) {$to}";
    }
    if ($type === 'image' && !empty($bg['image_url'])) {
        $url = $bg['image_url'];
        $base = $bg['color'] ?? '#0f172a'; // same white-bars rationale as the gradient case
        return "url('" . str_replace(["'", "\n", "\r"], '', $url) . "') center/cover no-repeat {$base}";
    }
    return $bg['color'] ?? '#0f172a';
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
        // The value comes from JSON we wrote ourselves; still strip newlines/quotes defensively.
        $safe = str_replace(["\n", "\r"], '', (string)$v);
        $css .= "  {$k}: {$safe};\n";
    }
    $css .= "}\n";

    // Element visibility — emit `display:none` for hidden elements so first paint matches.
    $visMap = [
        'event_name'   => '.timer-event-name',
        'player_count' => '#playerWrap',
        'pool_total'   => '#poolWrap',
        'level_label'  => '.timer-level-label',
        'blinds'       => '.timer-blinds',
        'clock'        => '.timer-clock',
        'paused_label' => '#pausedLabel',
        'next_level'   => '.timer-next',
        'avg_stack'    => '#avgStackWrap',
        'payouts'      => '#payoutsWrap',
        'qr'           => '#qrWrap',
        'image'        => '#themeImage',
        'rebuys'        => '#rebuysWrap',
        'chips_in_play' => '#chipsInPlayWrap',
        'next_break'    => '#nextBreakWrap',
        'streaming'     => '#streamingWrap',
    ];
    foreach ($visMap as $key => $sel) {
        $visible = $el[$key]['visible'] ?? true;
        if (!$visible) {
            $css .= "{$sel} { display: none !important; }\n";
        }
    }

    // Order — emit CSS `order` for the four main display elements.
    $orderMap = [
        'level_label' => '.timer-level-label',
        'blinds'      => '.timer-blinds',
        'clock'       => '.timer-clock',
        'next_level'  => '.timer-next',
    ];
    foreach ($orderMap as $key => $sel) {
        $ord = (int)($el[$key]['order'] ?? 0);
        if ($ord > 0) {
            $css .= "{$sel} { order: {$ord}; }\n";
        }
    }

    return $css;
}
