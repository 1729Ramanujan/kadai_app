<?php
require_once __DIR__ . '/../config.php';
require_login();

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT
        us.id,
        us.email,
        us.university_id,
        us.period1_start,
        us.period1_end,
        us.period2_start,
        us.period2_end,
        us.period3_start,
        us.period3_end,
        us.period4_start,
        us.period4_end,
        us.period5_start,
        us.period5_end,
        us.period6_start,
        us.period6_end,
        uni.name AS university_name,
        uni.lms_url
     FROM users us
     LEFT JOIN universities uni ON uni.id = us.university_id
     WHERE us.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $user_id]);
$me = $stmt->fetch();

if (!$me) {
    http_response_code(404);
    exit('user not found');
}

$periodTimes = [];
for ($i = 1; $i <= 6; $i++) {
    $start = $me["period{$i}_start"] ?? null;
    $end   = $me["period{$i}_end"] ?? null;

    $periodTimes[$i] = [
        'start' => $start ? substr((string)$start, 0, 5) : '',
        'end'   => $end ? substr((string)$end, 0, 5) : '',
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>時間割</title>
    <link rel="stylesheet" href="<?= h(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/timetable.css')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/task_card.css')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/app_layout.css')) ?>">
</head>

<body class="page--withAppHeader">
    <div class="appShell appShell--withHeader">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="appBody">
            <div class="sideNavSlot">
                <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            </div>

            <main class="pageMain pageMain--timetable">
                <section class="pageContent">
                    <div class="card errorCard">
                        <p class="error" id="error" hidden></p>
                    </div>

                    <div class="timetableLayout" id="layout">
                        <div class="timetableGrid" id="gridArea">
                            <div class="pageIntro">
                                <h1 class="pageHeading">時間割</h1>

                                <?php if (!empty($me['university_name'])): ?>
                                    <p class="muted" id="timetableMessage">
                                        所属大学：<?= h($me['university_name']) ?>
                                    </p>
                                <?php else: ?>
                                    <p class="muted" id="timetableMessage">
                                        大学が未設定です。プロフィールから設定するとLMSへ移動できます。
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div id="timetableGridWrap"></div>
                        </div>

                        <div class="panelOverlay" id="panelOverlay" hidden></div>

                        <aside class="sidePanel" id="sidePanel" aria-hidden="true">
                            <div class="panelHeader">
                                <h3 style="margin:0;">授業の詳細</h3>
                                <button type="button" class="secondary" id="panelClose">×</button>
                            </div>

                            <div class="timetableEditor">
                                <p class="muted" id="selectedLabel">左のセルを選択してください</p>

                                <div class="form">
                                    <label>授業名
                                        <input type="text" id="editName" placeholder="例：線形代数">
                                    </label>

                                    <label>教室
                                        <input type="text" id="editRoom" placeholder="例：532、21KOMCEE West K011">
                                    </label>

                                    <div class="buttonRow">
                                        <button id="saveCallButton" disabled type="button">保存</button>
                                        <button type="button" id="deleteCourseButton" class="secondary" hidden disabled>授業を削除</button>
                                    </div>
                                </div>

                                <hr class="divider">

                                <div id="slotsSection">
                                    <h3>登録コマ</h3>
                                    <p class="muted" id="slotsInfo">授業を選択してください</p>
                                    <div class="slotsList" id="slotsList"></div>

                                    <details class="slotAccordion" id="slotAccordion">
                                        <summary class="slotAccordionToggle">コマを追加 / コマから授業を削除</summary>
                                        <div class="slotAccordionBody">
                                            <label>曜日
                                                <select id="addSlotDay">
                                                    <option value="">選択してください</option>
                                                    <option value="mon">月</option>
                                                    <option value="tue">火</option>
                                                    <option value="wed">水</option>
                                                    <option value="thu">木</option>
                                                    <option value="fri">金</option>
                                                    <option value="sat">土</option>
                                                </select>
                                            </label>

                                            <label>時限
                                                <select id="addSlotPeriod">
                                                    <option value="">選択してください</option>
                                                    <option value="1">1限</option>
                                                    <option value="2">2限</option>
                                                    <option value="3">3限</option>
                                                    <option value="4">4限</option>
                                                    <option value="5">5限</option>
                                                    <option value="6">6限</option>
                                                </select>
                                            </label>

                                            <div class="buttonRow">
                                                <button type="button" id="addSlotButton" disabled>別のコマを追加</button>
                                                <button id="deleteCallButton" class="secondary" disabled type="button">このコマから外す</button>
                                            </div>
                                        </div>
                                    </details>
                                </div>

                                <hr class="divider">

                                <div id="tasksSection">
                                    <h3>課題</h3>
                                    <p class="muted" id="tasksInfo">授業を選択してください</p>
                                    <div class="tasksList" id="tasksList"></div>

                                    <div class="card innerCard">
                                        <label>タイトル
                                            <input type="text" id="taskTitle" placeholder="例:レポート１">
                                        </label>

                                        <label>締切
                                            <input type="datetime-local" id="taskDue">
                                        </label>

                                        <label>詳細
                                            <textarea id="taskDetail" rows="4" placeholder="提出方法、ページ数など"></textarea>
                                        </label>

                                        <button type="button" id="addTaskbutton" disabled>追加</button>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script>
        window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf']) ?>;
        window.USER_EMAIL = <?= json_encode($_SESSION['user_email'] ?? '') ?>;
        window.PERIOD_TIMES = <?= json_encode($periodTimes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.min.js"></script>
    <script src="<?= h(base_url('assets/js/timetable.js')) ?>"></script>
    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>

</html>