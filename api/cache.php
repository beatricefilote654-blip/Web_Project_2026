<?php
require_once __DIR__ . '/db.php';

define('CACHE_TTL', 3600); // 1 hour

function cache_get(string $key): ?string {
    $pdo = get_pdo();
    $row = $pdo->prepare("SELECT payload, created_at FROM cache_entries WHERE cache_key = ?");
    $row->execute([$key]);
    $r = $row->fetch();
    if (!$r) return null;
    if (time() - (int)$r['created_at'] > CACHE_TTL) {
        $pdo->prepare("DELETE FROM cache_entries WHERE cache_key = ?")->execute([$key]);
        return null;
    }
    return $r['payload'];
}

function cache_set(string $key, string $payload): void {
    get_pdo()->prepare(
        "INSERT OR REPLACE INTO cache_entries (cache_key, payload, created_at) VALUES (?, ?, ?)"
    )->execute([$key, $payload, time()]);
}

function cache_flush(): void {
    get_pdo()->exec("DELETE FROM cache_entries");
}
