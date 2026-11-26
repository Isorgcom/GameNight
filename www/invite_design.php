<?php
/**
 * Invite design: the host's editor for how one event's invitation looks.
 *
 * Ready-made looks, a cover picture, the background, the accent colour, the
 * title font and an effect, beside a live preview. The preview is the real
 * invite page (invite.php?preview=1) in a frame, so what the host sees is what
 * a guest gets; invite_design.js changes the look by changing the attributes
 * on that page, the same ones the server writes.
 *
 * There is no Save button: each pick is stored as it is made
 * (invite_design_dl.php) and the status line says so. The parts are keys from
 * the closed catalogue in _invite_design.php, never free text.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/_invite_design.php';

$current = require_login();
$db      = get_db();
$isAdmin = ($current['role'] ?? '') === 'admin';

$eid = (int)($_GET['id'] ?? 0);
if ($eid <= 0 || !can_manage_event($db, $eid, (int)$current['id'], $isAdmin)) {
    http_response_code(403);
    exit('Access denied.');
}
$q = $db->prepare('SELECT id, title, cancelled_at, invite_design FROM events WHERE id = ?');
$q->execute([$eid]);
$event = $q->fetch();
if (!$event) { http_response_code(404); exit('Event not found.'); }
if (!empty($event['cancelled_at'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => EVENT_CANCELLED_MSG . ' Duplicate it to plan a new one.'];
    header('Location: /event.php?id=' . $eid);
    exit;
}

$design    = invite_design_decode($event['invite_design'], $eid);
$cat       = invite_design_catalog();
$site_name = get_setting('site_name', 'Game Night');
$backUrl   = '/event.php?id=' . $eid;
$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE);
$ver = fn(string $f) => $h(APP_VERSION . '.' . (@filemtime(__DIR__ . '/' . $f) ?: 0));
$on  = fn(bool $b) => $b ? 'true' : 'false';

// Which ready-made look, if any, the stored parts add up to.
$lookNow = '';
foreach ($cat['looks'] as $lk => $l) {
    if ($l['theme'] === $design['theme'] && $l['accent'] === $design['accent']
        && $l['font'] === $design['font'] && $l['effect'] === $design['effect']) { $lookNow = $lk; break; }
}
$modes = [];
foreach ($cat['themes'] as $k => $t) $modes[$k] = $t['mode'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Invite design &ndash; <?= $h($event['title']) ?> &ndash; <?= $h($site_name) ?></title>
    <link rel="stylesheet" href="/style.css?v=<?= $ver('style.css') ?>">
    <link rel="stylesheet" href="/fonts.css?v=<?= $ver('fonts.css') ?>">
    <link rel="stylesheet" href="/invite_look.css?v=<?= $ver('invite_look.css') ?>">
    <style>
        .idz-wrap { max-width: 1080px; margin: 1.25rem auto 2.5rem; padding: 0 1rem; }
        .idz-head { display: flex; align-items: center; gap: .5rem .75rem; flex-wrap: wrap; margin-bottom: .35rem; }
        .idz-head h1 { font-size: 1.4rem; font-weight: 700; margin: 0; flex: 1 1 auto; min-width: 0; color: var(--fg-1e293b, #1e293b); }
        .idz-head .btn { text-decoration: none; }
        .idz-sub { color: var(--fg-64748b, #64748b); font-size: .9rem; line-height: 1.55; margin: 0 0 1.1rem; max-width: 62ch; overflow-wrap: anywhere; }
        .idz-sub strong { color: var(--fg-334155, #334155); }

        .idz-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 1.5rem; align-items: start; }
        .idz-controls { min-width: 0; }
        .idz-group { background: var(--bg-ffffff, #ffffff); border: 1.5px solid var(--bd-e2e8f0, #e2e8f0); border-radius: 12px; padding: 1rem 1.1rem 1.1rem; margin-bottom: .9rem; }
        .idz-group h2 { font-size: .95rem; font-weight: 700; color: var(--fg-1e293b, #1e293b); margin: 0 0 .2rem; }
        .idz-hint { font-size: .82rem; color: var(--fg-64748b, #64748b); line-height: 1.5; margin: 0 0 .75rem; }
        .idz-tiles { display: flex; flex-wrap: wrap; gap: .6rem; }

        /* One pick: a tile with its name under it. aria-pressed marks the choice. */
        .idz-pick { display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: 0; border: 0; background: none; cursor: pointer;
                    font: inherit; font-size: .74rem; font-weight: 600; color: var(--fg-475569, #475569); }
        .idz-pick .idz-face { display: flex; align-items: center; justify-content: center; border-radius: 10px; border: 1.5px solid var(--bd-cbd5e1, #cbd5e1);
                              overflow: hidden; transition: box-shadow .15s, transform .1s; }
        .idz-pick:hover .idz-face { transform: translateY(-1px); }
        .idz-pick[aria-pressed="true"] { color: var(--fg-1e293b, #1e293b); }
        .idz-pick[aria-pressed="true"] .idz-face { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent); }
        .idz-pick:focus-visible { outline: none; }
        .idz-pick:focus-visible .idz-face { outline: 2px solid var(--accent-h); outline-offset: 4px; }

        /* Looks: the background with a small card on it, title letters in the look's font. */
        .idz-look .idz-face { width: 104px; height: 74px; background: var(--ivd-bg, var(--bg-f1f5f9, #f1f5f9)); }
        /* The little card is a picture of the invite's own card: fixed light or
           dark when the background pins one (data-theme marks it as having its
           own look), the viewer's setting for Classic. */
        .idz-mini { width: 62px; height: 46px; border-radius: 6px; background: var(--bg-ffffff, #ffffff); color: var(--fg-0f172a, #0f172a); box-shadow: 0 3px 10px rgba(0,0,0,.28);
                    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; }
        .idz-mini[data-theme="light"] { background: #ffffff; color: #0f172a; }
        .idz-mini[data-theme="dark"] { background: #0f172a; color: #f1f5f9; }
        .idz-mini b { font-size: 1.05rem; line-height: 1; font-weight: 400; }
        .idz-mini i { display: block; width: 30px; height: 6px; border-radius: 3px; background: var(--accent); }
        .idz-bg .idz-face { width: 66px; height: 50px; background: var(--ivd-bg, var(--bg-f8fafc, #f8fafc)); }
        .idz-accent .idz-face { width: 38px; height: 38px; border-radius: 50%; border-color: transparent; }
        .idz-font .idz-face { width: 70px; height: 50px; background: var(--bg-f8fafc, #f8fafc); color: var(--fg-0f172a, #0f172a); font-size: 1.35rem; font-weight: 400; line-height: 1; }
        .idz-f-default  { font-family: inherit; font-weight: 800 !important; }
        .idz-f-playfair { font-family: 'Playfair Display', Georgia, serif; }
        .idz-f-cinzel   { font-family: 'Cinzel', Georgia, serif; }
        .idz-f-oswald   { font-family: 'Oswald', 'Arial Narrow', sans-serif; }
        .idz-f-bebas    { font-family: 'Bebas Neue', Impact, sans-serif; }
        .idz-f-anton    { font-family: 'Anton', Impact, sans-serif; }
        .idz-f-lobster  { font-family: 'Lobster', cursive; }
        .idz-f-rye      { font-family: 'Rye', Georgia, serif; }
        .idz-f-monoton  { font-family: 'Monoton', Impact, sans-serif; }
        .idz-f-orbitron { font-family: 'Orbitron', sans-serif; }

        /* Cover */
        .idz-cover-row { display: flex; align-items: center; gap: .6rem .9rem; flex-wrap: wrap; }
        .idz-cover-thumb { width: 116px; height: 78px; border-radius: 10px; border: 1.5px dashed var(--bd-cbd5e1, #cbd5e1); background: var(--bg-f8fafc, #f8fafc);
                           display: flex; align-items: center; justify-content: center; overflow: hidden; font-size: .74rem; color: var(--fg-64748b, #64748b); flex-shrink: 0; }
        .idz-cover-thumb.has { border-style: solid; }
        .idz-cover-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .idz-cover-btns { display: flex; gap: .5rem; flex-wrap: wrap; }
        .idz-cover-btns [hidden] { display: none; }
        .idz-err { background: var(--bg-fef2f2, #fef2f2); border: 1.5px solid var(--bd-fca5a5, #fca5a5); color: var(--fg-991b1b, #991b1b); border-radius: 8px;
                   padding: .55rem .75rem; font-size: .85rem; margin: .75rem 0 0; }

        /* Effect: the house segmented control, stretched to the row. */
        .idz-fx .pk-seg { display: flex; width: 100%; max-width: 440px; }
        .idz-fx .pk-seg button { flex: 1 1 0; padding: .45rem .25rem; font-size: .8rem; }
        .idz-foot { display: flex; align-items: center; gap: .6rem 1rem; flex-wrap: wrap; margin-top: .25rem; }
        .idz-reset { background: none; border: 0; padding: .3rem 0; font: inherit; font-size: .85rem; font-weight: 600; color: var(--link); cursor: pointer; }
        .idz-reset:hover { text-decoration: underline; }

        /* Preview: a phone-sized frame holding the real invite page. */
        /* --idz-top is the height of the site nav, which is sticky itself; invite_design.js measures it. */
        .idz-preview { position: sticky; top: calc(var(--idz-top, 0px) + .75rem); }
        .idz-status { font-size: .82rem; font-weight: 600; color: var(--fg-64748b, #64748b); min-height: 1.3em; margin: 0 0 .5rem; text-align: center; }
        .idz-status.ok { color: var(--fg-166534, #166534); }
        .idz-status.bad { color: var(--fg-991b1b, #991b1b); }
        .idz-phone { border: 8px solid #0f172a; border-radius: 30px; overflow: hidden; background: #0f172a; box-shadow: 0 14px 40px rgba(0,0,0,.25); }
        .idz-phone iframe { display: block; width: 100%; height: min(640px, calc(100vh - var(--idz-top, 0px) - 8rem)); min-height: 340px; border: 0; background: var(--bg-ffffff, #ffffff); border-radius: 22px; }
        .idz-cap { font-size: .8rem; color: var(--fg-64748b, #64748b); line-height: 1.5; margin: .7rem .25rem 0; text-align: center; }

        @media (max-width: 860px) {
            .idz-grid { grid-template-columns: minmax(0, 1fr); gap: 1rem; }
            /* On a phone the preview leads and stays in view while the picks scroll under it. */
            .idz-preview { order: -1; top: var(--idz-top, 0px); z-index: 20; background: var(--bg, var(--bg-f8fafc, #f8fafc)); margin: 0 -1rem; padding: .5rem 1rem .6rem;
                           border-bottom: 1px solid var(--bd-e2e8f0, #e2e8f0); }
            .idz-phone { border-width: 5px; border-radius: 20px; max-width: 420px; margin: 0 auto; }
            .idz-phone iframe { height: 40vh; height: 40dvh; min-height: 250px; border-radius: 15px; }
            .idz-cap { display: none; }
            .idz-status { margin-bottom: .35rem; }
            .idz-look .idz-face { width: 92px; height: 66px; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/_nav.php'; ?>
<div role="main" class="idz-wrap">
    <div class="idz-head">
        <h1>Invite design</h1>
        <a class="btn btn-primary" href="<?= $h($backUrl) ?>">Done</a>
    </div>
    <p class="idz-sub">How the invitation to <strong><?= $h($event['title']) ?></strong> looks to your guests: on the invite link, on each guest's own link and on the QR poster. The invitation email carries the cover picture and the accent colour.</p>

    <div class="idz-grid">
        <div class="idz-controls">

            <section class="idz-group" aria-labelledby="idzLooksT">
                <h2 id="idzLooksT">Ready-made looks</h2>
                <p class="idz-hint">A starting point. Pick one, then change any part of it below.</p>
                <div class="idz-tiles" role="group" aria-labelledby="idzLooksT">
                    <?php foreach ($cat['looks'] as $lk => $l): ?>
                    <button type="button" class="idz-pick idz-look" data-look="<?= $h($lk) ?>" aria-pressed="<?= $on($lk === $lookNow) ?>"
                            data-act="idzLook" data-a1="<?= $h($lk) ?>">
                        <span class="idz-face" data-ivd-theme="<?= $h($l['theme']) ?>">
                            <span class="idz-mini" data-ivd-accent="<?= $h($l['accent']) ?>"<?= $cat['themes'][$l['theme']]['mode'] !== '' ? ' data-theme="' . $h($cat['themes'][$l['theme']]['mode']) . '"' : '' ?>><b class="idz-f-<?= $h($l['font']) ?>">Aa</b><i></i></span>
                        </span>
                        <span><?= $h($l['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="idz-group" aria-labelledby="idzCoverT">
                <h2 id="idzCoverT">Cover picture</h2>
                <p class="idz-hint">A photo, a poster or a GIF of your own. It tops the invite, leads the invitation email, and is the picture that shows when the link is pasted into a chat.</p>
                <div class="idz-cover-row">
                    <div class="idz-cover-thumb<?= $design['cover'] !== '' ? ' has' : '' ?>" id="idzCoverThumb">
                        <?php if ($design['cover'] !== ''): ?><img src="<?= $h($design['cover']) ?>" alt="Current cover"><?php else: ?>No cover<?php endif; ?>
                    </div>
                    <div class="idz-cover-btns">
                        <button type="button" class="btn btn-primary" id="idzCoverBtn" data-act="idzCoverPick"><?= $design['cover'] !== '' ? 'Replace picture' : 'Upload a picture' ?></button>
                        <button type="button" class="btn btn-outline" id="idzCoverRm" data-act="idzCoverRemove"<?= $design['cover'] === '' ? ' hidden' : '' ?>>Remove</button>
                    </div>
                    <input type="file" id="idzCoverFile" accept="image/*" hidden data-act-change="idzCoverChosen" data-change-a1="@self" aria-label="Cover picture file">
                </div>
                <p class="idz-err" id="idzCoverErr" role="alert" hidden></p>
            </section>

            <section class="idz-group" aria-labelledby="idzBgT">
                <h2 id="idzBgT">Background</h2>
                <p class="idz-hint">What sits behind the invite card. Classic is the site's own look and follows each guest's light or dark setting.</p>
                <div class="idz-tiles" role="group" aria-labelledby="idzBgT">
                    <?php foreach ($cat['themes'] as $k => $t): ?>
                    <button type="button" class="idz-pick idz-bg" data-kind="theme" data-key="<?= $h($k) ?>" aria-pressed="<?= $on($k === $design['theme']) ?>"
                            data-act="idzPick" data-a1="theme" data-a2="<?= $h($k) ?>">
                        <span class="idz-face" data-ivd-theme="<?= $h($k) ?>"></span>
                        <span><?= $h($t['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="idz-group" aria-labelledby="idzAccentT">
                <h2 id="idzAccentT">Accent colour</h2>
                <p class="idz-hint">Buttons and links on the invite, and the main button in the email.</p>
                <div class="idz-tiles" role="group" aria-labelledby="idzAccentT">
                    <?php foreach ($cat['accents'] as $k => $a): ?>
                    <button type="button" class="idz-pick idz-accent" data-kind="accent" data-key="<?= $h($k) ?>" aria-pressed="<?= $on($k === $design['accent']) ?>"
                            data-act="idzPick" data-a1="accent" data-a2="<?= $h($k) ?>">
                        <span class="idz-face" style="background:<?= $h($a['fill']) ?>"></span>
                        <span><?= $h($a['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="idz-group" aria-labelledby="idzFontT">
                <h2 id="idzFontT">Title font</h2>
                <p class="idz-hint">The event's name at the top of the invite.</p>
                <div class="idz-tiles" role="group" aria-labelledby="idzFontT">
                    <?php foreach ($cat['fonts'] as $k => $f): ?>
                    <button type="button" class="idz-pick idz-font" data-kind="font" data-key="<?= $h($k) ?>" aria-pressed="<?= $on($k === $design['font']) ?>"
                            data-act="idzPick" data-a1="font" data-a2="<?= $h($k) ?>">
                        <span class="idz-face idz-f-<?= $h($k) ?>" aria-hidden="true">Aa</span>
                        <span><?= $h($f['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="idz-group idz-fx" aria-labelledby="idzFxT">
                <h2 id="idzFxT">Effect</h2>
                <p class="idz-hint">A few seconds of movement when the invite opens, then it stops. Guests who have asked their device for less motion do not get it.</p>
                <div class="pk-seg" id="idzFxSeg" role="group" aria-labelledby="idzFxT">
                    <span class="pk-seg-thumb"></span>
                    <?php foreach ($cat['effects'] as $k => $e): ?>
                    <button type="button" data-fx="<?= $h($k) ?>" class="<?= $k === $design['effect'] ? 'active' : '' ?>" aria-pressed="<?= $on($k === $design['effect']) ?>"
                            data-act="idzPick" data-a1="effect" data-a2="<?= $h($k) ?>"><?= $h($e['name']) ?></button>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="idz-foot">
                <a class="btn btn-primary" style="text-decoration:none" href="<?= $h($backUrl) ?>">Done</a>
                <button type="button" class="idz-reset" data-act="idzReset">Back to the standard look</button>
            </div>
        </div>

        <aside class="idz-preview" aria-label="Preview">
            <p class="idz-status" id="idzStatus" role="status">Changes save as you make them.</p>
            <div class="idz-phone">
                <iframe id="idzFrame" title="Preview of the invite" src="/invite.php?e=<?= (int)$eid ?>&amp;preview=1"></iframe>
            </div>
            <p class="idz-cap">The invite link, as a guest sees it before signing up. Nothing in the preview can be tapped.</p>
        </aside>
    </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
<script nonce="<?= csp_nonce() ?>">window.IDZ = <?= js_json([
    'eid'    => $eid,
    'csrf'   => csrf_token(),
    'design' => $design,
    'looks'  => $cat['looks'],
    'modes'  => $modes,
    'maxBytes' => INVITE_COVER_MAX_BYTES,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/invite_design.js?v=<?= $ver('invite_design.js') ?>" defer></script>
</body>
</html>
