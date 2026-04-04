<?php
require_once __DIR__ . '/../config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('CSRF invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// 添付ファイル（物理ファイル）を先に集める
$stmtF = $pdo->prepare('SELECT stored_name FROM task_files WHERE user_id = :uid');
$stmtF->execute([':uid' => $user_id]);
$stored = $stmtF->fetchAll(PDO::FETCH_COLUMN);

// 新構成では uploads はルート配下
$uploadDir = __DIR__ . '/../uploads/task_files';

$pdo->beginTransaction();
try {
    // notifications が users/tasks を参照しているなら先に削除
    $pdo->prepare('DELETE FROM notifications WHERE user_id = :uid')->execute([':uid' => $user_id]);
    $pdo->prepare('DELETE FROM task_files WHERE user_id = :uid')->execute([':uid' => $user_id]);
    // DBの削除（子 → 親）
    $pdo->prepare('DELETE FROM tasks WHERE user_id = :uid')->execute([':uid' => $user_id]);
    $pdo->prepare('DELETE FROM timetable_course_slots WHERE user_id = :uid')->execute([':uid' => $user_id]);
    $pdo->prepare('DELETE FROM timetable_courses WHERE user_id = :uid')->execute([':uid' => $user_id]);

    // Google連携情報も消す
    $pdo->prepare('UPDATE users SET google_token_json = NULL, google_email = NULL, google_sub = NULL WHERE id = :uid')
        ->execute([':uid' => $user_id]);

    // 最後にユーザー削除
    $pdo->prepare('DELETE FROM users WHERE id = :uid')->execute([':uid' => $user_id]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    exit('Delete failed: ' . $e->getMessage());
}

// 物理ファイル削除（トランザクション外）
foreach ($stored as $name) {
    $path = $uploadDir . '/' . $name;
    if (is_file($path)) {
        @unlink($path);
    }
}

// セッション破棄してトップへ
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: ../index.html');
exit;
