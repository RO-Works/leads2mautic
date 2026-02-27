<?php
declare(strict_types=1);

class Logger
{
    private string $logFile;
    private int    $maxBytes;

    public function __construct(string $logFile, int $maxBytes = 10 * 1024 * 1024)
    {
        $this->logFile  = $logFile;
        $this->maxBytes = $maxBytes;
    }

    public function info(string $message, string $batchId = '', array $context = []): void
    {
        $this->write('INFO', $message, $batchId, $context);
    }

    public function warn(string $message, string $batchId = '', array $context = []): void
    {
        $this->write('WARN', $message, $batchId, $context);
    }

    public function error(string $message, string $batchId = '', array $context = []): void
    {
        $this->write('ERROR', $message, $batchId, $context);
    }

    private function write(string $level, string $message, string $batchId, array $context): void
    {
        $entry = json_encode([
            'ts'       => date('c'),
            'nivel'    => $level,
            'batch'    => $batchId,
            'mensagem' => $message,
            'contexto' => $context,
        ], JSON_UNESCAPED_UNICODE);

        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxBytes) {
            @rename($this->logFile, $this->logFile . '.' . date('YmdHis') . '.old');
        }

        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
        echo $entry . "\n";
    }
}
