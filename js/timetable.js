// ===== DOM =====
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

// ===== UI Utils =====
function showError(message) {
    if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = "";
        return;
    }
    errorEl.hidden = false;
    errorEl.textContent = message;
}

const days = [
    { key: "mon", label: "月" },
    { key: "tue", label: "火" },
    { key: "wed", label: "水" },
    { key: "thu", label: "木" },
    { key: "fri", label: "金" },
    { key: "sat", label: "土" },
];
const periods = [1, 2, 3, 4, 5, 6];

const callCache = new Map(); // key: "mon_1" => { courseName, start, end, ... }
let selectedKey = null;

function setEditorEnabled(enabled) {
    editName.disabled = !enabled;
    editStart.disabled = !enabled;
    editEnd.disabled = !enabled;
    saveCallButton.disabled = !enabled;
    deleteCallButton.disabled = !enabled;
}

function findCellButton(day, period) {
    return document.querySelector(`.timetableCell[data-day="${day}"][data-period="${period}"]`);
}

function updateCellUIBykey(key) {
    const [day, periodString] = key.split("_");
    const period = Number(periodString);
    const button = findCellButton(day, period);
    if (!button) return;

    const nameEl = button.querySelector(".name");
    const timeEl = button.querySelector(".time");

    const data = callCache.get(key);
    const name = (data?.courseName ?? "").trim();
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
    timeEl.textContent =
        (start && end) ? `${start}-${end}` :
            (start || end ? `時間：${start}${end ? "-" + end : ""}` : "");
}

function fillEditorFromCache(key) {
    const data = callCache.get(key);
    editName.value = data?.courseName ?? "";
    editStart.value = data?.start ?? "";
    editEnd.value = data?.end ?? "";
}

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
    const cellData = callCache.get(key);
    const courseName = (cellData?.courseName ?? cellData?.name ?? "").trim();

    if (!courseName) {
        clearTasksUI("授業を選択してください（授業名を保存すると課題が使えます）");
    } else {
        loadTasksForCell(key, courseName).catch(e => showError(e.message));
    }
}

function buildGridOnce() {
    if (timetableGridWrap.dataset.built === "1") return;

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

            const timeSpan = document.createElement("div");
            timeSpan.className = "time";

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

// ===== API helpers =====
async function apiGetCells() {
    const res = await fetch("./api/timetable_get.php", { credentials: "same-origin" });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || "時間割の取得に失敗しました");
    return json.cells || {};
}

