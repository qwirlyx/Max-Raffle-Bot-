<?php

/**
 * Простой файловый логгер
 * Пишет структурированные записи в /data/app.log
 *
 * Использование:
 *   AppLogger::info('Пользователь зарегистрирован', ['user_id' => 123, 'raffle_id' => 5]);
 *   AppLogger::error('Ошибка БД', ['user_id' => 123], $exception);
 */
class AppLogger
{
    const LOG_FILE = __DIR__ . '/../data/app.log';

    // Максимальный размер лога до ротации (5 МБ)
    const MAX_SIZE = 5 * 1024 * 1024;

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = [], \Throwable $e = null): void
    {
        if ($e !== null) {
            $context['exception'] = get_class($e) . ': ' . $e->getMessage();
            $context['file']      = $e->getFile() . ':' . $e->getLine();
        }
        self::write('ERROR', $message, $context);
    }

    // -------------------------------------------------------------------------

    private static function write(string $level, string $message, array $context): void
    {
        self::rotate();

        $date   = date('Y-m-d H:i:s');
        $ctx    = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $caller = self::getCaller();
        $line   = "[$date] [$level] [$caller] $message$ctx" . PHP_EOL;

        @file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Возвращает имя файла и строку, откуда вызван логгер
     */
    private static function getCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (strpos($file, 'logger.php') !== false) continue;
            return basename($file) . ':' . ($frame['line'] ?? '?');
        }
        return 'unknown';
    }

    /**
     * Ротация лога: если файл вырос — переименовываем в app.log.bak
     */
    private static function rotate(): void
    {
        $logFile = self::LOG_FILE;
        if (file_exists($logFile) && filesize($logFile) > self::MAX_SIZE) {
            @rename($logFile, $logFile . '.bak');
        }
    }
}
?>