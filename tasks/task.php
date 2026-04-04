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

// 戻る用
$requestedCell = (string)($_REQUEST['cell'] ?? '');
$cell = preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', $requestedCell) ? $requestedCell : '';

$backUrl = '../timetable/timetable.php';
if ($cell !== '') {
    $backUrl .= '?cell=' . urlencode($cell);
}

$error = '';
$updated = false;

// ----------------------
// POST（更新 or 削除）
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF invalid');
    }

    $action = (string)($_POST['action'] ?? 'update');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        exit('idが不正です');
    }

    if ($action === 'delete') {
        $stmtF = $pdo->prepare('
            SELECT stored_name
            FROM task_files
            WHERE user_id = :uid AND task_id = :tid
        ');
        $stmtF->execute([':uid' => $user_id, ':tid' => $id]);
        $storedNames = $stmtF->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM notifications WHERE user_id = :uid AND task_id = :tid')
                ->execute([':uid' => $user_id, ':tid' => $id]);

            $pdo->prepare('DELETE FROM task_files WHERE user_id = :uid AND task_id = :tid')
                ->execute([':uid' => $user_id, ':tid' => $id]);

            $delTask = $pdo->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :uid LIMIT 1');
            $delTask->execute([':id' => $id, ':uid' => $user_id]);

            if ($delTask->rowCount() === 0) {
                $pdo->rollBack();
                http_response_code(404);
                exit('課題が見つかりません');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            exit('削除に失敗しました');
        }

        $dir = __DIR__ . '/../uploads/task_files';
        foreach ($storedNames as $sn) {
            $path = $dir . '/' . $sn;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        header('Location: ' . $backUrl);
        exit;
    }

    $title  = trim((string)($_POST['title'] ?? ''));
    $due    = (string)($_POST['due'] ?? '');
    $detail = trim((string)($_POST['detail'] ?? ''));
    $status = (string)($_POST['status'] ?? 'open');

    if ($title === '') {
        $error = 'タイトルは必須です。';
    }

    if (!in_array($status, ['open', 'done'], true)) {
        $status = 'open';
    }

    $due_at = null;
    if ($due !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $due);
        if (!$dt) {
            $error = $error ?: '締切日時の形式が不正です。';
        } else {
            $due_at = $dt->format('Y-m-d H:i:00');
        }
    }

    if ($error === '') {
        $stmt = $pdo->prepare('
            UPDATE tasks
            SET title = :title,
                due_at = :due_at,
                detail = :detail,
                status = :status
            WHERE id = :id AND user_id = :uid
            LIMIT 1
        ');
        $stmt->execute([
            ':title'  => $title,
            ':due_at' => $due_at,
            ':detail' => ($detail !== '' ? $detail : null),
            ':status' => $status,
            ':id'     => $id,
            ':uid'    => $user_id,
        ]);

        $to = 'task.php?id=' . urlencode((string)$id) . '&updated=1';
        if ($cell !== '') {
            $to .= '&cell=' . urlencode($cell);
        }
        header('Location: ' . $to);
        exit;
    }
}

// ----------------------
// GET（表示）
// ----------------------
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('idが不正です');
}

$is_edit = (($_GET['edit'] ?? '') === '1') || ($error !== '');
$updated = (($_GET['updated'] ?? '') === '1');

