<?php
/**
 * Cache-busting version for the ES modules assets/js/main.js dynamically
 * imports (see layout/header.php). Static import specifiers can't carry
 * a query string, so main.js appends this to each dynamic import() call
 * instead — the newest mtime across every module means editing any one
 * of them busts the browser's cache for that module without needing a
 * manually-bumped version number.
 */
function jsAssetVersion(): int {
    static $version = null;
    if ($version !== null) return $version;

    $version = 0;
    foreach (glob(BASE_PATH . '/assets/js/*.js') ?: [] as $jsFile) {
        $version = max($version, (int)@filemtime($jsFile));
    }
    if (!$version) $version = time();
    return $version;
}
