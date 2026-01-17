// 最初にhtmlの要素を参照してjs上で操作できるように設定する
const statusEl = document.getElementById("status");
const errorEl = document.getElementById("error");

const taskView = document.getElementById("taskView");
const courseNameEl = document.getElementById("courseName");
const taskTitleEl = document.getElementById("taskTitle");
const taskDueEl = document.getElementById("taskDue");
const taskStatusEl = document.getElementById("taskStatus");
const taskDetailEl = document.getElementById("taskDetail");

const toggleDoneButton = document.getElementById("toggleDoneButton");
const deleteButton = document.getElementById("deleteButton");

// 以下の２つの関数はtimetable.jsでも導入したけど、もう一回定義し直す
// エラーが発生した時に表示部分を変える関数（messageに文字列を入れて表示を変更する）戻り値はない
function showError(message) {
    if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = "";
        return;
    }
    errorEl.hidden = false;
    errorEl.textContent = message;
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

// timetable.htmlで課題の詳細をクリックしたときに、遷移されるurlの部分には課題のアドレスと授業のアドレスが入っている
// ここではそこを参照することで、とってくるデータの管理をしている
const params = new URLSearchParams(location.search);
const courseId = params.get("courseId");
const taskId = params.get("taskId");

// ユーザーが今選択している課題がどれかを保存するための変数
let unsubscribeTask = null;
let currentTask = null;

auth.onAuthStateChanged(async (user) => {
    showError("");

    if (!user) {
        window.location.href = "./index.html";
        return;
    }

    if (!courseId || !taskId) {
        statusEl.textContent = "エラー";
        showError("URLにcourseIdまたはtaskIdがありません。");
        return;
    }

    statusEl.textContent = "読み込み中...";

    // 授業名表示（任意）
    try {
        const courseSnap = await db.collection("users").doc(user.uid)
            .collection("courses").doc(courseId).get();
        courseNameEl.textContent = courseSnap.exists ? (courseSnap.data().name ?? "-") : "-";
    } catch {
        courseNameEl.textContent = "-";
    }

    const ref = db.collection("users").doc(user.uid)
        .collection("courses").doc(courseId)
        .collection("tasks").doc(taskId);

    if (unsubscribeTask) unsubscribeTask();

    unsubscribeTask = ref.onSnapshot((doc) => {
        if (!doc.exists) {
            statusEl.textContent = "エラー";
            showError("課題が見つかりません（削除済みの可能性があります）。");
            taskView.hidden = true;
            return;
        }

        currentTask = doc.data();
        statusEl.textContent = "表示中";
        taskView.hidden = false;

        const title = currentTask.title ?? "(無題)";
        const due = formatDue(currentTask.dueAt);
        const status = currentTask.status ?? "open";
        const detail = currentTask.detail ?? "";

        taskTitleEl.textContent = title;
        taskDueEl.textContent = due;
        taskStatusEl.textContent = status;
        taskDetailEl.textContent = detail;

        toggleDoneButton.textContent = (status === "done") ? "未完了に戻す" : "完了にする";
    }, (err) => {
        statusEl.textContent = "エラー";
        showError(err?.message ?? "課題の読み込みに失敗しました");
    });
    toggleDoneButton.addEventListener("click", async () => {
        if (!currentTask) return;
        const now = currentTask.status ?? "open";
        const next = (now === "done") ? "open" : "done";
        try {
            await ref.set({
                status: next,
                updatedAt: firebase.firestore.FieldValue.serverTimestamp(),
            }, { merge: true });
        } catch (err) {
            showError(err?.message ?? "更新に失敗しました");
        }
    });

    deleteButton.addEventListener("click", async () => {
        try {
            await ref.delete();
            window.location.href = "./timetable.html";
        } catch (err) {
            showError(err?.message ?? "削除に失敗しました");
        }
    });
});