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
    { key: "sun", label: "日" },
];

const periods = [1, 2, 3, 4, 5, 6];

const callCache = new Map();
let selectedKey = null;
let unsubscribeCells = null;

function setEditorEnabled(enabled) {
    editName.disabled = !enabled;
    editStart.disabled = !enabled;
    editEnd.disabled = !enabled;
    saveCallButton.disabled = !enabled;
    deleteCallButton.disabled = !enabled;
}

function findCellButton(day, period) {
    return document.querySelector(`.timetable-cell[data-day="${day}"][data-period="${period}"]`);
}

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

    button.classList.remove("filled");
    nameEl.textContent = name || "(授業名なし)";
    timeEl.textcontent = (start && end) ? `${start}-${end}` : (start || end ? `時間：${start}${end ? "-" + end : ""}` : "");
}

function fillEditorFromCache(key){
    const data = callCache.get(key)
}