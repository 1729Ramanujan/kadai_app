// ===== Firebase 初期化 =====
// TODO: 自分のfirebaseConfigに置き換え
const firebaseConfig = {
    apiKey: "AIzaSyDlyxGKqW8O4I0tnkTuVvaEED_Gqd8MXTw",
    authDomain: "kadai-app-39039.firebaseapp.com",
    projectId: "kadai-app-39039",
    storageBucket: "kadai-app-39039.firebasestorage.app",
    messagingSenderId: "566160878478",
    appId: "1:566160878478:web:13ccd715afc9f1256b52df"
};

if(!firebase.apps.length){
    firebase.initializeApp(firebaseConfig);
}

window.auth = firebase.auth();
window.db = firebase.firestore();

