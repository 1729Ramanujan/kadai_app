// まずはhtml上にある要素をとってきて、js上で操作できるように名前をつけて保存
const statusEl = document.getElementById("status");
const errorEl = document.getElementById("error");
const userEmailEl = document.getElementById("userEmail");
const logoutButton = document.getElementById("logoutButton");

const timetableGridWrap = document.getElementById("timetableGridWrap");
const timetableMessage = document.getElementById("timetableMessage");

const selectedLabel = document.getElementById("selectedLabel");
const editName = document.getElementById("editName");
const editStart = document.getElementById("editStart");
const editEnd = document.getElementById("editEnd");
const saveCallButton = document.getElementById("saveCallButton");
const deleteCallButton = document.getElementById("deleteCallButton");

// 課題の追加を行う部分のhtml上の要素をとってきている
const tasksInfo = document.getElementById("tasksInfo");
const tasksList = document.getElementById("tasksList");
const taskTitle = document.getElementById("taskTitle");
const taskDue = document.getElementById("taskDue");
const taskDetail = document.getElementById("taskDetail");
const addTaskbutton = document.getElementById("addTaskbutton");

let selectedCourseId = null;
let unsubscribeTasks = null;
const tasksCache = new Map();

// エラーがあれば表示させて、なかったらhiddenをつけて隠すようにする関数（エラー欄の表示・非表示）
function showError(message) {
    if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = "";
        return;
    }
    errorEl.hidden = false;
    errorEl.textContent = message;
}

// 時間割にセットしたい曜日を書いておく
const days = [
    { key: "mon", label: "月" },
    { key: "tue", label: "火" },
    { key: "wed", label: "水" },
    { key: "thu", label: "木" },
    { key: "fri", label: "金" },
    { key: "sat", label: "土" },
];

// 時間割にセットしたい限の個数を定義
const periods = [1, 2, 3, 4, 5, 6];

// Firebaseから読んだ各コマのデータをcallCacheに保存してる
const callCache = new Map();
// 現在ユーザーが選択しているコマを保存するための変数
let selectedKey = null;
// Firebaseは保存されたデータの更新がないかをずっと続ける（これは購読という）
// ずっとデータの更新を監視し続けると何重にもなっていつか壊れるので、購読を解除するための変数を設定
// 変数の中身は関数
let unsubscribeCells = null;

// コマが選ばれていない時は編集欄を操作できないようにしている
// 編集欄にある要素を一つづつ!enabledにすることで反応しないようにしてる
function setEditorEnabled(enabled) {
    editName.disabled = !enabled;
    editStart.disabled = !enabled;
    editEnd.disabled = !enabled;
    saveCallButton.disabled = !enabled;
    deleteCallButton.disabled = !enabled;
}

// 指定したコマのボタンを探す関数
function findCellButton(day, period) {
    return document.querySelector(`.timetableCell[data-day="${day}"][data-period="${period}"]`);
}

// セルに表示されている時間割の情報を更新する関数
function updateCellUIBykey(key) {
    const [day, periodString] = key.split("_");
    const period = Number(periodString);
    const button = findCellButton(day, period);
    if (!button) {
        return;
    }

    const nameEl = button.querySelector(".name");
    const timeEl = button.querySelector(".time");

    const data = callCache.get(key);
    const name = (data?.name ?? "").trim();
    const start = data?.start ?? "";
    const end = data?.end ?? "";

    if (!name && !start && !end) {
        button.classList.remove("filled");
        nameEl.textContent = "未設定";
        timeEl.textContent = "";
        return;
    }

    button.classList.add("filled");
    nameEl.textContent = name || "(授業名なし)";
    timeEl.textContent = (start && end) ? `${start}-${end}` : (start || end ? `時間：${start}${end ? "-" + end : ""}` : "");
}

// 選択中のコマの授業の情報をcallCacheからとってきて、その情報を右側の欄に入力している
function fillEditorFromCache(key) {
    const data = callCache.get(key);
    editName.value = data?.name ?? "";
    editStart.value = data?.start ?? "";
    editEnd.value = data?.end ?? "";
}

