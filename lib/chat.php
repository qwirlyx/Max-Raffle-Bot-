<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

/**
 * Сохраняет сообщение в базу данных SQL
 *
 * @param mixed       $userId      ID пользователя
 * @param string|null $senderName  Имя пользователя
 * @param string      $text        Текст сообщения
 * @param string      $dir         Направление: 'in' или 'out'
 * @param string      $type        Тип: 'text', 'image', 'file'
 * @param string|null $content     Контент (ссылка на файл)
 * @param string|null $msgId       ID сообщения в MAX API
 */
function save_chat_message($userId, $senderName, $text, $dir = 'in', $type = 'text', $content = null, $msgId = null, $platform = 'max') {
    if (!$userId) {
        AppLogger::warning('save_chat_message: пустой user_id, пропускаем');
        return;
    }

    try {
        $pdo  = get_db_connection();
        ensure_platform_columns($pdo);
        $platform = function_exists('normalize_platform') ? normalize_platform($platform) : (($platform === 'telegram') ? 'telegram' : 'max');
        $time = time();
        $name = $senderName ?: "ID: $userId";

        // 1. Создаём или обновляем пользователя (INSERT ON DUPLICATE KEY UPDATE)
        $stmtUser = $pdo->prepare("
            INSERT INTO users (id, platform, name, mess_date, unread, status) 
            VALUES (:id, :platform, :name, :mess_date, :unread, :status)
            ON DUPLICATE KEY UPDATE 
                name      = IF(:dir_in = 'in' AND :name_new != '', :name_update, name),
                mess_date = :mess_date_update,
                platform  = IF(platform = '' OR platform IS NULL, :platform_update, platform),
                unread    = unread + IF(:dir_unread = 'in', 1, 0)
        ");

        $stmtUser->execute([
            ':id'               => $userId,
            ':platform'         => $platform,
            ':name'             => $name,
            ':mess_date'        => $time,
            ':unread'           => ($dir === 'in' ? 1 : 0),
            ':status'           => 'Начал диалог',
            ':dir_in'           => $dir,
            ':name_new'         => $name,
            ':name_update'      => $name,
            ':mess_date_update' => $time,
            ':platform_update'  => $platform,
            ':dir_unread'       => $dir,
        ]);

        // 2. Сохраняем сообщение в историю
        $stmtMsg = $pdo->prepare("
            INSERT INTO messages (user_id, platform, sender_name, direction, type, content, msg_id, created_at)
            VALUES (:user_id, :platform, :sender_name, :direction, :type, :content, :msg_id, :created_at)
        ");

        $contentToSave = $content !== null ? $content : $text;

        $stmtMsg->execute([
            ':user_id'     => $userId,
            ':platform'    => $platform,
            ':sender_name' => $name,
            ':direction'   => $dir,
            ':type'        => $type,
            ':content'     => $contentToSave,
            ':msg_id'      => $msgId,
            ':created_at'  => $time,
        ]);

    } catch (Exception $e) {
        AppLogger::error('save_chat_message: ошибка при сохранении сообщения', [
            'user_id'  => $userId,
            'dir'      => $dir,
            'type'     => $type,
            'msg_id'   => $msgId,
        ], $e);
        // Не прерываем выполнение — ошибка логирования не должна ломать бота
    }
}
?>