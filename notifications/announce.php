<?php
require_once __DIR__ . '/../config.php';
require_login();

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmtMe = $pdo->prepare(
    'SELECT
        us.id,
        us.email,
        us.university_id,
        uni.name AS university_name,
        uni.lms_url
     FROM users us
     LEFT JOIN universities uni ON uni.id = us.university_id
     WHERE us.id = :id
     LIMIT 1'
);
$stmtMe->execute([':id' => $user_id]);
$me = $stmtMe->fetch();

if (!$me) {
    http_response_code(404);
    exit('user not found');
}

function type_label(string $type): string
{
    return match ($type) {
        'deadline_1day'  => '明日締切',
        'deadline_today' => '今日締切',
        'overdue'        => '期限超過',
        default          => $type,
    };
}

function format_dt(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    if ($ts === false) return (string)$dt;
    return date('Y-m-d H:i', $ts);
}

$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unread'], true)) {
    $filter = 'all';
}

/* -------------------------
   POST: 既読処理
------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF invalid');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'read_one') {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if ($notification_id) {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1,
                    read_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
                  AND channel = 'in_app'
                LIMIT 1
            ");
            $stmt->execute([
                ':id'  => $notification_id,
                ':uid' => $user_id,
            ]);
        }
    }

    if ($action === 'read_all') {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = NOW(),
                updated_at = NOW()
            WHERE user_id = :uid
              AND channel = 'in_app'
              AND is_read = 0
        ");
        $stmt->execute([':uid' => $user_id]);
    }

    header('Location: announce.php?filter=' . urlencode($filter));
    exit;
}

/* -------------------------
   未読件数
------------------------- */
$stmtUnread = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications
    WHERE user_id = :uid
      AND channel = 'in_app'
      AND is_read = 0
");
$stmtUnread->execute([':uid' => $user_id]);
$unread_count = (int)$stmtUnread->fetchColumn();

/* -------------------------
   一覧取得
------------------------- */
$sql = "
    SELECT
        n.id,
        n.task_id,
        n.notification_type,
        n.message,
        n.is_read,
        n.read_at,
        n.created_at,
        t.title AS task_title,
        t.due_at,
        t.status
    FROM notifications n
    LEFT JOIN tasks t
      ON t.id = n.task_id
    WHERE n.user_id = :uid
      AND n.channel = 'in_app'
";

$params = [':uid' => $user_id];

if ($filter === 'unread') {
    $sql .= " AND n.is_read = 0";
}

$sql .= "
    ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>お知らせ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/task_card.css">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/app_layout.css')) ?>">


</head>

<body class="page--withAppHeader page--announcePage">
    <div class="appShell appShell--withHeader">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="appBody">
            <div class="sideNavSlot">
                <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            </div>

            <main class="pageMain">
                <div class="signedinHeader">
                    <div>
                        <h1>お知らせ</h1>
                        <p class="muted">
                            未読 <?= (int)$unread_count ?> 件
                            <?php if ($filter === 'unread'): ?>
                                ／ 未読のみ表示中
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="buttonRow">
                        <a class="button <?= $filter === 'all' ? '' : 'secondary' ?>" href="announce.php?filter=all">すべて</a>
                        <a class="button <?= $filter === 'unread' ? '' : 'secondary' ?>" href="announce.php?filter=unread">未読のみ</a>

                        <?php if ($unread_count > 0): ?>
                            <form method="post" action="announce.php?filter=<?= h($filter) ?>" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                                <input type="hidden" name="action" value="read_all">
                                <button type="submit" class="secondary">すべて既読</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($items)): ?>
                    <div class="card">
                        <p class="status">お知らせはありません。</p>
                        <p class="muted">締切が近い課題が発生すると、ここに表示されます。</p>
                    </div>
                <?php else: ?>
                    <div class="tasksList">
                        <?php foreach ($items as $row): ?>
                            <?php
                            $taskId = (int)($row['task_id'] ?? 0);
                            $taskLink = '';
                            if ($taskId > 0) {
                                $taskLink = '../tasks/task.php?id=' . urlencode((string)$taskId);
                            }
                            ?>
                            <div class="taskItem" style="<?= !empty($row['is_read']) ? 'opacity:.78;' : '' ?>">
                                <div class="taskTop">
                                    <div>
                                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                            <div class="taskTitle"><?= h($row['message'] ?: 'お知らせ') ?></div>

                                            <?php if (empty($row['is_read'])): ?>
                                                <span style="display:inline-flex; align-items:center; padding:4px 9px; border-radius:999px; background:rgba(23,184,137,.14); color:#0f172a; font-size:12px; font-weight:800;">
                                                    未読
                                                </span>
                                            <?php endif; ?>

                                            <span style="display:inline-flex; align-items:center; padding:4px 9px; border-radius:999px; background:#f7faf9; border:1px solid #e2e8e6; color:#667085; font-size:12px; font-weight:700;">
                                                <?= h(type_label((string)$row['notification_type'])) ?>
                                            </span>
                                        </div>

                                        <div class="taskMeta" style="margin-top:6px;">
                                            作成日時：<?= h(format_dt($row['created_at'] ?? null)) ?>
                                            <?php if (!empty($row['due_at'])): ?>
                                                ／ 締切：<?= h(format_dt($row['due_at'])) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($row['status'])): ?>
                                                ／ 状態：<?= h((string)$row['status']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="taskBtns">
                                        <?php if ($taskLink !== ''): ?>
                                            <a class="button small" href="<?= h($taskLink) ?>">課題を見る</a>
                                        <?php endif; ?>

                                        <?php if (empty($row['is_read'])): ?>
                                            <form method="post" action="announce.php?filter=<?= h($filter) ?>" style="display:inline;">
                                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                                                <input type="hidden" name="action" value="read_one">
                                                <input type="hidden" name="notification_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="secondary small">既読にする</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="taskDetail muted">
                                    課題名：<?= h((string)($row['task_title'] ?? '（課題情報なし）')) ?>
                                    <?php if (!empty($row['read_at'])): ?>
                                        <br>既読日時：<?= h(format_dt($row['read_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.min.js"></script>
    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>

</html>