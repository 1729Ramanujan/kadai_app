(() => {
    const cfg = window.__TASK__;
    if (!cfg) return;

    const taskId = cfg.taskId;
    const csrf = cfg.csrf;

    const $ = (id) => document.getElementById(id);

    async function parseJsonResponse(res) {
        const raw = await res.text();

        let json;
        try {
            json = JSON.parse(raw);
        } catch {
            const err = new Error(`サーバー応答がJSONではありません (HTTP ${res.status})\n` + raw.slice(0, 400));
            err.httpStatus = res.status;
            throw err;
        }

        if (!json.ok) {
            const err = new Error(json.error || "不明なエラー");
            err.httpStatus = res.status;
            throw err;
        }

        return json;
    }

    async function postForm(url, formData) {
        const res = await fetch(url, {
            method: "POST",
            body: formData,
            credentials: "same-origin",
        });
        return parseJsonResponse(res);
    }

    async function getJson(url) {
        const res = await fetch(url, {
            method: "GET",
            credentials: "same-origin",
        });
        return parseJsonResponse(res);
    }

    // ---------- AI Selection ----------
    const aiConfig = cfg.aiConfig || {};
    const providerLabels = aiConfig.providerLabels || {};
    const allowedModels = aiConfig.allowedModels || {};
    const defaults = aiConfig.defaults || { provider: "openai", model: "" };

    let selectedProvider = (cfg.aiSelection && cfg.aiSelection.provider) || defaults.provider || "openai";
    let selectedModel = (cfg.aiSelection && cfg.aiSelection.model) || defaults.model || "";

    const aiProviderButtons = document.querySelectorAll("[data-ai-provider]");
    const aiModelSelect = $("aiModelSelect");
    const aiSelectedInfo = $("aiSelectedInfo");

    function getProviderModels(provider) {
        const models = allowedModels[provider];
        return Array.isArray(models) ? models : [];
    }

    function getProviderLabel(provider) {
        return providerLabels[provider] || provider;
    }

    function resolveAiSelection(provider, model) {
        let nextProvider = typeof provider === "string" ? provider.trim() : "";
        if (!allowedModels[nextProvider]) {
            nextProvider = defaults.provider || Object.keys(allowedModels)[0] || "openai";
        }

        const models = getProviderModels(nextProvider);
        let nextModel = typeof model === "string" ? model.trim() : "";

        if (!models.includes(nextModel)) {
            nextModel = models[0] || "";
        }

        return {
            provider: nextProvider,
            model: nextModel,
        };
    }

    function renderProviderButtons() {
        aiProviderButtons.forEach((btn) => {
            const provider = btn.dataset.aiProvider || "";
            const isActive = provider === selectedProvider;
            btn.classList.add("button");
            btn.classList.toggle("secondary", !isActive);
            btn.classList.toggle("is-active", isActive);
            btn.setAttribute("aria-pressed", isActive ? "true" : "false");
        });
    }

    function renderModelOptions() {
        if (!aiModelSelect) return;

        const models = getProviderModels(selectedProvider);
        aiModelSelect.innerHTML = "";

        models.forEach((model) => {
            const option = document.createElement("option");
            option.value = model;
            option.textContent = model;
            if (model === selectedModel) {
                option.selected = true;
            }
            aiModelSelect.appendChild(option);
        });
    }

    function renderAiSelectionInfo() {
        if (!aiSelectedInfo) return;
        aiSelectedInfo.textContent = `現在の選択: ${getProviderLabel(selectedProvider)} / ${selectedModel}`;
    }

    function syncAiSelection(provider, model) {
        const resolved = resolveAiSelection(provider, model);
        selectedProvider = resolved.provider;
        selectedModel = resolved.model;
        renderProviderButtons();
        renderModelOptions();
        renderAiSelectionInfo();
    }

    function appendAiSelection(fd) {
        fd.append("provider", selectedProvider);
        fd.append("model", selectedModel);
    }

    syncAiSelection(selectedProvider, selectedModel);

    aiProviderButtons.forEach((btn) => {
        btn.addEventListener("click", () => {
            const nextProvider = btn.dataset.aiProvider || "";
            syncAiSelection(nextProvider, "");
        });
    });

    if (aiModelSelect) {
        aiModelSelect.addEventListener("change", () => {
            syncAiSelection(selectedProvider, aiModelSelect.value);
        });
    }

    // ---------- Drawer ----------
    const taskAssistantDrawer = $("taskAssistantDrawer");
    const taskAssistantPanel = $("taskAssistantPanel");
    const taskAssistantToggle = $("taskAssistantToggle");
    const taskAssistantMiniFab = $("taskAssistantMiniFab");
    const taskAssistantClose = $("taskAssistantClose");
    const taskAssistantBackdrop = $("taskAssistantBackdrop");
    const drawerTabButtons = document.querySelectorAll("[data-assist-tab]");
    const drawerPanels = document.querySelectorAll("[data-assist-panel]");

    const drawerStateKey = "kadai_task_assistant_open";
    const drawerTabKey = "kadai_task_assistant_tab";

    function updateLucideIcons() {
        if (window.lucide && typeof window.lucide.createIcons === "function") {
            window.lucide.createIcons();
        }
    }

    function setAssistTab(nextTab) {
        const target = nextTab === "settings" ? "settings" : "chat";

        drawerTabButtons.forEach((btn) => {
            const active = btn.dataset.assistTab === target;
            btn.classList.toggle("is-active", active);
            btn.setAttribute("aria-selected", active ? "true" : "false");
        });

        drawerPanels.forEach((panel) => {
            const active = panel.dataset.assistPanel === target;
            panel.classList.toggle("is-active", active);
            panel.hidden = !active;
        });

        try {
            localStorage.setItem(drawerTabKey, target);
        } catch {
            // ignore
        }

        if (target === "chat") {
            ensureChatHistoryLoaded();
        }

        updateLucideIcons();
    }

    function setDrawerOpen(open) {
        if (!taskAssistantDrawer || !taskAssistantPanel) return;

        const isOpen = !!open;
        taskAssistantDrawer.classList.toggle("is-open", isOpen);
        taskAssistantPanel.setAttribute("aria-hidden", isOpen ? "false" : "true");
        taskAssistantDrawer.setAttribute("aria-hidden", isOpen ? "false" : "true");
        document.body.classList.toggle("taskAssistantOpen", isOpen);

        if (taskAssistantToggle) {
            taskAssistantToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
            taskAssistantToggle.setAttribute("aria-label", isOpen ? "AIアシスタントを閉じる" : "AIアシスタントを開く");
        }

        if (taskAssistantMiniFab) {
            taskAssistantMiniFab.setAttribute("aria-expanded", isOpen ? "true" : "false");
            taskAssistantMiniFab.hidden = isOpen;
        }

        if (taskAssistantBackdrop) {
            taskAssistantBackdrop.hidden = !isOpen;
        }

        try {
            localStorage.setItem(drawerStateKey, isOpen ? "1" : "0");
        } catch {
            // ignore
        }

        if (isOpen) {
            const activeTabBtn = document.querySelector("[data-assist-tab].is-active");
            const currentTab = activeTabBtn ? activeTabBtn.dataset.assistTab : "chat";
            if (currentTab === "chat") {
                ensureChatHistoryLoaded();
            }
        }
    }

    function toggleDrawer() {
        if (!taskAssistantDrawer) return;
        setDrawerOpen(!taskAssistantDrawer.classList.contains("is-open"));
    }

    if (taskAssistantToggle) {
        taskAssistantToggle.addEventListener("click", toggleDrawer);
    }

    if (taskAssistantMiniFab) {
        taskAssistantMiniFab.addEventListener("click", () => {
            setDrawerOpen(true);
        });
    }

    if (taskAssistantClose) {
        taskAssistantClose.addEventListener("click", () => {
            setDrawerOpen(false);
        });
    }

    if (taskAssistantBackdrop) {
        taskAssistantBackdrop.addEventListener("click", () => {
            setDrawerOpen(false);
        });
    }

    drawerTabButtons.forEach((btn) => {
        btn.addEventListener("click", () => {
            setAssistTab(btn.dataset.assistTab || "chat");
        });
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            setDrawerOpen(false);
        }
    });

    // ---------- AI Guidance ----------
    const aiRun = $("aiRun");
    const aiStatus = $("aiStatus");
    const aiBox = $("aiBox");
    const aiText = $("aiText");

    if (aiRun && aiStatus && aiBox && aiText) {
        aiRun.addEventListener("click", async () => {
            aiRun.disabled = true;
            aiStatus.textContent = `${getProviderLabel(selectedProvider)} / ${selectedModel} で生成中…`;
            aiBox.style.display = "none";
            aiText.textContent = "";

            try {
                const fd = new FormData();
                fd.append("csrf", csrf);
                fd.append("task_id", taskId);
                appendAiSelection(fd);

                const json = await postForm("task_ai.php", fd);

                aiStatus.textContent = json.cached
                    ? `${getProviderLabel(selectedProvider)} の保存済み結果を表示しました。`
                    : `${getProviderLabel(selectedProvider)} で生成しました。`;
                aiBox.style.display = "block";
                aiText.textContent = json.answer || "（AIの出力が空でした）";
            } catch (e) {
                aiStatus.textContent = "エラー：" + (e.message || "不明なエラー");
            } finally {
                aiRun.disabled = false;
            }
        });
    }

    // ---------- Chat ----------
    const chatForm = $("chatForm");
    const chatHistory = $("chatHistory");
    const chatInput = $("chatInput");
    const chatSend = $("chatSend");
    const chatEmpty = $("chatEmpty");

    let chatLoaded = false;
    let chatBusy = false;
    let chatLoadPromise = null;

    function escapeText(value) {
        return value == null ? "" : String(value);
    }

    function setChatStatus(_text) {
        // 表示しない設計のため何もしない
    }

    function setChatBusy(flag) {
        chatBusy = !!flag;
        if (chatSend) chatSend.disabled = chatBusy;
        if (chatInput) chatInput.disabled = chatBusy;
    }

    function clearChatHistory() {
        if (!chatHistory) return;
        chatHistory.innerHTML = "";
    }

    function toggleChatEmpty(show) {
        if (!chatEmpty) return;
        chatEmpty.style.display = show ? "block" : "none";
    }

    function formatDateText(value) {
        if (!value) return "";
        return String(value);
    }

    function createChatMessageEl(message) {
        const wrap = document.createElement("div");
        wrap.className = `chatMessage chatMessage--${message.role || "assistant"}`;

        const meta = document.createElement("div");
        meta.className = "chatMessageMeta";

        const roleLabel = message.role === "user" ? "あなた" : (message.role === "assistant" ? "AI" : "SYSTEM");
        let metaText = roleLabel;

        if (message.provider && message.model && message.role === "assistant") {
            metaText += ` / ${getProviderLabel(message.provider)} / ${message.model}`;
        }

        if (message.safety_flag && message.safety_flag !== "normal" && message.role === "assistant") {
            metaText += ` / ${message.safety_flag}`;
        }

        const createdAt = formatDateText(message.created_at);
        if (createdAt) {
            metaText += ` / ${createdAt}`;
        }

        meta.textContent = metaText;

        const body = document.createElement("div");
        body.className = "chatMessageBody";
        body.textContent = escapeText(message.body);

        wrap.appendChild(meta);
        wrap.appendChild(body);

        return wrap;
    }

    function appendChatMessage(message) {
        if (!chatHistory) return;
        chatHistory.appendChild(createChatMessageEl(message));
        toggleChatEmpty(false);
        chatHistory.scrollTop = chatHistory.scrollHeight;
    }

    function renderChatMessages(messages) {
        clearChatHistory();

        if (!Array.isArray(messages) || messages.length === 0) {
            toggleChatEmpty(true);
            return;
        }

        toggleChatEmpty(false);
        messages.forEach((message) => appendChatMessage(message));
    }

    async function loadChatHistory(force = false) {
        if (!chatHistory) return Promise.resolve();

        if (!force && chatLoaded) {
            return Promise.resolve();
        }

        if (chatLoadPromise) {
            return chatLoadPromise;
        }

        chatLoadPromise = (async () => {
            try {
                const json = await getJson(
                    `task_chat_history.php?task_id=${encodeURIComponent(taskId)}&limit=50`
                );

                renderChatMessages(json.messages || []);
                chatLoaded = true;
            } catch (e) {
                clearChatHistory();
                toggleChatEmpty(true);
                setChatStatus("履歴の読み込みに失敗しました: " + (e.message || "不明なエラー"));
            } finally {
                chatLoadPromise = null;
            }
        })();

        return chatLoadPromise;
    }

    function ensureChatHistoryLoaded() {
        if (!chatHistory) return Promise.resolve();
        if (chatLoaded) return Promise.resolve();
        return loadChatHistory(false);
    }

    async function submitChat() {
        if (!chatInput || !chatHistory || chatBusy) return;

        const message = chatInput.value.trim();
        if (!message) {
            setChatStatus("質問内容を入力してください。");
            return;
        }

        await ensureChatHistoryLoaded();
        setChatBusy(true);

        try {
            const fd = new FormData();
            fd.append("csrf", csrf);
            fd.append("task_id", taskId);
            fd.append("message", message);
            appendAiSelection(fd);

            const json = await postForm("task_chat.php", fd);

            if (json.user_message) {
                appendChatMessage(json.user_message);
            }
            if (json.assistant_message) {
                appendChatMessage(json.assistant_message);
            }

            chatInput.value = "";
            chatLoaded = true;
        } catch (e) {
            setChatStatus("エラー：" + (e.message || "不明なエラー"));
        } finally {
            setChatBusy(false);
            if (chatInput) {
                chatInput.focus();
            }
        }
    }

    if (chatForm) {
        chatForm.addEventListener("submit", (e) => {
            e.preventDefault();
            submitChat();
        });
    } else if (chatSend) {
        chatSend.addEventListener("click", () => {
            submitChat();
        });
    }

    // ---------- Draft (DB autosave + counter) ----------
    const draftEl = $("draft");
    const draftSaveStatus = $("draftSaveStatus");
    const draftCount = $("draftCount");

    function countDraftChars(text) {
        return Array.from((text || "").replace(/\s/g, "")).length;
    }

    function renderDraftCount() {
        if (!draftEl || !draftCount) return;
        draftCount.textContent = `${countDraftChars(draftEl.value)}文字`;
    }

    async function saveDraftNow(text) {
        const fd = new FormData();
        fd.append("csrf", csrf);
        fd.append("task_id", taskId);
        fd.append("draft", text);

        return postForm("task_draft_save.php", fd);
    }

    if (draftEl) {
        let saveTimer = null;
        let saving = false;
        let pendingText = null;

        renderDraftCount();

        async function flushDraftSave(text) {
            pendingText = text;

            if (saving) return;
            saving = true;

            while (pendingText !== null) {
                const currentText = pendingText;
                pendingText = null;

                try {
                    const json = await saveDraftNow(currentText);
                    if (draftSaveStatus) {
                        draftSaveStatus.textContent = json.saved_at
                            ? `自動保存済み：${json.saved_at}`
                            : "自動保存しました";
                    }
                } catch (e) {
                    if (draftSaveStatus) {
                        draftSaveStatus.textContent = "自動保存エラー：" + (e.message || "不明なエラー");
                    }
                }
            }

            saving = false;
        }

        draftEl.addEventListener("input", () => {
            renderDraftCount();

            if (draftSaveStatus) {
                draftSaveStatus.textContent = "保存中…";
            }

            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                void flushDraftSave(draftEl.value);
            }, 800);
        });

        draftEl.addEventListener("blur", () => {
            clearTimeout(saveTimer);
            void flushDraftSave(draftEl.value);
        });
    }

    // ---------- Google Docs ----------
    const docsCreate = $("docsCreate");
    const docsSync = $("docsSync");
    const docsStatus = $("docsStatus");
    const docLink = $("docLink");

    function showDocLink(url) {
        if (!docLink || !url) return;
        docLink.href = url;
        docLink.style.display = "inline-flex";
    }

    if (docsCreate && docsStatus) {
        docsCreate.addEventListener("click", async () => {
            docsCreate.disabled = true;
            docsStatus.textContent = "Docs作成中…";

            try {
                const fd = new FormData();
                fd.append("csrf", csrf);
                fd.append("task_id", taskId);

                const json = await postForm("../google/docs_create.php", fd);

                docsStatus.textContent = "Docsを作成しました。";
                showDocLink(json.doc_url);
                window.open(json.doc_url, "_blank", "noopener");
            } catch (e) {
                docsStatus.textContent = "エラー：" + (e.message || "不明なエラー");
            } finally {
                docsCreate.disabled = false;
            }
        });
    }

    if (docsSync && docsStatus && draftEl) {
        docsSync.addEventListener("click", async () => {
            if (!draftEl.value.trim()) {
                docsStatus.textContent = "下書き欄に文章を書いてから反映してください。";
                return;
            }

            docsSync.disabled = true;
            docsStatus.textContent = "Docsへ反映中…";

            try {
                const fd = new FormData();
                fd.append("csrf", csrf);
                fd.append("task_id", taskId);
                fd.append("draft", draftEl.value);

                const json = await postForm("../google/docs_sync.php", fd);

                docsStatus.textContent = "反映しました。";
                showDocLink(json.doc_url);
            } catch (e) {
                docsStatus.textContent = "エラー：" + (e.message || "不明なエラー");
            } finally {
                docsSync.disabled = false;
            }
        });
    }

    // ---------- Grade ----------
    const gradeRun = $("gradeRun");
    const gradeRegen = $("gradeRegen");
    const gradeStatus = $("gradeStatus");

    function renderGrade(result, meta) {
        const gradeBox = $("gradeBox");
        const gradeScore = $("gradeScore");
        const gradeLetter = $("gradeLetter");
        const gradedAt = $("gradedAt");
        const good = $("gradeGood");
        const bad = $("gradeBad");
        const next = $("gradeNext");
        const gradeSummary = $("gradeSummary");

        if (!gradeBox || !gradeScore || !gradeLetter || !gradedAt || !good || !bad || !next || !gradeSummary) {
            return;
        }

        gradeBox.style.display = "block";
        gradeScore.textContent = result.score ?? "";
        gradeLetter.textContent = result.grade ?? "";
        gradedAt.textContent = meta?.graded_at ? `（${meta.graded_at}）` : "";

        good.innerHTML = "";
        bad.innerHTML = "";
        next.innerHTML = "";

        (result.good_points || []).forEach((s) => {
            const li = document.createElement("li");
            li.textContent = s;
            good.appendChild(li);
        });

        (result.bad_points || []).forEach((s) => {
            const li = document.createElement("li");
            li.textContent = s;
            bad.appendChild(li);
        });

        (result.next_actions || []).forEach((s) => {
            const li = document.createElement("li");
            li.textContent = s;
            next.appendChild(li);
        });

        gradeSummary.textContent = result.summary || "";
    }

    async function runGrade(force) {
        if (!draftEl || !gradeStatus) return;

        const draft = draftEl.value;
        if (!draft.trim()) {
            gradeStatus.textContent = "下書き欄に答案を書いてから採点してください。";
            return;
        }

        if (gradeRun) gradeRun.disabled = true;
        if (gradeRegen) gradeRegen.disabled = true;
        gradeStatus.textContent = force
            ? `${getProviderLabel(selectedProvider)} / ${selectedModel} で再採点中…`
            : `${getProviderLabel(selectedProvider)} / ${selectedModel} で採点中…`;

        try {
            const fd = new FormData();
            fd.append("csrf", csrf);
            fd.append("task_id", taskId);
            fd.append("draft", draft);
            appendAiSelection(fd);
            if (force) fd.append("force", "1");

            const json = await postForm("task_grade.php", fd);

            gradeStatus.textContent = json.cached
                ? `${getProviderLabel(selectedProvider)} の保存済み採点結果を表示しました。`
                : `${getProviderLabel(selectedProvider)} で採点しました。`;
            renderGrade(json.result, { graded_at: json.graded_at });
        } catch (e) {
            gradeStatus.textContent = "エラー：" + (e.message || "不明なエラー");
        } finally {
            if (gradeRun) gradeRun.disabled = false;
            if (gradeRegen) gradeRegen.disabled = false;
        }
    }

    if (gradeRun) {
        gradeRun.addEventListener("click", () => runGrade(false));
    }
    if (gradeRegen) {
        gradeRegen.addEventListener("click", () => runGrade(true));
    }

    // ---------- Initial UI state ----------
    let initialTab = "chat";
    let initialOpen = false;

    try {
        initialTab = localStorage.getItem(drawerTabKey) || "chat";
        initialOpen = localStorage.getItem(drawerStateKey) === "1";
    } catch {
        initialTab = "chat";
        initialOpen = false;
    }

    setAssistTab(initialTab);
    setDrawerOpen(initialOpen);
    updateLucideIcons();
})();