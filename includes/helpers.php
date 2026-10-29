<?php
/**
 * BAIT - Helper Functions
 */

// ============================================================
// SLUG
// ============================================================
function createSlug(string $text): string {
    // แปลง Thai → transliterate แบบ simple (ใช้ datetime ถ้าแปลงไม่ได้)
    $text = mb_strtolower(trim($text), 'UTF-8');
    // ลบอักขระพิเศษ คงไว้แต่ตัวอักษร ตัวเลข เว้นวรรค ขีด
    $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    if (empty($text)) $text = 'item';
    return $text . '-' . time();
}

// Never announce the PHP version (PHP adds this header itself; every page loads this file before sending anything).
if (!headers_sent()) header_remove('X-Powered-By');

// ============================================================
// IMAGE UPLOAD
// ============================================================
function uploadImage(array $file, string $folder, int $maxSizeMB = 5): string|false {
    // Browser-reported MIME (image/svg+xml, image/bmp, .ico's two possible
    // types) can't be trusted alone, but this endpoint is admin-only and
    // authenticated, and every upload is only ever rendered back out via
    // <img src="...">, never inlined as SVG markup — so widening the
    // accepted set here doesn't introduce script-execution risk.
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/avif'];
    $ext     = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/svg+xml' => 'svg', 'image/bmp' => 'bmp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'image/avif' => 'avif',
    ];

    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if (!in_array($file['type'], $allowed)) return false;
    if ($file['size'] > $maxSizeMB * 1024 * 1024) return false;

    // The browser's MIME type is only a claim — look at the file itself, so a script or any other file renamed
    // to .jpg can't get in. (SVG is XML text, so for it the check is that it really contains an <svg> element.)
    if (function_exists('finfo_open') && is_file($file['tmp_name'])) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $real = (string)@finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($file['type'] === 'image/svg+xml') {
            if (!in_array($real, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)) return false;
            if (stripos((string)@file_get_contents($file['tmp_name'], false, null, 0, 200000), '<svg') === false) return false;
        } elseif ($real !== '' && !in_array($real, $allowed, true)) {
            return false;
        }
    }

    $dir = UPLOAD_PATH . '/' . ltrim($folder, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid('img_', true) . '.' . $ext[$file['type']];
    $dest     = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return $folder . '/' . $filename;
}

/**
 * Upload bookkeeping for forms that can be rejected after a file was already uploaded.
 * fileTrackNew()   - a file that was just uploaded (deleted again if the form is rejected)
 * fileQueueDelete() - the file being replaced/removed (deleted only once the form is really saved)
 * So a rejected edit never destroys the picture that is currently in use, and never leaves a stray upload behind.
 * A request that stops before fileCommitChanges() (jsonError / adminFail, e.g. a text too long for its column) drops its new
 * uploads at the end of the request, so no caller has to remember a rollback on every error path.
 */
function fileTrackNew(?string $rel): void {
    if (!$rel) return;
    if (empty($GLOBALS['__file_guard'])) { $GLOBALS['__file_guard'] = true; register_shutdown_function('fileRollbackChanges'); }
    $GLOBALS['__file_new'][$rel] = true;
}
function fileQueueDelete(?string $rel): void { if ($rel) $GLOBALS['__file_del'][$rel] = true; }
function fileCommitChanges(): void {
    foreach (array_keys($GLOBALS['__file_del'] ?? []) as $rel) {
        if (!isset($GLOBALS['__file_new'][$rel]) && !str_contains($rel, '..') && is_file(UPLOAD_PATH . '/' . $rel)) @unlink(UPLOAD_PATH . '/' . $rel);
    }
    $GLOBALS['__file_del'] = $GLOBALS['__file_new'] = [];
}
function fileRollbackChanges(): void {
    foreach (array_keys($GLOBALS['__file_new'] ?? []) as $rel) {
        if (!str_contains($rel, '..') && is_file(UPLOAD_PATH . '/' . $rel)) @unlink(UPLOAD_PATH . '/' . $rel);
    }
    $GLOBALS['__file_del'] = $GLOBALS['__file_new'] = [];
}

/** True for an absolute http(s) address. Used for every link an admin types, so a link can never be javascript:, data: ... */
function isHttpUrl(string $url): bool {
    return (bool)preg_match('#^https?://[^\s<>"]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Like uploadImage(), but also accepts short video clips (mp4/webm) — for
 * spots like the home page's "จุดเด่นของคณะ" highlights, where the admin
 * may want to attach a still image OR a short looping clip instead of just
 * a generic icon. Returns which kind was actually uploaded so the caller
 * can store it (there's no single file extension that implies "video").
 */
function uploadMedia(array $file, string $folder, int $maxSizeMB = 20): array|false {
    // svg+xml included per the same rationale as uploadImage() above — admin-only,
    // authenticated, and always rendered back out via <img src="...">, never inlined.
    $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    $videoTypes = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = $imageTypes[$file['type']] ?? $videoTypes[$file['type']] ?? null;
    if (!$ext) return false;
    if ($file['size'] > $maxSizeMB * 1024 * 1024) return false;

    // Same content check as uploadImage(): the browser-reported type is only a claim.
    if (function_exists('finfo_open') && is_file($file['tmp_name'])) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $real = (string)@finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($file['type'] === 'image/svg+xml') {
            if (!in_array($real, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)) return false;
            if (stripos((string)@file_get_contents($file['tmp_name'], false, null, 0, 200000), '<svg') === false) return false;
        } elseif ($real !== '' && !in_array($real, array_merge(array_keys($imageTypes), array_keys($videoTypes)), true)) {
            return false;
        }
    }

    $dir = UPLOAD_PATH . '/' . ltrim($folder, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid('media_', true) . '.' . $ext;
    $dest     = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return ['type' => isset($imageTypes[$file['type']]) ? 'image' : 'video', 'path' => $folder . '/' . $filename];
}

/**
 * Saves an image already cropped/framed client-side (see assets/js/image-
 * cropper.js) — the browser's own <canvas> does the actual pixel work via
 * toDataURL(), so this just needs to decode the base64 payload and write
 * it out; no GD/Imagick involved (neither is installed on this server).
 */
function uploadImageFromDataUrl(string $dataUrl, string $folder, int $maxSizeMB = 5): string|false {
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/', $dataUrl, $m)) return false;
    $ext  = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $data = base64_decode($m[2], true);
    if ($data === false) return false;
    if (strlen($data) > $maxSizeMB * 1024 * 1024) return false;

    $dir = UPLOAD_PATH . '/' . ltrim($folder, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid('img_', true) . '.' . $ext;
    $dest     = $dir . '/' . $filename;
    if (file_put_contents($dest, $data) === false) return false;

    return $folder . '/' . $filename;
}

// ============================================================
// TRANSLATION (MyMemory + DB cache)
// ============================================================
/**
 * Translation cache lookups. An English page asks for the cached translation of 50-150 strings; with the database on
 * another machine (the real host) one query per string is slow. So the first lookup of a request loads the whole cache
 * (hash, translation, date - about 250 KB for ~850 rows) in ONE query and every later lookup is an array read.
 * If the table ever grows past TRANSLATION_PRELOAD_MAX rows it quietly goes back to one query per string.
 */
function translationCacheGet(Database $db, string $hash, string $lang): array|false|null {
    $tc = &$GLOBALS['__tc'];
    if ($tc === null) {
        $tc = ['all' => false, 'rows' => []];
        try {
            $n = (int)($db->fetchOne('SELECT COUNT(*) AS c FROM translation_cache')['c'] ?? 0);
            if ($n <= (defined('TRANSLATION_PRELOAD_MAX') ? TRANSLATION_PRELOAD_MAX : 20000)) {
                foreach ($db->fetchAll('SELECT source_hash, target_lang, translated, updated_at FROM translation_cache') as $r) {
                    $tc['rows'][$r['target_lang']][$r['source_hash']] = ['translated' => $r['translated'], 'updated_at' => $r['updated_at']];
                }
                $tc['all'] = true;
            }
        } catch (Throwable $e) { $tc = ['all' => false, 'rows' => []]; }
    }
    if ($tc['all']) return $tc['rows'][$lang][$hash] ?? false;
    return $db->fetchOne('SELECT translated, updated_at FROM translation_cache WHERE source_hash = ? AND target_lang = ?', [$hash, $lang]);
}
function translationCacheRemember(string $hash, string $lang, string $translated): void {
    if (!empty($GLOBALS['__tc']['all'])) $GLOBALS['__tc']['rows'][$lang][$hash] = ['translated' => $translated, 'updated_at' => date('Y-m-d H:i:s')];
}
function translateText(string $text, string $targetLang = 'en', string $sourceLang = 'th', bool $plainOnly = false): string {
    if (empty(trim($text))) return $text;
    if ($targetLang === $sourceLang) return $text;

    // Fixed wording written in the code comes from includes/lang_en.php —
    // no API call, no cache row. Only database content falls through below.
    if ($targetLang === 'en' && $sourceLang === 'th') {
        static $dict = null;
        $dict ??= require __DIR__ . '/lang_en.php';
        $key = trim($text);
        if (isset($dict[$key])) { $GLOBALS["__i18n"]["file"][$key] = $dict[$key]; return $dict[$key]; }
    }

    // Nothing Thai in it (numbers, English names, URLs...) — there is nothing
    // to translate, and sending it to the API only invites garbage back
    // ("2548" came back as "2005. ") and wastes quota.
    if (!preg_match('/\p{Thai}/u', $text)) return $text;

    // Markup (news/article bodies from the editor or pasted from Facebook): sending it whole to
    // the translator garbles the tags ("< ahref = ..." showed up as visible text), so translate
    // only the text between the tags and keep the markup itself untouched.
    if (!$plainOnly && preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i', $text)) {
        return translateHtml($text, $targetLang, $sourceLang);
    }

    $db   = Database::getInstance();
    $hash = hash('sha256', $text . $targetLang);

    // ตรวจ DB cache
    $cached = translationCacheGet($db, $hash, $targetLang);

    if ($cached) {
        $age = time() - strtotime($cached['updated_at']);
        if ($age < TRANSLATION_CACHE_TTL) { $fixed = restoreAcronyms($text, $cached["translated"]); $GLOBALS["__i18n"]["cache"][$text] = $fixed; return $fixed; }
    }

    // MyMemory caps a single request at 500 characters — split longer text
    // (a full news body, a program overview, ...) into pieces that fit,
    // translate each, then rejoin. Any single piece failing bails out to
    // the original Thai text rather than caching a half-translated result.
    // Per-request budget: a slow or failing API (each call can wait up to its
    // timeout) must not stack up across dozens of strings and outlive PHP's
    // max_execution_time, which cuts the page off half-rendered. After ~8 s
    // spent here, or one failure, the rest of this request keeps the Thai
    // text; later visits pick up where this one stopped (finished ones are cached).
    static $spent = 0.0, $halted = false;
    if ($halted || $spent > ($GLOBALS["__i18n_budget"] ?? 8)) { $GLOBALS["__i18n"]["failed"][$text] = $text; return $text; }
    $t0 = microtime(true);
    $translated = '';
    foreach (splitForTranslation($text) as $chunk) {
        $piece = callMyMemory($chunk, $sourceLang, $targetLang);
        if ($piece === false) {
            $halted = true;
            $spent += microtime(true) - $t0;
            $GLOBALS["__i18n"]["failed"][$text] = $text;
            return $text;
        }
        $translated .= $piece;
    }
    $spent += microtime(true) - $t0;

    // บันทึก cache — one upsert: another request (a second visitor, or the background pre-translation right after an
    // admin save) may have stored this same text a moment ago. A plain INSERT then hit the unique key and the page died
    // with a 500. A cache write must never break the page, so any other failure is only logged.
    try {
        $db->query(
            "INSERT INTO translation_cache (source_hash, source_text, target_lang, translated) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE translated = VALUES(translated), updated_at = CURRENT_TIMESTAMP",
            [$hash, $text, $targetLang, $translated]
        );
    } catch (Throwable $e) {
        error_log('[translateText] could not cache a translation: ' . $e->getMessage());
    }
    translationCacheRemember($hash, $targetLang, $translated);

    $GLOBALS["__i18n"]["api"][$text] = $translated;
    return restoreAcronyms($text, $translated);
}

/**
 * Removes the cached English translations belonging to rows that are about
 * to be deleted, so the translation_cache table doesn't keep growing with
 * text nobody can reach any more. Called from Database::delete().
 *
 * The cache is keyed by the exact Thai string, so this rebuilds the same
 * strings the public pages translate: every text column, the parts of JSON
 * columns (program about/overview), the content with Facebook emoji images
 * stripped, and the short excerpts cut for list cards.
 */
function forgetTranslationsOfRows(array $rows, array $keepRows = []): void {
    $strings = translationSourceStrings($rows);
    // After an edit: only what the new row no longer contains is stale.
    if ($keepRows) $strings = array_diff($strings, translationSourceStrings($keepRows));
    forgetTranslationStrings($strings);
}

/** Every Thai string (and derived form) the public pages could have sent to the translator for these rows. */
function translationSourceStrings(array $rows): array {
    $strings = [];
    $collect = function ($v) use (&$collect, &$strings) {
        if (is_array($v)) { foreach ($v as $x) $collect($x); return; }
        if (!is_string($v) || trim($v) === '' || !preg_match('/\p{Thai}/u', $v)) return;
        $strings[] = $v;
        $strings[] = trim($v);
        // HTML values are cached per text node (see translateHtml) — collect those too.
        if (preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?\/?>/i', $v) && class_exists('DOMDocument')) {
            $d = new DOMDocument();
            $pv = libxml_use_internal_errors(true);
            $d->loadHTML('<?xml encoding="utf-8"?><div>' . $v . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors(); libxml_use_internal_errors($pv);
            foreach ((new DOMXPath($d))->query('//div//text()') as $tn) {
                if (preg_match('/\p{Thai}/u', $tn->nodeValue) && preg_match('/^\s*(.*?)\s*$/su', $tn->nodeValue, $mm)) $strings[] = $mm[1];
            }
        }
        if (function_exists('stripFacebookEmojiImages')) $strings[] = stripFacebookEmojiImages($v);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($v)));
        $strings[] = mb_substr($plain, 0, 160, 'UTF-8');
        $strings[] = mb_substr($plain, 0, 140, 'UTF-8');
        $strings[] = $plain;
    };
    foreach ($rows as $row) {
        foreach ($row as $val) {
            if (!is_string($val)) continue;
            $collect($val);
            if ($val !== '' && ($val[0] === '{' || $val[0] === '[')) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) $collect($decoded);
            }
        }
    }
    return array_values(array_unique($strings));
}

function forgetTranslationStrings(array $strings): void {
    $hashes = [];
    foreach (array_unique($strings) as $s) {
        if (trim($s) !== '') $hashes[] = hash('sha256', $s . 'en');
    }
    if (!$hashes) return;
    $db = Database::getInstance();
    unset($GLOBALS['__tc']);   // drop the per-request preload; it is read again from the table
    foreach (array_chunk($hashes, 200) as $part) {
        $marks = implode(',', array_fill(0, count($part), '?'));
        $db->query("DELETE FROM translation_cache WHERE target_lang = 'en' AND source_hash IN ($marks)", $part);
    }
}

/**
 * Translates (and so caches) English versions of freshly saved content right
 * away, from the admin's save handler, so the first public visitor doesn't
 * pay for the API round trips. Pass the SAME strings the public page will
 * later hand to translateText() — the cache is keyed by the exact text.
 * Never throws: a slow/failed API must not break saving; whatever isn't
 * finished within $budget seconds is simply translated on a later visit.
 */
function prewarmEnglish(array $texts, int $budget = 20): void {
    try {
        @set_time_limit($budget + 30);
        $GLOBALS['__i18n_budget'] = $budget;
        foreach (array_unique($texts) as $text) {
            if (is_string($text) && trim($text) !== '') translateText($text, 'en');
        }
    } catch (Throwable $e) {
        // ignore — see above
    } finally {
        unset($GLOBALS['__i18n_budget']);
    }
}

/**
 * Redirects the admin to the next page RIGHT AWAY and only then translates the saved text (prewarmEnglish).
 * The translations take about 1.5 s per paragraph (an outside service), so doing them before the redirect made every save of a
 * news item / article / program wait ten seconds or more. Now the save finishes at once and the translating carries on in the
 * background; whatever is not finished in time is simply translated on a later visit, as before.
 * The session is released first, otherwise the next admin page would wait for it until the translating is done.
 */
function redirectThenPrewarm(string $to, array $texts, int $budget = 20): never {
    ignore_user_abort(true);
    @set_time_limit($budget + 30);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    // A compressing filter (mod_deflate / zlib.output_compression) drops "Content-Length: 0" and holds the reply until the
    // script ends, which would make the admin wait for the whole translation again. Switch compression off for this reply.
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', '0');
    if (!headers_sent()) {
        http_response_code(302);
        header('Content-Encoding: identity');
        header('Location: ' . $to);
        header('Connection: close');
        header('Content-Length: 0');
    }
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    prewarmEnglish($texts, $budget);   // never throws
    exit;
}
/**
 * The translator lower-cases acronyms ("SMART Business" came back as "smart Business"). Any ALL-CAPS Latin
 * word of 3+ letters in the Thai source (SMART, RMUTSB, BAIT ...) is put back in its original capitals
 * wherever it shows up in the translation. Two-letter ones (IT, AI) are left alone — "it" is also a real word.
 */
function restoreAcronyms(string $source, string $translated): string {
    if (!preg_match_all('/(?<![A-Za-z])[A-Z]{3,}(?![A-Za-z])/', $source, $m)) return $translated;
    foreach (array_unique($m[0]) as $acr) {
        $translated = preg_replace('/(?<![A-Za-z])' . preg_quote($acr, '/') . '(?![A-Za-z])/i', $acr, $translated);
    }
    return $translated;
}

/** One Thai sentence for "already exists" that names what is duplicated. */
function dupMsg(string $what, string $name): string {
    return 'มี' . $what . ' "' . $name . '" อยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น หรือแก้ไขรายการเดิมแทนการเพิ่มใหม่';
}
/** True when another row (not $exceptId) of $table matches ALL of the given column => value pairs. */
function rowExists(Database $db, string $table, array $match, string $idCol = '', int $exceptId = 0): bool {
    $where = []; $params = [];
    foreach ($match as $col => $val) { $where[] = "`$col` = ?"; $params[] = $val; }
    if ($idCol !== '') { $where[] = "`$idCol` != ?"; $params[] = $exceptId; }
    return (bool)$db->fetchOne("SELECT 1 FROM `$table` WHERE " . implode(' AND ', $where) . " LIMIT 1", $params);
}

/**
 * True when another row of $table already has this exact name (case-insensitive, the tables use *_unicode_ci).
 * Admin forms use it so a duplicate gets a clear Thai message instead of a second identical row.
 */
function nameTaken(Database $db, string $table, string $nameCol, string $idCol, string $name, int $exceptId = 0): bool {
    return (bool)$db->fetchOne("SELECT 1 FROM `$table` WHERE `$nameCol` = ? AND `$idCol` != ? LIMIT 1", [$name, $exceptId]);
}

/**
 * English for a stored label ONLY when the fixed dictionary (includes/lang_en.php) knows it; anything
 * else is returned unchanged. For values that may be a person's name (post authors): the machine
 * translator would turn a real name into random words, so it is never asked.
 */
function translateFixed(string $text, string $lang): string {
    if ($lang !== 'en' || trim($text) === '') return $text;
    static $dict = null;
    $dict ??= require __DIR__ . '/lang_en.php';
    return $dict[trim($text)] ?? $text;
}

/**
 * A staff member's name as shown on the public site. On English pages the English name is used
 * only once an admin has confirmed it (name_en_verified) — the auto-suggested spelling can be wrong,
 * so an unconfirmed one is never shown; the Thai name stays until then. The prefix is mapped through
 * the fixed dictionary ("ผศ.ดร." -> "Asst. Prof. Dr."), and dropped if it has no English form.
 */
function staffName(array $s, string $lang): string {
    $prefix = trim($s['prefix'] ?? '');
    if ($lang === 'en' && !empty($s['name_en_verified']) && trim($s['full_name_en'] ?? '') !== '') {
        $p = translateFixed($prefix, 'en');
        if (preg_match('/\p{Thai}/u', $p)) $p = '';
        return trim($p . ' ' . trim($s['full_name_en']));
    }
    return ($s['prefix'] ?? '') . $s['full_name'];
}

/** First letter for an avatar placeholder, in the language the name is shown in. */
function staffInitial(array $s, string $lang): string {
    $n = ($lang === 'en' && !empty($s['name_en_verified']) && trim($s['full_name_en'] ?? '') !== '') ? trim($s['full_name_en']) : $s['full_name'];
    return mb_strtoupper(mb_substr($n, 0, 1, 'UTF-8'), 'UTF-8');
}

/**
 * Display options of a manageable About-page section (about_sections.display_options, JSON).
 * Everything an admin can choose lives here so the admin form and the public page agree:
 * every value is whitelisted, and a missing/empty column simply means "ordinary card".
 */
function aboutOptionDefaults(): array {
    return ['style' => 'card', 'autoplay' => 0, 'badge' => 'auto', 'shape' => 'pill', 'effect' => 'fade', 'counter' => 1, 'mark' => 1];
}
function aboutOptionChoices(): array {
    return [
        'style'    => ['card' => 'การ์ดข้อความปกติ', 'slider' => 'สไลด์การ์ด (แสดงทีละข้อ)', 'statement' => 'กล่องประโยคเด่น (ข้อความสั้น ตัวใหญ่)'],
        'autoplay' => [0 => 'ไม่เลื่อนเอง', 4 => 'ทุก 4 วินาที', 6 => 'ทุก 6 วินาที', 8 => 'ทุก 8 วินาที', 10 => 'ทุก 10 วินาที'],
        'badge'    => ['auto' => 'อัตโนมัติ', 'number' => 'เลขข้อ', 'letter' => 'ตัวอักษรแรก', 'icon' => 'ไอคอนของหัวข้อ'],
        'shape'    => ['pill' => 'ปลายขวาโค้งกลม', 'rounded' => 'มุมมนปกติ'],
        'effect'   => ['fade' => 'จางข้าม', 'slide' => 'เลื่อนซ้าย-ขวา'],
    ];
}
/** Parses + validates the stored JSON (or an already-decoded array, e.g. from $_POST). */
function aboutSectionOptions($raw): array {
    $o = aboutOptionDefaults();
    $in = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $choices = aboutOptionChoices();
    foreach (['style', 'shape', 'effect', 'badge'] as $k) {
        if (isset($in[$k]) && array_key_exists($in[$k], $choices[$k])) $o[$k] = $in[$k];
    }
    if (isset($in['autoplay']) && array_key_exists((int)$in['autoplay'], $choices['autoplay'])) $o['autoplay'] = (int)$in['autoplay'];
    foreach (['counter', 'mark'] as $k) if (isset($in[$k])) $o[$k] = empty($in[$k]) ? 0 : 1;
    return $o;
}
/**
 * Plain-text snippet of rich content for <meta name="description"> / link previews: block tags become
 * spaces (so "<p>a</p><p>b</p>" does not read "ab"), entities are decoded, emoji are dropped, and the
 * result is cut at $len characters.
 */
function metaSnippet(string $html, int $len = 160): string {
    $t = preg_replace("#</?(p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|section|article|blockquote)\b[^>]*>#i", " ", $html);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, "UTF-8");
    $t = preg_replace("/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u", "", $t);
    $t = trim(preg_replace("/\s+/u", " ", $t));
    return mb_strimwidth($t, 0, $len, "...", "UTF-8");
}

