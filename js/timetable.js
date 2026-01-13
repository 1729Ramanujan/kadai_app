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
    return document.querySelector(`.timetableCell[data-day="${day}"][data-period="${period}"]`);
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
    timeEl.textContent = (start && end) ? `${start}-${end}` : (start || end ? `時間：${start}${end ? "-" + end : ""}` : "");
}

function fillEditorFromCache(key) {
    const data = callCache.get(key);
    editName.value = data?.name ?? "";
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
}

function buildGridOnce() {
    if (timetableGridWrap.dataset.built === "1") {
        return;
    }

    const table = document.createElement("table");
    table.className = "table";

    const thead = document.createElement("thead");
    const hr = document.createElement("hr");
    const corner = document.createElement("corner");
    corner.textContent = "限";
    hr.appendChild(corner);
    days.forEach(d => {
        const th = document.createElement("th");
        th.textContent = d.label;
        hr.appendChild(th);
    });
    thead.appendChild(hr);
    thead.appendChild(thead);

    const tbody = document.createElement("tbody");
    periods.forEach(d => {
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
            nameSpan.textContent = "未設定";

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

function subscribeTimetableCells(uid){
    if(unsubscribeCells) unsubscribeCells();

    const col =db.collection("users").doc(uid).collection("timetableCells");
    timetableMessage.textContent="読み込み中..."

    unsubscribeCells= col.onSnapshot((snap)=>{
        
    })
}

