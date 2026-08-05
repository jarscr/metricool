<?php

namespace Metricool\Utils;

class Logger
{
    /** @var list<string> */
    private array $buffer = [];
    private string $logDir;

    public function __construct(?string $logDir = null)
    {
        $this->logDir = $logDir ?? dirname(__DIR__, 2) . '/logs/sync';
    }

    public function info(string $message): void
    {
        $this->log($message, 'INFO');
    }

    public function warn(string $message): void
    {
        $this->log($message, 'WARN');
    }

    public function error(string $message): void
    {
        $this->log($message, 'ERROR');
    }

    public function success(string $message): void
    {
        $this->log($message, 'SUCCESS');
    }

    public function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}";
        $this->buffer[] = $line;
        echo $line . PHP_EOL;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $file = $this->logDir . '/sync_' . date('Y-m-d') . '.log';
        file_put_contents($file, implode(PHP_EOL, $this->buffer) . PHP_EOL, FILE_APPEND);
    }
}
