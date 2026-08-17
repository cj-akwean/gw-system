<?php

$publicPath = __DIR__.'/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Emulate Apache's mod_rewrite from the built-in PHP web server: existing
// files (css/js/images) are served as-is; everything else goes through the
// Laravel front controller. Without this, the container's `php -S` router
// would hand every request to index.php, and static assets would return the
// landing-page HTML instead of the real files (broken admin/portal styling).
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
