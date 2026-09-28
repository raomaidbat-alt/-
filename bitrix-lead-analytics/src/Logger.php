<?php
declare(strict_types=1);

namespace App;

/**
 * Минимальный логгер: строка в месячный файл var/log/<channel>-YYYY-MM.log
 * и дубль в STDERR для CLI (cron перешлёт его в почту или в журнал).
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private int $minLevel;
    private ?string $file;
    private bool $echo;

    public function __construct(string $channel, ?string $logDir, string $minLevel = 'info', ?bool $echo = null)
    {
        $this->minLevel = self::LEVELS[$minLevel] ?? 20;
        $this->echo = $echo ?? (PHP_SAPI === 'cli');
        $this->file = null;
        if ($logDir !== null && $logDir !== '') {
            if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                $logDir = null;
            }
            if ($logDir !== null) {
                $this->file = rtrim($logDir, '/') . '/' . $channel . '-' . gmdate('Y-m') . '.log';
            }
        }
    }

    public function debug(string $msg, array $ctx = []): void   { $this->log('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void    { $this->log('info', $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->log('warning', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void   { $this->log('error', $msg, $ctx); }

    public function log(string $level, string $msg, array $ctx = []): void
    {
        if ((self::LEVELS[$level] ?? 20) < $this->minLevel) {
            return;
        }
        $line = sprintf(
            "%s [%s] %s%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        if ($this->file !== null) {
            @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        }
        if ($this->echo) {
            fwrite(STDERR, $line);
        }
    }
}
