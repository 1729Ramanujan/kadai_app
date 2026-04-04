// ===== DOM =====
const errorEl = document.getElementById("error");

const timetableGridWrap = document.getElementById("timetableGridWrap");
const timetableMessage = document.getElementById("timetableMessage");

const selectedLabel = document.getElementById("selectedLabel");
const editName = document.getElementById("editName");
const editRoom = document.getElementById("editRoom");
const saveCallButton = document.getElementById("saveCallButton");
const deleteCallButton = document.getElementById("deleteCallButton");

const layoutEl = document.getElementById("layout");
const panelEl = document.getElementById("sidePanel");
const overlayEl = document.getElementById("panelOverlay");
const closeBtn = document.getElementById("panelClose");

// 課題UI
const tasksInfo = document.getElementById("tasksInfo");
const tasksList = document.getElementById("tasksList");
const addTaskbutton =
    document.getElementById("addTaskbutton") ||
    document.getElementById("addTaskButton");
const taskTitle = document.getElementById("taskTitle");
const taskDue = document.getElementById("taskDue");
const taskDetail = document.getElementById("taskDetail");

// 追加コマUI（存在すれば使う。なくても動く）
const slotsInfo = document.getElementById("slotsInfo");
const slotsList = document.getElementById("slotsList");
const addSlotButton = document.getElementById("addSlotButton");
const addSlotDay = document.getElementById("addSlotDay");
const addSlotPeriod = document.getElementById("addSlotPeriod");
const deleteCourseButton = document.getElementById("deleteCourseButton");

// ===== 定数 =====
const days = [
    { key: "mon", label: "月" },
    { key: "tue", label: "火" },
    { key: "wed", label: "水" },
    { key: "thu", label: "木" },
    { key: "fri", label: "金" },
    { key: "sat", label: "土" },
];

const periods = [1, 2, 3, 4, 5, 6];
const periodTimes = window.PERIOD_TIMES || {};
const callCache = new Map(); // key: "mon_1" => { courseId, courseName, room, openTaskCount, nearestDueAt, ... }

let selectedKey = null;

// ===== UI Utils =====
function showError(message) {
    if (!errorEl) return;

    if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = "";
        return;
    }

    errorEl.hidden = false;
    errorEl.textContent = message;
}

function setEditorEnabled(enabled) {
    if (editName) editName.disabled = !enabled;
    if (editRoom) editRoom.disabled = !enabled;
    if (saveCallButton) saveCallButton.disabled = !enabled;
    if (deleteCallButton) deleteCallButton.disabled = !enabled;
}

function findCellButton(day, period) {
    return document.querySelector(
        `.timetableCell[data-day="${day}"][data-period="${period}"]`
    );
}

function getSelectedCellData() {
    return selectedKey ? callCache.get(selectedKey) || null : null;
}

function getSelectedCourseId() {
    const data = getSelectedCellData();
    const id = Number(data?.courseId || 0);
    return id > 0 ? id : null;
}

function getDayLabel(dayKey) {
    return days.find((d) => d.key === dayKey)?.label ?? dayKey;
}

function splitCellKey(key) {
    if (!/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/.test(String(key || ""))) {
        return null;
    }
    const [day, periodText] = String(key).split("_");
    return {
        day,
        period: Number(periodText),
    };
}

function fillEditorFromCache(key) {
    const data = callCache.get(key);
    if (editName) editName.value = data?.courseName ?? "";
    if (editRoom) editRoom.value = data?.room ?? "";
}

function clearTaskInputs() {
    if (taskTitle) taskTitle.value = "";
    if (taskDue) taskDue.value = "";
    if (taskDetail) taskDetail.value = "";
}

function clearTasksUI(msg) {
    if (tasksInfo) {
        tasksInfo.textContent = msg;
    }
    if (tasksList) {
        tasksList.innerHTML = "";
    }
    if (addTaskbutton) {
        addTaskbutton.disabled = true;
    }
}

function openPanel() {
    if (layoutEl) layoutEl.classList.add("panel-open");
    if (panelEl) panelEl.setAttribute("aria-hidden", "false");
    if (overlayEl) overlayEl.hidden = false;
}

