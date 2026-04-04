<?php
// Googleの同意画面へ飛ばす
require_once __DIR__ . '/_google.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

// 新構成では task 詳細は tasks/task.php
$return  = (string)($_GET['return'] ?? '../tasks/task.php');
$task_id = (int)($_GET['task_id'] ?? 0);

// 外部URLは拒否
if (preg_match('#^https?://#', $return)) {
    $return = '../tasks/task.php';
}

// 相対パス補正
if (!str_starts_with($return, '/') && !str_starts_with($return, '../')) {
    $return = '../' . ltrim($return, './');
}

// stateに必要情報を詰める
$state = json_encode([
    'csrf'   => ($_SESSION['csrf'] ?? ''),
    'return' => $return,
    'task_id'=> $task_id,
], JSON_UNESCAPED_UNICODE);

$client = google_client($pdo, $user_id, false);
$client->setState(base64_encode($state));

$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit;