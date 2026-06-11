<?php
// Router for PHP built-in server (`php -S localhost:8080 router.php`).
// Blocks paths that should never be served (the .htaccess covers Apache).
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/(db|scripts)(/|$)#i', $uri)
    || preg_match('#\.(sqlite|sqlite3|db|env|ini|log)$#i', $uri)
    || preg_match('#/\.(htaccess|git)#i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("403 Forbidden\n");
}

return false; // let the built-in server serve the requested file as-is
