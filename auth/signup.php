<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: signup_view.php');
    exit;
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $_SESSION['flash_error'] = '不正なリクエストです（CSRF）。';
    header('Location: signup_view.php');
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash_error'] = 'メールアドレスが正しくありません。';
    header('Location: signup_view.php');
    exit;
}

if (mb_strlen($pass) < 6) {
    $_SESSION['flash_error'] = 'パスワードは6文字以上にしてください。';
    header('Location: signup_view.php');
    exit;
}

/* 先に既存ユーザー確認 */
$st = $pdo->prepare('
    SELECT id, email, password_hash, google_sub
    FROM users
    WHERE email = :email
    LIMIT 1
');
$st->execute([':email' => $email]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    if (empty($existing['password_hash']) && !empty($existing['google_sub'])) {
        $_SESSION['flash_error'] = 'そのメールアドレスはGoogleアカウントで既に登録されています。Googleでログインしてください。';
    } else {
        $_SESSION['flash_error'] = 'そのメールアドレスは既に登録されています。';
    }

    header('Location: signup_view.php');
    exit;
}

/* 新規登録 */
$hash = password_hash($pass, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('
        INSERT INTO users (email, password_hash)
        VALUES (:email, :hash)
    ');
    $stmt->execute([
        ':email' => $email,
        ':hash'  => $hash,
    ]);

    $user_id = (int)$pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id']    = $user_id;
    $_SESSION['user_email'] = $email;

    header('Location: ../timetable/timetable.php');
    exit;
} catch (PDOException $e) {
    $_SESSION['flash_error'] = '登録に失敗しました。';
    header('Location: signup_view.php');
    exit;
}