/** [r,g,b] 0-255 of "#rgb"/"#rrggbb", or null when it is not a plain hex colour (e.g. var(--x)). */
function hexToRgb(string $hex): ?array {
    $h = ltrim(trim($hex), "#");
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match("/^[0-9a-f]{6}$/i", $h)) return null;
    return array_map("hexdec", str_split($h, 2));
}
function relLuminance(array $rgb): float {
    [$r, $g, $b] = array_map(function ($c) { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; }, $rgb);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}
function contrastRatio(array $a, array $b): float {
    $l1 = relLuminance($a); $l2 = relLuminance($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
/** Text colour (white or the site ink) that reads better on a coloured background. A non-hex value (var(...)) gets white. */
function inkOn(string $bgHex): string {
    $bg = hexToRgb($bgHex);
    if (!$bg) return "#FFFFFF";
    return contrastRatio($bg, [255, 255, 255]) >= contrastRatio($bg, [27, 32, 50]) ? "#FFFFFF" : "#1B2032";
}
/** A colour chosen in the admin used as TEXT on a white card: darkened step by step until it reaches $min contrast (3 = large text, 4.5 = body text). */
function legibleOnWhite(string $hex, float $min = 3.0): string {
    $rgb = hexToRgb($hex);
    if (!$rgb) return $hex;
    $bg = [238, 241, 250];   // the soft section background is the lighter-contrast case; white cards then pass too
    for ($i = 0; $i < 20 && contrastRatio($rgb, $bg) < $min; $i++) {
        $rgb = array_map(fn($c) => (int)round($c * 0.9), $rgb);
    }
    return sprintf("#%02X%02X%02X", ...$rgb);
}

/**
 * Contact channels an admin can pick, label by type. YouTube, X and Instagram exist only once the campus_contacts.contact_type
 * column has been widened (run contact_type_youtube_x_instagram.sql); until then they are left out so nothing is saved as a blank type.
 */
function contactTypeLabels(Database $db): array {
    $labels = ['phone' => 'โทรศัพท์', 'email' => 'Email', 'facebook' => 'Facebook', 'line' => 'Line', 'tiktok' => 'TikTok', 'website' => 'เว็บไซต์'];
    try {
        $col = $db->fetchOne("SHOW COLUMNS FROM campus_contacts LIKE 'contact_type'");
        $type = (string)($col['Type'] ?? '');
    } catch (Throwable $e) { $type = ''; }
    if (strpos($type, "'youtube'") !== false) $labels['youtube'] = 'YouTube';
    if (strpos($type, "'x'") !== false)       $labels['x'] = 'X (Twitter)';
    if (strpos($type, "'instagram'") !== false) $labels['instagram'] = 'Instagram';
    return $labels;
}

/**
 * Link for a campus contact row. Admins may store a bare handle ("@ba.it.huntra") or a full URL, so
 * a handle is turned into the site's address and anything that is not http(s)/tel/mailto becomes "#".
 */
function contactHref(array $ct): string {
    $v = trim((string)($ct['contact_value'] ?? ''));
    if ($v === '') return '#';
    switch ($ct['contact_type'] ?? '') {
        case 'phone': return 'tel:' . preg_replace('/[^0-9+]/', '', $v);
        case 'email': return 'mailto:' . $v;
    }
    if (preg_match('#^https?://#i', $v)) return $v;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $v)) return '#';   // javascript:, data: ... never a link
    switch ($ct['contact_type'] ?? '') {
        case 'line':     return 'https://line.me/ti/p/' . $v;
        case 'tiktok':   return 'https://www.tiktok.com/@' . ltrim($v, '@');
        case 'facebook': return 'https://www.facebook.com/' . ltrim($v, '/');
        case 'youtube':  return preg_match('/^UC[\w-]{22}$/', $v) ? 'https://www.youtube.com/channel/' . $v : 'https://www.youtube.com/@' . ltrim($v, '@/');
        case 'x':        return 'https://x.com/' . ltrim($v, '@/');
        case 'instagram': return 'https://www.instagram.com/' . ltrim($v, '@/');
    }
    return 'https://' . ltrim($v, '/');
}

/**
 * Keyword marquee under the home hero (site_settings.marquee_options, JSON). Managed on the admin
 * banners page. Every value is whitelisted; a missing column / empty value simply gives the defaults.
 * A word line may carry its own English after a "|"  ("ธุรกิจ|Business"); without it the English
 * page translates the Thai (fixed dictionary first, then the cached API).
 */
