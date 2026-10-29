<?php
/**
 * BAIT - Reusable frontend component renderers (Phase 3).
 * Every function returns an HTML string; callers echo it. Keeping these as
 * pure functions (rather than copy-pasted include partials) avoids PHP's
 * include-scope variable leakage and lets every page share one definition.
 */

require_once __DIR__ . '/helpers.php';

/** Inline SVG icon reference into the sprite defined in layout/icons.php. */
function icon(string $name, string $class = ''): string {
    $cls = trim('icon ' . $class);
    return '<svg class="' . e($cls) . '" aria-hidden="true" focusable="false"><use href="#icon-' . e($name) . '"></use></svg>';
}

/**
 * Renders an admin-uploaded custom icon (info_links.icon_image / form_categories.image).
 * SVGs are inlined (sanitized) rather than put in an <img> — an <img>-loaded SVG
 * is rendered in its own document context, so a `stroke="currentColor"` icon (e.g.
 * anything copied from Feather Icons, per the admin upload hint) always comes out
 * black/whatever color is baked into the file, ignoring the card's CSS `color` —
 * which is exactly why a downloaded icon "doesn't match" the sprite icons next to
 * it. Inlined into the page, `currentColor` resolves against `.info-link-icon`'s
 * `color` like the sprite icons do, so it recolors (and hover-swaps to white)
 * identically. Raster formats (png/jpg/gif/webp) have their color baked into
 * pixels either way, so they stay plain <img> — nothing to gain from inlining.
 */
function customIcon(string $relativePath, string $alt = ''): string {
    $absolutePath = UPLOAD_PATH . '/' . $relativePath;
    if (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === 'svg') {
        $inline = sanitizeSvgForInline($absolutePath);
        if ($inline !== null) return $inline;
    }
    return '<img src="' . e(UPLOAD_URL . '/' . $relativePath) . '" alt="' . e($alt) . '"' . imgSizeAttrs($relativePath) . ' loading="lazy" decoding="async">';
}

/**
 * Strips anything script-capable out of an admin-uploaded SVG before it gets
 * inlined into a public page (unlike an <img src="...svg">, inline markup runs
 * as live DOM — a <script> or onload="" in the file would execute). Whitelists
 * known-safe shape/container elements only; drops everything else (script,
 * foreignObject, style with url(), event-handler attributes, javascript: URLs).
 * Returns null on any parse failure so the caller can fall back to <img>.
 */
function sanitizeSvgForInline(string $absolutePath): ?string {
    if (!is_file($absolutePath)) return null;
    $raw = @file_get_contents($absolutePath);
    if ($raw === false || stripos($raw, '<svg') === false) return null;

    // Reject a DOCTYPE outright rather than trust libxml's default external-entity
    // handling to stay disabled across environments — cheap, and this file only
    // ever needs to be a bare <svg>, never a document with its own doctype.
    if (stripos($raw, '<!doctype') !== false) return null;

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($raw, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') return null;

    $allowedTags = ['svg','g','path','circle','rect','ellipse','line','polyline','polygon','defs','symbol','use','title','desc','linearGradient','radialGradient','stop','clippath','mask'];
    $disallowedAttrPrefixes = ['on'];

    $strip = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if (!in_array(strtolower($el->localName), $allowedTags, true)) { $strip[] = $el; continue; }
        $attrsToRemove = [];
        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue);
            $isEventAttr = false;
            foreach ($disallowedAttrPrefixes as $p) { if (str_starts_with($name, $p)) { $isEventAttr = true; break; } }
            // style="" dropped too — besides being a CSS-injection surface, a
            // baked-in inline color/fill here would fight the whole point of
            // inlining: letting the icon inherit the card's currentColor.
            if ($isEventAttr || $name === 'style' || stripos($value, 'javascript:') === 0) $attrsToRemove[] = $attr->nodeName;
        }
        foreach ($attrsToRemove as $name) $el->removeAttribute($name);
    }
    foreach ($strip as $el) $el->parentNode?->removeChild($el);

    $svg = $doc->documentElement;
    $svg->removeAttribute('width');
    $svg->removeAttribute('height');
    $existingClass = trim($svg->getAttribute('class') . ' icon');
    $svg->setAttribute('class', $existingClass);
    $svg->setAttribute('aria-hidden', 'true');
    $svg->setAttribute('focusable', 'false');

    return $doc->saveXML($svg) ?: null;
}