function closePanel() {
    if (layoutEl) layoutEl.classList.remove("panel-open");
    if (panelEl) panelEl.setAttribute("aria-hidden", "true");
    if (overlayEl) overlayEl.hidden = true;

    selectedKey = null;

    document
        .querySelectorAll(".timetableCell.active")
        .forEach((el) => el.classList.remove("active"));

    if (selectedLabel) {
        selectedLabel.textContent = "左のセルを選択してください";
        selectedLabel.hidden = false;
    }

    setEditorEnabled(false);

    if (deleteCourseButton) {
        deleteCourseButton.disabled = true;
        deleteCourseButton.hidden = true;
    }
    if (deleteCallButton) {
        deleteCallButton.hidden = false;
        deleteCallButton.disabled = true;
    }
    if (addSlotButton) addSlotButton.disabled = true;
    if (addSlotDay) addSlotDay.disabled = true;
    if (addSlotPeriod) addSlotPeriod.disabled = true;

    clearTaskInputs();
    clearTasksUI("授業を選択してください");
    renderLinkedSlots(null);
}

function escapeHtml(s) {
    return String(s ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function pad2(n) {
    return String(n).padStart(2, "0");
}

function isCompletedStatus(status) {
    const s = String(status ?? "").trim().toLowerCase();
    return ["done", "completed", "finished"].includes(s);
}

function formatMonthDayTime(date) {
    return `${date.getMonth() + 1}/${date.getDate()} ${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
}

function formatFullDueText(dueText) {
    if (!dueText) return "締切なし";

    const due = new Date(dueText);
    if (Number.isNaN(due.getTime())) return "締切なし";

    return `${due.getFullYear()}-${pad2(due.getMonth() + 1)}-${pad2(due.getDate())} ${pad2(due.getHours())}:${pad2(due.getMinutes())}`;
}

function sortCellKeys(a, b) {
    const sa = splitCellKey(a);
    const sb = splitCellKey(b);
    if (!sa || !sb) return String(a).localeCompare(String(b), "ja");

    const dayOrderA = days.findIndex((d) => d.key === sa.day);
    const dayOrderB = days.findIndex((d) => d.key === sb.day);

    if (dayOrderA !== dayOrderB) return dayOrderA - dayOrderB;
    return sa.period - sb.period;
}

function formatCellLabel(cellKey) {
    const s = splitCellKey(cellKey);
    if (!s) return cellKey;
    return `${getDayLabel(s.day)}曜${s.period}限`;
}

function getCourseSlotsFromCache(courseId) {
    if (!courseId) return [];

    return Array.from(callCache.entries())
        .filter(([, data]) => Number(data?.courseId || 0) === Number(courseId))
        .map(([cellKey, data]) => ({
            cellKey,
            day: data?.day || splitCellKey(cellKey)?.day || "",
            period: Number(data?.period || splitCellKey(cellKey)?.period || 0),
        }))
        .sort((a, b) => sortCellKeys(a.cellKey, b.cellKey));
}

function updateDeleteButtons() {
    if (!deleteCallButton) return;

    const courseId = getSelectedCourseId();
    const slots = getCourseSlotsFromCache(courseId);

    if (!courseId) {
        deleteCallButton.disabled = false;
        if (deleteCourseButton) {
            deleteCourseButton.hidden = true;
            deleteCourseButton.disabled = true;
        }
        return;
    }

    if (deleteCourseButton) {
        deleteCourseButton.hidden = false;
        deleteCourseButton.disabled = false;
    }

    deleteCallButton.hidden = slots.length <= 1;
    deleteCallButton.disabled = slots.length <= 1;

}

function renderLinkedSlots(courseId) {
    if (slotsInfo) slotsInfo.textContent = "";
    if (slotsList) slotsList.innerHTML = "";

    if (addSlotButton) addSlotButton.disabled = !courseId;
    if (addSlotDay) addSlotDay.disabled = !courseId;
    if (addSlotPeriod) addSlotPeriod.disabled = !courseId;

    if (deleteCourseButton) {
        deleteCourseButton.hidden = !courseId;
        deleteCourseButton.disabled = !courseId;
    }

    if (!courseId) {
        if (slotsInfo) slotsInfo.textContent = "登録コマはありません";
        return;
    }

    const slots = getCourseSlotsFromCache(courseId);

    if (slotsInfo) slotsInfo.textContent = "";

    if (!slotsList) return;

    if (!slots.length) {
        slotsList.innerHTML = `<div class="muted">登録コマはありません</div>`;
        return;
    }

    slotsList.innerHTML = `<p class="slotLabels">${slots
        .map((slot) => `<span class="slotLabel${slot.cellKey === selectedKey ? " is-current" : ""}">${escapeHtml(formatCellLabel(slot.cellKey))}</span>`)
        .join("")}</p>`;
}

function initOptionalSlotInputs() {
    if (addSlotDay && addSlotDay.tagName === "SELECT" && addSlotDay.options.length === 0) {
        addSlotDay.innerHTML = days
            .map((d) => `<option value="${escapeHtml(d.key)}">${escapeHtml(d.label)}</option>`)
            .join("");
    }

    if (addSlotPeriod && addSlotPeriod.tagName === "SELECT" && addSlotPeriod.options.length === 0) {
        addSlotPeriod.innerHTML = periods
            .map((p) => `<option value="${p}">${p}限</option>`)
            .join("");
    }
}

// ===== 緊急度 =====
function getUrgencyLevel(data) {
    const openCount = Number(data?.openTaskCount || 0);
    if (openCount <= 0) return 0;

    const dueText = data?.nearestDueAt;
    if (!dueText) return 1;

    const due = new Date(dueText);
    if (Number.isNaN(due.getTime())) return 1;

    const diffMs = due.getTime() - Date.now();
    const diffHours = diffMs / (1000 * 60 * 60);

    if (diffHours < 0) return 6;
    if (diffHours <= 24) return 5;
    if (diffHours <= 48) return 4;
    if (diffHours <= 72) return 3;
    if (diffHours <= 168) return 2;
    return 1;
}

function getTaskUrgencyLevel(task) {
    if (isCompletedStatus(task?.status)) return 0;

    const dueText = task?.due_at;
    if (!dueText) return 1;

    const due = new Date(dueText);
    if (Number.isNaN(due.getTime())) return 1;

    const diffMs = due.getTime() - Date.now();
    const diffHours = diffMs / (1000 * 60 * 60);

    if (diffHours < 0) return 6;
    if (diffHours <= 24) return 5;
    if (diffHours <= 48) return 4;
    if (diffHours <= 72) return 3;
    if (diffHours <= 168) return 2;
    return 1;
}

function getTaskCountdownText(task) {
    if (isCompletedStatus(task?.status)) return "完了";

    const dueText = task?.due_at;
    if (!dueText) return "締切なし";

    const due = new Date(dueText);
    if (Number.isNaN(due.getTime())) return "締切なし";

    const now = Date.now();
    const diffMs = due.getTime() - now;

    if (diffMs < 0) {
        const overdueMs = Math.abs(diffMs);
        const overdueMinutes = Math.max(1, Math.ceil(overdueMs / (1000 * 60)));
        const overdueHours = Math.floor(overdueMinutes / 60);
        const overdueDays = Math.floor(overdueHours / 24);

        if (overdueDays >= 1) return `${overdueDays}日超過`;
        if (overdueHours >= 1) return `${overdueHours}時間超過`;
        return `${overdueMinutes}分超過`;
    }

    const totalMinutes = Math.max(1, Math.ceil(diffMs / (1000 * 60)));
    const totalHours = Math.floor(totalMinutes / 60);

    if (diffMs <= 24 * 60 * 60 * 1000) {
        if (totalHours >= 1) {
            const remainMinutes = totalMinutes % 60;

            if (totalHours <= 6 && remainMinutes > 0) {
                return `あと${totalHours}時間${remainMinutes}分`;
            }
            return `あと${totalHours}時間`;
        }
        return `あと${totalMinutes}分`;
    }

    if (diffMs <= 48 * 60 * 60 * 1000) {
        const daysCount = Math.floor(totalHours / 24);
        const remainHours = totalHours % 24;
        return remainHours > 0
            ? `あと${daysCount}日${remainHours}時間`
            : `あと${daysCount}日`;
    }

    if (diffMs <= 7 * 24 * 60 * 60 * 1000) {
        const daysCount = Math.ceil(diffMs / (24 * 60 * 60 * 1000));
        return `あと${daysCount}日`;
    }

    return formatMonthDayTime(due);
}

function getTaskCountdownClass(task) {
    if (isCompletedStatus(task?.status)) return "countdown-done";

    const dueText = task?.due_at;
    if (!dueText) return "countdown-neutral";

    const due = new Date(dueText);
    if (Number.isNaN(due.getTime())) return "countdown-neutral";

    const urgency = getTaskUrgencyLevel(task);
    return urgency > 0 ? `countdown-${urgency}` : "countdown-neutral";
}

// ===== 時間割UI =====
function updateCellUIByKey(key) {
    const s = splitCellKey(key);
    if (!s) return;

    const button = findCellButton(s.day, s.period);
    if (!button) return;

    const nameEl = button.querySelector(".name");
    const timeEl = button.querySelector(".time");

    const data = callCache.get(key);
    const name = (data?.courseName ?? "").trim();
    const room = (data?.room ?? "").trim();

    button.classList.remove(
        "filled",
        "has-open-task",
        "urgency-1",
        "urgency-2",
        "urgency-3",
        "urgency-4",
        "urgency-5",
        "urgency-6"
    );

    if (!name && !room) {
        if (nameEl) nameEl.textContent = "";
        if (timeEl) timeEl.textContent = "";
        return;
    }

    button.classList.add("filled");
    if (nameEl) nameEl.textContent = name || "(授業名なし)";
    if (timeEl) timeEl.textContent = room || "";

    const urgency = getUrgencyLevel(data);
    if (urgency > 0) {
        button.classList.add("has-open-task", `urgency-${urgency}`);
    }
}

function setActiveCellUI(key) {
    document
        .querySelectorAll(".timetableCell.active")
        .forEach((el) => el.classList.remove("active"));

    const s = splitCellKey(key);
    if (!s) return;

    const button = findCellButton(s.day, s.period);
    if (button) button.classList.add("active");
}

async function refreshSelectedPanel() {
    if (!selectedKey) return;

    setActiveCellUI(selectedKey);
    setEditorEnabled(true);
    fillEditorFromCache(selectedKey);
    updateDeleteButtons();

    const cellData = getSelectedCellData();
    const courseId = Number(cellData?.courseId || 0) || null;
    const courseName = (cellData?.courseName ?? "").trim();

    renderLinkedSlots(courseId);

    // 変更後
    if (!courseName && !courseId) {
        if (selectedLabel) {
            selectedLabel.textContent = "授業を追加してください";
            selectedLabel.hidden = false;
        }
        clearTasksUI("授業を保存すると課題が使えます");
        return;
    }

    // 授業がある場合は非表示
    if (selectedLabel) selectedLabel.hidden = true;

    if (tasksInfo) {
        tasksInfo.textContent = `${courseName || "授業"}の課題`;
    }
    if (addTaskbutton) {
        addTaskbutton.disabled = false;
    }

    const tasks = await apiTasksList({
        courseId,
        cellKey: selectedKey,
    });
    renderTasks(tasks);
}

async function selectCell(key, options = {}) {
    const { toggleIfSame = true } = options;

    if (toggleIfSame && selectedKey === key && layoutEl?.classList.contains("panel-open")) {
        closePanel();
        return;
    }

    openPanel();
    selectedKey = key;
    await refreshSelectedPanel();
}

function buildGridOnce() {
    if (!timetableGridWrap || timetableGridWrap.dataset.built === "1") return;

    const table = document.createElement("table");
    table.className = "table";

    const thead = document.createElement("thead");
    const headRow = document.createElement("tr");

    const corner = document.createElement("th");
    corner.textContent = "限";
    headRow.appendChild(corner);

    days.forEach((d) => {
        const th = document.createElement("th");
        th.textContent = d.label;
        headRow.appendChild(th);
    });

    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement("tbody");

    periods.forEach((p) => {
        const tr = document.createElement("tr");

        const th = document.createElement("th");
        th.className = "periodHead";

        const numDiv = document.createElement("div");
        numDiv.className = "periodNum";
        numDiv.textContent = `${p}限`;

        const periodTimeDiv = document.createElement("div");
        periodTimeDiv.className = "periodTime";

        const start = periodTimes?.[p]?.start || "";
        const end = periodTimes?.[p]?.end || "";
        periodTimeDiv.textContent = start && end ? `${start}–${end}` : "";

        th.appendChild(numDiv);
        th.appendChild(periodTimeDiv);
        tr.appendChild(th);

        days.forEach((d) => {
            const td = document.createElement("td");

            const button = document.createElement("button");
            button.type = "button";
            button.className = "timetableCell";
            button.dataset.day = d.key;
            button.dataset.period = String(p);

            const nameDiv = document.createElement("div");
            nameDiv.className = "name";

            const timeDiv = document.createElement("div");
            timeDiv.className = "time";

            button.appendChild(nameDiv);
            button.appendChild(timeDiv);

            button.addEventListener("click", async () => {
                try {
                    await selectCell(`${d.key}_${p}`);
                } catch (e) {
                    showError(e?.message || "セルの読み込みに失敗しました");
                }
            });

            td.appendChild(button);
            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    timetableGridWrap.appendChild(table);
    timetableGridWrap.dataset.built = "1";

    setEditorEnabled(false);
    initOptionalSlotInputs();
}

// ===== API helpers =====
async function parseJsonResponse(res) {
    const raw = await res.text();

    let json;
    try {
        json = JSON.parse(raw);
    } catch {
        throw new Error(
            `サーバー応答がJSONではありません (HTTP ${res.status})\n${raw.slice(0, 300)}`
        );
    }

    if (!json.ok) {
        throw new Error(json.error || "処理に失敗しました");
    }

    return json;
}

async function apiGetCells() {
    const res = await fetch("./timetable_get.php", {
        credentials: "same-origin",
    });
    const json = await parseJsonResponse(res);
    return json.cells || {};
}

async function apiCourseSave({ day, period, courseId, courseName, room }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("day", day);
    fd.append("period", String(period));
    fd.append("courseName", courseName);
    if (room) fd.append("room", room);
    if (courseId) fd.append("course_id", String(courseId));

    const res = await fetch("./course_save.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    return await parseJsonResponse(res);
}

async function apiCourseSlotAdd({ courseId, day, period }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("course_id", String(courseId));
    fd.append("day", day);
    fd.append("period", String(period));

    const res = await fetch("./course_slot_add.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    return await parseJsonResponse(res);
}

async function apiCourseSlotDelete({ courseId, day, period }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("course_id", String(courseId));
    fd.append("day", day);
    fd.append("period", String(period));

    const res = await fetch("./course_slot_delete.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    return await parseJsonResponse(res);
}

async function apiCourseDelete({ courseId }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    fd.append("course_id", String(courseId));

    const res = await fetch("./course_delete.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    return await parseJsonResponse(res);
}

async function apiTasksList({ courseId, cellKey }) {
    const url = new URL("../tasks/tasks_list.php", window.location.href);

    if (courseId) {
        url.searchParams.set("course_id", String(courseId));
    } else if (cellKey) {
        url.searchParams.set("cell", cellKey);
    } else {
        throw new Error("courseId or cellKey is required");
    }

    const res = await fetch(url.toString(), {
        credentials: "same-origin",
    });
    const json = await parseJsonResponse(res);
    return json.tasks || [];
}

async function apiTaskAdd({ courseId, cellKey, title, due, detail }) {
    const fd = new FormData();
    fd.append("csrf", window.CSRF_TOKEN || "");
    if (courseId) fd.append("course_id", String(courseId));
    if (cellKey) fd.append("cell", cellKey);
    fd.append("title", title);
    if (due) fd.append("due", due);
    if (detail) fd.append("detail", detail);

    const res = await fetch("../tasks/task_add.php", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const json = await parseJsonResponse(res);
    return json.task_id;
}

// ===== Data -> UI =====
async function loadAndRenderAllCells() {
    if (timetableMessage) {
        timetableMessage.textContent = "読み込み中...";
    }

    const cells = await apiGetCells();

    callCache.clear();
    for (const [key, data] of Object.entries(cells)) {
        callCache.set(key, data);
    }

    periods.forEach((p) => {
        days.forEach((d) => {
            updateCellUIByKey(`${d.key}_${p}`);
        });
    });

    if (timetableMessage) {
        timetableMessage.textContent = "";
    }

    if (selectedKey) {
        await refreshSelectedPanel();
    }
}

function getStatusLabel(status) {
    const s = String(status ?? "").trim().toLowerCase();

    if (s === "done") return "完了";
    if (s === "open") return "未完了";

    return status ? String(status) : "未設定";
}

function renderTasks(tasks) {
    if (!tasksList) return;

    if (!tasks.length) {
        tasksList.innerHTML = `<div class="muted">課題はまだありません</div>`;
        return;
    }

    tasksList.innerHTML = tasks
        .map((t) => {
            const due = t.due_at ? formatFullDueText(t.due_at) : "締切なし";
            const title = escapeHtml(t.title ?? "(無題)");
            const detail = escapeHtml(t.detail ?? "");
            const statusRaw = t.status ?? "未設定";
            const status = escapeHtml(getStatusLabel(statusRaw));
            const shortDetail =
                detail.length > 140 ? detail.slice(0, 140) + "…" : detail;

            const countdownText = getTaskCountdownText(t);
            const urgency = getTaskUrgencyLevel(t);
            const urgencyClass = urgency > 0 ? ` task-urgency-${urgency}` : "";
            const doneClass = isCompletedStatus(statusRaw) ? " task-done" : "";
            const countdownClass = getTaskCountdownClass(t);

            const cellParam = selectedKey
                ? `&cell=${encodeURIComponent(selectedKey)}`
                : "";

            return `
<div class="taskItem${urgencyClass}${doneClass}">
    <div class="taskTop">
        <div class="taskMain">
            <div class="taskTitleRow">
                <div class="taskTitle">${title}</div>
                <div class="taskCountdown ${countdownClass}">
                    ${escapeHtml(countdownText)}
                </div>
            </div>
            <div class="taskMeta">締切：${escapeHtml(due)} ／ 状態：${status}</div>
        </div>

        <div class="taskBtns">
            <a class="button small" href="../tasks/task.php?id=${encodeURIComponent(t.id)}${cellParam}">詳細</a>
        </div>
    </div>

    <div class="taskDetail">${shortDetail || "（詳細なし）"}</div>
</div>
`;
        })
        .join("");
}

// ===== Events =====
if (saveCallButton) {
    saveCallButton.addEventListener("click", async () => {
        if (!selectedKey) return;

        showError("");

        const courseName = editName?.value.trim() ?? "";
        const room = editRoom?.value.trim() ?? "";

        if (!courseName) {
            showError("授業名を入力してください。削除したい場合は削除ボタンを使ってください。");
            return;
        }

        const s = splitCellKey(selectedKey);
        if (!s) {
            showError("セル情報が不正です");
            return;
        }

        const currentCourseId = getSelectedCourseId();

        try {
            await apiCourseSave({
                day: s.day,
                period: s.period,
                courseId: currentCourseId,
                courseName,
                room,
            });

            await loadAndRenderAllCells();
            await refreshSelectedPanel();
        } catch (e) {
            showError(e?.message || "保存に失敗しました");
        }
    });
}

if (deleteCallButton) {
    deleteCallButton.addEventListener("click", async () => {
        if (!selectedKey) return;

        showError("");

        const s = splitCellKey(selectedKey);
        if (!s) {
            showError("セル情報が不正です");
            return;
        }

        const courseId = getSelectedCourseId();
        const courseName = (getSelectedCellData()?.courseName ?? "").trim();

        if (!courseId) {
            if (editName) editName.value = "";
            if (editRoom) editRoom.value = "";
            clearTaskInputs();
            clearTasksUI("授業を保存すると課題が使えます");
            return;
        }

        const slots = getCourseSlotsFromCache(courseId);

        try {
            if (deleteCourseButton) {
                if (!window.confirm(`「${courseName || "この授業"}」から ${formatCellLabel(selectedKey)} を外しますか？`)) {
                    return;
                }

                const json = await apiCourseSlotDelete({
                    courseId,
                    day: s.day,
                    period: s.period,
                });

                const nextKey =
                    json?.slots?.[0]?.cellKey && typeof json.slots[0].cellKey === "string"
                        ? json.slots[0].cellKey
                        : null;

                await loadAndRenderAllCells();

                if (nextKey) {
                    selectedKey = nextKey;
                    await refreshSelectedPanel();
                } else {
                    await refreshSelectedPanel();
                }
            } else {
                if (slots.length > 1) {
                    if (!window.confirm(`「${courseName || "この授業"}」から ${formatCellLabel(selectedKey)} だけを外しますか？`)) {
                        return;
                    }

                    const json = await apiCourseSlotDelete({
                        courseId,
                        day: s.day,
                        period: s.period,
                    });

                    const nextKey =
                        json?.slots?.[0]?.cellKey && typeof json.slots[0].cellKey === "string"
                            ? json.slots[0].cellKey
                            : selectedKey;

                    await loadAndRenderAllCells();
                    selectedKey = nextKey;
                    await refreshSelectedPanel();
                } else {
                    if (!window.confirm(`「${courseName || "この授業"}」を削除しますか？\n登録されている課題もすべて削除されます。`)) {
                        return;
                    }

                    await apiCourseDelete({ courseId });
                    await loadAndRenderAllCells();
                    await refreshSelectedPanel();
                }
            }
        } catch (e) {
            showError(e?.message || "削除に失敗しました");
        }
    });
}

if (deleteCourseButton) {
    deleteCourseButton.addEventListener("click", async () => {
        if (!selectedKey) return;

        showError("");

        const courseId = getSelectedCourseId();
        const courseName = (getSelectedCellData()?.courseName ?? "").trim();

        if (!courseId) {
            showError("削除対象の授業がありません");
            return;
        }

        if (!window.confirm(`「${courseName || "この授業"}」を丸ごと削除しますか？\n登録されている課題もすべて削除されます。`)) {
            return;
        }

        try {
            await apiCourseDelete({ courseId });
            await loadAndRenderAllCells();
            await refreshSelectedPanel();
        } catch (e) {
            showError(e?.message || "授業の削除に失敗しました");
        }
    });
}

if (addSlotButton) {
    addSlotButton.addEventListener("click", async () => {
        showError("");

        const courseId = getSelectedCourseId();
        if (!courseId) {
            showError("先に授業を保存してください");
            return;
        }

        const day = String(addSlotDay?.value ?? "");
        const period = Number(addSlotPeriod?.value ?? 0);

        if (!day || !period) {
            showError("追加する曜日・時限を選んでください");
            return;
        }

        try {
            await apiCourseSlotAdd({
                courseId,
                day,
                period,
            });

            const addedKey = `${day}_${period}`;
            await loadAndRenderAllCells();
            selectedKey = addedKey;
            await refreshSelectedPanel();
        } catch (e) {
            showError(e?.message || "コマの追加に失敗しました");
        }
    });
}

if (addTaskbutton) {
    addTaskbutton.addEventListener("click", async () => {
        if (!selectedKey) return;

        try {
            showError("");

            const title = taskTitle?.value.trim() ?? "";
            if (!title) {
                showError("課題タイトルを入力してください。");
                return;
            }

            const courseId = getSelectedCourseId();

            await apiTaskAdd({
                courseId,
                cellKey: selectedKey,
                title,
                due: taskDue?.value ?? "",
                detail: taskDetail?.value.trim() ?? "",
            });

            clearTaskInputs();
            await loadAndRenderAllCells();
            await refreshSelectedPanel();
        } catch (e) {
            showError(e?.message || "課題の追加に失敗しました");
        }
    });
}

if (closeBtn) {
    closeBtn.addEventListener("click", closePanel);
}

if (overlayEl) {
    overlayEl.addEventListener("click", closePanel);
}

// ===== init =====
document.addEventListener("DOMContentLoaded", async () => {
    showError("");
    buildGridOnce();

    try {
        await loadAndRenderAllCells();
    } catch (e) {
        if (timetableMessage) {
            timetableMessage.textContent = "";
        }
        showError(e?.message || "時間割の読み込みに失敗しました");
    }

    const params = new URLSearchParams(location.search);
    const cell = params.get("cell");

    if (cell && /^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/.test(cell)) {
        try {
            await selectCell(cell, { toggleIfSame: false });
        } catch (e) {
            showError(e?.message || "セルの選択に失敗しました");
        }
    }
});