function marqueeDefaults(): array {
    return [
        'enabled' => 1,
        'words'   => ['ธุรกิจ|Business', 'เทคโนโลยี|Technology', 'นวัตกรรม|Innovation', 'ดิจิทัล|Digital',
                      'ผู้ประกอบการ|Entrepreneurship', 'ข้อมูล|Data', 'ความคิดสร้างสรรค์|Creativity', 'ภาวะผู้นำ|Leadership'],
        'bg' => '#0A0A0A', 'fg' => '#FFFFFF', 'mark_color' => '#FF914D',
        'marker' => 'diamond', 'direction' => 'left', 'speed' => 'normal', 'size' => 'sm', 'pause' => 1,
    ];
}
function marqueeChoices(): array {
    return [
        'marker'    => ['diamond' => 'สี่เหลี่ยมขนมเปียกปูน ◆', 'dot' => 'จุดกลม ●', 'star' => 'ดาว ✦', 'slash' => 'ขีดทับ /', 'none' => 'ไม่มีตัวคั่น'],
        'direction' => ['left' => 'วิ่งไปทางซ้าย (←)', 'right' => 'วิ่งไปทางขวา (→)'],
        'speed'     => ['slow' => 'ช้า', 'normal' => 'ปานกลาง', 'fast' => 'เร็ว'],
        'size'      => ['sm' => 'เล็ก', 'md' => 'กลาง', 'lg' => 'ใหญ่'],
    ];
}
function marqueeOptions($raw): array {
    $o = marqueeDefaults();
    $in = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    if (!$in) return $o;
    $choices = marqueeChoices();
    foreach ($choices as $k => $list) if (isset($in[$k]) && array_key_exists($in[$k], $list)) $o[$k] = $in[$k];
    foreach (['bg', 'fg', 'mark_color'] as $k) if (isset($in[$k]) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$in[$k])) $o[$k] = strtoupper($in[$k]);
    foreach (['enabled', 'pause'] as $k) if (isset($in[$k])) $o[$k] = empty($in[$k]) ? 0 : 1;
    if (isset($in['words']) && is_array($in['words'])) {
        $words = [];
        foreach ($in['words'] as $w) {
            $w = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$w)));
            if ($w !== '') $words[] = mb_substr($w, 0, 200, 'UTF-8');   // generous: a long line must never be cut mid-word
            if (count($words) >= 24) break;
        }
        $o['words'] = $words;   // may be empty: the admin then sees "add at least one word"; the page shows no marquee
    }
    return $o;
}
/** Words for the page language, plus how many times to repeat them per group and the loop time (s), so speed looks the same however many words there are. */
function marqueeRender(array $o, string $lang): array {
    $items = [];
    foreach ($o['words'] as $w) {
        $parts = explode('|', $w, 2);
        $th = trim($parts[0]); $en = trim($parts[1] ?? '');
        $items[] = $lang === 'en' ? ($en !== '' ? $en : translateText($th, 'en')) : $th;
    }
    $items = array_values(array_filter($items, fn($x) => $x !== ''));
    $fs = ['sm' => 13, 'md' => 16, 'lg' => 20][$o['size']];
    $unit = 0;
    foreach ($items as $x) $unit += mb_strlen($x, 'UTF-8') * $fs * 0.45 + 40;   // low estimate on purpose: too many repeats is harmless, too few leaves a gap
    $unit = max($unit, 80);
    $reps = max(2, (int)ceil(3000 / $unit));
    $pxPerSec = ['slow' => 30, 'normal' => 50, 'fast' => 85][$o['speed']];
    return ['items' => $items, 'reps' => $reps, 'pxs' => $pxPerSec, 'duration' => max(8, (int)round($reps * $unit / $pxPerSec))];
}

/**
 * Translates the visible text of an HTML fragment and leaves tags, attributes and links alone.
 * Each text node is translated (and cached) on its own; a node that can't be translated right now
 * simply stays Thai, so the page structure never breaks.
 */
function translateHtml(string $html, string $targetLang = 'en', string $sourceLang = 'th'): string {
    if (!class_exists('DOMDocument')) return $html;
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadHTML('<?xml encoding="utf-8"?><div id="bait-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return $html;
    $xp = new DOMXPath($dom);
    $root = $xp->query('//div[@id="bait-root"]')->item(0);
    if (!$root) return $html;
    foreach ($xp->query('.//text()', $root) as $node) {
        $parent = $node->parentNode ? strtolower($node->parentNode->nodeName) : '';
        if ($parent === 'script' || $parent === 'style') continue;
        $raw = $node->nodeValue;
        if (!preg_match('/\p{Thai}/u', $raw)) continue;
        if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $raw, $m) || $m[2] === '') continue;
        $node->nodeValue = $m[1] . translateText($m[2], $targetLang, $sourceLang, true) . $m[3];
    }
    $out = '';
    foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
    return $out;
}

/**
 * Breaks $text into pieces no longer than $maxLen, preferring to cut right
 * after a space, newline, or ">" (end of an HTML tag, since this often runs
 * over CKEditor HTML) so a word or tag doesn't get split across two pieces
 * and sent to the translator half-broken.
 */
function splitForTranslation(string $text, int $maxLen = 480): array {
    if (mb_strlen($text, 'UTF-8') <= $maxLen) return [$text];

    $chunks = [];
    $remaining = $text;
    while (mb_strlen($remaining, 'UTF-8') > $maxLen) {
        $slice = mb_substr($remaining, 0, $maxLen, 'UTF-8');
        $breakAt = null;
        for ($i = mb_strlen($slice, 'UTF-8') - 1; $i > 0; $i--) {
            $ch = mb_substr($slice, $i, 1, 'UTF-8');
            if ($ch === ' ' || $ch === "\n" || $ch === '>') { $breakAt = $i + 1; break; }
        }
        if ($breakAt === null) $breakAt = $maxLen;
        $chunks[] = mb_substr($remaining, 0, $breakAt, 'UTF-8');
        $remaining = mb_substr($remaining, $breakAt, null, 'UTF-8');
    }
    if ($remaining !== '') $chunks[] = $remaining;

    return $chunks;
}

function callMyMemory(string $text, string $source, string $target): string|false {
    if (trim($text) === '') return $text;

    $params = ['q' => $text, 'langpair' => $source . '|' . $target];
    if (MYMEMORY_EMAIL !== '') $params['de'] = MYMEMORY_EMAIL;
    $url = 'https://api.mymemory.translated.net/get?' . http_build_query($params);

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: BAIT-RMUTSB-Website/1.0\r\n",
        'timeout' => 5,
    ]]);

    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return false;

    $data = json_decode($response, true);
    if ((int)($data['responseStatus'] ?? 200) !== 200) return false;

    $translated = $data['responseData']['translatedText'] ?? false;
    if ($translated === false || $translated === '') return false;

    // MyMemory answers quota/length problems with a normal 200 response and
    // the complaint sitting in translatedText itself, not an error code —
    // catch that here so it never gets cached as a "translation".
    foreach (['QUERY LENGTH LIMIT', 'MYMEMORY WARNING', 'INVALID', 'AMOUNT OF WORDS'] as $needle) {
        if (stripos($translated, $needle) !== false) return false;
    }

    return $translated;
}

/**
 * Content pasted into the CKEditor straight from a Facebook post carries
 * Facebook's own emoji as <img src="…fbcdn.net…" alt="😀"> instead of a
 * plain Unicode character — invisible while editing, but those images are
 * hotlinked to Facebook's CDN and fail to load anywhere else, showing up
 * as broken-image icons that visually split the surrounding paragraph.
 * The real emoji is sitting right there in the tag's own alt text; swap
 * the whole broken <img> for that and the text reads as one continuous
 * paragraph again, exactly as it was typed on Facebook.
 */
/**
 * Makes editor HTML (news, articles, about sections, program text) safe to print on a public page: scripts, forms,
 * frames from unknown sites, on*="..." handlers and javascript:/data: links are removed; ordinary formatting
 * (paragraphs, headings, lists, tables, links, images, bold/italic, alignment styles) is kept. Admins are trusted
 * people, but a stolen admin login or a pasted web page must never be able to run code in every visitor's browser.
 */
function cleanRichHtml(string $html): string {
    if (trim($html) === '' || !class_exists('DOMDocument')) return $html === '' ? '' : e($html);
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $dom->loadHTML('<?xml encoding="utf-8"?><div id="rich-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return e(strip_tags($html));
    $xp = new DOMXPath($dom);
    $root = $xp->query('//div[@id="rich-root"]')->item(0);
    if (!$root) return e(strip_tags($html));

    $dropTags = ['script', 'style', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'frame', 'frameset'];
    $embedHosts = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.facebook.com'];
    $safeUrl = function (string $v, bool $image = false): bool {
        $v = trim(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($v === '') return true;
        if (preg_match('#^(https?:|mailto:|tel:|//|/|\#|\?|\.{1,2}/)#i', $v)) return true;
        if ($image && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) return true;
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $v);   // a relative path is fine, any other scheme is not
    };

    $remove = [];
    foreach ($xp->query('.//*', $root) as $el) {
        $tag = strtolower($el->nodeName);
        if (in_array($tag, $dropTags, true)) { $remove[] = $el; continue; }
        if ($tag === 'iframe') {
            $src = trim((string)$el->getAttribute('src'));
            $host = strtolower((string)parse_url($src, PHP_URL_HOST));
            if (!preg_match('#^https://#i', $src) || !in_array($host, $embedHosts, true)) { $remove[] = $el; continue; }
        }
        $drop = [];
        foreach ($el->attributes as $a) {
            $n = strtolower($a->nodeName); $v = (string)$a->nodeValue;
            if (str_starts_with($n, 'on')) { $drop[] = $a->nodeName; continue; }
            if (in_array($n, ['href', 'src', 'xlink:href', 'action', 'formaction', 'poster', 'background', 'data', 'srcset'], true) && !$safeUrl($v, $tag === 'img')) { $drop[] = $a->nodeName; continue; }
            if ($n === 'style' && preg_match('/expression\s*\(|javascript:|@import|behavior\s*:|-moz-binding|url\s*\(/i', $v)) { $drop[] = $a->nodeName; continue; }
            if ($n === 'srcdoc') $drop[] = $a->nodeName;
        }
        foreach ($drop as $n) $el->removeAttribute($n);
        if ($tag === 'a' && strtolower((string)$el->getAttribute('target')) === '_blank') $el->setAttribute('rel', 'noopener noreferrer');
    }
    foreach ($remove as $el) if ($el->parentNode) $el->parentNode->removeChild($el);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
    return $out;
}

function stripFacebookEmojiImages(string $html): string {
    return preg_replace_callback(
        '/<img\b[^>]*>/i',
        function (array $m): string {
            $tag = $m[0];
            if (stripos($tag, 'fbcdn.net') === false && stripos($tag, 'facebook.com') === false) {
                return $tag;
            }
            return preg_match('/\balt="([^"]*)"/i', $tag, $alt) ? $alt[1] : '';
        },
        $html
    );
}

// ============================================================
// XSS / OUTPUT
// ============================================================
/**
 * ' width="W" height="H"' for an image file (path relative to the uploads folder, or absolute on disk when $absolute),
 * so the browser can reserve its space before it loads (no layout jump). '' when the size can not be read.
 */
function imgSizeAttrs(string $path, bool $absolute = false): string {
    static $cache = [];
    $full = $absolute ? $path : UPLOAD_PATH . '/' . ltrim($path, '/');
    if (!isset($cache[$full])) {
        $d = (str_contains($full, '..') || !is_file($full)) ? false : @getimagesize($full);
        $cache[$full] = ($d && $d[0] > 0 && $d[1] > 0) ? ' width="' . (int)$d[0] . '" height="' . (int)$d[1] . '"' : '';
    }
    return $cache[$full];
}

/**
 * The one address a page should be indexed under: the script itself (no PATH_INFO like /news.php/anything) plus only the
 * parameters that really change the content (id, category, page > 1, tag, ...). Search words, junk parameters, page=0,
 * cat=abc and the like all collapse into the clean URL, so search engines see one address per page.
 */
function canonicalUrl(): string {
    // same scheme as BASE_URL_ABSOLUTE (config/database.php also honours X-Forwarded-Proto behind an https proxy)
    $scheme = (defined('BASE_URL_ABSOLUTE') ? parse_url(BASE_URL_ABSOLUTE, PHP_URL_SCHEME) : '') ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $path = (string)(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? '/'), PHP_URL_PATH) ?: '/');
    if (basename($path) === 'index.php') $path = rtrim(dirname($path), '/\\') . '/';   // the home page is the folder address
    $q = [];
    foreach (['id', 'cat', 'level', 'campus', 'major', 'type', 'sdg'] as $k) {
        $v = $_GET[$k] ?? null;
        if (is_string($v) && ctype_digit($v) && (int)$v > 0) $q[$k] = (int)$v;
    }
    $p = $_GET['page'] ?? null;
    if (is_string($p) && ctype_digit($p) && (int)$p > 1) $q['page'] = (int)$p;
    $t = $_GET['tag'] ?? null;
    if (is_string($t) && trim($t) !== '') $q['tag'] = trim($t);
    foreach (['audience' => ['student', 'staff'], 'view' => ['executive', 'campus']] as $k => $ok) {
        $v = $_GET[$k] ?? null;
        if (is_string($v) && in_array($v, $ok, true)) $q[$k] = $v;
    }
    // Always the language of the page being shown, written out (?lang=th / ?lang=en) - the same form the hreflang links and
    // sitemap.xml use. (A bare URL can show either language through the lang cookie, so it is never the canonical one.)
    $q['lang'] = currentLang();
    ksort($q);
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $path . ($q ? '?' . http_build_query($q) : '');
}
/** A query-string value as a trimmed string; '' (or $default) when it is missing or not a plain string — e.g. ?search[]=x must not crash trim(). */
function getStr(string $key, string $default = ''): string {
    $v = $_GET[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}
/** The same for a posted form field (trimmed): a crafted title[]=x must not crash trim() with a 500. */
function postStr(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}
/** A posted field as a string, NOT trimmed (rich text, JSON, multi-line text); $default when missing or not a plain string. */
function postRaw(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? null;
    return is_string($v) ? $v : $default;
}

/**
 * The language of this request: ?lang=th|en wins and is remembered in a cookie, otherwise the cookie, otherwise Thai.
 * (The public site has no PHP session, so the old $_SESSION['lang'] was never kept: pressing the logo / "หน้าหลัก"
 * on an English page dropped the visitor back into Thai.)
 */
function currentLang(): string {
    $ok = fn($v) => is_string($v) && in_array($v, ['th', 'en'], true);
    if ($ok($_GET['lang'] ?? null)) {
        $lang = $_GET['lang'];
        if (($_COOKIE['lang'] ?? '') !== $lang && !headers_sent()) {
            $path = rtrim((string)(parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_PATH) ?: ''), '/') . '/';
            setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => $path, 'samesite' => 'Lax']);
        }
        $_COOKIE['lang'] = $lang;
        return $lang;
    }
    // No ?lang= in the address: the language comes from the cookie, so any cache in front must keep one copy per cookie.
    static $varySent = false;
    if (!$varySent && !headers_sent()) { header('Vary: Cookie', false); $varySent = true; }
    if ($ok($_COOKIE['lang'] ?? null)) return $_COOKIE['lang'];
    if ($ok($_SESSION['lang'] ?? null)) return $_SESSION['lang'];
    return 'th';
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function sanitize(string $str): string {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

// ============================================================
// PAGINATION
// ============================================================
function paginate(int $total, int $perPage, int $currentPage, string $urlPattern = '?page={page}'): array {
    $totalPages = (int)ceil($total / $perPage);
    // Keep the requested page inside 1..last page. Without this, a huge ?page= value overflows the
    // offset into a float ("9.2E+19") that breaks the SQL and returns a 500 error.
    $currentPage = max(1, min($currentPage, max(1, $totalPages)));
    $offset     = ($currentPage - 1) * $perPage;
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
        'url_pattern'  => $urlPattern,
    ];
}