$stmt = $pdo->prepare('
  SELECT id, course_id, title, due_at, detail, status,
         ai_answer, ai_answer_updated_at, ai_answer_provider, ai_answer_model,
         ai_grade_provider, ai_grade_model,
         google_doc_id,
         draft_body,
         draft_updated_at
  FROM tasks
  WHERE user_id = :uid AND id = :id
  LIMIT 1
');
$stmt->execute([':uid' => $user_id, ':id' => $id]);
$task = $stmt->fetch();

if (!$task) {
    http_response_code(404);
    exit('課題が見つかりません');
}

if (empty($task['course_id'])) {
    http_response_code(500);
    exit('course_id が設定されていない課題です');
}

// cell が渡っていない場合は course_id から代表セルを補完
if ($cell === '') {
    $stmtCell = $pdo->prepare('
        SELECT CONCAT(day, "_", period) AS cell_key
        FROM timetable_course_slots
        WHERE user_id = :uid AND course_id = :cid
        ORDER BY
            FIELD(day, "mon", "tue", "wed", "thu", "fri", "sat"),
            period ASC
        LIMIT 1
    ');
    $stmtCell->execute([
        ':uid' => $user_id,
        ':cid' => (int)$task['course_id'],
    ]);
    $derivedCell = $stmtCell->fetchColumn();

    if ($derivedCell !== false && preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', (string)$derivedCell)) {
        $cell = (string)$derivedCell;
    }
}

$backUrl = '../timetable/timetable.php';
if ($cell !== '') {
    $backUrl .= '?cell=' . urlencode($cell);
}

$stmtCourse = $pdo->prepare('
    SELECT course_name
    FROM timetable_courses
    WHERE user_id = :uid AND id = :cid
    LIMIT 1
');
$stmtCourse->execute([
    ':uid' => $user_id,
    ':cid' => (int)$task['course_id'],
]);
$course = $stmtCourse->fetch();

$dueText = $task['due_at'] ? date('Y-m-d H:i', strtotime((string)$task['due_at'])) : '締切なし';
$due_local = $task['due_at'] ? date('Y-m-d\TH:i', strtotime((string)$task['due_at'])) : '';

$stmtF = $pdo->prepare('
  SELECT id, original_name, size, created_at
  FROM task_files
  WHERE user_id = :uid AND task_id = :tid
  ORDER BY id DESC
');
$stmtF->execute([':uid' => $user_id, ':tid' => (int)$task['id']]);
$files = $stmtF->fetchAll();

$editUrl = 'task.php?id=' . urlencode((string)$task['id']) . '&edit=1';
if ($cell !== '') {
    $editUrl .= '&cell=' . urlencode($cell);
}

$cancelUrl = 'task.php?id=' . urlencode((string)$task['id']);
if ($cell !== '') {
    $cancelUrl .= '&cell=' . urlencode($cell);
}

$returnToThis = '../tasks/task.php?id=' . (int)$task['id'];
if ($cell !== '') {
    $returnToThis .= '&cell=' . urlencode($cell);
}

// AI 設定
$aiConfig = ai_public_config();

$initialProvider =
    (string)($task['ai_answer_provider'] ?? '') !== ''
    ? (string)$task['ai_answer_provider']
    : (
        (string)($task['ai_grade_provider'] ?? '') !== ''
        ? (string)$task['ai_grade_provider']
        : (string)($aiConfig['defaults']['provider'] ?? ai_default_provider())
    );

$initialModel =
    (string)($task['ai_answer_model'] ?? '') !== ''
    ? (string)$task['ai_answer_model']
    : (
        (string)($task['ai_grade_model'] ?? '') !== ''
        ? (string)$task['ai_grade_model']
        : (string)($aiConfig['defaults']['model'] ?? ai_default_model($initialProvider))
    );

$initialAiSelection = ai_resolve_selection($initialProvider, $initialModel);
$providerLabels = (array)($aiConfig['providerLabels'] ?? []);

$statusKey = (string)($task['status'] ?? 'open');
$statusLabel = $statusKey === 'done' ? '完了' : '未完了';
$statusClass = $statusKey === 'done' ? 'is-done' : 'is-open';

$heroTitle = (string)($task['title'] ?: '(無題)');
$heroDetail = (string)($task['detail'] ?? '');
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>課題詳細</title>
    <link rel="stylesheet" href="<?= h(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/task.css')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/app_layout.css')) ?>">
</head>

<body class="page--withAppHeader page--taskPage">
    <div class="appShell appShell--withHeader">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="appBody">
            <?php include __DIR__ . '/../partials/sidebar.php'; ?>

            <main class="pageMain pageMain--task">
                <section class="pageContent pageContent--task">
                    <?php if ($updated): ?>
                        <div class="card taskFlashCard">
                            <p class="status">更新しました。</p>
                        </div>
                    <?php endif; ?>

                    <section class="card taskHero">
                        <div class="taskHeroMain">
                            <?php if ($course): ?>
                                <p class="taskHeroCourse">
                                    授業：<?= h((string)($course['course_name'] ?? '')) ?>
                                </p>
                            <?php endif; ?>

                            <h1 class="taskHeroTitle"><?= h($heroTitle) ?></h1>

                            <div class="taskHeroMeta">
                                <span class="taskMetaChip">
                                    <span class="taskMetaLabel">締切</span>
                                    <span class="taskMetaValue"><?= h($dueText) ?></span>
                                </span>

                                <span class="taskState <?= h($statusClass) ?>">
                                    <?= h($statusLabel) ?>
                                </span>
                            </div>
                        </div>

                        <div class="taskHeroActions">
                            <?php if (!$is_edit): ?>
                                <a class="button" href="<?= h($editUrl) ?>">編集</a>
                            <?php else: ?>
                                <a class="button secondary" href="<?= h($cancelUrl) ?>">キャンセル</a>
                            <?php endif; ?>
                        </div>
                    </section>

                    <div class="taskMainGrid">
                        <section class="card taskDetailCard">
                            <div class="taskSectionHeader">
                                <h2 class="taskSectionTitle">
                                    <?= $is_edit ? '課題を編集' : '課題詳細' ?>
                                </h2>
                            </div>

                            <?php if (!$is_edit): ?>
                                <div class="taskBodyText">
                                    <?= h($heroDetail !== '' ? $heroDetail : '（詳細なし）') ?>
                                </div>
                            <?php else: ?>
                                <div class="taskEditTopActions">
                                    <form method="post" action="task.php"
                                        onsubmit="return confirm('この課題を削除しますか？（添付ファイルも削除されます）');">
                                        <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                                        <input type="hidden" name="cell" value="<?= h($cell) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="danger">削除</button>
                                    </form>
                                </div>

                                <?php if ($error !== ''): ?>
                                    <p class="error taskFormError"><?= h($error) ?></p>
                                <?php endif; ?>

                                <form class="form taskEditForm" method="post" action="task.php">
                                    <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                                    <input type="hidden" name="cell" value="<?= h($cell) ?>">

                                    <label>
                                        タイトル
                                        <input
                                            type="text"
                                            name="title"
                                            required
                                            value="<?= h((string)($error ? ($_POST['title'] ?? '') : ($task['title'] ?? ''))) ?>">
                                    </label>

                                    <label>
                                        締切（任意）
                                        <input
                                            type="datetime-local"
                                            name="due"
                                            value="<?= h((string)($error ? ($_POST['due'] ?? '') : $due_local)) ?>">
                                    </label>

                                    <label>
                                        状態
                                        <?php $curStatus = (string)($error ? ($_POST['status'] ?? 'open') : ($task['status'] ?? 'open')); ?>
                                        <select name="status">
                                            <option value="open" <?= $curStatus === 'open' ? 'selected' : '' ?>>未完了</option>
                                            <option value="done" <?= $curStatus === 'done' ? 'selected' : '' ?>>完了</option>
                                        </select>
                                    </label>

                                    <label>
                                        詳細
                                        <textarea name="detail" rows="10"><?= h((string)($error ? ($_POST['detail'] ?? '') : ($task['detail'] ?? ''))) ?></textarea>
                                    </label>

                                    <div class="buttonRow">
                                        <button type="submit">保存</button>
                                        <a class="button secondary" href="<?= h($cancelUrl) ?>">キャンセル</a>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </section>

                        <aside class="card taskFilesCard">
                            <div class="taskSectionHeader">
                                <h2 class="taskSectionTitle">添付ファイル</h2>
                            </div>

                            <?php if (!$is_edit): ?>
                                <form action="task_file_upload.php" method="post" enctype="multipart/form-data" class="form taskUploadForm">
                                    <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                    <input type="hidden" name="cell" value="<?= h($cell) ?>">

                                    <label>
                                        ファイルを選択
                                        <input type="file" name="file" required>
                                    </label>

                                    <button type="submit">アップロード</button>

                                    <p class="muted taskUploadHelp">
                                        ※ PDF / 画像 / txt / Office系（docx,xlsx,pptx）など（サーバー側で制限）
                                    </p>
                                </form>

                                <hr class="divider">
                            <?php endif; ?>

                            <?php if (empty($files)): ?>
                                <p class="muted">添付ファイルはありません。</p>
                            <?php else: ?>
                                <ul class="taskFileList">
                                    <?php foreach ($files as $f): ?>
                                        <li class="taskFileItem">
                                            <div class="taskFileMain">
                                                <a class="button small" href="task_file_download.php?id=<?= (int)$f['id'] ?>">
                                                    <?= h((string)$f['original_name']) ?>
                                                </a>

                                                <div class="taskFileMeta">
                                                    <span><?= (int)$f['size'] ?> bytes</span>
                                                    <span><?= h((string)$f['created_at']) ?></span>
                                                </div>
                                            </div>

                                            <form action="task_file_delete.php" method="post" class="taskFileDeleteForm">
                                                <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
                                                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                                <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                <input type="hidden" name="cell" value="<?= h($cell) ?>">
                                                <button
                                                    type="submit"
                                                    class="danger small"
                                                    onclick="return confirm('このファイルを削除しますか？')">削除</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </aside>
                    </div>

                    <section class="workbench studyWorkbench">
                        <section class="pane pane--guide">
                            <div class="paneHeader">
                                <h3>AIのガイダンス</h3>
                                <button id="aiRun" class="button" type="button">方針を生成</button>
                            </div>

                            <p id="aiStatus" class="muted"></p>

                            <div id="aiBox" style="display:none;">
                                <div id="aiText"></div>
                            </div>
                        </section>

                        <section class="pane pane--draft">
                            <div class="paneHeader">
                                <h3>提出用下書き</h3>

                                <div class="buttonRow">
                                    <a class="button secondary"
                                        href="../google/google_connect.php?task_id=<?= (int)$task['id'] ?>&return=<?= urlencode($returnToThis) ?>">
                                        Google連携
                                    </a>
                                    <button id="docsCreate" class="secondary" type="button">Docs作成</button>
                                    <button id="docsSync" type="button">Docsへ反映</button>
                                </div>
                            </div>

                            <p class="muted" id="docsStatus"></p>

                            <div class="draftMetaRow">
                                <p class="muted" id="draftSaveStatus">
                                    <?php if (!empty($task['draft_updated_at'])): ?>
                                        自動保存済み：<?= h((string)$task['draft_updated_at']) ?>
                                    <?php else: ?>
                                        まだ保存されていません
                                    <?php endif; ?>
                                </p>
                                <p class="muted" id="draftCount"></p>
                            </div>

                            <a
                                id="docLink"
                                class="button small secondary"
                                href="#"
                                target="_blank"
                                rel="noopener"
                                style="display:none;">
                                Google Docsを開く
                            </a>

                            <textarea id="draft" rows="20" placeholder="ここにレポート本文を書いていきます…"><?= h((string)($task['draft_body'] ?? '')) ?></textarea>
                        </section>
                    </section>

                    <section class="card taskFeedbackSection">
                        <div class="taskSectionHeader taskSectionHeader--feedback">
                            <h2 class="taskSectionTitle">AIによるフィードバック</h2>

                            <div class="buttonRow">
                                <button id="gradeRun" class="button" type="button">採点する</button>
                                <button id="gradeRegen" class="button secondary" type="button">再採点</button>
                            </div>
                        </div>

                        <p id="gradeStatus" class="muted"></p>

                        <div id="gradeCard">
                            <div id="gradeBox" style="display:none;">
                                <div class="gradeScoreRow">
                                    <div><strong>得点：</strong><span id="gradeScore"></span>/100</div>
                                    <div><strong>評価：</strong><span id="gradeLetter"></span></div>
                                    <div class="muted" id="gradedAt"></div>
                                </div>

                                <hr class="divider">

                                <h3>良い点</h3>
                                <ul id="gradeGood"></ul>

                                <h3 class="gradeBlockTitle">悪い点</h3>
                                <ul id="gradeBad"></ul>

                                <h3 class="gradeBlockTitle">次のアクション</h3>
                                <ul id="gradeNext"></ul>

                                <hr class="divider">
                                <div id="gradeSummary"></div>
                            </div>
                        </div>
                    </section>
                </section>
            </main>

            <div id="taskAssistantDrawer" class="taskAssistantDrawer" aria-hidden="true">
                <aside id="taskAssistantPanel" class="taskAssistantPanel" aria-hidden="true">
                    <button
                        id="taskAssistantToggle"
                        class="taskAssistantHandle"
                        type="button"
                        aria-label="AIアシスタントを開く"
                        aria-expanded="false"
                        aria-controls="taskAssistantPanel">
                        <span class="taskAssistantHandleIcon"><i data-lucide="sparkles"></i></span>
                        <span class="taskAssistantHandleText">AI</span>
                    </button>

                    <div class="taskAssistantHeader">
                        <div>
                            <h2 class="taskAssistantTitle">AIアシスタント</h2>
                        </div>

                        <button id="taskAssistantClose" class="taskAssistantClose" type="button" aria-label="閉じる">
                            <i data-lucide="x"></i>
                        </button>
                    </div>

                    <div class="taskAssistantTabs" role="tablist" aria-label="AIアシスタントの表示切替">
                        <button
                            type="button"
                            class="taskAssistantTab is-active"
                            data-assist-tab="chat"
                            role="tab"
                            aria-selected="true">
                            <i data-lucide="messages-square"></i>
                            <span>AI相談</span>
                        </button>

                        <button
                            type="button"
                            class="taskAssistantTab"
                            data-assist-tab="settings"
                            role="tab"
                            aria-selected="false">
                            <i data-lucide="sliders-horizontal"></i>
                            <span>AI設定</span>
                        </button>
                    </div>

                    <div class="taskAssistantBody">
                        <section class="assistSection is-active" data-assist-panel="chat" role="tabpanel">
                            <div class="chatStage">
                                <div id="chatHistory"></div>
                                <p id="chatEmpty" class="muted" style="display:none;">
                                    まだ相談はありません。
                                </p>
                            </div>

                            <form id="chatForm" class="chatForm">
                                <textarea
                                    id="chatInput"
                                    rows="2"
                                    placeholder="知りたいことや、どこで詰まっているかを書いてください。"></textarea>

                                <div class="buttonRow chatFormActions">
                                    <button id="chatSend" class="button" type="submit">相談する</button>
                                </div>
                            </form>
                        </section>

                        <section class="assistSection" data-assist-panel="settings" role="tabpanel" hidden>
                            <div class="taskAssistantSectionHeader">
                                <h3>AIモデル設定</h3>
                                <p class="muted">ガイダンス生成・AI相談・採点に共通で使うAIを選びます。</p>
                            </div>

                            <div id="aiProviderButtons" class="buttonRow taskAiProviderButtons">
                                <?php foreach ($providerLabels as $providerKey => $providerLabel): ?>
                                    <button
                                        type="button"
                                        class="<?= $providerKey === $initialAiSelection['provider'] ? 'button' : 'button secondary' ?>"
                                        data-ai-provider="<?= h((string)$providerKey) ?>">
                                        <?= h((string)$providerLabel) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <label class="taskAiModelLabel">
                                モデル
                                <select id="aiModelSelect"></select>
                            </label>

                            <p id="aiSelectedInfo" class="muted"></p>
                        </section>
                    </div>
                </aside>
            </div>

            <div id="taskAssistantBackdrop" class="taskAssistantBackdrop" hidden></div>
        </div>
    </div>

    <script>
        window.__TASK__ = {
            taskId: <?= json_encode((int)$task['id']) ?>,
            csrf: <?= json_encode($_SESSION['csrf'] ?? '') ?>,
            cell: <?= json_encode($cell) ?>,
            hasAi: <?= json_encode(!empty($task['ai_answer'])) ?>,
            hasDoc: <?= json_encode(!empty($task['google_doc_id'])) ?>,
            aiConfig: <?= json_encode($aiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            aiSelection: <?= json_encode($initialAiSelection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        };
    </script>

    <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.min.js"></script>
    <script src="../assets/js/task.js?v=<?= filemtime(__DIR__ . '/../assets/js/task.js') ?>"></script>
    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>

</html>