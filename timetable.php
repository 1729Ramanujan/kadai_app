<?php
require_once __DIR__ . '/config.php';
require_login();
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>時間割</title>
    <link rel="stylesheet" href="./css/style.css">
</head>


<body>
    <h1>時間割</h1>

    <div class="card">
        <p class="status" id="status">読み込み中...</p>
        <p class="error" id="error" hidden></p>
    </div>

    <div class="signedinHeader">
        <div>
            <p>ユーザー：<span id="userEmail"></span></p>
        </div>
        <div class="buttonRow">
            <a href="./index.html" class="button">ログイン画面へ</a>
            <button class="danger" id="logoutButton" type="button">ログアウト</button>
        </div>
    </div>

    <div class="timetableLayout">
        <div class="timetableGrid">
            <h3>時間割（クリックして編集）</h3>
            <p class="muted" id="timetableMessage"></p>
            <div id="timetableGridWrap"></div>
        </div>

        <div class="timetableEditor">
            <h3>授業の編集</h3>
            <p class="muted" id="selectedLabel">左のセルを選択してください</p>

            <div class="form">
                <label>授業名
                    <input type="text" id="editName" placeholder="例：線形代数">
                </label>

                <div class="timeRow">
                    <label>開始時刻
                        <input type="time" id="editStart">
                    </label>
                    <label>終了時刻
                        <input type="time" id="editEnd">
                    </label>
                </div>

                <div class="buttonRow">
                    <button id="saveCallButton" disabled type="button">保存</button>
                    <button id="deleteCallButton" class="secondary" disabled type="button">削除</button>
                </div>
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
    </div>

    <script src="./js/timetable.js"></script>




    <!-- ここに、後で timetable.html をPHP/JSに移植していく -->
    <script>
        window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf']) ?>;
        window.USER_EMAIL = <?= json_encode($_SESSION['user_email'] ?? '') ?>;
        window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf']) ?>;
    </script>

</body>

</html>