<?php
require_once __DIR__ . '/db.php';

session_start();

function require_admin(): void {
    if (empty($_SESSION['admin'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function login(string $username, string $password): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['admin'] = trim($username);
        return true;
    }
    return false;
}
