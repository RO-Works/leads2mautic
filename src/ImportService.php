<?php
declare(strict_types=1);

class ImportService
{
    public function __construct(
        private StateDB $state,
        private Logger  $logger,
        private array   $sources,
    ) {}

    public function run(): void
    {
        $batchId = uniqid('import_', true);
        $this->logger->info('Import iniciado', $batchId, ['fontes' => count($this->sources)]);

        foreach ($this->sources as $source) {
            $this->processSource($source, $batchId);
        }

        $this->logger->info('Import concluído', $batchId);
    }

    private function processSource(array $source, string $batchId): void
    {
        $label = $source['label'] ?? '(sem label)';

        $this->logger->info("Fonte iniciada: {$label}", $batchId);

        try {
            // 'columns' é obrigatório: define os campos de dados e seus tipos SQLite
            $columnTypes = $source['columns'] ?? [];
            if (empty($columnTypes)) {
                throw new \RuntimeException(
                    "Fonte '{$label}' não tem a chave 'columns' configurada."
                );
            }

            // Credenciais vêm do .env via getenv(); valida antes de tentar conectar
            $dsn  = $source['dsn']  ?? '';
            $user = $source['user'] ?? '';
            $pass = $source['pass'] ?? '';

            if (!$dsn || !$user) {
                throw new \RuntimeException(
                    "Fonte '{$label}': credenciais não encontradas no .env " .
                    "(verifique DB_" . strtoupper($label) . "_DSN e DB_" . strtoupper($label) . "_USER)."
                );
            }

            $pdo = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT            => 30,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $stmt = $pdo->query($source['query']);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                $this->logger->info("Fonte vazia: {$label}", $batchId);
                return;
            }

            // Garante que a coluna 'email' está presente na query
            if (!array_key_exists('email', $rows[0])) {
                throw new \RuntimeException(
                    "A query da fonte '{$label}' deve retornar uma coluna 'email'."
                );
            }

            // Auto-evolui o schema com os tipos declarados no config
            $this->state->ensureColumns($columnTypes);

            // UPSERT usando os tipos declarados para binding correto
            $this->state->upsertFromSource($rows, $columnTypes);

            $this->logger->info("Fonte concluída: {$label}", $batchId, [
                'linhas'  => count($rows),
                'colunas' => $columnTypes,
            ]);

        } catch (\Throwable $e) {
            // Falha por fonte é isolada: loga e continua para a próxima
            $this->logger->error("Fonte falhou: {$label}", $batchId, [
                'erro'    => $e->getMessage(),
                'arquivo' => $e->getFile(),
                'linha'   => $e->getLine(),
            ]);
        }
    }
}
