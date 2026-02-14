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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash_error'] = 'メールアドレスが正しくありません。';
    header('Location: index.php');
    exit;
}
if (mb_strlen($pass) < 6) {
    $_SESSION['flash_error'] = 'パスワードは6文字以上にしてください。';
    header('Location: index.php');
    exit;
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)');
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    $user_id = (int)$pdo->lastInsertId();

    // 自動ログイン
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_email'] = $email;

    header('Location: timetable.php');
    exit;
} catch (PDOException $e) {
    // email重複
    if ((int)$e->errorInfo[1] === 1062) {
        $_SESSION['flash_error'] = 'そのメールアドレスは既に登録されています。';
    } else {
        $_SESSION['flash_error'] = '登録に失敗しました。';
    }
    header('Location: index.php');
    exit;
}
