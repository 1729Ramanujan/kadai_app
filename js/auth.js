// ===== DOM =====
const statusEl = document.getElementById("status");
const errorEl = document.getElementById("error");

const signedOutView = document.getElementById("signedOutView");
const signedInView = document.getElementById("signedInView");
const userEmailEl = document.getElementById("userEmail");

const loginForm = document.getElementById("loginForm");
const signupForm = document.getElementById("signupForm");
const logoutButton = document.getElementById("logoutButton");

function showError(message) {
    if (!message) {
        errorEl.hidden = true;
        errorEl.textContent = "";
        return;
    }
    errorEl.hidden = false;
    errorEl.textContent = message;
}

function setSignedInUI(user) {
    signedOutView.hidden = true;
    signedInView.hidden = false;
    statusEl.textContent = "ログイン済み";
    userEmailEl.textContent = user?.email ?? "(emailなし)";
}

function setSignedOutUI() {
    signedOutView.hidden = false;
    signedInView.hidden = true;
    statusEl.textContent = "未ログイン";
    userEmailEl.textContent = "";
}

// 認証状態の監視
auth.onAuthStateChanged((user) => {
    showError("");
    if (user) setSignedInUI(user);
    else setSignedOutUI();
});

// ===== ログイン =====
loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    showError("");

    const email = document.getElementById("loginEmail").value;
    const pass = document.getElementById("loginPassword").value;

    try {
        await auth.signInWithEmailAndPassword(email, pass);
        loginForm.reset();
    } catch (err) {
        showError(err?.message ?? "ログインに失敗しました");
    }
});

// ===== 新規登録 =====
signupForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    showError("");

    const email = document.getElementById("signupEmail").value;
    const pass = document.getElementById("signupPassword").value;

    try {
        await auth.createUserWithEmailAndPassword(email, pass);
        signupForm.reset();
    } catch (err) {
        showError(err?.message ?? "登録に失敗しました");
    }
});

// ===== ログアウト =====
logoutButton.addEventListener("click", async () => {
    showError("");
    try {
        await auth.signOut();
    } catch (err) {
        showError(err?.message ?? "ログアウトに失敗しました");
    }
});