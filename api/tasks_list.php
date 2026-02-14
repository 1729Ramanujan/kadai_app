<?php
require_once __DIR__ . '/bootstrap.php';

$user_id = (int)$_SESSION['user_id'];
$cell = $_GET['cell'] ?? '';

if (!preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', $cell)) {
    json_ng('cell is invalid', 400);
}

$stmt = $pdo->prepare('
  SELECT id, title, due_at, detail, status
  FROM tasks
  WHERE user_id = :uid AND cell_key = :cell
  ORDER BY (due_at IS NULL) ASC, due_at ASC, id ASC
');
$stmt->execute([
    ':uid' => $user_id,
    ':cell' => $cell,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// due_at を "YYYY-MM-DDTHH:MM" に寄せたい場合は整形して返す
foreach ($rows as &$r) {
    $r['due_at'] = $r['due_at'] ? date('Y-m-d\TH:i', strtotime($r['due_at'])) : null;
}

json_ok(['tasks' => $rows]);