// ユーザーがコマをクリックしたときに更新する関数
// selectedKeyを更新して、.activeのつけ外しを行う
// 編集欄を有効にしたり、編集欄の更新を担ってる
function selectCell(key) {
    selectedKey = key;

    document.querySelectorAll(".timetableCell.active").forEach(el => el.classList.remove("active"));
    const [day, period] = key.split("_");
    const button = findCellButton(day, Number(period));
    if (button) button.classList.add("active");

    const dayLabel = days.find(d => d.key === day)?.label ?? day;
    selectedLabel.textContent = `${dayLabel}曜${period}限`;

    setEditorEnabled(true);
    fillEditorFromCache(key);
}

// もし作っていなければ、時間割表を作るようにしている（何回も作っても意味ないので、一回だけ）
// 箱だけ作っているイメージ中身は別の関数で作ってる
function buildGridOnce() {
    if (timetableGridWrap.dataset.built === "1") {
        return;
    }

    const table = document.createElement("table");
    table.className = "table";

    const thead = document.createElement("thead");
    const tr = document.createElement("tr");

    const corner = document.createElement("th");
    corner.textContent = "限";
    tr.appendChild(corner);

    days.forEach(d => {
        const th = document.createElement("th");
        th.textContent = d.label;
        tr.appendChild(th);
    });

    thead.appendChild(tr);
    table.appendChild(thead);

    const tbody = document.createElement("tbody");
    periods.forEach(p => {
        const tr = document.createElement("tr");

        const th = document.createElement("th");
        th.textContent = String(p);
        tr.appendChild(th);

        days.forEach(d => {
            const td = document.createElement("td");

            const button = document.createElement("button");
            button.type = "button";
            button.className = "timetableCell";
            button.dataset.day = d.key;
            button.dataset.period = String(p);

            const nameSpan = document.createElement("div");
            nameSpan.className = "name";
            nameSpan.textContent = "";

            const timeSpan = document.createElement("div");
            timeSpan.className = "time";
            timeSpan.textContent = "";

            button.appendChild(nameSpan);
            button.appendChild(timeSpan);

            button.addEventListener("click", () => selectCell(`${d.key}_${p}`));

            td.appendChild(button);
            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    timetableGridWrap.appendChild(table);
    timetableGridWrap.dataset.built = "1";

    setEditorEnabled(false);
}

// Firebase上でデータが更新されたら、それを反映できるようにFirebaseを購読する関数
function subscribeTimetableCells(uid) {
    if (unsubscribeCells) unsubscribeCells();

    const col = db.collection("users").doc(uid).collection("timetableCells");
    timetableMessage.textContent = "読み込み中..."

    unsubscribeCells = col.onSnapshot((snap) => {
        snap.docChanges().forEach((chg) => {
            const id = chg.doc.id;
            if (chg.type === "removed") {
                callCache.delete(id);
            } else {
                callCache.set(id, chg.doc.data());
            }
            updateCellUIBykey(id);
        });

        timetableMessage.textContent = "";
        if (selectedKey) fillEditorFromCache(selectedKey);
    }, (err) => {
        timetableMessage.textContent = "";
        showError(err?.message ?? "時間割の読み込みに失敗しました");
    });
}

// 保存ボタンが押された時にそれをFirebaseに保存するための関数
saveCallButton.addEventListener("click", async () => {
    if (!selectedKey) return;
    showError("");

    const user = auth.currentUser;
    if (!user) return;

    const name = editName.value.trim();
    const start = editStart.value;
    const end = editEnd.value;

    if (start && end && start >= end) {
        showError("開始時刻が終了時刻以上になっています。");
        return;
    }

    const [day, periodString] = selectedKey.split("_");
    const period = Number(periodString);

    const ref = db.collection("users").doc(user.uid).collection("timetableCells").doc(selectedKey);

    try {
        await ref.set({
            day, period, name, start, end,
            updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
        }, { merge: true });
    } catch (err) {
        showError(err?.message ?? "保存に失敗しました");
    }
});

// 削除ボタンが押された時に情報を更新する関数
deleteCallButton.addEventListener("click", async () => {
    if (!selectedKey) return;
    showError("");

    const user = auth.currentUser;
    if (!user) return;

    const ref = db.collection("users").doc(user.uid).collection("timetableCells").doc(selectedKey);

    try {
        await ref.delete();
        editName.value = "";
        editStart.value = "";
        editEnd.value = "";
    } catch (err) {
        showError(err?.message ?? "削除に失敗しました");
    }
});

// ログアウトボタンが押された時の関数
logoutButton.addEventListener("click", async () => {
    showError("");
    try {
        await auth.signOut();
    } catch (err) {
        showError(err?.message ?? "ログアウトに失敗しました");
    }
});

// ログイン状態かどうかを監視して、違っているのならindex.htmlに戻す関数
auth.onAuthStateChanged((user) => {
    showError("");
    if (!user) {
        window.location.href = "./index.html"
        return;
    }

    statusEl.textContent = "ログイン済み";
    userEmailEl.textContent = user.email ?? "(emailなし)";

    buildGridOnce();
    subscribeTimetableCells(user.uid);
});

// 授業を整理するためのcourseId(授業名とidを紐づけている)を作成する関数
async function getOrCreateCourseId(uid, courseName) {
    // まずは授業名をとっていてきる
    const name = (courseName ?? "").trim();
    // もし授業名がないならここでnullを返す
    if (!name) return null;

    // すでに保存されている授業のすべてを一度とってきている
    const coursesCol = db.collection("users").doc(uid).collection("courses");

    // qという変数を考える。先ほど撮ってきた授業の一覧から、今回受け取った授業名と
    // 一致しているものがあればその授業をqに保存する
    const q = await coursesCol.where("name", "==", name).limit(1).get();
    // もしqに要素が入っていれば
    if (!q.empty) {
        // その保存されている授業のデータからidを返す
        return q.docs[0].id;
    }

    // もしその授業が登録されていなかったら新しく作る
    const docRef = await coursesCol.add({
        name,
        createdAt: firebase.firestore.FieldValue.serverTimestamp(),
        updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
    });
    // 新しく作った授業のidを返す
    return docRef.id;
}

// 選択中の授業の課題(tasks)をリアルタイムで監視して、更新などがあればすぐに同期するようにしている
// 課題のセクションの中身を空にする関数
function clearTasksUI(message) {
    // 課題データのキャッシュをからに
    tasksCache.clear();
    // tasksList(html上の)を空にして、何も表示しないようにしている
    tasksList.innerHTML = "";
    // 課題の情報のところにメッセージを表示するようにしてい
    tasksInfo.textContent = message;
    // 課題一覧のところのボタンが反応しないように設定
    addTaskbutton.disabled = true;
    // 今見ている課題を保存する変数をリセット
    selectedCourseId = null;

    // もし今購読している課題があるなら
    if (unsubscribeTasks) {
        unsubscribeTasks();
        // nullにして止める
        unsubscribeTasks = null;
    }
}

// 授業の課題をFirebaseからリアルタイムで監視して、常に更新し続けるための関数
// 引き値：ユーザーid,授業id,授業のタイトル
// 返り値：なし（監視するだけだから）
function subscribeTasksForCourse(uid, courseId, courseNameForLabel) {
    // もし今購読している課題があれば
    if (unsubscribeTasks) {
        unsubscribeTasks();
        // 購読用の変数をnullにして止める
        unsubscribeTasks = null;
    }

    // キャッシュをリセットする
    tasksCache.clear();
    // 課題の説明欄を表示しない
    tasksList.innerHTML = "";
    // courseIdを一旦保存
    selectedCourseId = courseId;

    // もしcourseIdが存在しないものだったら
    if (!courseId) {
        // エラーメッセージを表示して
        clearTasksUI("このコマは授業が未登録です");
        // 離脱
        return;
    }

    // 課題UIの部分を「授業名＋の課題」と表示してわかりやすく
    tasksInfo.textContent = `${courseNameForLabel || "授業"}の課題`;
    // 課題を追加できるようにしている
    addTaskbutton.disabled = false;

    // Firebase上で授業の課題を参照するためのインデックスを変数として用意している
    const tasksCol = db.collection("users").doc(uid).collection("courses").doc(courseId).collection("tasks").orderBy("dueAt", "asc");

    // さっき作った変数を利用して実際にFirebaseからデータをとってきているのがここの行
    // unsubscribeTasksという関数をとりあえず設定
    unsubscribeTasks = tasksCol.onSnapshot((snap) => {
        // 課題をFirebaseから送ってもらう。変更があったら追加で送ってもらうように設定。（変更された部分だけを処理）
        snap.docChanges().forEach((chg) => {
            // 課題のidを一旦保存
            const id = chg.doc.id;
            // もし課題を消したいときはキャッシュから消す
            if (chg.type === "removed") tasksCache.delete(id);
            // 消されなかったら上書きして保存する
            else tasksCache.set(id, chg.doc.data());
        });
        // 右側の課題の表示を更新する
        renderTasksList();
        // もし失敗したら
    }, (err) => {
        // エラーを出す
        showError(err?.message ?? "課題の読み込みに失敗しました");
    });
}

// 課題の締め切り日時を返す関数
function formatDue(dueAt) {
    if (!dueAt) return "締切なし";

    const dt = dueAt.toDate ? dueAt.toDate() : new Date(dueAt);

    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, "0");
    const d = String(dt.getDate()).padStart(2, "0");
    const hh = String(dt.getHours()).padStart(2, "0");
    const mm = String(dt.getMinutes()).padStart(2, "0");
    return `${y}/${m}/${d} ${hh}:${mm}`;
}

// 入力された文字がhtmlと解釈されるとハックされる可能性があるから、それの対策として事前に手を打っておく
function escapeHtml(s) {
    return String(s ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}

function renderTasksList() {
    const items = Array.from(tasksCache.entries())
        .map(([id, data]) => ({ id, ...data }))
        .sort((a, b) => {
            const at = a.dueAt?.toMillis ? a.dueAt.toMillis() : (a.dueAt ? new Date(a.dueAt).getTime() : Infinity);
            const bt = b.dueAt?.toMillis ? b.dueAt.toMillis() : (b.dueAt ? new Date(b.dueAt).getTime() : Infinity);
            return at - bt;
        });

    if (items.length === 0) {
        tasksList.innerHTML = `<div class="muted">課題はまだありません</div>`;
        return;
    }

    tasksList.innerHTML = items.map(t => {
        const title = escapeHtml(t.title ?? "(無題)");
        const due = escapeHtml(formatDue(t.dueAt));
        const status = t.status ?? "open";
        const detail = escapeHtml(t.detail ?? "");
        return `
      <div class="taskItem" data-task-id="${t.id}">
        <div class="taskTop">
          <div>
            <div class="taskTitle">${title}</div>
            <div class="taskMeta">締切：${due} ／ 状態：${status}</div>
          </div>
        </div>

        <div class="taskBtns">
          <button type="button" class="secondary" data-action="toggleDetail">詳細</button>
          <button type="button" data-action="toggleDone">${status === "done" ? "未完了に戻す" : "完了にする"}</button>
          <button type="button" class="danger" data-action="delete">削除</button>
        </div>

        <div class="taskDetail muted" data-detail hidden>${detail || "（詳細なし）"}</div>
      </div>
    `;
    }).join("");
    tasksList.querySelectorAll(".taskItem").forEach((el) => {
        const taskId = el.dataset.taskId;

        el.addEventListener("click", async (e) => {
            const btn = e.target.closest("button");
            if (!btn) return;

            const action = btn.dataset.action;
            if (!action) return;

            if (action === "toggleDetail") {
                const detailEl = el.querySelector("[data-detail]");
                detailEl.hidden = !detailEl.hidden;
                return;
            }

            if (action === "delete") {
                await deleteTask(taskId);
                return;
            }
            if (action === "toggleDone") {
                await toggleTaskDone(taskId);
                return;
            }
        }, { once: true });

    });
}

async function addTask() {
    showError("");
    const user = auth.currentUser;
    if (!user) return;

    if (!selectedCourseId) {
        showError("授業が未登録です。先にこのコマを保存してください。");
        return;
    }

    const title = taskTitle.value.trim();
    if (!title) {
        showError("課題タイトルを入力してください。");
        return;
    }

    let dueAt = null;
    if (taskDue.value) {
        const dt = new Date(taskDue.value);
        if (!isNaN(dt.getTime())) {
            dueAt = firebase.firestore.Timestamp.fromDate(dt);
        }
    }

    const detail = taskDetail.value.trim();

    const tasksCol = db.collection("users").doc(user.uid)
        .collection("courses").doc(selectedCourseId)
        .collection("tasks");

    await tasksCol.add({
        title,
        dueAt,
        detail,
        status: "open",
        createdAt: firebase.firestore.FieldValue.serverTimestamp(),
        updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
    });


    taskTitle.value = "";
    taskDue.value = "";
    taskDetail.value = "";
}

async function deleteTask(taskId) {
    showError("");
    const user = auth.currentUser;
    if (!user) return;
    if (!selectedCourseId) return;

    const ref = db.collection("users").doc(user.uid)
        .collection("courses").doc(selectedCourseId)
        .collection("tasks").doc(taskId);

    await ref.delete();
}

async function toggleTaskDone(taskId) {
    showError("");
    const user = auth.currentUser;
    if (!user) return;
    if (!selectedCourseId) return;

    const data = tasksCache.get(taskId);
    const current = data?.status ?? "open";
    const next = current === "done" ? "open" : "done";

    const ref = db.collection("users").doc(user.uid)
        .collection("courses").doc(selectedCourseId)
        .collection("tasks").doc(taskId);

    await ref.set({
        status: next,
        updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
    }, { merge: true });
}

addTaskbutton.addEventListener("click", async () => {
    try {
        await addTask();
    } catch (err) {
        showError(err?.message ?? "課題の追加に失敗しました");
    }
});

saveCallButton.addEventListener("click", async () => {
    if (!selectedKey) return;
    showError("");

    const user = auth.currentUser;
    if (!user) return;

    const courseName = editName.value.trim();
    const start = editStart.value;
    const end = editEnd.value;

    if (start && end && start >= end) {
        showError("開始時刻が終了時刻以上になっています。");
        return;
    }

    const [day, periodString] = selectedKey.split("_");
    const period = Number(periodString);

    const courseId = await getOrCreateCourseId(user.uid, courseName);

    const ref = db.collection("users").doc(user.uid)
        .collection("timetableCells").doc(selectedKey);

    try {
        await ref.set({
            day,
            period,
            start,
            end,


            courseId: courseId || null,
            courseName: courseName || "",

            updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
        }, { merge: true });


        subscribeTasksForCourse(user.uid, courseId, courseName);

    } catch (err) {
        showError(err?.message ?? "保存に失敗しました");
    }
});

function selectCell(key) {
    selectedKey = key;

    document.querySelectorAll(".timetableCell.active").forEach(el => el.classList.remove("active"));
    const [day, period] = key.split("_");
    const button = findCellButton(day, Number(period));
    if (button) button.classList.add("active");

    const dayLabel = days.find(d => d.key === day)?.label ?? day;
    selectedLabel.textContent = `${dayLabel}曜${period}限`;

    setEditorEnabled(true);
    fillEditorFromCache(key);

    const user = auth.currentUser;
    if (user) {
        const data = callCache.get(key);
        const courseId = data?.courseId ?? null;
        const courseName = data?.courseName ?? (data?.name ?? "");
        subscribeTasksForCourse(user.uid, courseId, courseName);
    }
}

auth.onAuthStateChanged((user) => {
    showError("");
    if (!user) {
        window.location.href = "./index.html";
        return;
    }

    statusEl.textContent = "ログイン済み";
    userEmailEl.textContent = user.email ?? "(emailなし)";

    buildGridOnce();
    subscribeTimetableCells(user.uid);

    clearTasksUI("左のコマを選択すると、その授業の課題が表示されます");
});
