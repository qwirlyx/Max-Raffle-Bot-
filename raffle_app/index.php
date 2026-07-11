<?php
// ОТКЛЮЧАЕМ вывод ошибок, чтобы не ломать страницу WebApp
ini_set('display_errors', 0);
error_reporting(0);

// MAX открывает мини-приложение внутри встроенного окна WebView.
// Если хостинг/сервер отдает X-Frame-Options или строгий frame-ancestors,
// браузер MAX может показать ERR_BLOCKED_BY_RESPONSE. Для страницы участия
// явно разрешаем открытие внутри MAX.
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors 'self' https://max.ru https://web.max.ru https://*.max.ru");
    header('Referrer-Policy: no-referrer-when-downgrade');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Розыгрыш</title>
    <link rel="icon" type="image/x-icon" href="../icon.ico">
    <link rel="stylesheet" href="../styles.css"> 
    <script src="https://st.max.ru/js/max-web-app.js"></script>
    <style>
        body { 
            background: #121212; 
            color: #fff; 
            font-family: sans-serif; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            height: 100vh; 
            margin: 0; 
            padding: 20px; 
            box-sizing: border-box;
        }
        .card { 
            background: #1e1e1e; 
            padding: 40px 30px; 
            border-radius: 20px; 
            width: 100%; 
            max-width: 350px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.5); 
            text-align: center; 
        }
        .loader { 
            width: 50px; 
            height: 50px; 
            border: 5px solid #333; 
            border-top-color: #4a90e2; 
            border-radius: 50%; 
            animation: spin 1s infinite linear; 
            margin: 0 auto 20px; 
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        h2 { margin: 10px 0; font-size: 24px; }
        p { color: #aaa; font-size: 16px; line-height: 1.5; margin-bottom: 20px; }
        
        .hidden { display: none; }
        
        .btn-action {
            background: #27ae60;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-action:hover { background: #219150; }

        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<div class="card fade-in" id="card">
    <div id="loading">
        <div class="loader"></div>
        <p>Проверяем твой билет...</p>
    </div>

    <div id="content" class="hidden">
        <div style="font-size: 60px; margin-bottom: 20px;" id="icon"></div>
        <h2 id="title"></h2>
        <p id="msg"></p>
        
        <button id="notifyBtn" class="btn-action hidden" onclick="openBotChat()">
            🔔 Включить уведомления о победе
        </button>
    </div>
</div>

<script>
    const App = window.WebApp;
    const BOT_USERNAME = 'id9102204024_2_bot';

    function openBotChat() {
        if (App && App.openMaxLink) {
            App.openMaxLink('https://max.ru/' + BOT_USERNAME + '?start=test');
        } else {
            window.location.href = 'https://max.ru/' + BOT_USERNAME + '?start=test';
        }
    }
    
    async function init() {
        if (App) App.ready();

        // Получаем ID розыгрыша
        let raffleId = null;
        if (App && App.initDataUnsafe && App.initDataUnsafe.start_param) {
            raffleId = App.initDataUnsafe.start_param;
        } else {
            const urlParams = new URLSearchParams(window.location.search);
            raffleId = urlParams.get('id');
        }

        // Получаем данные пользователя — только из WebApp SDK, никакого хардкода
        let user = null;
        if (App && App.initDataUnsafe && App.initDataUnsafe.user) {
            user = App.initDataUnsafe.user;
        }

        if (!user) {
            show('❌', 'Ошибка', 'Открой страницу через приложение MAX');
            return;
        }

        if (!raffleId) {
            show('❌', 'Ошибка', 'Неверная ссылка на розыгрыш');
            return;
        }

        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    user_id: user.id,
                    user_name: (user.first_name + ' ' + (user.last_name || '')).trim(),
                    raffle_id: raffleId
                })
            });

            if (res.status !== 200) {
                show('🔥', 'Ошибка сервера', 'Попробуйте позже');
                return;
            }

            const data = await res.json();
            
            if (data.success) {
                show('🎉', 'Готово!', data.message);
                document.getElementById('notifyBtn').classList.remove('hidden');
                if (App && App.HapticFeedback) App.HapticFeedback.notificationOccurred('success');
            } else {
                show('✋', 'Внимание', data.message);
                if (App && App.HapticFeedback) App.HapticFeedback.notificationOccurred('warning');
            }

        } catch (e) {
            show('📡', 'Ошибка сети', 'Проверьте интернет');
            console.error(e);
        }
    }

    function show(icon, title, text) {
        document.getElementById('loading').classList.add('hidden');
        document.getElementById('content').classList.remove('hidden');
        document.getElementById('icon').innerText = icon;
        document.getElementById('title').innerText = title;
        document.getElementById('msg').innerText = text;
        document.getElementById('content').classList.add('fade-in');
    }

    setTimeout(init, 300);
</script>

</body>
</html>