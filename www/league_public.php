<?php
/**
 * Public league landing page: /league/<slug> (rewritten to ?slug=<slug>).
 *
 * Rendered without login for leagues that have opted in (leagues.public_page = 1).
 * Shows league identity, upcoming events (date/time/title/location only), a top-5
 * leaderboard teaser with truncated names, and a join button. Deliberately never
 * exposes: invite_code, owner identity, member contact info, attendee/RSVP data,
 * event descriptions, or money stats. Unknown, non-public, and hidden slugs all
 * return the same 404 so the route is not an existence oracle.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/version.php';

$user      = current_user();
$db        = get_db();
$site_name = get_setting('site_name', 'Game Night');

$slug = strtolower(trim($_GET['slug'] ?? ''));

// ── Directory mode: bare /league lists every league with a public page ──────
if ($slug === '') {
    $siteTz = new DateTimeZone(get_setting('timezone', 'UTC'));
    $today  = (new DateTime('now', $siteTz))->format('Y-m-d');
    $dStmt = $db->prepare(
        "SELECT l.name, l.description, l.slug, l.approval_mode, l.banner_path, l.banner_fit,
                (SELECT COUNT(*) FROM league_members lm WHERE lm.league_id = l.id AND lm.user_id IS NOT NULL) AS member_count,
                (SELECT COUNT(*) FROM events e WHERE e.league_id = l.id AND COALESCE(NULLIF(e.end_date, ''), e.start_date) >= ?) AS upcoming_count
           FROM leagues l
          WHERE l.public_page = 1 AND l.is_hidden = 0 AND l.slug IS NOT NULL AND l.slug <> ''
          ORDER BY LOWER(l.name)"
    );
    $dStmt->execute([$today]);
    $dirLeagues = $dStmt->fetchAll();
    $dirUrl     = get_site_url() . '/league';
    $allowReg   = get_setting('allow_registration', '1') === '1';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leagues &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($dirUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Leagues &mdash; <?= htmlspecialchars($site_name) ?>">
    <meta property="og:description" content="Browse the leagues on <?= htmlspecialchars($site_name) ?> and see their upcoming events.">
    <meta property="og:url" content="<?= htmlspecialchars($dirUrl) ?>">
    <meta name="description" content="Browse the leagues on <?= htmlspecialchars($site_name) ?> and see their upcoming events.">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .ld-layout { max-width: 860px; margin: 0 auto; padding: 0 1.25rem 3rem; }
        .ld-head { margin: 1.75rem 0 .35rem; font-size: 1.8rem; font-weight: 800; color: #0f172a; }
        .ld-sub { color: #64748b; font-size: .92rem; margin-bottom: 1.5rem; }
        .ld-card { display: block; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: .9rem; text-decoration: none; overflow: hidden; transition: border-color .15s; }
        .ld-card:hover { border-color: #93c5fd; }
        .ld-banner { background: #f1f5f9; }
        .ld-banner img { display: block; width: 100%; height: 120px; object-fit: cover; }
        .ld-banner.fit img { object-fit: contain; }
        .ld-body { padding: 1rem 1.25rem; }
        .ld-name { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-bottom: .25rem; }
        .ld-desc { color: #475569; font-size: .9rem; line-height: 1.55; margin-bottom: .5rem; }
        .ld-meta { display: flex; gap: .9rem; flex-wrap: wrap; color: #64748b; font-size: .82rem; }
        .ld-empty { color: #64748b; font-size: .9rem; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 1.25rem; }
    </style>
</head>
<body>

<?php if ($user): ?>
    <?php $nav_active = ''; $nav_user = $user; require __DIR__ . '/_nav.php'; ?>
<?php else:
    // Same guest-nav workaround as the per-league page below.
    $__hdr_banner = get_setting('header_banner_path', '');
?>
<nav style="background:#0f172a;color:#fff;padding:.75rem 1.25rem;display:flex;align-items:center;gap:1rem;border-bottom:1px solid #1e293b">
    <a href="/" style="color:#fff;text-decoration:none;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:.6rem">
        <?php if ($__hdr_banner): ?>
            <img src="<?= htmlspecialchars($__hdr_banner) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:40px;width:auto;display:block">
        <?php else: ?>
            <?= htmlspecialchars($site_name) ?>
        <?php endif; ?>
    </a>
    <div style="margin-left:auto;display:flex;gap:.5rem">
        <a href="/login.php" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;background:#2563eb;font-size:.9rem;font-weight:600">Log in</a>
        <?php if ($allowReg): ?>
            <a href="/register.php" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;border:1px solid #475569;font-size:.9rem;font-weight:600">Sign up</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<div class="ld-layout">
    <h1 class="ld-head">&#127942; Leagues</h1>
    <p class="ld-sub">Regular crews playing on <?= htmlspecialchars($site_name) ?>. Open a league to see its schedule and join.</p>

    <?php if (!$dirLeagues): ?>
        <div class="ld-empty">No leagues have a public page yet. Check back soon.</div>
    <?php else: foreach ($dirLeagues as $dl):
        $desc = trim(mb_substr(strip_tags((string)($dl['description'] ?? '')), 0, 180));
    ?>
        <a class="ld-card" href="/league/<?= htmlspecialchars($dl['slug']) ?>">
            <?php if (!empty($dl['banner_path'])): ?>
            <div class="ld-banner<?= ($dl['banner_fit'] ?? 'cover') === 'contain' ? ' fit' : '' ?>"><img src="<?= htmlspecialchars($dl['banner_path']) ?>" alt="<?= htmlspecialchars($dl['name']) ?>"></div>
            <?php endif; ?>
            <div class="ld-body">
                <div class="ld-name"><?= htmlspecialchars($dl['name']) ?></div>
                <?php if ($desc !== ''): ?><div class="ld-desc"><?= htmlspecialchars($desc) ?></div><?php endif; ?>
                <div class="ld-meta">
                    <span>&#128101; <?= (int)$dl['member_count'] ?> member<?= (int)$dl['member_count'] !== 1 ? 's' : '' ?></span>
                    <span>&#128197; <?= (int)$dl['upcoming_count'] ?> upcoming event<?= (int)$dl['upcoming_count'] !== 1 ? 's' : '' ?></span>
                    <span><?= $dl['approval_mode'] === 'auto' ? '&#9989; Open to join' : '&#128274; Join by approval' ?></span>
                </div>
            </div>
        </a>
    <?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
    <?php
    exit;
}

$league = null;
if (preg_match('/^[a-z0-9-]{1,60}$/', $slug)) {
    // Explicit column list: this projection is the public contract.
    $L = $db->prepare(
        "SELECT id, name, description, slug, approval_mode, banner_path, banner_fit
           FROM leagues
          WHERE slug = ? AND public_page = 1 AND is_hidden = 0"
    );
    $L->execute([$slug]);
    $league = $L->fetch();
}

if (!$league) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <title>League Not Found &mdash; <?= htmlspecialchars($site_name) ?></title>
        <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">
        <div style="max-width:480px;width:100%;text-align:center">
            <div style="background:#fef2f2;border:2px solid #dc2626;border-radius:12px;padding:2rem 1.5rem;margin-bottom:1.5rem">
                <h1 style="font-size:1.5rem;color:#dc2626;margin:0 0 .75rem">League Not Found</h1>
                <div style="font-size:1rem;color:#334155;line-height:1.6">There is no public league page at this address. The link may be outdated, or the league's public page may have been turned off.</div>
            </div>
            <a href="/" style="color:#2563eb;text-decoration:none;font-size:.9rem">Go to <?= htmlspecialchars($site_name) ?></a>
        </div>
    </body></html>
    <?php
    exit;
}

$league_id = (int)$league['id'];

// Viewer state drives the join block.
$myRole  = $user ? league_role($league_id, (int)$user['id']) : null;
$pending = false;
if ($user && $myRole === null) {
    $p = $db->prepare("SELECT 1 FROM league_join_requests WHERE league_id = ? AND user_id = ? AND status = 'pending'");
    $p->execute([$league_id, (int)$user['id']]);
    $pending = (bool)$p->fetchColumn();
}
$csrf = $user ? csrf_token() : '';

$memberCount = (int)$db->query("SELECT COUNT(*) FROM league_members WHERE league_id = $league_id AND user_id IS NOT NULL")->fetchColumn();

// Upcoming events. Bypasses event_visibility_sql() on purpose: the guest branch of
// that helper allows only visibility='public', and the league's public_page opt-in is
// the authorization for this limited projection instead. Events an author marked
// 'invitees_only' are still excluded -- that choice is theirs, not the league owner's.
// Columns are allowlisted; no description, address, creator, or RSVP data is selected.
$siteTz = new DateTimeZone(get_setting('timezone', 'UTC'));
$today  = (new DateTime('now', $siteTz))->format('Y-m-d');
$ev = $db->prepare(
    "SELECT e.id, e.title, e.start_date, e.end_date, e.start_time, e.end_time, e.venue_name
       FROM events e
      WHERE e.league_id = ? AND e.visibility IN ('league', 'public')
        AND COALESCE(NULLIF(e.end_date, ''), e.start_date) >= ?
      ORDER BY e.start_date ASC, e.start_time ASC
      LIMIT 20"
);
$ev->execute([$league_id, $today]);
$events = $ev->fetchAll();

// Stats teaser: collapsed version of the league.php all-time leaderboard.
// Names are truncated to "First L." and money columns are deliberately absent.
$LB_MIN_GAMES = 3;
$lbStmt = $db->prepare("
    SELECT
        g.display_name,
        COUNT(*) as games,
        SUM(CASE WHEN g.game_type = 'tournament' AND g.finish_position = 1 THEN 1 ELSE 0 END) as wins,
        ROUND(AVG(CASE WHEN g.game_type = 'tournament' THEN g.score END), 1) as avg_score,
        (COUNT(*) >= $LB_MIN_GAMES) as qualified
    FROM (
        SELECT
            COALESCE(u.username, pp.display_name) as display_name,
            COALESCE(CAST(pp.user_id AS TEXT), 'g_' || LOWER(pp.display_name)) as player_key,
            ps.game_type,
            CASE WHEN ps.game_type = 'tournament' THEN COALESCE(pp.finish_position, pc.field_size) END as finish_position,
            CASE WHEN ps.game_type = 'tournament' AND pc.field_size > 1
                THEN ROUND(CAST(pc.field_size - COALESCE(pp.finish_position, pc.field_size) AS REAL) / pc.field_size * 80 + 20, 1)
                WHEN ps.game_type = 'tournament' THEN 100
            END as score
        FROM poker_players pp
        JOIN poker_sessions ps ON ps.id = pp.session_id
        JOIN events e ON e.id = ps.event_id
        LEFT JOIN users u ON u.id = pp.user_id
        JOIN (
            SELECT session_id, COUNT(*) as field_size
            FROM poker_players WHERE bought_in = 1 AND removed = 0
            GROUP BY session_id
        ) pc ON pc.session_id = pp.session_id
        WHERE pp.bought_in = 1 AND pp.removed = 0
          AND ps.status = 'finished'
          AND e.league_id = ?
    ) g
    GROUP BY g.player_key
    ORDER BY qualified DESC, avg_score IS NULL, avg_score DESC, games ASC
    LIMIT 5
");
$lbStmt->execute([$league_id]);
$teaser = $lbStmt->fetchAll();

// League posts the managers explicitly made public (share_token minted via the
// posts tab). Same gate as post_public.php: a revoked share link (share_token
// NULLed) or hidden post disappears from here too. No author shown.
$pStmt = $db->prepare(
    "SELECT id, title, content, created_at, pinned, share_token
       FROM posts
      WHERE league_id = ? AND share_token IS NOT NULL AND COALESCE(hidden, 0) = 0
      ORDER BY pinned DESC, created_at DESC
      LIMIT 5"
);
$pStmt->execute([$league_id]);
$publicPosts = $pStmt->fetchAll();

// "Brad Cooper" -> "Brad C." so full member names never appear on a public page.
function lp_public_name(string $n): string {
    $p = preg_split('/\s+/', trim($n));
    $out = $p[0] ?? '';
    if (isset($p[1]) && $p[1] !== '') $out .= ' ' . mb_strtoupper(mb_substr($p[1], 0, 1)) . '.';
    return $out;
}

$pubPath  = '/league/' . $league['slug'];
$pubUrl   = get_site_url() . $pubPath;
$icsUrl   = $pubUrl . '.ics';
// webcal:// is the scheme Apple/Outlook treat as "subscribe and keep polling".
// The same URL over https is treated as a one-time import instead, so the
// Subscribe action uses webcal and the plain https URL is offered separately
// for Google Calendar (which wants it pasted into "From URL") and downloads.
$webcalUrl = preg_replace('#^https?://#i', 'webcal://', $icsUrl);
$joinRet  = $pubPath . '?join=1';
$allowReg = get_setting('allow_registration', '1') === '1';
$ogDesc   = trim(mb_substr(strip_tags((string)($league['description'] ?? '')), 0, 200));
if ($ogDesc === '') $ogDesc = 'Upcoming events for ' . $league['name'] . ' on ' . $site_name . '.';
$banner   = trim((string)($league['banner_path'] ?? ''));
$autoJoin = $user && $myRole === null && !$pending && ($_GET['join'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($league['name']) ?> &mdash; <?= htmlspecialchars($site_name) ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($pubUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($league['name'] . ' — ' . $site_name) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($pubUrl) ?>">
    <?php if ($banner !== ''): ?>
    <meta property="og:image" content="<?= htmlspecialchars(get_site_url() . $banner) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="description" content="<?= htmlspecialchars($ogDesc) ?>">
    <link rel="stylesheet" href="/style.css?v=<?= htmlspecialchars(APP_VERSION . '.' . (@filemtime(__DIR__ . '/style.css') ?: 0)) ?>">
    <style>
        .lp-layout { max-width: 860px; margin: 0 auto; padding: 0 1.25rem 3rem; }
        .lp-hero { margin: 1.5rem 0 0; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; background: #f1f5f9; }
        .lp-hero img { display: block; width: 100%; height: 280px; object-fit: cover; }
        /* "Fit" mode: show the whole image, letterboxed against the card background,
           and never blow a small logo up past its natural size. */
        .lp-hero.fit img { object-fit: contain; max-height: 280px; height: auto; }
        .lp-head { margin: 1.5rem 0 .35rem; font-size: 2rem; font-weight: 800; color: #0f172a; }
        .lp-sub { color: #64748b; font-size: .9rem; margin-bottom: 1rem; display: flex; gap: .9rem; flex-wrap: wrap; }
        .lp-desc { color: #334155; line-height: 1.7; margin-bottom: 1.4rem; max-width: 62ch; }
        .lp-join { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: 2rem; }
        .lp-btn { display: inline-block; border: none; cursor: pointer; background: #2563eb; color: #fff; font-weight: 700; font-size: .95rem; padding: .6rem 1.4rem; border-radius: 8px; text-decoration: none; }
        .lp-btn.secondary { background: #fff; color: #2563eb; border: 1px solid #93c5fd; }
        .lp-pill { background: #fef9c3; border: 1px solid #fde047; color: #854d0e; font-weight: 600; font-size: .85rem; padding: .45rem 1rem; border-radius: 999px; }
        .lp-note { color: #64748b; font-size: .82rem; }
        .lp-section { margin: 0 0 2rem; }
        .lp-section h2 { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-bottom: .8rem; display: flex; align-items: center; gap: .5rem; }
        .lp-ev { display: flex; gap: 1rem; align-items: baseline; padding: .7rem .9rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; margin-bottom: .55rem; flex-wrap: wrap; }
        .lp-ev-date { font-weight: 700; color: #1e40af; white-space: nowrap; font-size: .9rem; }
        .lp-ev-title { font-weight: 600; color: #0f172a; }
        .lp-ev-meta { color: #64748b; font-size: .85rem; }
        .lp-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        .lp-table th { text-align: left; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; background: #f8fafc; padding: .55rem .8rem; border-bottom: 1px solid #e2e8f0; }
        .lp-table td { padding: .55rem .8rem; border-bottom: 1px solid #f1f5f9; font-size: .92rem; color: #334155; }
        .lp-table tr:last-child td { border-bottom: none; }
        .lp-empty { color: #64748b; font-size: .9rem; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 1rem; }
        .lp-cal { margin: 0 0 1.25rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; }
        .lp-cal[hidden] { display: none; }
        .lp-cal-row { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
        .lp-cal-row input { flex: 1; min-width: 220px; padding: .5rem .6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-family: ui-monospace, monospace; font-size: .78rem; background: #fff; color: #334155; }
        .lp-post { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1.1rem 1.25rem; margin-bottom: .75rem; }
        .lp-post-meta { font-size: .75rem; color: #94a3b8; margin-bottom: .35rem; display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
        .lp-post-title { font-weight: 700; color: #0f172a; margin-bottom: .5rem; font-size: 1.05rem; }
        .lp-post-body { line-height: 1.65; color: #334155; font-size: .92rem; overflow: hidden; }
        .lp-post-body img { max-width: 100%; height: auto; border-radius: 6px; }
        .lp-post-body a { color: #2563eb; }
        .lp-pin { font-size: .68rem; font-weight: 600; color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 999px; padding: .1rem .5rem; }
    </style>
</head>
<body>

<?php if ($user): ?>
    <?php $nav_active = ''; $nav_user = $user; require __DIR__ . '/_nav.php'; ?>
<?php else:
    // Logged-out viewers: _nav.php renders nothing when show_landing_page=1,
    // so give guests a minimal top bar (same pattern as post_public.php).
    $__hdr_banner = get_setting('header_banner_path', '');
?>
<nav style="background:#0f172a;color:#fff;padding:.75rem 1.25rem;display:flex;align-items:center;gap:1rem;border-bottom:1px solid #1e293b">
    <a href="/" style="color:#fff;text-decoration:none;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:.6rem">
        <?php if ($__hdr_banner): ?>
            <img src="<?= htmlspecialchars($__hdr_banner) ?>" alt="<?= htmlspecialchars($site_name) ?>" style="max-height:40px;width:auto;display:block">
        <?php else: ?>
            <?= htmlspecialchars($site_name) ?>
        <?php endif; ?>
    </a>
    <div style="margin-left:auto;display:flex;gap:.5rem">
        <a href="/login.php?redirect=<?= urlencode($joinRet) ?>" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;background:#2563eb;font-size:.9rem;font-weight:600">Log in</a>
        <?php if ($allowReg): ?>
            <a href="/register.php?redirect=<?= urlencode($joinRet) ?>" style="color:#fff;text-decoration:none;padding:.4rem .9rem;border-radius:6px;border:1px solid #475569;font-size:.9rem;font-weight:600">Sign up</a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>

<div class="lp-layout">
    <?php if ($banner !== ''): ?>
    <div class="lp-hero<?= ($league['banner_fit'] ?? 'cover') === 'contain' ? ' fit' : '' ?>"><img src="<?= htmlspecialchars($banner) ?>" alt="<?= htmlspecialchars($league['name']) ?>"></div>
    <?php endif; ?>

    <h1 class="lp-head">&#127942; <?= htmlspecialchars($league['name']) ?></h1>
    <div class="lp-sub">
        <span>&#128101; <?= $memberCount ?> member<?= $memberCount !== 1 ? 's' : '' ?></span>
        <span><?= $league['approval_mode'] === 'auto' ? '&#9989; Open to join' : '&#128274; Join by approval' ?></span>
        <span>&#128197; <a href="#" id="lp-cal-toggle" role="button" aria-expanded="false" aria-controls="lp-cal"
                          onclick="return lpToggleCal()" style="color:#2563eb;text-decoration:none">Subscribe to calendar <span id="lp-cal-caret">&#9662;</span></a></span>
    </div>

    <div class="lp-cal" id="lp-cal" hidden>
        <div class="lp-cal-row">
            <a class="lp-btn" href="<?= htmlspecialchars($webcalUrl) ?>">&#128197; Subscribe</a>
            <a class="lp-btn secondary" href="<?= htmlspecialchars($icsUrl) ?>">Download .ics</a>
        </div>
        <div class="lp-note" style="margin:.6rem 0 .5rem">
            <strong>Subscribe</strong> keeps the schedule up to date automatically on iPhone, iPad, Mac, and Outlook.
            <strong>Download</strong> adds the current events once and won't update later.
        </div>
        <div class="lp-cal-row">
            <input type="text" id="lp-cal-url" readonly value="<?= htmlspecialchars($icsUrl) ?>" onclick="this.select()">
            <button class="lp-btn secondary" type="button" onclick="lpCopyCal()">Copy link</button>
            <span class="lp-note" id="lp-cal-copied" style="display:none;color:#16a34a">&#10003; Copied</span>
        </div>
        <div class="lp-note" style="margin-top:.45rem">
            For Google Calendar, paste that link into <em>Other calendars &rarr; From URL</em>.
        </div>
    </div>

    <?php if (trim((string)$league['description']) !== ''): ?>
    <div class="lp-desc"><?= nl2br(htmlspecialchars($league['description'])) ?></div>
    <?php endif; ?>

    <div class="lp-join" id="lp-join">
        <?php if ($myRole !== null): ?>
            <a class="lp-btn" href="/league.php?id=<?= $league_id ?>">Open league</a>
            <span class="lp-note">You're a member of this league.</span>
        <?php elseif ($pending): ?>
            <span class="lp-pill">&#8987; Request pending</span>
            <span class="lp-note">The league owner has your join request.</span>
        <?php elseif ($user): ?>
            <button class="lp-btn" id="lp-join-btn" type="button"><?= $league['approval_mode'] === 'auto' ? 'Join this league' : 'Request to join' ?></button>
            <span class="lp-note" id="lp-join-note"></span>
        <?php else: ?>
            <a class="lp-btn" href="/login.php?redirect=<?= urlencode($joinRet) ?>">Log in to join</a>
            <?php if ($allowReg): ?>
                <a class="lp-btn secondary" href="/register.php?redirect=<?= urlencode($joinRet) ?>">Create an account</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="lp-section">
        <h2>&#128197; Upcoming events</h2>
        <?php if (!$events): ?>
            <div class="lp-empty">No upcoming events are scheduled right now. Check back soon.</div>
        <?php else: foreach ($events as $e):
            $lbl = event_public_time_labels($e['start_date'], $e['start_time'] ?: null, $e['end_time'] ?: null, $user ? (int)$user['id'] : null);
        ?>
            <div class="lp-ev">
                <span class="lp-ev-date"><?= htmlspecialchars($lbl['date_lbl']) ?></span>
                <span class="lp-ev-title"><?= htmlspecialchars($e['title']) ?></span>
                <?php if ($lbl['time_lbl'] !== ''): ?><span class="lp-ev-meta">&#128336; <?= $lbl['time_lbl'] ?></span><?php endif; ?>
                <?php if (trim((string)$e['venue_name']) !== ''): ?><span class="lp-ev-meta">&#128205; <?= htmlspecialchars($e['venue_name']) ?></span><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($publicPosts): $post_tz = new DateTimeZone(get_setting('timezone', 'UTC')); ?>
    <div class="lp-section">
        <h2>&#128240; News &amp; announcements</h2>
        <?php foreach ($publicPosts as $p): ?>
        <div class="lp-post">
            <div class="lp-post-meta">
                <?php if ((int)($p['pinned'] ?? 0) === 1): ?><span class="lp-pin">&#128204; Pinned</span><?php endif; ?>
                <span>&#128197; <?= htmlspecialchars((new DateTime($p['created_at'], new DateTimeZone('UTC')))->setTimezone($post_tz)->format('F j, Y')) ?></span>
            </div>
            <div class="lp-post-title"><?= htmlspecialchars($p['title']) ?></div>
            <div class="lp-post-body"><?= sanitize_html($p['content']) ?></div>
            <div style="margin-top:.6rem">
                <a href="/post_public.php?token=<?= urlencode($p['share_token']) ?>" style="color:#2563eb;text-decoration:none;font-size:.85rem;font-weight:600">Comments &rarr;</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($teaser): ?>
    <div class="lp-section">
        <h2>&#127942; Leaderboard</h2>
        <table class="lp-table">
            <thead><tr><th>#</th><th>Player</th><th>Games</th><th>Wins</th><th>Score</th></tr></thead>
            <tbody>
            <?php foreach ($teaser as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars(lp_public_name((string)$row['display_name'])) ?></td>
                    <td><?= (int)$row['games'] ?></td>
                    <td><?= (int)$row['wins'] ?></td>
                    <td><?= $row['avg_score'] !== null ? htmlspecialchars((string)$row['avg_score']) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="lp-note" style="margin-top:.5rem">Join the league to see full standings.</div>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
function lpToggleCal() {
    var box    = document.getElementById('lp-cal');
    var link   = document.getElementById('lp-cal-toggle');
    var caret  = document.getElementById('lp-cal-caret');
    var open   = box.hasAttribute('hidden');
    if (open) { box.removeAttribute('hidden'); } else { box.setAttribute('hidden', ''); }
    link.setAttribute('aria-expanded', open ? 'true' : 'false');
    caret.innerHTML = open ? '&#9652;' : '&#9662;';
    return false;   // never navigate; this is a disclosure toggle, not a jump link
}
function lpCopyCal() {
    var f = document.getElementById('lp-cal-url');
    f.select();
    pkCopy(f.value).then(function (ok) {
        if (!ok) return;
        var m = document.getElementById('lp-cal-copied');
        m.style.display = '';
        setTimeout(function () { m.style.display = 'none'; }, 1800);
    });
}
</script>

<?php if ($user && $myRole === null && !$pending): ?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var btn  = document.getElementById('lp-join-btn');
    var note = document.getElementById('lp-join-note');
    if (!btn) return;
    function doJoin() {
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', <?= json_encode($csrf) ?>);
        fd.append('action', 'request_join');
        fd.append('league_id', '<?= $league_id ?>');
        fetch('/leagues_dl.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.joined) { window.location.href = '/league.php?id=<?= $league_id ?>'; return; }
                if (j && (j.requested || j.ok)) {
                    btn.style.display = 'none';
                    note.textContent = 'Request sent. The league owner will review it.';
                    return;
                }
                btn.disabled = false;
                note.textContent = (j && j.error) ? j.error : 'Something went wrong. Please try again.';
            })
            .catch(function () { btn.disabled = false; note.textContent = 'Something went wrong. Please try again.'; });
    }
    btn.addEventListener('click', doJoin);
    <?php if ($autoJoin): ?>
    // Arrived back from login/register with join intent: fire it once, then clean the URL.
    history.replaceState(null, '', <?= json_encode($pubPath) ?>);
    doJoin();
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