/**
 * Inline `style="aspect-ratio:W/H"` for a card thumb, sized to a photo's own
 * aspect ratio instead of a fixed crop, so nothing gets cropped off. Needed
 * *before* the (lazy-loaded) image itself has downloaded, or the box
 * collapses to 0 height and the card is blank until it finishes loading.
 * getimagesize() reads just the file header, not the whole image, so this
 * is cheap enough to call on every card render.
 */
function thumbRatioStyle(?string $relativeImagePath): string {
    if (!$relativeImagePath) return '';
    // Some hosts restrict/disable getimagesize() (open_basedir, disable_functions);
    // calling a disabled function throws a catchable Error in PHP 7+, which the
    // @ operator alone does NOT suppress — this is purely a cosmetic nicety, so
    // any failure here must never be allowed to take the whole page down with it.
    try {
        $dims = @getimagesize(UPLOAD_PATH . '/' . $relativeImagePath);
    } catch (\Throwable $e) {
        return '';
    }
    return $dims ? ' style="aspect-ratio:' . $dims[0] . '/' . $dims[1] . '"' : '';
}

/** Translate-or-escape shortcut used throughout the render functions below. */
function tx(string $text, string $lang): string {
    if ($text === '') return '';
    return $lang === 'en' ? translateText($text, 'en') : $text;
}

/**
 * News card. Expects: news_id, title, image, created_at, view_count,
 * category_name, color, tags (optional).
 */
