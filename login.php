<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $_SESSION['flash_error'] = '不正なリクエストです（CSRF）。';
    header('Location: index.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

$stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password_hash'])) {
    $_SESSION['flash_error'] = 'メールアドレスまたはパスワードが違います。';
    header('Location: index.php');
    exit;
}

// セッション固定化対策
session_regenerate_id(true);

$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_email'] = (string)$user['email'];

header('Location: timetable.php');
exit;
