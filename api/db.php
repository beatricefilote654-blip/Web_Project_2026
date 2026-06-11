<?php
// Returns a singleton PDO connection.
// On first call it creates the db/ directory, all tables, and the county list
// so a fresh checkout works with just: php -S localhost:8080
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $path = __DIR__ . '/../db/und.sqlite';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,          PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    _bootstrap($pdo);
    return $pdo;
}

// Create tables and seed reference data if this is a fresh database.
function _bootstrap(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS counties (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS unemployment_records (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            county_id       INTEGER NOT NULL REFERENCES counties(id),
            year            INTEGER NOT NULL,
            month           INTEGER NOT NULL,
            age_group       TEXT NOT NULL,
            education_level TEXT NOT NULL,
            environment     TEXT NOT NULL,
            value           REAL NOT NULL,
            count           INTEGER DEFAULT 0,
            UNIQUE(county_id, year, month, age_group, education_level, environment)
        );

        CREATE TABLE IF NOT EXISTS cache_entries (
            cache_key  TEXT PRIMARY KEY,
            payload    TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS admin_users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        );
    ");

    // Seed the 42 Romanian counties once (INSERT OR IGNORE = safe to re-run)
    $counties = [
        ['AB','Alba'],         ['AR','Arad'],           ['AG','Argeș'],
        ['BC','Bacău'],        ['BH','Bihor'],          ['BN','Bistrița-Năsăud'],
        ['BT','Botoșani'],     ['BV','Brașov'],         ['BR','Brăila'],
        ['BZ','Buzău'],        ['CS','Caraș-Severin'],  ['CL','Călărași'],
        ['CJ','Cluj'],         ['CT','Constanța'],      ['CV','Covasna'],
        ['DB','Dâmbovița'],    ['DJ','Dolj'],           ['GL','Galați'],
        ['GR','Giurgiu'],      ['GJ','Gorj'],           ['HR','Harghita'],
        ['HD','Hunedoara'],    ['IL','Ialomița'],       ['IS','Iași'],
        ['IF','Ilfov'],        ['MM','Maramureș'],      ['MH','Mehedinți'],
        ['MS','Mureș'],        ['NT','Neamț'],          ['OT','Olt'],
        ['PH','Prahova'],      ['SM','Satu Mare'],      ['SJ','Sălaj'],
        ['SB','Sibiu'],        ['SV','Suceava'],        ['TR','Teleorman'],
        ['TM','Timiș'],        ['TL','Tulcea'],         ['VS','Vaslui'],
        ['VL','Vâlcea'],       ['VN','Vrancea'],        ['B', 'București'],
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO counties (code, name) VALUES (?, ?)");
    foreach ($counties as [$code, $name]) $stmt->execute([$code, $name]);

    // Default admin account — random password generated once on first bootstrap.
    // The plaintext is written to db/ADMIN_PASSWORD.txt (server-only, blocked by .htaccess).
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'")->fetchColumn();
    if ($exists === 0) {
        $plain = bin2hex(random_bytes(8));
        $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)")
            ->execute(['admin', password_hash($plain, PASSWORD_BCRYPT)]);
        $pwFile = __DIR__ . '/../db/ADMIN_PASSWORD.txt';
        @file_put_contents($pwFile,
            "Initial admin credentials (delete this file after first login):\n" .
            "  username: admin\n  password: $plain\n");
        @chmod($pwFile, 0600);
    }
}
