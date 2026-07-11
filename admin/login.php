<?php
session_name('MAXRAFFLEBOT_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/projects/bots/MaxRaffleBot/admin/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// === НАЧАЛО SSO БЛОКА ===
define('SSO_SECRET', '2X+8c6L^T&U_oF4bvDe)JvgQ5fdi*D');

$sso_allowed_users = [
    'Александр Титов' => 'admin',
    'Хинкалыч Бот'    => 'user',
    'Иван Шевченко'   => 'admin',
    'Виталия Старовойтова'    => 'user',
    'Ольга Коваленко'    => 'user'
];

// Если уже авторизован — сразу в админку
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: index.php');
    exit;
}

// SSO-вход из зеркала
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sso_sign'])) {
    $sso_user    = $_POST['sso_user'] ?? '';
    $sso_time    = $_POST['sso_time'] ?? '0';
    $sso_service = $_POST['sso_service'] ?? '';
    $sso_sign    = $_POST['sso_sign'];

    if (abs(time() - (int)($sso_time / 1000)) > 60) {
        die("Ошибка SSO: Срок действия токена перехода истек.");
    }

    $dataToSign = $sso_user . "|" . $sso_time . "|" . $sso_service;
    $expectedSign = hash_hmac('sha256', $dataToSign, SSO_SECRET);

    if (!hash_equals($expectedSign, $sso_sign)) {
        die("Ошибка SSO: Недействительная или подделанная подпись.");
    }

    if (!isset($sso_allowed_users[$sso_user])) {
        die('Доступ в данный сервис через корпоративное зеркало запрещен для сотрудника: ' . htmlspecialchars($sso_user));
    }

    $_SESSION['is_admin'] = true;
    $_SESSION['sso_user'] = $sso_user;
    $_SESSION['role'] = $sso_allowed_users[$sso_user];

    session_regenerate_id(true);

    header('Location: index.php');
    exit;
}
// === КОНЕЦ SSO БЛОКА ===

$error = '';

// Обработка входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $pass  = $_POST['password'] ?? '';
    
    // Путь к файлу с паролями
    $authFile = __DIR__ . '/../data/auth.json';
    
    if (file_exists($authFile)) {
        $creds = json_decode(file_get_contents($authFile), true);
        
        // Сверка
        if ($login === ($creds['login'] ?? '') && $pass === ($creds['password'] ?? '')) {
            $_SESSION['is_admin'] = true;
            header('Location: index.php');
            exit;
        } else {
            $error = 'Неверный логин или пароль';
        }
    } else {
        // Если файла нет — создадим дефолтный (admin/123)
        file_put_contents($authFile, json_encode(['login'=>'admin', 'password'=>'123']));
        $error = 'Файл auth.json создан. Логин: admin, Пароль: 123. Попробуйте еще раз.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему</title>
    <link rel="icon" type="image/x-icon" href="../icon.ico">
    <link rel="stylesheet" href="../styles.css">
    <style>
        body { 
            display: flex; align-items: center; justify-content: center; height: 100vh; 
            margin: 0; background-color: #121212; 
        }
        .login-card {
            background: #1e1e1e; padding: 30px; border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5); width: 300px; text-align: center;
            border: 1px solid #333;
        }
        .login-title { color: #fff; margin-bottom: 20px; font-weight: 600; font-size: 20px; }
        .form-group { margin-bottom: 15px; }
        input {
            width: 100%; padding: 12px; box-sizing: border-box;
            background: #2b2b2b; border: 1px solid #333; color: #fff; border-radius: 8px;
            outline: none; transition: border 0.3s;
        }
        input:focus { border-color: #4a90e2; }
        button {
            width: 100%; padding: 12px; background: #4a90e2; border: none;
            color: #fff; border-radius: 8px; cursor: pointer; font-weight: 600;
            font-size: 16px; transition: background 0.3s;
        }
        button:hover { background: #357abd; }
        .error-msg { color: #e24a4a; font-size: 14px; margin-bottom: 15px; background: rgba(226, 74, 74, 0.1); padding: 8px; border-radius: 6px; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-title">🔒 Вход в панель</div>
    
    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <input type="text" name="login" placeholder="Логин" required autofocus>
        </div>
        <div class="form-group">
            <input type="password" name="password" placeholder="Пароль" required>
        </div>
        <button type="submit">Войти</button>
    </form>
</div>

</body>
</html>