function paginationHtml(array $p): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';

    // Prev
    $prevClass = $p['has_prev'] ? '' : ' disabled';
    $prevUrl   = $p['has_prev'] ? str_replace('{page}', $p['current_page'] - 1, $p['url_pattern']) : '#';
    $html .= "<li class=\"page-item$prevClass\"><a class=\"page-link\" href=\"$prevUrl\">&laquo;</a></li>";

    // Pages
    for ($i = 1; $i <= $p['total_pages']; $i++) {
        if ($p['total_pages'] > 7 && abs($i - $p['current_page']) > 2 && $i !== 1 && $i !== $p['total_pages']) {
            if ($i === 2 || $i === $p['total_pages'] - 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            continue;
        }
        $active = ($i === $p['current_page']) ? ' active' : '';
        $url    = str_replace('{page}', $i, $p['url_pattern']);
        $html .= "<li class=\"page-item$active\"><a class=\"page-link\" href=\"$url\">$i</a></li>";
    }

    // Next
    $nextClass = $p['has_next'] ? '' : ' disabled';
    $nextUrl   = $p['has_next'] ? str_replace('{page}', $p['current_page'] + 1, $p['url_pattern']) : '#';
    $html .= "<li class=\"page-item$nextClass\"><a class=\"page-link\" href=\"$nextUrl\">&raquo;</a></li>";

    $html .= '</ul></nav>';
    return $html;
}

// ============================================================
// DATE / TIME
// ============================================================
function thaiDate(string $datetime, bool $withTime = false): string {
    $ts = strtotime($datetime);
    // English pages: "29 Jul 2026 14:05" (Gregorian year, no Thai wording).
    // (The admin shares the session with the public site but always stays Thai.)
    $inAdmin = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
    if (!$inAdmin && ($_SESSION['lang'] ?? 'th') === 'en') {
        return date('j M Y', $ts) . ($withTime ? date(' H:i', $ts) : '');
    }
    $months = ['', 'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $d  = date('j', $ts);
    $m  = $months[(int)date('n', $ts)];
    $y  = date('Y', $ts) + 543;
    $t  = $withTime ? ' ' . date('H:i', $ts) . ' น.' : '';
    return "$d $m $y$t";
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'เมื่อกี้';
    if ($diff < 3600)   return (int)($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400)  return (int)($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 604800) return (int)($diff / 86400) . ' วันที่แล้ว';
    return thaiDate($datetime);
}

// ============================================================
// PAGE VIEW TRACKER
// ============================================================
function trackPageView(string $url, string $title = ''): void {
    if (isset($_GET['itx_refresh'])) return;   // the page loading itself again in the background to show new IT news: not a visit
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    // Crawlers, link-preview fetchers and uptime monitors are not visitors: they would inflate every number and the table.
    if ($ua === '' || preg_match('/(?<![a-z])bot(?![a-z0-9_])|[a-z]bot[\/;]|telegrambot|whatsapp|robot|crawl|spider|slurp|facebookexternalhit|bingpreview|linkpreview|headless|lighthouse|pingdom|uptime|monitor\/|site24x7|statuscake|curl|wget|python-requests|python-urllib|axios\/|node-fetch|okhttp|scrapy|httpclient|go-http|java\//i', $ua)) return;
    // One VISIT = one person's time on the site: the first page they open counts, moving to other pages / back to the home
    // page / reloading does not. A cookie that lives until the browser is closed holds the time of their last page; a new
    // visit starts when it is missing (first page, browser closed and opened again) or older than 30 minutes (left the
    // site and came back later) — the usual web-analytics rule. Every page opened is still logged (popular pages need
    // that); new_visit = 1 marks the rows that start a visit (migration step 18).
    $now = time();
    $last = (int)($_COOKIE['bait_v'] ?? 0);
    $newVisit = $last <= 0 || $now - $last > VISIT_IDLE_SECONDS || $last > $now + 60;
    if (!headers_sent()) {
        setcookie('bait_v', (string)$now, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    }
    try {
        $db = Database::getInstance();
        $row = [
            // mb_strcut cuts on a character boundary: a plain substr could leave half a Thai letter at the end
            'page_url'   => mb_strcut($url, 0, 255, 'UTF-8'),
            'page_title' => mb_strcut($title, 0, 255, 'UTF-8'),
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => mb_strcut($ua, 0, 500, 'UTF-8'),
        ];
        if (hasVisitCounting()) $row['new_visit'] = $newVisit ? 1 : 0;
        $db->insert('page_views', $row);
        // Retention: visits older than 400 days (about 13 months, as the privacy page says) are removed; a config can set
        // define('PAGEVIEW_RETENTION_DAYS', n) for another length, or 0 to keep everything. Runs on roughly 1 request in 500
        // and removes a small batch each time, so it never slows a page down. The numbers removed (page views, and visits
        // once step 18 is in) are added to site_settings in the same transaction, so "all-time" totals stay right; without
        // those columns (migration not run yet) the UPDATE fails, the delete is rolled back and nothing is removed.
        $days = defined('PAGEVIEW_RETENTION_DAYS') ? (int)PAGEVIEW_RETENTION_DAYS : 400;
        if ($days > 0 && mt_rand(1, 500) === 1) {
            try {
                $db->beginTransaction();
                $cut = 'viewed_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
                $upTo = (int)($db->fetchOne("SELECT MAX(view_id) AS m FROM (SELECT view_id FROM page_views WHERE $cut ORDER BY view_id LIMIT 5000) b")['m'] ?? 0);
                if ($upTo > 0) {
                    $visits = hasVisitCounting() ? (int)($db->fetchOne("SELECT COALESCE(SUM(new_visit), 0) AS v FROM page_views WHERE $cut AND view_id <= ?", [$upTo])['v'] ?? 0) : 0;
                    $n = $db->query("DELETE FROM page_views WHERE $cut AND view_id <= ?", [$upTo])->rowCount();
                    if ($n > 0) $db->query('UPDATE site_settings SET pageviews_removed = pageviews_removed + ?' . (hasVisitCounting() ? ', visits_removed = visits_removed + ' . $visits : '') . ' ORDER BY setting_id LIMIT 1', [$n]);
                }
                $db->commit();
            } catch (Throwable $e) { try { $db->rollback(); } catch (Throwable $e2) {} }
            try { $db->query('DELETE FROM page_referrers WHERE viewed_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY) LIMIT 5000'); } catch (Throwable $e) {}   // the backlink log, same retention
        }
    } catch (Throwable $e) { /* silent */ }
    recordReferrer($url, $title);
}

// ============================================================
// BACKLINKS (admin: สถิติเว็บไซต์ > Backlink). When a visitor arrives by clicking a link on ANOTHER website, the page they
// came from (the browser's Referer, without its query string) is written to page_referrers together with the page of ours
// they landed on. Only real clicks are seen this way; links nobody clicked never show, and some browsers / sites send
// only the domain or nothing. Visits from inside the site itself are not recorded. Nothing happens before the migration
// (step 10) has created the table.
// ============================================================
/**
 * The other site's address this visit came from: ['url' => scheme://host/path, 'domain' => host without www./m.], or null.
 * Thai and other non-Latin domains (sent by browsers as "xn--…") are kept readable: the domain is stored as ไทย.com, the
 * link stays the working "xn--" form. A visit opened from a phone app (Gmail, LINE … send android-app://<app id>) is kept
 * too, with the domain "แอป Android: <app id>" (iOS apps: "แอป iOS: …").
 */
function externalReferrer(): ?array {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($ref) || $ref === '' || strlen($ref) > 2000) return null;
    $p = parse_url($ref);
    if (!is_array($p)) return null;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host   = rtrim((string)($p['host'] ?? ''), '.');
    if (preg_match('/^(android|ios)-app$/', $scheme)) {   // the app id, e.g. com.google.android.gm
        $app = strtolower($host);
        if (!preg_match('/^[a-z0-9_.-]{1,150}$/', $app)) return null;
        return ['url' => $scheme . '://' . $app, 'domain' => ($scheme === 'ios-app' ? 'แอป iOS: ' : 'แอป Android: ') . $app];
    }
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
    $host = mb_strtolower($host, 'UTF-8');
    if (!preg_match('/^[a-z0-9_.-]+$/', $host)) {   // a raw (not "xn--") international name: turn it into the "xn--" form when possible
        if (!preg_match('/^[\p{L}\p{M}\p{N}_.-]+$/u', $host)) return null;
        $ascii = function_exists('idn_to_ascii') ? @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        $urlHost = is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $host;
    } else {
        $urlHost = $host;
    }
    $bare = fn(string $h) => preg_replace('/^(www|m|l|lm|mobile|amp)\./', '', $h);
    $own = [$bare(strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))))];
    if (defined('BASE_URL_ABSOLUTE')) $own[] = $bare(strtolower((string)parse_url(BASE_URL_ABSOLUTE, PHP_URL_HOST)));
    if (in_array($bare($urlHost), $own, true)) return null;   // a link inside our own site
    $domain = $bare(idnToUnicode($urlHost));
    $path = (string)($p['path'] ?? '/');
    return ['url' => mb_strcut($scheme . '://' . $urlHost . ($path === '' ? '/' : $path), 0, 500, 'UTF-8'), 'domain' => mb_strcut($domain, 0, 190, 'UTF-8')];
}

/** "xn--o3cw4h.com" -> "ไทย.com" (labels that are not valid "xn--" stay as they are). Works without the intl extension. */
function idnToUnicode(string $host): string {
    if (strpos($host, 'xn--') === false) return $host;
    if (function_exists('idn_to_utf8')) {
        $u = @idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($u) && $u !== '') return $u;
    }
    $labels = explode('.', $host);
    foreach ($labels as &$l) if (strncmp($l, 'xn--', 4) === 0 && ($d = punycodeDecode(substr($l, 4))) !== null) $l = $d;
    unset($l);
    return implode('.', $labels);
}

/** RFC 3492 decoder for one label (without "xn--"); null when it is not valid. */
function punycodeDecode(string $in): ?string {
    if ($in === '' || strlen($in) > 63) return null;
    $base = 36; $tmin = 1; $tmax = 26; $n = 128; $i = 0; $bias = 72; $out = [];
    $adapt = function (int $delta, int $points, bool $first) use ($base, $tmin, $tmax): int {
        $delta = $first ? intdiv($delta, 700) : intdiv($delta, 2);
        $delta += intdiv($delta, $points);
        $k = 0;
        while ($delta > intdiv(($base - $tmin) * $tmax, 2)) { $delta = intdiv($delta, $base - $tmin); $k += $base; }
        return $k + intdiv(($base - $tmin + 1) * $delta, $delta + 38);
    };
    $dash = strrpos($in, '-');
    if ($dash !== false) {
        for ($j = 0; $j < $dash; $j++) { $c = ord($in[$j]); if ($c >= 0x80) return null; $out[] = $c; }
        $in = substr($in, $dash + 1);
    }
    $len = strlen($in);
    for ($pos = 0; $pos < $len;) {
        $old = $i; $w = 1;
        for ($k = $base; ; $k += $base) {
            if ($pos >= $len) return null;
            $c = ord($in[$pos++]);
            if ($c >= 48 && $c <= 57) $digit = $c - 22;
            elseif ($c >= 65 && $c <= 90) $digit = $c - 65;
            elseif ($c >= 97 && $c <= 122) $digit = $c - 97;
            else return null;
            $i += $digit * $w;
            $t = $k <= $bias ? $tmin : ($k >= $bias + $tmax ? $tmax : $k - $bias);
            if ($digit < $t) break;
            $w *= $base - $t;
            if ($w > 0x7FFFFFFF) return null;
        }
        $count = count($out) + 1;
        $bias = $adapt($i - $old, $count, $old === 0);
        $n += intdiv($i, $count);
        $i %= $count;
        if ($n > 0x10FFFF || ($n >= 0xD800 && $n <= 0xDFFF)) return null;
        array_splice($out, $i, 0, [$n]);
        $i++;
    }
    return implode('', array_map('mb_chr', $out));
}
/** What kind of page of ours this is, from its address (for the report's "ประเภทเนื้อหา" filter). */
function pageTypeOf(string $url): string {
    $file = basename((string)parse_url($url, PHP_URL_PATH));
    $map = [
        'news' => ['news.php', 'news-detail.php'], 'article' => ['articles.php'], 'program' => ['programs.php', 'program-detail.php', 'majors.php', 'tuition.php'],
        'staff' => ['staff.php'], 'about' => ['about.php', 'campuses.php', 'campus-detail.php', 'privacy.php'], 'stats' => ['statistics.php', 'statistics-staff.php'],
        'download' => ['forms.php', 'info.php'], 'contact' => ['contact.php'],
    ];
    if ($file === '' || $file === 'index.php' || substr((string)parse_url($url, PHP_URL_PATH), -1) === '/') return 'home';
    foreach ($map as $type => $files) if (in_array($file, $files, true)) return $type;
    return 'other';
}

/** Thai labels of pageTypeOf() values, in menu order. */
function pageTypeLabels(): array {
    return ['home' => 'หน้าแรก', 'news' => 'ข่าวสาร', 'article' => 'บทความ', 'program' => 'หลักสูตร / สาขาวิชา', 'staff' => 'บุคลากร',
            'about' => 'เกี่ยวกับคณะ / ศูนย์พื้นที่', 'stats' => 'สถิติ', 'download' => 'ดาวน์โหลด / สารสนเทศ', 'contact' => 'ติดต่อ', 'other' => 'อื่น ๆ'];
}

/**
 * Called from trackPageView() (so bots are already filtered out). Reloading the page (or coming back to it from the same
 * link) within 30 minutes is not counted again: each row carries a short one-way key of the visitor (a hash of the day,
 * the IP address and the browser; the address itself is not stored here). Before migration step 12c adds that column
 * every visit is recorded as before.
 */