function newsCard(array $n, string $lang): string {
    $url   = BASE_URL . '/news-detail.php?id=' . (int)$n['news_id'] . '&lang=' . e($lang);
    $title = e(tx($n['title'], $lang));
    $cat   = e(tx($n['category_name'], $lang));
    $color = e($n['color'] ?? '#1558A6');
    $date  = thaiDate($n['created_at']);
    $views = number_format((int)($n['view_count'] ?? 0));

    // News is a high-volume, many-contributors feed — unlike a campus/major's
    // one curated photo, thumbnails here come from whoever wrote the post,
    // in whatever size they happened to upload. Fitting the box to each
    // photo's own ratio (like campusCard()) makes a grid of them look
    // uneven/messy, so this stays a fixed crop instead.
    $thumb = !empty($n['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $n['image']) . '" alt=""' . imgSizeAttrs($n['image']) . ' loading="lazy" decoding="async">'
        : '<div class="card-thumb-fallback">' . icon('newspaper') . '</div>';

    $tagsHtml = '';
    if (!empty($n['tags'])) {
        $tags = array_slice(array_filter(array_map('trim', explode(',', $n['tags']))), 0, 3);
        if ($tags) {
            $tagsHtml = '<div class="card-tags">' . implode('', array_map(
                fn($t) => '<span class="tag-chip">#' . e($lang === 'en' ? translateText($t, 'en') : $t) . '</span>', $tags
            )) . '</div>';
        }
    }
    $sdgHtml   = !empty($n['sdgs']) ? sdgBadges($n['sdgs'], $lang, true) : '';   // the goals, when the caller attached them (attachNewsSdgs())
    $readMore  = $lang === 'en' ? 'Read more' : 'อ่านเพิ่มเติม';
    $arrowIcon = icon('arrow-right');

    return <<<HTML
    <article class="card card-news reveal">
      <a href="{$url}" class="card-thumb-link" aria-hidden="true" tabindex="-1">
        <div class="card-thumb">{$thumb}</div>
      </a>
      <div class="card-body">
        <div class="card-meta">
          <span class="card-badge card-badge-news card-badge-inline" style="--badge-color:{$color}">{$cat}</span>
          <span>{$date}</span>
          <span class="dot-sep" aria-hidden="true">&middot;</span>
          <span>{$views}</span>
        </div>
        <h3 class="card-title"><a href="{$url}">{$title}</a></h3>
        {$sdgHtml}
        {$tagsHtml}
        <a href="{$url}" class="card-readmore">{$readMore}<span class="visually-hidden">: {$title}</span> {$arrowIcon}</a>
      </div>
    </article>
    HTML;
}

/**
 * programs.about is stored as a JSON string ({"description":"...","topics":[...]})
 * — see program-detail.php's renderAbout(). Cards only need the plain
 * description line, with a fallback for legacy plain-text rows.
 */
function programAboutSummary(?string $raw): string {
    if (!$raw) return '';
    $data = json_decode($raw, true);
    if (is_array($data)) return trim(strip_tags($data['description'] ?? ''));
    return trim(strip_tags($raw));
}

/**
 * Programs have no `image` column in the live database, so the fallback
 * thumbnail is the norm rather than the exception — give it a level-tinted
 * gradient (using tokens already in the brand palette) instead of one flat
 * placeholder color for every program regardless of level.
 */
function programLevelThumbClass(string $levelAbbr): string {
    return match (true) {
        str_contains($levelAbbr, 'ปวช') => 'thumb-level-1',
        str_contains($levelAbbr, 'ปวส') => 'thumb-level-2',
        str_contains($levelAbbr, 'โท')  => 'thumb-level-4',
        str_contains($levelAbbr, 'เอก') => 'thumb-level-5',
        default => 'thumb-level-3', // ป.ตรี and anything unrecognized
    };
}

/** Program card. Expects: program_id, program_name, image, level_abbr, major_name, about (optional), campus_name (optional). */
function programCard(array $p, string $lang): string {
    $url   = BASE_URL . '/program-detail.php?id=' . (int)$p['program_id'] . '&lang=' . e($lang);
    $name  = e(tx($p['program_name'], $lang));
    $major = e(tx($p['major_name'] ?? '', $lang));
    $level = e(tx($p['level_abbr'] ?? '', $lang));
    // campus_names is a GROUP_CONCAT of every campus this program is linked
    // to via program_campuses; empty means no campus was singled out, i.e.
    // it's offered at all of them (same convention as the admin form).
    $campus = !empty($p['campus_names']) ? e(tx($p['campus_names'], $lang)) : ($lang === 'en' ? 'All Campuses' : 'ทุกศูนย์');

    $desc = programAboutSummary($p['about'] ?? '');
    $descHtml = $desc
        ? e(mb_strimwidth(tx(mb_substr($desc, 0, 140, 'UTF-8'), $lang), 0, 90, '...', 'UTF-8'))
        : $major;

    $thumb = !empty($p['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $p['image']) . '" alt=""' . imgSizeAttrs($p['image']) . ' loading="lazy" decoding="async">'
        : '<div class="card-thumb-fallback ' . programLevelThumbClass($p['level_abbr'] ?? '') . '">' . icon('graduation-cap') . '</div>';
    $pinIcon = icon('pin', 'icon-sm');
    // closed for applications (หลักสูตร > สถานะการรับสมัคร): still listed so visitors can find it, marked on the picture
    $closed = isset($p['is_active']) && (int)$p['is_active'] !== 1;
    $closedBadge = $closed ? '<span class="card-badge card-badge-closed">' . ($lang === 'en' ? 'Applications closed' : 'ปิดรับสมัคร') . '</span>' : '';
    $closedClass = $closed ? ' is-closed' : '';

    return <<<HTML
    <article class="card card-program{$closedClass} reveal">
      <a href="{$url}" class="card-thumb-link">
        <div class="card-thumb">{$thumb}{$closedBadge}<span class="card-badge card-badge-level">{$level}</span></div>
      </a>
      <div class="card-body">
        <h3 class="card-title"><a href="{$url}">{$name}</a></h3>
        <p class="card-excerpt">{$descHtml}</p>
        <div class="card-meta">{$pinIcon} {$campus}</div>
      </div>
    </article>
    HTML;
}

/** Major/department card — reuses .card-program's visuals so it drops
 *  into the same grid as programCard() with no new CSS. Expects: major_id,
 *  major_name, major_abbr (optional), image (optional), program_count. */
function majorCard(array $m, string $lang): string {
    $url   = BASE_URL . '/programs.php?major=' . (int)$m['major_id'] . '&lang=' . e($lang);
    $name  = e(tx($m['major_name'], $lang));
    $abbr  = e($m['major_abbr'] ?? '');
    $count = (int)($m['program_count'] ?? 0);
    $countLabel = $lang === 'en'
        ? $count . ' program' . ($count === 1 ? '' : 's')
        : $count . ' หลักสูตร';

    $thumbRatioStyle = thumbRatioStyle($m['image'] ?? null);

    $thumb = !empty($m['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $m['image']) . '" alt=""' . imgSizeAttrs($m['image']) . ' loading="lazy" decoding="async">'
        : '<div class="card-thumb-fallback">' . icon('graduation-cap') . '</div>';
    $pinIcon = icon('book', 'icon-sm');

    return <<<HTML
    <article class="card card-program reveal">
      <a href="{$url}" class="card-thumb-link">
        <div class="card-thumb"{$thumbRatioStyle}>{$thumb}<span class="card-badge card-badge-level">{$abbr}</span></div>
      </a>
      <div class="card-body">
        <h3 class="card-title"><a href="{$url}">{$name}</a></h3>
        <div class="card-meta">{$pinIcon} {$countLabel}</div>
      </div>
    </article>
    HTML;
}

/** "จุดเด่นของคณะ" card (home page) — image/video only, no visible title or
 *  description; just the icon/media filling the whole card. Expects: icon,
 *  title (used only as alt/aria text), media_type ('none'|'image'|'video'),
 *  media_path (optional). Admin-managed via admin/modules/highlights —
 *  falls back to the icon when no media has been uploaded for that item. */
function highlightCard(array $h, string $lang): string {
    $title = e(tx($h['title'], $lang));

    if ($h['media_type'] === 'image' && $h['media_path']) {
        // Per-photo crop scale (measured client-side at upload time — see
        // admin/modules/highlights/index.php's detectCropScale()) trims
        // exactly as much of each photo's own baked-in transparent margin
        // as that photo actually has. Falls back to pages.css's flat
        // default for older highlights saved before this existed.
        $cropStyle = '';
        if (!empty($h['crop_scale_x']) && !empty($h['crop_scale_y'])) {
            $cropStyle = ' style="--crop-x:' . e((string)$h['crop_scale_x']) . ';--crop-y:' . e((string)$h['crop_scale_y']) . '"';
        }
        $thumb = '<img src="' . e(UPLOAD_URL . '/' . $h['media_path']) . '" alt="' . $title . '"' . $cropStyle . imgSizeAttrs($h['media_path']) . ' loading="lazy" decoding="async">';
    } elseif ($h['media_type'] === 'video' && $h['media_path']) {
        $thumb = '<video src="' . e(UPLOAD_URL . '/' . $h['media_path']) . '" muted loop playsinline autoplay preload="metadata" aria-label="' . $title . '"></video>';
    } else {
        $thumb = '<div class="card-thumb-fallback">' . icon($h['icon'] ?: 'lightbulb') . '</div>';
    }

    return <<<HTML
    <article class="card card-highlight reveal" aria-label="{$title}">
      <div class="card-thumb">{$thumb}</div>
    </article>
    HTML;
}

/**
 * One item inside an admin-managed "grid"-type about_sections block (see
 * admin/modules/faculty/section-form.php + about.php). Deliberately a
 * simpler sibling of highlightCard() above — image-or-icon only, no video/
 * crop-scale — since each grid section here is its own independent,
 * admin-created gallery rather than the one fixed homepage highlights set.
 */
function aboutGridItemCard(array $item, string $lang): string {
    $title = e(tx($item['title'], $lang));
    $thumb = !empty($item['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $item['image']) . '" alt="' . $title . '"' . imgSizeAttrs($item['image']) . ' loading="lazy" decoding="async">'
        : '<div class="card-thumb-fallback">' . icon($item['icon'] ?: 'lightbulb') . '</div>';

    return <<<HTML
    <article class="card card-highlight reveal" aria-label="{$title}">
      <div class="card-thumb">{$thumb}</div>
    </article>
    HTML;
}

/** Campus card. Expects: campus_id, campus_name, image, address (optional), map_url (optional). */
function campusCard(array $c, string $lang): string {
    $name = e(tx($c['campus_name'], $lang));
    $addr = !empty($c['address']) ? e(mb_strimwidth(tx($c['address'], $lang), 0, 70, '...', 'UTF-8')) : '';
    $mapUrl = !empty($c['map_url']) ? $c['map_url'] : (BASE_URL . '/campus-detail.php#campus-' . (int)$c['campus_id']);
    $viewLabel = $lang === 'en' ? 'View Map' : 'ดูแผนที่';

    $thumbRatioStyle = thumbRatioStyle($c['image'] ?? null);

    $thumb = !empty($c['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $c['image']) . '" alt=""' . imgSizeAttrs($c['image']) . ' loading="lazy" decoding="async">'
        : '<div class="card-thumb-fallback">' . icon('building') . '</div>';
    $pinIcon = icon('pin', 'icon-sm');

    return <<<HTML
    <article class="card card-campus reveal">
      <div class="card-thumb overlay-dark"{$thumbRatioStyle}>
        {$thumb}
        <a href="{$mapUrl}" target="_blank" rel="noopener" class="card-thumb-cta">{$pinIcon} {$viewLabel}</a>
      </div>
      <div class="card-body">
        <h3 class="card-title">{$name}</h3>
        <p class="card-excerpt">{$addr}</p>
      </div>
    </article>
    HTML;
}

/**
 * Staff card. Expects: full_name, prefix (optional), image, position_name, responsibility (optional), e_profile_url (optional).
 * $compact drops the position AND responsibility lines, leaving just the
 * name — used on the ประจำศูนย์/ประจำสาขา drill-down, where every card in a
 * group already shares roughly the same position and showing it (or not,
 * depending on whether responsibility happens to be filled in) made cards
 * in the same group look inconsistent. $headStaffId + $headLabel add a
 * badge instead — a deliberate, explicit marker rather than text that
 * happens to be sitting in a data field.
 */
/**
 * Escaped text in which a line may only wrap at a real space: each space-separated chunk is unbreakable
 * (.staff-nw), because the browser's Thai line-breaker otherwise splits words like "ชำนาญการ" or "สถาน|ศึกษา"
 * (word-break:keep-all is not honoured for Thai). A single chunk longer than $maxChunk characters is left
 * breakable so it can never push past a phone screen — put spaces in such long texts to keep them whole.
 */
function thaiNoBreak(string $t, int $maxChunk = 30): string {
    return implode(' ', array_map(
        fn($w) => mb_strlen($w) > $maxChunk ? e($w) : '<span class="staff-nw">' . e($w) . '</span>',
        preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY)
    ));
}

/**
 * "IT NEWS วันนี้" card on the news page (full width above the news list): a header row (title, date, ‹ ›) and, below it,
 * one headline at a time that changes by itself every 5 seconds — it stops while hovered / focused,
 * in a background tab and for people who ask for less motion; dots and swipe work too.
 * Each headline links out to the full article on the source site. With no headlines yet only an empty placeholder is
 * printed. The card sits in #itxLive (data-itnews-live) so itNewsFetchLater() can swap in a fresh copy after a read, and
 * window.itxInit() starts any card that is not running yet.
 */
function itNewsCarousel(array $items, string $lang): string {
    $en = $lang === 'en';
    $days = $en ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    $newest = $items ? strtotime($items[0]['published_at']) : time();
    $dateLabel = $days[(int)date('w', $newest)] . ' ' . thaiDate(date('Y-m-d', $newest));
    $n = count($items);
    $slides = ''; $dots = '';
    foreach ($items as $i => $it) {
        $slides .= '<article class="itx-slide' . ($i ? '' : ' is-on') . '" aria-roledescription="slide" aria-label="' . ($i + 1) . ' / ' . $n . '"' . ($i ? ' aria-hidden="true"' : '') . '>'
                 . '<span class="itx-num" aria-hidden="true">' . ($i + 1) . '</span><div class="itx-sbody">'
                 . '<span class="itx-tag">' . e($en ? translateFixed($it['tag'], 'en') : $it['tag']) . '</span>'
                 . '<a href="' . e($it['url']) . '" target="_blank" rel="noopener nofollow"' . ($i ? ' tabindex="-1"' : '') . '><b>' . thaiNoBreak($it['title']) . '</b></a>'
                 . '<small>' . e($it['source']) . ' · ' . e(thaiDate($it['published_at'], true)) . '</small></div></article>';
        $dots .= '<button type="button" class="itx-dot' . ($i ? '' : ' is-on') . '" aria-label="' . ($en ? 'Headline ' : 'ข่าวที่ ') . ($i + 1) . '"></button>';
    }
    static $js = false;
    $script = $js ? '' : '<script>window.itxInit=function(){document.querySelectorAll("[data-itx]:not([data-itx-on])").forEach(function(car){car.setAttribute("data-itx-on","");var s=car.querySelectorAll(".itx-slide"),d=car.querySelectorAll(".itx-dot"),i=0,t=null,still=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;'
        . 'function go(n){i=(n+s.length)%s.length;s.forEach(function(x,k){var on=k===i;x.classList.toggle("is-on",on);x.setAttribute("aria-hidden",on?"false":"true");var a=x.querySelector("a");if(a)a.tabIndex=on?0:-1;});d.forEach(function(x,k){x.classList.toggle("is-on",k===i);});}'
        . 'function play(){if(still||s.length<2)return;clearInterval(t);t=setInterval(function(){go(i+1);},5000);}function stop(){clearInterval(t);}'
        . 'car.querySelector("[data-next]").onclick=function(){go(i+1);play();};car.querySelector("[data-prev]").onclick=function(){go(i-1);play();};'
        . 'd.forEach(function(x,k){x.onclick=function(){go(k);play();};});car.addEventListener("mouseenter",stop);car.addEventListener("mouseleave",play);car.addEventListener("focusin",stop);car.addEventListener("focusout",play);'
        . 'var x0=null;car.addEventListener("touchstart",function(e){x0=e.touches[0].clientX;},{passive:true});car.addEventListener("touchend",function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>40){go(i+(dx<0?1:-1));play();}x0=null;});'
        . 'document.addEventListener("visibilitychange",function(){if(!document.contains(car))return;document.hidden?stop():play();});play();});};window.itxInit();</script>';
    $js = true;
    if (!$items) return '<div id="itxLive" data-itnews-live></div>' . $script;
    return '<div id="itxLive" data-itnews-live><section class="itx" data-itx aria-roledescription="carousel" aria-labelledby="itxTitle">'
         . '<div class="itx-side"><h2 id="itxTitle">IT NEWS<span>' . ($en ? 'Today' : 'วันนี้') . '</span></h2>'
         . '<span class="itx-date">' . e($dateLabel) . '</span>'
         . '<span class="itx-nav"><button type="button" data-prev aria-label="' . ($en ? 'Previous' : 'ก่อนหน้า') . '">‹</button><button type="button" data-next aria-label="' . ($en ? 'Next' : 'ถัดไป') . '">›</button></span></div>'
         . '<div class="itx-main"><div class="itx-view">' . $slides . '</div>'
         . '<div class="itx-foot"><div class="itx-dots">' . $dots . '</div><a href="' . BASE_URL . '/it-news.php?lang=' . e($lang) . '">' . ($en ? 'More IT news' : 'ดูข่าวไอทีย้อนหลัง') . ' →</a></div></div>'
         . '</section></div>' . $script;
}
/** One headline as a card (ข่าวไอทีย้อนหลัง). */
function itNewsCard(array $it, string $lang): string {
    return '<a class="itx-card" href="' . e($it['url']) . '" target="_blank" rel="noopener nofollow">'
         . '<span class="itx-tag">' . e($lang === 'en' ? translateFixed($it['tag'], 'en') : $it['tag']) . '</span>'
         . '<b>' . thaiNoBreak($it['title']) . '</b>'
         . ($it['summary'] !== '' ? '<p>' . thaiNoBreak($it['summary']) . '</p>' : '<p></p>')
         . '<small>' . e($it['source']) . ' · ' . e(thaiDate($it['published_at'], true)) . '</small></a>';
}

function staffCard(array $s, string $lang, bool $compact = false, ?int $headStaffId = null, string $headLabel = 'หัวหน้าสาขา'): string {
    $noBreak = fn(string $t) => thaiNoBreak($t);
    $name = $noBreak(staffName($s, $lang));
    $position = $noBreak(tx($s['position_name'] ?? '', $lang));
    $initial = e(staffInitial($s, $lang));
    $isHead = $headStaffId && $headStaffId === (int)$s['staff_id'];

    $avatar = !empty($s['image'])
        ? '<img src="' . e(UPLOAD_URL . '/' . $s['image']) . '" alt=""' . imgSizeAttrs($s['image']) . ' loading="lazy" decoding="async" class="staff-avatar-img">'
        : '<div class="staff-avatar-fallback">' . $initial . '</div>';

    $positionHtml = '';
    if (!$compact) {
        $positionHtml = '<p class="staff-position">' . $position . '</p>';
        if (!empty($s['responsibility'])) { $positionHtml .= '<p class="staff-responsibility">' . $noBreak(tx($s['responsibility'], $lang)) . '</p>'; }
    }
    $headBadgeHtml = $isHead ? '<span class="staff-head-badge">' . icon('star', 'icon-sm') . ' ' . e($headLabel) . '</span>' : '';
    $profileHtml = !empty($s['e_profile_url'])
        ? '<a href="' . e($s['e_profile_url']) . '" class="staff-profile-link" target="_blank" rel="noopener">' . icon('external-link', 'icon-sm') . ' ' . ($lang === 'en' ? 'Profile' : 'ดูโปรไฟล์') . '</a>'
        : '';

    return <<<HTML
    <article class="card card-staff reveal">
      <div class="staff-avatar">{$avatar}</div>
      <h3 class="staff-name">{$name}</h3>
      {$positionHtml}
      {$headBadgeHtml}
      {$profileHtml}
    </article>
    HTML;
}


/**
 * Breadcrumb trail.
 * @param array $items [['label'=>string,'url'=>?string], ...] last item should omit 'url'
 */
function breadcrumb(array $items): string {
    $out = ['<nav class="breadcrumb" aria-label="Breadcrumb"><ol>'];
    $count = count($items);
    foreach ($items as $i => $item) {
        $label = e($item['label']);
        if (!empty($item['url']) && $i < $count - 1) {
            $out[] = '<li><a href="' . e($item['url']) . '">' . $label . '</a><span class="crumb-sep" aria-hidden="true">/</span></li>';
        } else {
            $out[] = '<li aria-current="page"><span class="crumb-current">' . $label . '</span></li>';
        }
    }
    $out[] = '</ol></nav>';
    return implode('', $out);
}

/** Pagination nav. Expects the array shape returned by helpers.php paginate(). */
function paginationNav(array $pager): string {
    if ($pager['total_pages'] <= 1) return '';
    $cur = $pager['current_page'];
    $out = ['<nav class="pagination" aria-label="Pagination"><ul>'];

    $prevUrl = str_replace('{page}', (string)($cur - 1), $pager['url_pattern']);
    $out[] = '<li><a class="page-btn page-btn-nav" href="' . e($prevUrl) . '"'
        . ($pager['has_prev'] ? '' : ' aria-disabled="true" tabindex="-1"')
        . '>' . icon('chevron-left') . '<span class="visually-hidden">Previous</span></a></li>';

    for ($i = 1; $i <= $pager['total_pages']; $i++) {
        if ($pager['total_pages'] > 7 && abs($i - $cur) > 2 && $i !== 1 && $i !== $pager['total_pages']) {
            if ($i === 2 || $i === $pager['total_pages'] - 1) {
                $out[] = '<li class="page-ellipsis" aria-hidden="true">&hellip;</li>';
            }
            continue;
        }
        $url = str_replace('{page}', (string)$i, $pager['url_pattern']);
        $activeAttr = $i === $cur ? ' class="page-btn is-active" aria-current="page"' : ' class="page-btn"';
        $out[] = '<li><a' . $activeAttr . ' href="' . e($url) . '">' . $i . '</a></li>';
    }

    $nextUrl = str_replace('{page}', (string)($cur + 1), $pager['url_pattern']);
    $out[] = '<li><a class="page-btn page-btn-nav" href="' . e($nextUrl) . '"'
        . ($pager['has_next'] ? '' : ' aria-disabled="true" tabindex="-1"')
        . '>' . icon('chevron-right') . '<span class="visually-hidden">Next</span></a></li>';

    $out[] = '</ul></nav>';
    return implode('', $out);
}
