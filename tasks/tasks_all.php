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

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$course = (string)($_GET['course'] ?? 'all');

$stS = $pdo->prepare('SELECT DISTINCT status FROM tasks WHERE user_id=:uid ORDER BY status');
$stS->execute([':uid' => $user_id]);
$statuses = array_values(array_filter(array_map(fn($r) => (string)$r['status'], $stS->fetchAll())));

$stC = $pdo->prepare('
    SELECT DISTINCT course_name
    FROM timetable_courses
    WHERE user_id = :uid
      AND course_name <> ""
    ORDER BY course_name
');
$stC->execute([':uid' => $user_id]);
$courses = array_values(array_filter(array_map(fn($r) => (string)$r['course_name'], $stC->fetchAll())));

$where = ['t.user_id = :uid'];
$params = [':uid' => $user_id];

if ($status !== 'all' && $status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}
if ($q !== '') {
    $where[] = '(t.title LIKE :q1 OR t.detail LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($course !== 'all' && $course !== '') {
    $where[] = 'c.course_name = :course';
    $params[':course'] = $course;
}

$sql = '
  SELECT
    t.id,
    t.title,
    t.due_at,
    t.status,
    t.detail,
    t.course_id,
    c.course_name
  FROM tasks t
  LEFT JOIN timetable_courses c
    ON c.id = t.course_id
   AND c.user_id = t.user_id
  WHERE ' . implode(' AND ', $where) . '
  ORDER BY (t.due_at IS NULL) ASC, t.due_at ASC, t.id DESC
  LIMIT 500
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_due($due_at): string
{
    if (!$due_at) return '締切なし';
    return date('Y-m-d H:i', strtotime((string)$due_at));
}

function jp_weekday(int $w): string
{
    return ['日', '月', '火', '水', '木', '金', '土'][$w] ?? '';
}

function due_group_key($due_at): string
{
    if (!$due_at) return '__no_due';

    $ts = strtotime((string)$due_at);
    $today = strtotime(date('Y-m-d'));
    $target = strtotime(date('Y-m-d', $ts));

    if ($target < $today) {
        return '__overdue';
    }
    if ($target === $today) {
        return '__today';
    }
    if ($target === strtotime('+1 day', $today)) {
        return '__tomorrow';
    }

    return date('Y-m-d', $ts);
}

function due_group_label($due_at): string
{
    if (!$due_at) return '締切未設定';

    $ts = strtotime((string)$due_at);
    $today = strtotime(date('Y-m-d'));
    $target = strtotime(date('Y-m-d', $ts));

    if ($target < $today) {
        return '期限切れ';
    }
    if ($target === $today) {
        return '今日';
    }
    if ($target === strtotime('+1 day', $today)) {
        return '明日';
    }

    return date('Y年n月j日', $ts) . '（' . jp_weekday((int)date('w', $ts)) . '）';
}

function task_status_class(?string $status): string
{
    return $status === 'done' ? 'is-done' : 'is-open';
}

function task_status_label(?string $status): string
{
    return $status === 'done' ? '完了' : '未完了';
}

$groupedRows = [];
foreach ($rows as $t) {
    $key = due_group_key($t['due_at']);
    if (!isset($groupedRows[$key])) {
        $groupedRows[$key] = [
            'label' => due_group_label($t['due_at']),
            'items' => [],
        ];
    }
    $groupedRows[$key]['items'][] = $t;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>課題一覧</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/task_card.css">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/app_layout.css')) ?>">


</head>

<body class="page--withAppHeader page--tasksAll">
    <div class="appShell appShell--withHeader">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="appBody">
            <div class="sideNavSlot">
                <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            </div>

            <div class="pageMain">
                <div class="card">
                    <form method="get" class="form" style="gap:12px;">
                        <label>検索（タイトル/詳細）
                            <input type="text" name="q" value="<?= h($q) ?>" placeholder="例：レポート / 期末 / Chapter">
                        </label>

                        <div class="timeRow">
                            <label>ステータス
                                <select name="status">
                                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>すべて</option>
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>授業
                                <select name="course">
                                    <option value="all" <?= $course === 'all' ? 'selected' : '' ?>>すべて</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?= h($c) ?>" <?= $course === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="buttonRow">
                            <button type="submit">表示</button>
                            <a class="button secondary" href="tasks_all.php">リセット</a>
                        </div>
                    </form>
                </div>

                <?php if (empty($rows)): ?>
                    <p class="muted">該当する課題がありません。</p>
                <?php else: ?>
                    <?php foreach ($groupedRows as $group): ?>
                        <section class="dueSection">
                            <h2 class="dueSectionTitle"><?= h($group['label']) ?></h2>

                            <div class="tasksList">
                                <?php foreach ($group['items'] as $t): ?>
                                    <?php
                                    $courseName = $t['course_name'] ?: '（授業未設定）';
                                    $taskUrl = 'task.php?id=' . (int)$t['id'];
                                    $statusClass = task_status_class($t['status'] ?? '');
                                    $statusLabel = task_status_label($t['status'] ?? '');
                                    ?>
                                    <div class="taskItem <?= h($statusClass) ?>">
                                        <div class="taskTop">
                                            <div class="taskMain">
                                                <div class="taskTitleRow">
                                                    <div class="taskTitle"><?= h($t['title'] ?: '(無題)') ?></div>
                                                    <span class="taskState <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                                                </div>

                                                <div class="taskMeta">
                                                    授業：<?= h($courseName) ?>
                                                </div>
                                            </div>

                                            <div class="taskSide">
                                                <div class="taskDueLabel">締切</div>
                                                <div class="taskDueValue"><?= h(fmt_due($t['due_at'])) ?></div>

                                                <div class="taskBtns">
                                                    <a class="button small" href="<?= h($taskUrl) ?>">詳細</a>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if (!empty($t['detail'])): ?>
                                            <div class="taskDetail"><?= nl2br(h($t['detail'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
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