function recordReferrer(string $url, string $title): void {
    $ref = externalReferrer();
    if (!$ref) return;
    static $hasVisitor = null;
    try {
        $db = Database::getInstance();
        $row = [
            'page_url'   => mb_strcut($url, 0, 255, 'UTF-8'),
            'page_title' => mb_strcut($title, 0, 255, 'UTF-8'),
            'page_type'  => pageTypeOf($url),
            'ref_url'    => $ref['url'],
            'ref_domain' => $ref['domain'],
        ];
        if ($hasVisitor === null) {
            try { $db->query('SELECT visitor FROM page_referrers LIMIT 0'); $hasVisitor = true; } catch (Throwable $e) { $hasVisitor = false; }
        }
        if ($hasVisitor) {
            $row['visitor'] = substr(hash('sha256', date('Y-m-d') . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
            if ($db->fetchOne('SELECT 1 AS x FROM page_referrers WHERE visitor = ? AND viewed_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                               AND ref_url = ? AND page_url = ? LIMIT 1', [$row['visitor'], $row['ref_url'], $row['page_url']])) return;
        }
        $db->insert('page_referrers', $row);
    } catch (Throwable $e) {
        // before migration step 10 the table does not exist: nothing to record. Any other problem goes to the PHP error log.
        if (strpos($e->getMessage(), '1146') === false && stripos($e->getMessage(), "doesn't exist") === false) error_log('recordReferrer: ' . $e->getMessage());
    }
}
/** Visits already removed by the retention above (0 before the migration adds the column): added to every all-time total. */
function pageViewsRemoved(): int {
    try {
        return (int)(Database::getInstance()->fetchOne('SELECT pageviews_removed AS n FROM site_settings ORDER BY setting_id LIMIT 1')['n'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/** A visit ends after this long without opening another page (30 minutes, as in common web analytics). */
if (!defined('VISIT_IDLE_SECONDS')) define('VISIT_IDLE_SECONDS', 1800);
/** True once migration step 18 added page_views.new_visit (and site_settings.visits_removed): visitors are then counted per visit. */
function hasVisitCounting(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW COLUMNS FROM page_views LIKE 'new_visit'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}
/** Visits already removed by the 400-day retention (added to "all time"). */
function visitsRemoved(): int {
    if (!hasVisitCounting()) return pageViewsRemoved();
    try {
        return (int)(Database::getInstance()->fetchOne('SELECT visits_removed AS n FROM site_settings ORDER BY setting_id LIMIT 1')['n'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/**
 * Visit totals (today / this month / this year / all time). The public footer and the admin statistics page both read
 * THIS one snapshot, so the two always show the same numbers. It is counted again after $ttl seconds and as soon as the
 * date changes (so "today" never shows yesterday's count after midnight); $ttl = 0 counts now and refreshes the snapshot.
 * The date tests are written as ranges on viewed_at (not DATE()/YEAR() of the column) so the index is used: with
 * 700,000 rows the old form took 0.7 s per refresh. While one request refreshes, others keep reading the old copy.
 * Returns null when the table is missing or unreadable (footer then hides the strip).
 */
function siteVisitStats(int $ttl = 60): ?array {
    $dir = is_dir(BASE_PATH . '/cache') && is_writable(BASE_PATH . '/cache') ? BASE_PATH . '/cache' : rtrim(sys_get_temp_dir(), '/\\');
    $cache = $dir . DIRECTORY_SEPARATOR . 'visit_stats.json';
    $old = null;
    if (is_file($cache)) {
        $d = json_decode((string)@file_get_contents($cache), true);
        if (is_array($d) && isset($d['all'])) {
            $old = $d;
            if ($ttl > 0 && ($d['day'] ?? '') === date('Y-m-d') && (time() - (int)@filemtime($cache)) < $ttl) return $d;
            if ($ttl > 0) @touch($cache);   // claim the refresh: requests arriving meanwhile still see a "fresh" file and use the old numbers
        }
    }
    try {
        // visits (step 18) — before that migration every page opened counts, as before
        $only = hasVisitCounting() ? 'new_visit = 1 AND ' : '';
        $r = Database::getInstance()->fetchOne(
            "SELECT (SELECT COUNT(*) FROM page_views" . ($only ? ' WHERE new_visit = 1' : '') . ") AS total,
                    (SELECT COUNT(*) FROM page_views WHERE {$only}viewed_at >= CURDATE()) AS today,
                    (SELECT COUNT(*) FROM page_views WHERE {$only}viewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month,
                    (SELECT COUNT(*) FROM page_views WHERE {$only}viewed_at >= MAKEDATE(YEAR(CURDATE()), 1)) AS year"
        );
    } catch (Throwable $e) { return $old; }
    if (!$r) return $old;
    $d = ['today' => (int)$r['today'], 'month' => (int)$r['month'], 'year' => (int)$r['year'], 'all' => (int)$r['total'] + visitsRemoved(),
          'day' => date('Y-m-d'), 'at' => time()];
    @file_put_contents($cache, json_encode($d), LOCK_EX);
    return $d;
}
// ============================================================
// MOURNING MODE (admin: ตั้งค่าเว็บไซต์ > ไว้อาลัย). Options live as JSON in site_settings.mourning_options.
// ============================================================
/** Cleaned mourning options; whatever is stored (or nothing at all) always yields a safe, switched-off default. */
function mourningOptions($raw): array {
    $d = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    $d = is_array($d) ? $d : [];
    $date = fn($v) => (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) ? $v : '';   // a real calendar day (2026-02-31 is not)
    $img  = fn($v) => (is_string($v) && preg_match('#^mourning/[A-Za-z0-9_.\-]+\.(?:png|jpe?g|webp|gif)$#i', $v)) ? $v : '';   // only files this feature uploaded itself
    return [
        'enabled'     => !empty($d['enabled']) ? 1 : 0,
        'mode'        => (($d['mode'] ?? 'manual') === 'schedule') ? 'schedule' : 'manual',   // manual = on until switched off; schedule = between two dates
        'start'       => $date($d['start'] ?? ''),
        'end'         => $date($d['end'] ?? ''),
        'gray'        => array_key_exists('gray', $d) ? (!empty($d['gray']) ? 1 : 0) : 1,       // the grey filter (older saved options had no such switch: it was always on)
        'level'       => max(0, min(100, (int)($d['level'] ?? 95))),                            // % of grey: 100 = black and white, ~95 recommended, ~50 = just muted
        'ribbon'      => !empty($d['ribbon']) ? 1 : 0,
        'ribbon_image'=> $img($d['ribbon_image'] ?? ''),                                         // optional own icon inside the corner ribbon
        'popup'       => !empty($d['popup']) ? 1 : 0,
        'popup_freq'  => (($d['popup_freq'] ?? 'session') === 'always') ? 'always' : 'session',  // once per browser session / on every page load
        'popup_image' => $img($d['popup_image'] ?? ''),
    ];
}
// ============================================================
// FOOTER BACKGROUND PICTURES (admin: ตั้งค่าเว็บไซต์ > ภาพพื้นหลัง Footer). Stored as JSON in site_settings.footer_bg: {"tall":"footer/x.jpg","wide":"footer/y.jpg"}
// tall = phones and tablets, wide = from 1024px up. A side left empty keeps the built-in picture.
// ============================================================
function footerBgOptions($raw): array {
    $d = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    $d = is_array($d) ? $d : [];
    $img = fn($v) => (is_string($v) && preg_match('#^footer/[A-Za-z0-9_.\-]+\.(?:png|jpe?g|webp)$#i', $v)) ? $v : '';   // only files this feature uploaded itself
    return ['tall' => $img($d['tall'] ?? ''), 'wide' => $img($d['wide'] ?? '')];
}

/** The <style> that swaps in the chosen pictures ('' when nothing is chosen, so the stylesheet's own pictures stay). */
function footerBgStyle($raw): string {
    $o = footerBgOptions($raw);
    $url = function (string $rel, string $fallback): string {
        if ($rel !== '' && is_file(UPLOAD_PATH . '/' . $rel)) return UPLOAD_URL . '/' . $rel . '?v=' . (int)@filemtime(UPLOAD_PATH . '/' . $rel);
        return BASE_URL . '/assets/img/' . $fallback . '?v=' . (int)@filemtime(BASE_PATH . '/assets/img/' . $fallback);
    };
    $custom = fn(string $rel) => $rel !== '' && is_file(UPLOAD_PATH . '/' . $rel);
    if (!$custom($o['tall']) && !$custom($o['wide'])) return '';
    $t = $url($o['tall'], 'footer-building-tall.jpg');
    $w = $url($o['wide'], 'footer-building-wide.jpg');
    return '<style id="footer-bg">.site-footer { --footer-photo: url("' . e($t) . '"); } @media (min-width: 1024px) { .site-footer { --footer-photo: url("' . e($w) . '"); } }</style>';
}

// ============================================================
// HOME "ABOUT THE FACULTY" BACKGROUND (admin: ตั้งค่าเว็บไซต์ > พื้นหลัง "เกี่ยวกับคณะ"). JSON in site_settings.about_bg:
// {"image":"about/x.jpg","tone":"light"|"blue"|"color","dim":74} — a photo behind the section, treated by the stylesheet (dim = how
// strong the layer over the photo is, 30-90 %): "light" = grey photo under a
// white layer (dark text, like the plain section), "blue" = grey, darker photo under the brand blue (white text, like the footer),
// "color" = the photo in its own colours under a neutral dark layer (white text).
// No picture = the plain section as before.
// ============================================================
function aboutBgOptions($raw): array {
    $d = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    $d = is_array($d) ? $d : [];
    $img = (is_string($d['image'] ?? null) && preg_match('#^about/[A-Za-z0-9_.\-]+\.(?:png|jpe?g|webp)$#i', $d['image'])) ? $d['image'] : '';   // only files this feature uploaded itself
    return ['image' => $img, 'tone' => in_array($d['tone'] ?? '', ['blue', 'color'], true) ? $d['tone'] : 'light', 'dim' => max(30, min(90, (int)($d['dim'] ?? 74)))];
}

/** The class + inline style for the home about section ('' when no picture is set). */
function aboutBgAttrs($raw): array {
    $o = aboutBgOptions($raw);
    if ($o['image'] === '' || !is_file(UPLOAD_PATH . '/' . $o['image'])) return ['class' => '', 'style' => ''];
    $url = UPLOAD_URL . '/' . $o['image'] . '?v=' . (int)@filemtime(UPLOAD_PATH . '/' . $o['image']);
    return ['class' => ' about-band--photo about-band--' . $o['tone'] . ($o['tone'] === 'light' ? '' : ' about-band--dark'), 'style' => ' style="--about-photo:url(&quot;' . e($url) . '&quot;);--about-dim:' . ($o['dim'] / 100) . '"'];
}

// ============================================================
// SDGs (UN Sustainable Development Goals) on news. A news item can be linked to any number of the 17 goals
// (table news_sdgs: news_id + sdg 1-17, removed with the news). Names: short label for badges, and the full Thai
// wording of the goal as published by สศช. (sdgs.nesdc.go.th). Colours: the official UN colour of each goal.
// Everything here is safe before the migration has created news_sdgs: reads give nothing, saves do nothing.
// ============================================================
function sdgList(): array {
    return [
        1  => ['#E5243B', 'ขจัดความยากจน', 'No Poverty', 'ยุติความยากจนทุกรูปแบบในทุกที่'],
        2  => ['#DDA63A', 'ขจัดความหิวโหย', 'Zero Hunger', 'ยุติความหิวโหย บรรลุความมั่นคงทางอาหาร และยกระดับโภชนาการ และส่งเสริมเกษตรกรรมที่ยั่งยืน'],
        3  => ['#4C9F38', 'สุขภาพและความเป็นอยู่ที่ดี', 'Good Health and Well-being', 'สร้างหลักประกันการมีสุขภาวะที่ดี และส่งเสริมความเป็นอยู่ที่ดีสำหรับทุกคนในทุกช่วงวัย'],
        4  => ['#C5192D', 'การศึกษาที่มีคุณภาพ', 'Quality Education', 'สร้างหลักประกันว่าทุกคนมีการศึกษาที่มีคุณภาพอย่างครอบคลุมและเท่าเทียม และสนับสนุนโอกาสในการเรียนรู้ตลอดชีวิต'],
        5  => ['#FF3A21', 'ความเท่าเทียมทางเพศ', 'Gender Equality', 'บรรลุความเสมอภาคระหว่างเพศ และเพิ่มบทบาทของสตรีและเด็กหญิงทุกคน'],
        6  => ['#26BDE2', 'น้ำสะอาดและสุขาภิบาล', 'Clean Water and Sanitation', 'สร้างหลักประกันเรื่องน้ำและการสุขาภิบาล ให้มีการจัดการอย่างยั่งยืนและมีสภาพพร้อมใช้สำหรับทุกคน'],
        7  => ['#FCC30B', 'พลังงานสะอาดที่เข้าถึงได้', 'Affordable and Clean Energy', 'สร้างหลักประกันว่าทุกคนเข้าถึงพลังงานสมัยใหม่ในราคาที่สามารถซื้อหาได้ เชื่อถือได้ และยั่งยืน'],
        8  => ['#A21942', 'งานที่มีคุณค่าและการเติบโตทางเศรษฐกิจ', 'Decent Work and Economic Growth', 'ส่งเสริมการเจริญเติบโตทางเศรษฐกิจที่ต่อเนื่อง ครอบคลุม และยั่งยืน การจ้างงานเต็มที่และมีผลิตภาพ และการมีงานที่มีคุณค่าสำหรับทุกคน'],
        9  => ['#FD6925', 'อุตสาหกรรม นวัตกรรม และโครงสร้างพื้นฐาน', 'Industry, Innovation and Infrastructure', 'สร้างโครงสร้างพื้นฐานที่มีความยืดหยุ่นต่อการเปลี่ยนแปลง ส่งเสริมการพัฒนาอุตสาหกรรมที่ครอบคลุมและยั่งยืน และส่งเสริมนวัตกรรม'],
        10 => ['#DD1367', 'ลดความเหลื่อมล้ำ', 'Reduced Inequalities', 'ลดความไม่เสมอภาคภายในและระหว่างประเทศ'],
        11 => ['#FD9D24', 'เมืองและชุมชนที่ยั่งยืน', 'Sustainable Cities and Communities', 'ทำให้เมืองและการตั้งถิ่นฐานของมนุษย์ มีความครอบคลุม ปลอดภัย ยืดหยุ่นต่อการเปลี่ยนแปลง และยั่งยืน'],
        12 => ['#BF8B2E', 'การผลิตและการบริโภคที่ยั่งยืน', 'Responsible Consumption and Production', 'สร้างหลักประกันให้มีแบบแผนการผลิตและการบริโภคที่ยั่งยืน'],
        13 => ['#3F7E44', 'การรับมือการเปลี่ยนแปลงสภาพภูมิอากาศ', 'Climate Action', 'ปฏิบัติการอย่างเร่งด่วนเพื่อต่อสู้กับการเปลี่ยนแปลงสภาพภูมิอากาศและผลกระทบที่เกิดขึ้น'],
        14 => ['#0A97D9', 'ทรัพยากรทางทะเล', 'Life Below Water', 'อนุรักษ์และใช้ประโยชน์จากมหาสมุทร ทะเลและทรัพยากรทางทะเลอย่างยั่งยืนเพื่อการพัฒนาที่ยั่งยืน'],
        15 => ['#56C02B', 'ระบบนิเวศบนบก', 'Life on Land', 'ปกป้อง ฟื้นฟู และสนับสนุนการใช้ระบบนิเวศบนบกอย่างยั่งยืน จัดการป่าไม้อย่างยั่งยืน ต่อสู้การกลายสภาพเป็นทะเลทราย หยุดการเสื่อมโทรมของที่ดินและฟื้นฟูสภาพกลับมาใหม่ และหยุดการสูญเสียความหลากหลายทางชีวภาพ'],
        16 => ['#00689D', 'สันติภาพ ความยุติธรรม และสถาบันที่เข้มแข็ง', 'Peace, Justice and Strong Institutions', 'ส่งเสริมสังคมที่สงบสุขและครอบคลุมเพื่อการพัฒนาที่ยั่งยืน ให้ทุกคนเข้าถึงความยุติธรรม และสร้างสถาบันที่มีประสิทธิผล รับผิดชอบ และครอบคลุมในทุกระดับ'],
        17 => ['#19486A', 'ความร่วมมือเพื่อการพัฒนาที่ยั่งยืน', 'Partnerships for the Goals', 'เสริมความแข็งแกร่งให้แก่กลไกการดำเนินงาน และฟื้นฟูหุ้นส่วนความร่วมมือระดับโลกเพื่อการพัฒนาที่ยั่งยืน'],
    ];
}

/** Short name of goal $n in the page language. */
function sdgName(int $n, string $lang): string {
    $g = sdgList()[$n] ?? null;
    return $g ? ($lang === 'en' ? $g[2] : $g[1]) : '';
}

/** Does the news_sdgs table exist yet (migration step 9)? */
function hasNewsSdgs(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW TABLES LIKE 'news_sdgs'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** The goals ticked in the news form (sdgs[] = 1..17), cleaned and sorted. */
function sdgsFromPost(): array {
    $v = $_POST['sdgs'] ?? [];
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) if (is_string($x) && ctype_digit($x) && (int)$x >= 1 && (int)$x <= 17) $out[(int)$x] = true;
    $out = array_keys($out);
    sort($out);
    return $out;
}

/** news_id => [goal numbers] for the given news ids (one query). */
function newsSdgsMap(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !hasNewsSdgs()) return [];
    $map = [];
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (Database::getInstance()->fetchAll("SELECT news_id, sdg FROM news_sdgs WHERE news_id IN ($marks) ORDER BY sdg", $ids) as $r) $map[(int)$r['news_id']][] = (int)$r['sdg'];
    } catch (Throwable $e) { return []; }
    return $map;
}

/** Adds 'sdgs' => [...] to each news row (for newsCard()). */
function attachNewsSdgs(array $rows): array {
    $map = newsSdgsMap(array_column($rows, 'news_id'));
    foreach ($rows as &$r) $r['sdgs'] = $map[(int)($r['news_id'] ?? 0)] ?? [];
    unset($r);
    return $rows;
}

/** Replaces the goals of one news item. */
function saveNewsSdgs(int $newsId, array $sdgs): void {
    if ($newsId <= 0 || !hasNewsSdgs()) return;
    $db = Database::getInstance();
    $db->query('DELETE FROM news_sdgs WHERE news_id = ?', [$newsId]);
    foreach ($sdgs as $n) if ($n >= 1 && $n <= 17) $db->query('INSERT INTO news_sdgs (news_id, sdg) VALUES (?, ?)', [$newsId, (int)$n]);
}

/**
 * Badges for a list of goals. $compact = small "SDGs 4" chips for news cards (not links: the card itself is the link);
 * otherwise number + name, each a link to the news list filtered by that goal.
 */
function sdgBadges(array $nums, string $lang, bool $compact = false): string {
    $list = sdgList();
    $out = '';
    foreach ($nums as $n) {
        $n = (int)$n;
        if (!isset($list[$n])) continue;
        [$color, $th, $en, $full] = $list[$n];
        $name  = $lang === 'en' ? $en : $th;
        $style = '--sdg:' . $color . ';--sdg-ink:' . inkOn($color);
        $tip   = e('SDGs ' . $n . ': ' . ($lang === 'en' ? $en : $full));
        if ($compact) {
            $out .= '<span class="sdg-chip" style="' . $style . '" title="' . $tip . '">SDGs ' . $n . '<span class="visually-hidden"> ' . e($name) . '</span></span>';
        } else {
            $url = BASE_URL . '/news.php?sdg=' . $n . '&amp;lang=' . e($lang);
            $out .= '<a class="sdg-badge" href="' . $url . '" style="' . $style . '" title="' . $tip . '"><span class="sdg-num">' . $n . '</span><span class="sdg-name">' . e($name) . '</span></a>';
        }
    }
    return $out === '' ? '' : '<div class="' . ($compact ? 'card-sdgs' : 'sdg-badges') . '">' . $out . '</div>';
}

// ============================================================
// TUITION FEES (public tuition.php, admin: หลักสูตร > ค่าเทอม). Table tuition_fees: several rows per programme
// (ภาคเรียนและวัน), campus_id NULL = same at every campus. tuition_page: academic year, date of the figures, footnote.
// Everything here is safe before the migration (step 11): reads give nothing.
// ============================================================
function hasTuition(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW TABLES LIKE 'tuition_fees'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Fee kinds: lump = เหมาจ่ายต่อภาคเรียน, credit = the amounts are per credit. */
function tuitionFeeTypes(): array {
    return ['lump' => 'เหมาจ่าย', 'credit' => 'ต่อหน่วยกิต'];
}

/** program_id => fee rows (in the order the admin set), for the given programmes. */
function tuitionRowsByProgram(array $programIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $programIds))));
    if (!$ids || !hasTuition()) return [];
    $out = [];
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (Database::getInstance()->fetchAll(
            "SELECT f.*, c.campus_name FROM tuition_fees f LEFT JOIN campuses c ON c.campus_id = f.campus_id
             WHERE f.program_id IN ($marks) ORDER BY f.sort_order, f.fee_id", $ids) as $r) $out[(int)$r['program_id']][] = $r;
    } catch (Throwable $e) { return []; }
    return $out;
}

/** Page settings (academic year, date of the figures, footnote), with empty defaults. */
function tuitionSettings(): array {
    $d = ['academic_year' => '', 'data_date' => null, 'note' => '', 'updated_at' => null];
    if (!hasTuition()) return $d;
    try { $r = Database::getInstance()->fetchOne('SELECT * FROM tuition_page WHERE setting_id = 1'); } catch (Throwable $e) { $r = null; }
    return $r ? array_merge($d, $r) : $d;
}

/** The whole-programme figure: the one the admin typed, or for a lump fee (term 1 + term 2) x years; null when unknown. */
function tuitionTotal(array $r): ?int {
    if ($r['total'] !== null && $r['total'] !== '') return (int)$r['total'];
    if (($r['fee_type'] ?? 'lump') !== 'lump' || empty($r['term1']) || empty($r['duration_years'])) return null;
    $t1 = (int)$r['term1']; $t2 = $r['term2'] !== null && $r['term2'] !== '' ? (int)$r['term2'] : $t1;
    return (int)round(($t1 + $t2) * (float)$r['duration_years']);
}

/**
 * Dropdown choices of the tuition forms (table tuition_options, migration step 12): kind = year | schedule | duration.
 * Returns the values in order; [] before the migration (the forms then fall back to typing the value).
 */
function tuitionOptions(string $kind): array {
    static $cache = [];
    if (isset($cache[$kind])) return $cache[$kind];
    try {
        $rows = Database::getInstance()->fetchAll('SELECT value FROM tuition_options WHERE kind = ? ORDER BY sort_order, value', [$kind]);
        return $cache[$kind] = array_column($rows, 'value');
    } catch (Throwable $e) { return $cache[$kind] = []; }
}

/** Is the choices table there (migration step 12)? */
function hasTuitionOptions(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW TABLES LIKE 'tuition_options'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Adds a new choice (already validated by the caller); an existing one is left as it is. */
function tuitionAddOption(string $kind, string $value): void {
    if ($value === '' || !in_array($kind, ['year', 'schedule', 'duration'], true) || !hasTuitionOptions()) return;
    $order = $kind === 'year' ? (int)$value : ($kind === 'duration' ? (int)round((float)$value * 10) : 100);
    try { Database::getInstance()->query('INSERT IGNORE INTO tuition_options (kind, value, sort_order) VALUES (?, ?, ?)', [$kind, mb_substr($value, 0, 120), $order]); }
    catch (Throwable $e) { error_log('tuitionAddOption(' . $kind . '): ' . $e->getMessage()); }   // the fee itself is saved; only the new choice is missing from the list
}

/** "4.0" -> "4", "2.5" -> "2.5" (how a length is written in the choices). */
function tuitionYearsKey($v): string {
    return ($v === null || $v === '') ? '' : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
}

/** "4 ปี" / "2.5 ปี" (en: "4 years"). */
function tuitionYears($v, string $lang): string {
    if ($v === null || $v === '') return '-';
    $n = rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
    return $lang === 'en' ? $n . ' ' . ((float)$v == 1.0 ? 'year' : 'years') : $n . ' ปี';
}

/** Is the mourning look on right now (Thailand time)? A schedule with no date at all never switches itself on. */
function mourningActive(array $o, ?int $now = null): bool {
    if (empty($o['enabled'])) return false;
    if ($o['mode'] === 'manual') return true;
    if ($o['start'] === '' && $o['end'] === '') return false;
    $today = date('Y-m-d', $now ?? time());
    if ($o['start'] !== '' && $today < $o['start']) return false;
    if ($o['end'] !== '' && $today > $o['end']) return false;   // the end date itself is still included
    return true;
}

/** The one-line status shown to the admin. */
function mourningStatusText(array $o, ?int $now = null): string {
    $be = fn(string $d) => date('d/m/', strtotime($d)) . (date('Y', strtotime($d)) + 543);
    if (empty($o['enabled'])) return 'ปิดอยู่';
    if (mourningActive($o, $now)) return 'กำลังไว้อาลัยอยู่' . ($o['mode'] === 'schedule' && $o['end'] !== '' ? ' (ถึงวันที่ ' . $be($o['end']) . ')' : ($o['mode'] === 'manual' ? ' (เปิดจนกว่าจะปิดเอง)' : ''));
    if ($o['mode'] === 'schedule' && $o['start'] !== '' && date('Y-m-d', $now ?? time()) < $o['start']) return 'ตั้งเวลาไว้ จะเริ่มวันที่ ' . $be($o['start']);
    return $o['mode'] === 'schedule' ? 'หมดช่วงที่ตั้งไว้แล้ว (เว็บกลับเป็นสีปกติ)' : 'ปิดอยู่';
}

// ============================================================
// CSRF
// ============================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void {
    // A request bigger than post_max_size reaches PHP with an empty $_POST/$_FILES — that is a too-large upload, not an expired session.
    $lim = ini_get('post_max_size');
    $limBytes = (int)$lim * (stripos($lim, 'g') !== false ? 1073741824 : (stripos($lim, 'm') !== false ? 1048576 : (stripos($lim, 'k') !== false ? 1024 : 1)));
    if ($limBytes > 0 && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limBytes) {
        http_response_code(413);
        die('ไฟล์หรือข้อมูลที่ส่งมีขนาดใหญ่เกินที่เซิร์ฟเวอร์รับได้ (ไม่เกิน ' . $lim . ') กรุณาลดขนาดไฟล์แล้วลองอีกครั้ง');
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {   // a crafted csrf_token[]=x is a bad token, not a 500
        http_response_code(403);
        die('เซสชันหมดอายุหรือไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }
}

// ============================================================
// JSON RESPONSE (for AJAX)
// ============================================================
function jsonSuccess(array $data = [], string $message = 'success'): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'message' => $message, 'data' => $data]);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** True when the request came from page script (axios / fetch asking for JSON), false for an ordinary form submit. */
function wantsJson(): bool {
    return stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
}

/**
 * Stops a request the admin cannot complete (a value too long for its column, a row still in use ...). An AJAX save gets the
 * usual JSON error; an ordinary form (news, articles, programs, staff ...) gets a short Thai page with a way back, instead of
 * raw JSON with the Thai written as ข... The form's typed values survive the browser's Back.
 */
function adminFail(string $message, int $code = 400): void {
    if (wantsJson()) jsonError($message, $code);
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $m = e($message);
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>บันทึกไม่สำเร็จ</title><style>body{font:16px/1.6 system-ui,"Kanit",sans-serif;background:#f5f6fa;color:#1e2a45;margin:0;padding:16px}'
       . '.box{max-width:560px;margin:10vh auto;background:#fff;border:1px solid #e3e6ee;border-left:5px solid #c1443d;border-radius:10px;padding:20px 22px}'
       . 'h1{font-size:1.15rem;margin:0 0 8px;color:#c1443d}a,button{display:inline-block;margin-top:14px;padding:9px 16px;border-radius:8px;border:0;'
       . 'background:#162E93;color:#fff;font:inherit;text-decoration:none;cursor:pointer}</style></head><body><div class="box" role="alert">'
       . '<h1>บันทึกไม่สำเร็จ</h1><p>' . $m . '</p><p style="font-size:.9rem;color:#5b6480">ข้อมูลยังไม่ถูกบันทึก — กดย้อนกลับเพื่อแก้ไข ข้อความที่พิมพ์ไว้ยังอยู่</p>'
       . '<button type="button" onclick="history.back()">ย้อนกลับไปแก้ไข</button></div></body></html>';
    exit;
}

// ============================================================
// STRUCTURED DATA - faculty as an organisation (for Google and AI answer engines)
// ============================================================
/**
 * schema.org @graph describing the faculty itself: name, logo, address, phone/email, official profiles
 * (sameAs), its campuses and the parent university. Everything is read from the database (faculty,
 * site_settings, campuses, campus_contacts), so editing those in the admin updates it.
 * The first campus (lowest id) is treated as the faculty office.
 */
function orgSchema(Database $db, string $lang = 'th'): array {
    $site = BASE_URL_ABSOLUTE;
    $tr   = fn(string $s) => $lang === 'en' ? translateText($s, 'en') : $s;
    $fac  = $db->fetchOne("SELECT * FROM faculty LIMIT 1") ?: [];
    $set  = $db->fetchOne("SELECT logo, site_name, site_abbr FROM site_settings LIMIT 1") ?: [];
    $name = $lang === 'en'
        ? (trim($fac['faculty_name_en'] ?? '') ?: 'Faculty of Business Administration and Information Technology')
        : (trim($fac['faculty_name_th'] ?? '') ?: (trim($set['site_name'] ?? '') ?: 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ'));
    $logoFile = trim($fac['logo'] ?? '') ?: trim($set['logo'] ?? '');

    $campuses = $db->fetchAll("SELECT campus_id, campus_name, address FROM campuses ORDER BY campus_id");
    $contacts = [];
    foreach ($db->fetchAll("SELECT campus_id, contact_type, contact_value FROM campus_contacts ORDER BY campus_id, contact_id") as $c) {
        $contacts[$c['campus_id']][$c['contact_type']][] = trim($c['contact_value']);
    }
    $profile = function (string $type, string $v): ?string {
        if ($v === '') return null;
        $href = contactHref(['contact_type' => $type, 'contact_value' => $v]);   // same rules as the public contact page (handles, channel ids)
        return isHttpUrl($href) ? $href : null;
    };
    $profilesOf = function (int $cid) use ($contacts, $profile): array {
        $out = [];
        foreach (['facebook', 'tiktok', 'youtube', 'x', 'instagram'] as $t) foreach ($contacts[$cid][$t] ?? [] as $v) { if ($u = $profile($t, $v)) $out[] = $u; }
        return array_values(array_unique($out));
    };
    $clean = fn(string $s) => trim(preg_replace('/\s+/u', ' ', $s));

    $main = $campuses[0] ?? null;
    $org = [
        '@type' => 'EducationalOrganization',
        '@id'   => $site . '/#faculty',
        'name'  => $name,
        'alternateName' => array_values(array_filter([trim($set['site_abbr'] ?? '') ? trim($set['site_abbr']) . ' RMUTSB' : null, $lang === 'en' ? null : ($fac['faculty_name_en'] ?? null)])),
        'url'   => $site . '/',
        'parentOrganization' => ['@type' => 'CollegeOrUniversity', 'name' => $lang === 'en' ? 'Rajamangala University of Technology Suvarnabhumi' : 'มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ', 'url' => 'https://www.rmutsb.ac.th'],
    ];
    if ($logoFile) $org['logo'] = UPLOAD_URL_ABSOLUTE . '/' . $logoFile;
    if ($main) {
        $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $clean($tr((string)$main['address'])), 'addressCountry' => 'TH'];
        $point = ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'availableLanguage' => ['th', 'en']];
        if ($ph = ($contacts[$main['campus_id']]['phone'][0] ?? '')) $point['telephone'] = preg_replace('/^0\s*/', '+66 ', $ph);
        if ($em = ($contacts[$main['campus_id']]['email'][0] ?? '')) $point['email'] = $em;
        if (count($point) > 3) { $org['telephone'] = $point['telephone'] ?? null; $org['email'] = $point['email'] ?? null; $org['contactPoint'] = $point; $org = array_filter($org, fn($v) => $v !== null); }
        if ($same = $profilesOf((int)$main['campus_id'])) $org['sameAs'] = $same;
    }
    $subs = [];
    foreach ($campuses as $c) {
        $sub = ['@type' => 'EducationalOrganization', 'name' => $name . ' - ' . $clean($tr((string)$c['campus_name'])), 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $clean($tr((string)$c['address'])), 'addressCountry' => 'TH']];
        if ($same = $profilesOf((int)$c['campus_id'])) $sub['sameAs'] = $same;
        $subs[] = $sub;
    }
    if ($subs) $org['subOrganization'] = $subs;

    return [
        '@context' => 'https://schema.org',
        '@graph'   => [
            $org,
            [
                '@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site . '/', 'name' => $name,
                'inLanguage' => $lang === 'en' ? 'en' : 'th', 'publisher' => ['@id' => $site . '/#faculty'],
                'potentialAction' => ['@type' => 'SearchAction', 'target' => $site . '/search.php?q={search_term_string}', 'query-input' => 'required name=search_term_string'],
            ],
        ],
    ];
}

/* ------------------------------------------------------------------
 * Home page promo video (index.php + admin/modules/home-promo)
 * ------------------------------------------------------------------ */

/**
 * What to put on the page for a pasted video link. YouTube (watch / youtu.be / shorts / live / embed), Vimeo and Facebook video
 * links become a privacy-friendly <iframe>; a direct .mp4 / .webm address becomes a <video>. Anything else -> null.
 * Returns ['kind' => 'iframe'|'video', 'src' => ..., 'ratio' => '16/9'] ; $autoplay adds the muted-autoplay flags where the host supports it.
 */
function promoEmbed(?string $url, bool $autoplay = false): ?array {
    $url = trim((string)$url);
    if ($url === '' || !isHttpUrl($url)) return null;
    $p = parse_url($url); $host = strtolower($p['host'] ?? ''); $path = $p['path'] ?? ''; parse_str($p['query'] ?? '', $q);
    $isHost = fn(string $h) => $host === $h || str_ends_with($host, '.' . $h);

    if ($isHost('youtube.com') || $host === 'youtu.be') {
        $id = '';
        if ($host === 'youtu.be') $id = trim($path, '/');
        elseif (!empty($q['v']) && is_string($q['v'])) $id = $q['v'];
        elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) $id = $m[2];
        // a bare playlist link (youtube.com/playlist?list=PL...) plays as a playlist player
        if ($id === '' && !empty($q['list']) && is_string($q['list']) && preg_match('/^[A-Za-z0-9_-]{10,60}$/', $q['list'])) {
            return ['kind' => 'iframe', 'src' => 'https://www.youtube-nocookie.com/embed/videoseries?list=' . $q['list'] . '&rel=0&playsinline=1' . ($autoplay ? '&autoplay=1&mute=1' : ''), 'ratio' => '16/9'];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) return null;
        // start time: ?t=90, ?t=90s, ?t=1m30s, ?t=1h2m3s or ?start=90  ->  start=<seconds>
        $start = 0;
        $tv = (string)($q['t'] ?? $q['start'] ?? '');
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $tv, $tm) && $tv !== '') $start = ((int)($tm[1] ?? 0)) * 3600 + ((int)($tm[2] ?? 0)) * 60 + (int)($tm[3] ?? 0);
        $src = 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&playsinline=1' . ($start > 0 ? '&start=' . $start : '') . ($autoplay ? '&autoplay=1&mute=1&loop=1&playlist=' . $id : '');
        return ['kind' => 'iframe', 'src' => $src, 'ratio' => '16/9'];
    }
    if ($isHost('vimeo.com')) {
        if (!preg_match('#/(\d{5,12})(?:/|$)#', $path, $m)) return null;
        return ['kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/' . $m[1] . ($autoplay ? '?autoplay=1&muted=1&loop=1' : ''), 'ratio' => '16/9'];
    }
    if ($isHost('facebook.com') || $host === 'fb.watch') {
        $href = urlencode($url);
        return ['kind' => 'iframe', 'src' => 'https://www.facebook.com/plugins/video.php?href=' . $href . '&show_text=false', 'ratio' => '16/9'];
    }
    if (preg_match('#\.(mp4|webm)$#i', $path)) return ['kind' => 'video', 'src' => $url, 'ratio' => '16/9'];
    return null;
}

/** Defaults + cleaned values for the promo block, whatever is in the row (a missing / odd value never breaks the page). */
function promoOptions($row): array {   // $row: a table row, or false / null when there is none yet
    $r = is_array($row) ? $row : [];
    $pick = fn($v, array $ok, string $def) => in_array($v, $ok, true) ? $v : $def;
    $color = fn($v) => (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) ? strtoupper($v) : '';
    return [
        'is_active'    => isset($r['is_active']) ? (int)!empty($r['is_active']) : 1,
        'video_source' => $pick($r['video_source'] ?? 'url', ['url', 'upload'], 'url'),
        'video_url'    => (string)($r['video_url'] ?? ''),
        'video_file'   => (string)($r['video_file'] ?? ''),
        'poster'       => (string)($r['poster'] ?? ''),
        'logo_mode'    => $pick($r['logo_mode'] ?? 'none', ['none', 'site', 'upload'], 'none'),   // none | the site's own logo | an uploaded picture
        'logo'         => (string)($r['logo'] ?? ''),
        'logo_size'    => max(60, min(320, (int)($r['logo_size'] ?? 150))),
        'autoplay'     => !empty($r['autoplay']) ? 1 : 0,
        'video_side'   => $pick($r['video_side'] ?? 'left', ['left', 'right'], 'left'),
        'heading'      => (string)($r['heading'] ?? ''),
        'body'         => (string)($r['body'] ?? ''),
        'heading_size' => max(18, min(64, (int)($r['heading_size'] ?? 32))),
        'body_size'    => max(13, min(34, (int)($r['body_size'] ?? 18))),
        'text_align'   => $pick($r['text_align'] ?? 'left', ['left', 'center', 'right'], 'left'),
        'v_align'      => $pick($r['v_align'] ?? 'center', ['start', 'center', 'end'], 'center'),
        'text_color'   => $color($r['text_color'] ?? ''),
        'bg_color'     => $color($r['bg_color'] ?? ''),
        'btn_label'    => (string)($r['btn_label'] ?? ''),
        'btn_url'      => (string)($r['btn_url'] ?? ''),
    ];
}

// ============================================================
// LOGIN GUESS LIMIT - where the failed-attempt counter is kept (admin/login.php)
// ============================================================
/** cache/login_attempts.json when the web server can write there, else the system temp folder; null when neither is writable. */
function loginAttemptsFile(): ?string {
    $dir = BASE_PATH . '/cache';
    if (is_dir($dir) && is_writable($dir)) return $dir . '/login_attempts.json';
    $tmp = rtrim(sys_get_temp_dir(), '/\\');
    return ($tmp !== '' && is_dir($tmp) && is_writable($tmp)) ? $tmp . DIRECTORY_SEPARATOR . 'bait_login_attempts_' . md5(BASE_PATH) . '.json' : null;
}

// ============================================================
// IT NEWS of the day (หน้าข่าวสาร): headlines from Thai IT-news RSS feeds (migration step 17, table it_news)
// Only the headline, a short excerpt and the link are kept: the article itself stays on the source site.
// ============================================================
/** True once migration step 17 added the it_news table. */
function hasItNews(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW TABLES LIKE 'it_news'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** The feeds read, [source name => RSS/Atom URL]. */
function itNewsFeeds(): array {
    return [
        'Blognone'  => 'https://www.blognone.com/atom.xml',
        'DroidSans' => 'https://droidsans.com/feed/',
        'TechHub'   => 'https://www.techhub.in.th/feed/',
    ];
}

/** A short label for the news card, from words in the headline / excerpt (first match wins). */
function itNewsTag(string $text): string {
    $rules = [
        'AI'          => '/\bAI\b|ปัญญาประดิษฐ์|ChatGPT|Gemini|Claude|Copilot|LLM|OpenAI|Anthropic|DeepSeek|โมเดลภาษา/iu',
        'ภัยไซเบอร์'  => '/แฮก|แฮ็ก|ช่องโหว่|มัลแวร์|ransomware|ไซเบอร์|ข้อมูลรั่ว|รั่วไหล|ฟิชชิ่ง|phishing|มิจฉาชีพ|สแกมเมอร์|scam/iu',
        'ชิป'         => '/ชิป|\bCPU\b|\bGPU\b|Nvidia|\bAMD\b|Intel|Qualcomm|TSMC|เซมิคอนดักเตอร์|Snapdragon|RISC-V/iu',
        'การศึกษา'    => '/มหาวิทยาลัย|การศึกษา|นักศึกษา|นักเรียน|หลักสูตร|เรียนฟรี|ทุนการศึกษา|อาจารย์/u',
        'ฟินเทค'      => '/ธนาคาร|การเงิน|ชำระเงิน|พร้อมเพย์|คริปโต|Bitcoin|บิตคอยน์|e-Wallet|วอลเล็ต|ฟินเทค|fintech/iu',
        'ธุรกิจ'      => '/ระดมทุน|ผลประกอบการ|ซื้อกิจการ|\bIPO\b|มูลค่าบริษัท|หุ้น|ลงทุน|ปลดพนักงาน|เลิกจ้าง/u',
        'มือถือ'      => '/iPhone|iPad|Android|มือถือ|สมาร์ทโฟน|สมาร์ตโฟน|Galaxy|Pixel|Xiaomi|OPPO|vivo|realme|\biOS\b|แท็บเล็ต|สมาร์ตวอตช์|สมาร์ทวอทช์/iu',
        'โซเชียล'     => '/Facebook|Meta|TikTok|YouTube|Instagram|\bLINE\b|Threads|โซเชียล|Spotify|Netflix|แอป/iu',
        'เกม'         => '/เกม|PlayStation|Xbox|Nintendo|Steam|อีสปอร์ต/iu',
    ];
    foreach ($rules as $tag => $re) if (preg_match($re, $text)) return $tag;
    return 'ไอที';
}

/** The label from the headline first; only when the headline says nothing, from the excerpt / feed categories. */
function itNewsTagFor(string $title, string $more): string {
    $t = itNewsTag($title);
    return $t !== 'ไอที' ? $t : itNewsTag($more);
}

/** A one-line excerpt: plain text, the repeated headline removed, cut at a space so no Thai word is broken. */
function itNewsExcerpt(string $html, string $title, int $max = 110): string {
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br', '<p', '</p>'], [' <br', ' <p', '</p> '], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($title !== '' && mb_stripos($t, $title) === 0) $t = ltrim(mb_substr($t, mb_strlen($title)));   // Blognone repeats the title first
    $t = preg_replace('/^(Body|Read more\.?|อ่านต่อ)\s*/iu', '', $t);
    $t = preg_replace('/\s*(The post .+ appeared first on .+|อ่านต่อ.*)$/iu', '', $t);
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp !== false && $sp > $max * 0.5 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
}

/**
 * Reads the feeds and stores new headlines (already-seen ones are skipped). At most once every $everySeconds unless
 * $force; a lock file makes sure two visitors never fetch at the same time. Returns [source => "n new" / error text]
 * and remembers it (with the time) in cache/it_news_fetch.json for the admin page.
 */
function itNewsFetch(bool $force = false): array {
    if (!hasItNews() || !function_exists('curl_init')) return [];
    $stateFile = BASE_PATH . '/cache/it_news_fetch.json';
    $state = is_file($stateFile) ? (json_decode((string)@file_get_contents($stateFile), true) ?: []) : [];
    if (!$force && !itNewsDue()) return $state['result'] ?? [];
    $lock = @fopen(BASE_PATH . '/cache/it_news.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $state['result'] ?? [];
    // claim the slot first, so a slow or failing feed is not retried by every visitor
    @file_put_contents($stateFile, json_encode(['at' => time(), 'result' => $state['result'] ?? []], JSON_UNESCAPED_UNICODE));
    $db = Database::getInstance();
    $result = []; $added = 0;
    foreach (itNewsFeeds() as $source => $url) {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BAIT-ITNews/1.0)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code !== 200) { $result[$source] = 'ดึงไม่ได้ (' . ($err !== '' ? $err : "HTTP $code") . ')'; continue; }
            $prev = libxml_use_internal_errors(true);
            $x = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            libxml_use_internal_errors($prev);
            if (!$x) { $result[$source] = 'อ่านข้อมูลไม่ได้'; continue; }
            $items = isset($x->channel->item) ? $x->channel->item : ($x->entry ?? []);
            $new = 0;
            foreach ($items as $it) {
                $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$it->title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $link  = trim((string)$it->link);
                if ($link === '' && isset($it->link['href'])) $link = (string)$it->link['href'];
                if ($title === '' || !preg_match('#^https?://#i', $link)) continue;
                $guid = trim((string)($it->guid ?? '')) ?: (string)($it->id ?? '') ?: $link;
                $date = strtotime((string)($it->pubDate ?? '') ?: (string)($it->published ?? '') ?: (string)($it->updated ?? '')) ?: time();
                if ($date > time() + 3600) $date = time();
                if ($date < strtotime(itNewsSince())) continue;   // already outside the 7-day window
                $desc = (string)($it->description ?? '') ?: (string)($it->summary ?? '') ?: (string)($it->content ?? '');
                $cats = []; foreach ($it->category ?? [] as $c) $cats[] = (string)$c;
                $sum  = itNewsExcerpt($desc, $title);
                try {
                    $st = $db->query('INSERT IGNORE INTO it_news (guid_hash, source, title, summary, url, tag, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [sha1($source . '|' . $guid), $source, mb_substr($title, 0, 300), mb_substr($sum, 0, 400), mb_substr($link, 0, 500),
                         itNewsTagFor($title, $sum . ' ' . implode(' ', $cats)), date('Y-m-d H:i:s', $date)]);
                    $new += $st->rowCount();   // 0 when it was already there
                } catch (Throwable $e) { error_log('BAIT: it_news insert: ' . $e->getMessage()); }
            }
            $result[$source] = "ใหม่ $new ข่าว"; $added += $new;
        } catch (Throwable $e) {
            $result[$source] = 'ผิดพลาด';
            error_log("BAIT: it_news fetch $source: " . $e->getMessage());
        }
    }
    // keep the last itNewsDays() days of headlines (the archive page shows exactly those); older ones go
    try { $db->query('DELETE FROM it_news WHERE published_at < ?', [itNewsSince()]); } catch (Throwable $e) {}
    @file_put_contents($stateFile, json_encode(['at' => time(), 'result' => $result, 'added' => $added], JSON_UNESCAPED_UNICODE));
    flock($lock, LOCK_UN); fclose($lock);
    return $result;
}
/** How many headlines the last finished read added (it-news-fetch.php tells the page whether to refresh its IT news). */
function itNewsLastAdded(): int {
    $f = BASE_PATH . '/cache/it_news_fetch.json';
    $s = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    return (int)($s['added'] ?? 0);
}

/** How many days of headlines are kept and shown on ข่าวไอทีย้อนหลัง. */
function itNewsDays(): int { return 7; }
/** Start of the window: midnight 6 days ago, so the window is today + the 6 days before. When a new day starts, the
 *  oldest day drops out (on 26 Oct the window is 20–26 Oct, the 19th is gone). */
function itNewsSince(): string { return date('Y-m-d 00:00:00', strtotime('-' . (itNewsDays() - 1) . ' days')); }

/** True when it is time to read the feeds again: every hour, and at the first visit of a new day. */
function itNewsDue(): bool {
    if (!hasItNews()) return false;
    $stateFile = BASE_PATH . '/cache/it_news_fetch.json';
    $state = is_file($stateFile) ? (json_decode((string)@file_get_contents($stateFile), true) ?: []) : [];
    if (empty($state['at'])) return true;
    return time() - (int)$state['at'] >= 3600 || date('Y-m-d', (int)$state['at']) !== date('Y-m-d');
}

/**
 * When due, a tiny script on the page asks it-news-fetch.php to read the feeds once the page has loaded, so the
 * visitor never waits for the feed sites (on Apache mod_php a fetch inside the page request would hold it open).
 * If the read added headlines, the script loads this page again in the background and swaps in the fresh IT news parts
 * (every element with data-itnews-live and an id), so the visitor sees the new news without reloading.
 */
function itNewsFetchLater(): string {
    // without a writable cache/ the read can never run (no lock, no "last read" time): do not ask on every page view
    if (!itNewsDue() || !is_writable(BASE_PATH . '/cache')) return '';
    return '<script>window.addEventListener("load",function(){fetch("' . BASE_URL . '/it-news-fetch.php",{method:"POST",credentials:"same-origin"})'
        . '.then(function(r){return r.ok?r.json():null}).then(function(j){if(!j||!j.added)return;'
        . 'var u=location.href.split("#")[0];u+=(u.indexOf("?")<0?"?":"&")+"itx_refresh=1";'
        . 'return fetch(u,{cache:"no-store",credentials:"same-origin"}).then(function(r){return r.text()}).then(function(h){'
        . 'var d=new DOMParser().parseFromString(h,"text/html");document.querySelectorAll("[data-itnews-live][id]").forEach(function(el){var n=d.getElementById(el.id);if(n)el.replaceWith(document.importNode(n,true));});'
        . 'if(window.itxInit)window.itxInit();});}).catch(function(){});});</script>';
}

/** The headlines of the "IT NEWS วันนี้" band: the newest ones, at most $perSource per site so one site does not fill it. */
function itNewsPick(int $n = 6, int $perSource = 3): array {
    $picked = []; $count = [];
    foreach (itNewsLatest(40) as $it) {
        if (($count[$it['source']] ?? 0) >= $perSource) continue;
        $picked[] = $it; $count[$it['source']] = ($count[$it['source']] ?? 0) + 1;
        if (count($picked) >= $n) break;
    }
    return $picked;
}

/** The newest visible headlines. */
function itNewsLatest(int $n = 6): array {
    if (!hasItNews()) return [];
    try {
        return Database::getInstance()->fetchAll('SELECT * FROM it_news WHERE is_hidden = 0 ORDER BY published_at DESC, item_id DESC LIMIT ' . max(1, min(50, $n)));
    } catch (Throwable $e) { return []; }
}

// ============================================================
// STAFF: status (คงอยู่ / ลาออก), สายงาน from หน่วยงาน, live staff counts, the faculty committee
// ============================================================
/** True once migration step 14 added staff.work_status. */
function hasStaffStatus(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW COLUMNS FROM staff LIKE 'work_status'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/**
 * SQL condition for "a current member of staff": not deleted (is_active) and, after migration step 14, not resigned.
 * Used by every list, chart and count shown to people. Checks that only protect a value still in use (a position or
 * หน่วยงาน that must not be deleted) keep using is_active alone, because a resigned person's record still uses it.
 */
function staffCurrentSql(string $alias = 's'): string {
    $a = $alias !== '' ? $alias . '.' : '';
    return "{$a}is_active = 1" . (hasStaffStatus() ? " AND {$a}work_status = 'active'" : '');
}

/** The chart colours of the site (soft pastels), the same list (and order) as PALETTE in assets/js/stats-explorer.js: every colour a clearly
 *  different hue, so the rows / bars of one chart never look alike. */
function chartColors(): array {
    return ['#6199D5', '#ED9257', '#6BBE7E', '#E576A0', '#EBC244', '#9277E2', '#5EBCBA', '#AFCC5F', '#E05E5E'];
}

/**
 * Who is on the ผังผู้บริหาร, by the same rule staff.php draws it: a top is a current staff member with no current
 * หัวหน้างาน who has someone reporting to them; the chart is the tops plus everyone under them.
 * $without leaves one person out (the one being edited): their own links may be about to change, so a boss who is only
 * on the chart because of that person — or anyone under that person, which would make a loop — does not count.
 * Returns ['members' => [staff_id => true], 'tops' => [staff_id, ...]].
 */
function execChart(?int $without = null): array {
    $rows = Database::getInstance()->fetchAll('SELECT s.staff_id, s.supervisor_id FROM staff s WHERE ' . staffCurrentSql('s'));
    $boss = []; $kids = [];
    foreach ($rows as $r) {
        $id = (int)$r['staff_id'];
        if ($id === $without) continue;
        $boss[$id] = (int)$r['supervisor_id'];
    }
    foreach ($boss as $id => $b) if ($b && isset($boss[$b])) $kids[$b][] = $id;
    $tops = [];
    foreach ($boss as $id => $b) {
        // a boss who left / is the excluded person counts as no boss, except for the excluded person's own team:
        // without that person they are cut off, not tops of their own
        if ($b === $without && $without !== null) continue;
        if ((!$b || !isset($boss[$b])) && !empty($kids[$id])) $tops[] = $id;
    }
    $members = []; $todo = $tops;
    while ($todo) {
        $id = array_pop($todo);
        if (isset($members[$id])) continue;
        $members[$id] = true;
        foreach ($kids[$id] ?? [] as $k) $todo[] = $k;
    }
    return ['members' => $members, 'tops' => $tops];
}

/** Everyone below one person along the หัวหน้างาน links (any depth). */
function staffUnder(int $id): array {
    $kids = [];
    foreach (Database::getInstance()->fetchAll('SELECT staff_id, supervisor_id FROM staff WHERE supervisor_id IS NOT NULL') as $r) $kids[(int)$r['supervisor_id']][] = (int)$r['staff_id'];
    $out = []; $todo = $kids[$id] ?? [];
    while ($todo) { $k = array_pop($todo); if (isset($out[$k]) || $k === $id) continue; $out[$k] = true; foreach ($kids[$k] ?? [] as $x) $todo[] = $x; }
    return array_keys($out);
}

/** The หน่วยงาน value that holds the support staff (its name contains "สนับสนุน"); everything else counts as academic. */
function isSupportDepartmentName(?string $name): bool {
    return $name !== null && mb_strpos($name, 'สนับสนุน') !== false;
}

/** SQL condition on departments d (LEFT JOINed): 'support' = สายสนับสนุน, 'academic' = any other หน่วยงาน or none chosen yet. */
function staffLineSql(string $line, string $deptAlias = 'd'): string {
    return $line === 'support'
        ? "{$deptAlias}.department_name LIKE '%สนับสนุน%'"
        : "({$deptAlias}.department_name IS NULL OR {$deptAlias}.department_name NOT LIKE '%สนับสนุน%')";
}

/**
 * Staff counts for the statistics pages, counted live from the staff records (current staff only: not resigned, not
 * deleted). Committee members from outside the faculty are not staff records, so they are never counted.
 * Same row shape the hand-entered staff_counts table had: campus_id, major_id, department_id, staff_type_id, total.
 */
function staffCountRows(): array {
    try {
        return Database::getInstance()->fetchAll(
            'SELECT s.campus_id, s.major_id, s.department_id, s.staff_type_id, COUNT(*) AS total
             FROM staff s WHERE ' . staffCurrentSql('s') . '
             GROUP BY s.campus_id, s.major_id, s.department_id, s.staff_type_id'
        );
    } catch (Throwable $e) {
        error_log('BAIT: staffCountRows: ' . $e->getMessage());
        return [];
    }
}

/** When a current staff record last changed (the statistics pages show it as "อัปเดตล่าสุด"). */
function staffCountsUpdatedAt(): ?string {
    try { return Database::getInstance()->fetchOne('SELECT MAX(updated_at) AS u FROM staff s WHERE ' . staffCurrentSql('s'))['u'] ?? null; }
    catch (Throwable $e) { return null; }
}

/** True once migration step 14 created committee_members. */
function hasCommittee(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW TABLES LIKE 'committee_members'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Whether committee_members.name_en exists (migration step 16): the English name of an outside member. */
function hasCommitteeNameEn(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = hasCommittee() && (bool)Database::getInstance()->fetchOne("SHOW COLUMNS FROM committee_members LIKE 'name_en'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Roles in the faculty committee, in the order the chart shows them (the chair on top, the secretary last). */
function committeeRoles(): array {
    return ['chair' => 'ประธานกรรมการ', 'expert_ext' => 'ผู้ทรงคุณวุฒิภายนอก', 'expert_int' => 'ผู้ทรงคุณวุฒิภายใน', 'member' => 'กรรมการ', 'secretary' => 'เลขานุการ'];
}

/**
 * Committee members ready to show: a staff member brings their own name / photo / position (and is left out once they
 * have resigned); an outside member uses the name / title / photo typed in. Ordered by role, then by the admin's order.
 * Each row has: member_id, staff_id, role, role_label, prefix, full_name, full_name_en, name_en_verified, image,
 * position_name (the role), responsibility (their own position / affiliation), e_profile_url, is_external.
 */
function committeeMembers(bool $forPublic = true): array {
    if (!hasCommittee()) return [];
    try {
        $rows = Database::getInstance()->fetchAll(
            'SELECT cm.*, s.prefix, s.full_name, s.full_name_en, s.name_en_verified, s.image AS staff_image, s.e_profile_url,
                    s.is_active AS s_active' . (hasStaffStatus() ? ', s.work_status' : ", 'active' AS work_status") . ', p.position_name AS staff_position
             FROM committee_members cm
             LEFT JOIN staff s ON s.staff_id = cm.staff_id
             LEFT JOIN positions p ON p.position_id = s.position_id'
        );
    } catch (Throwable $e) {
        error_log('BAIT: committeeMembers: ' . $e->getMessage());
        return [];
    }
    $roles = committeeRoles(); $order = array_flip(array_keys($roles)); $out = [];
    foreach ($rows as $r) {
        $isStaff = $r['staff_id'] !== null;
        if ($isStaff && $forPublic && (!(int)$r['s_active'] || $r['work_status'] !== 'active')) continue;
        $role = isset($roles[$r['role']]) ? $r['role'] : 'member';
        $out[] = [
            'member_id' => (int)$r['member_id'], 'staff_id' => $isStaff ? (int)$r['staff_id'] : 0, 'role' => $role, 'role_label' => $roles[$role],
            'sort_order' => (int)$r['sort_order'], 'is_external' => !$isStaff,
            'prefix' => $isStaff ? (string)$r['prefix'] : '', 'full_name' => $isStaff ? (string)$r['full_name'] : (string)$r['name'],
            // an outsider's English name is typed by the admin in the committee form, so it counts as checked
            'full_name_en' => $isStaff ? (string)$r['full_name_en'] : trim((string)($r['name_en'] ?? '')),
            'name_en_verified' => $isStaff ? (int)$r['name_en_verified'] : (int)(trim((string)($r['name_en'] ?? '')) !== ''),
            'image' => $isStaff ? $r['staff_image'] : $r['image'], 'e_profile_url' => $isStaff ? (string)$r['e_profile_url'] : '',
            'position_name' => $roles[$role],
            'responsibility' => $isStaff ? (string)$r['staff_position'] : (string)$r['title'],
            'resigned' => $isStaff && (!(int)$r['s_active'] || $r['work_status'] !== 'active'),
        ];
    }
    usort($out, fn($a, $b) => [$order[$a['role']], $a['sort_order'], $a['member_id']] <=> [$order[$b['role']], $b['sort_order'], $b['member_id']]);
    return $out;
}

/** True once migration step 15 added the support-staff chart fields (staff.unit_id / job_title / is_unit_head, staff_units). */
function hasStaffUnits(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)Database::getInstance()->fetchOne("SHOW COLUMNS FROM staff LIKE 'unit_id'"); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** The งาน of the support-staff chart, in display order: [unit_id => unit_name]. */
function staffUnits(): array {
    if (!hasStaffUnits()) return [];
    try {
        return array_column(Database::getInstance()->fetchAll('SELECT unit_id, unit_name FROM staff_units ORDER BY sort_order, unit_name'), 'unit_name', 'unit_id');
    } catch (Throwable $e) {
        error_log('BAIT: staffUnits: ' . $e->getMessage());
        return [];
    }
}
