<?php
// Solo para probar en local:  php -S 127.0.0.1:8099 router.php
// Imita el .htaccess. NO hace falta subirlo al hosting (si se sube, no hace nada).
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|app)/#', $p)) {
    http_response_code(403);
    return true;
}
if ($p !== '/' && is_file(__DIR__ . $p)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