async function apiSaveCell({ day, period, courseName, start, end }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("day", day);
    fd.append("period", String(period));
    fd.append("courseName", courseName);
    if (start) fd.append("start", start);
    if (end) fd.append("end", end);

    const res = await fetch("./api/timetable_save.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || "保存に失敗しました");
}

async function apiDeleteCell({ day, period }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("day", day);
    fd.append("period", String(period));

    const res = await fetch("./api/timetable_delete.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || "削除に失敗しました");
}

async function loadAndRenderAllCells() {
    timetableMessage.textContent = "読み込み中...";
    const cells = await apiGetCells();

    callCache.clear();
    for (const [key, data] of Object.entries(cells)) {
        // data: { day, period, courseName, start, end }
        callCache.set(key, data);
    }

    // 全セルを再描画
    periods.forEach(p => {
        days.forEach(d => {
            updateCellUIBykey(`${d.key}_${p}`);
        });
    });

    timetableMessage.textContent = "";
    if (selectedKey) fillEditorFromCache(selectedKey);
}

// ===== Events =====
saveCallButton.addEventListener("click", async () => {
    if (!selectedKey) return;
    showError("");

    const courseName = editName.value.trim();
    const start = editStart.value;
    const end = editEnd.value;

    if (start && end && start >= end) {
        showError("開始時刻が終了時刻以上になっています。");
        return;
    }

    const [day, periodString] = selectedKey.split("_");
    const period = Number(periodString);

    try {
        // 「全部空」なら削除として扱う（UX的に便利）
        if (!courseName && !start && !end) {
            await apiDeleteCell({ day, period });
        } else {
            if (!courseName) {
                showError("授業名を入力してください（空にするなら削除を押すか、全項目を空にして保存してください）。");
                return;
            }
            await apiSaveCell({ day, period, courseName, start, end });
        }

        await loadAndRenderAllCells();
        // 保存直後の編集欄再同期
        fillEditorFromCache(selectedKey);
    } catch (e) {
        showError(e?.message || "保存に失敗しました");
    }
});

deleteCallButton.addEventListener("click", async () => {
    if (!selectedKey) return;
    showError("");

    const [day, periodString] = selectedKey.split("_");
    const period = Number(periodString);

    try {
        await apiDeleteCell({ day, period });
        editName.value = "";
        editStart.value = "";
        editEnd.value = "";
        await loadAndRenderAllCells();
    } catch (e) {
        showError(e?.message || "削除に失敗しました");
    }
});

if (logoutButton) {
    logoutButton.addEventListener("click", () => {
        // PHP側の logout.php を呼ぶ
        window.location.href = "./logout.php";
    });
}

// ===== init =====
document.addEventListener("DOMContentLoaded", async () => {
    showError("");

    statusEl.textContent = "ログイン済み";
    if (userEmailEl) userEmailEl.textContent = window.USER_EMAIL || "";

    buildGridOnce();

    try {
        await loadAndRenderAllCells();
    } catch (e) {
        timetableMessage.textContent = "";
        showError(e?.message || "時間割の読み込みに失敗しました");
    }
});

async function apiTasksList(cellKey) {
    const res = await fetch(`./api/tasks_list.php?cell=${encodeURIComponent(cellKey)}`, {
        credentials: "same-origin",
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || "課題の取得に失敗しました");
    return json.tasks || [];
}

async function apiTaskAdd({ cellKey, title, due, detail }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("cell", cellKey);
    fd.append("title", title);
    if (due) fd.append("due", due);       // "YYYY-MM-DDTHH:MM"
    if (detail) fd.append("detail", detail);

    const res = await fetch("./api/task_add.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || "課題の追加に失敗しました");
    return json.task_id;
}

function renderTasks(tasks) {
    if (!tasks.length) {
        tasksList.innerHTML = `<div class="muted">課題はまだありません</div>`;
        return;
    }

    tasksList.innerHTML = tasks.map(t => {
        const due = t.due_at ? t.due_at.replace("T", " ") : "締切なし";
        const title = String(t.title ?? "(無題)");
        const detail = String(t.detail ?? "");
        return `
      <div class="taskItem">
        <div class="taskTop">
          <div>
            <div class="taskTitle">${title}</div>
            <div class="taskMeta">締切：${due} ／ 状態：${t.status}</div>
          </div>
        </div>
        <div class="taskDetail muted">${detail || "（詳細なし）"}</div>
      </div>
    `;
    }).join("");
}

function clearTasksUI(msg) {
    tasksInfo.textContent = msg;
    tasksList.innerHTML = "";
    addTaskbutton.disabled = true;
}

async function loadTasksForCell(cellKey, courseLabel) {
    tasksInfo.textContent = `${courseLabel || "授業"}の課題`;
    addTaskbutton.disabled = false;

    const tasks = await apiTasksList(cellKey);
    renderTasks(tasks);
}

addTaskbutton.addEventListener("click", async () => {
    try {
        if (!selectedKey) return;

        const title = taskTitle.value.trim();
        if (!title) {
            showError("課題タイトルを入力してください。");
            return;
        }

        await apiTaskAdd({
            cellKey: selectedKey,
            title,
            due: taskDue.value,          // datetime-local
            detail: taskDetail.value.trim(),
        });

        taskTitle.value = "";
        taskDue.value = "";
        taskDetail.value = "";

        // 再読み込み
        const cellData = callCache.get(selectedKey);
        const courseName = (cellData?.courseName ?? cellData?.name ?? "").trim();
        await loadTasksForCell(selectedKey, courseName);
    } catch (e) {
        showError(e.message || "課題の追加に失敗しました");
    }
});
