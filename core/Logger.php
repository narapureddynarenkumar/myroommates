<?php

declare(strict_types=1);

class Logger
{
    private string $logFile;

    // Log Levels
    public const INFO = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;

        $this->ensureLogDirectoryExists();
    }

    /**
     * Generic log method
     */
    public function log(string $level, string $message): void
    {
        $formattedMessage = $this->formatMessage($level, $message);

        file_put_contents(
            $this->logFile,
            $formattedMessage,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Info log
     */
    public function info(string $message): void
    {
        $this->log(self::INFO, $message);
    }

    /**
     * Warning log
     */
    public function warning(string $message): void
    {
        $this->log(self::WARNING, $message);
    }

    /**
     * Error log
     */
    public function error(string $message): void
    {
        $this->log(self::ERROR, $message);
    }

    /**
     * Log exception properly
     */
    public function exception(Throwable $e): void
    {
        $message = sprintf(
            "%s | File: %s | Line: %d",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        $this->log(self::ERROR, $message);
    }

    /**
     * Format log line
     */
    private function formatMessage(string $level, string $message): string
    {
        return sprintf(
            "[%s] [%s] %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            PHP_EOL
        );
    }

    /**
     * Ensure logs directory exists
     */
    private function ensureLogDirectoryExists(): void
    {
        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
