<?php
declare(strict_types=1);

class StateDB
{
    private SQLite3 $db;

    // Colunas internas — nunca sobrescritas por dados de fontes externas
    private const META_COLS = [
        'email',
        'last_import',
        'last_export',
        'last_sanitize',
        'sanitize_status',
    ];

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new SQLite3($dbPath);
        $this->db->enableExceptions(true);

        // WAL mode: melhor concorrência de leitura e recuperação após crash
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA foreign_keys=ON');

        $this->initialize();
    }

    // ---------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------

    private function initialize(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                email           TEXT COLLATE NOCASE PRIMARY KEY NOT NULL,
                last_import     TEXT DEFAULT NULL,
                last_export     TEXT DEFAULT NULL,
                last_sanitize   TEXT DEFAULT NULL,
                sanitize_status TEXT DEFAULT NULL
            )
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_sanitize_status
            ON contacts (sanitize_status)
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_last_import
            ON contacts (last_import)
        ");

        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_last_export
            ON contacts (last_export)
        ");
    }

    /**
     * Adiciona colunas novas à tabela contacts sem destruir dados existentes.
     * Seguro executar múltiplas vezes (idempotente).
     *
     * @param array<string,string> $columns Mapa nome => tipo (TEXT | INTEGER | REAL)
     */
    public function ensureColumns(array $columns): void
    {
        $existing = $this->existingColumns();

        foreach ($columns as $col => $type) {
            if ($col === 'email') {
                continue; // PK, sempre existe
            }

            $this->validateColumnName($col);
            $sqliteType = $this->validateColumnType($type);

            if (!in_array($col, $existing, true)) {
                $this->db->exec("ALTER TABLE contacts ADD COLUMN \"{$col}\" {$sqliteType} DEFAULT NULL");
            }
        }
    }

    /**
     * Valida e normaliza o tipo SQLite declarado no config.
     * Aceita TEXT, INTEGER ou REAL (case-insensitive).
     */
    private function validateColumnType(string $type): string
    {
        $normalized = strtoupper(trim($type));
        if (!in_array($normalized, ['TEXT', 'INTEGER', 'REAL'], true)) {
            throw new \InvalidArgumentException(
                "Tipo de coluna inválido '{$type}': use TEXT, INTEGER ou REAL."
            );
        }
        return $normalized;
    }

    /** Retorna os nomes de todas as colunas atuais da tabela contacts. */
    public function existingColumns(): array
    {
        $result = $this->db->query('PRAGMA table_info(contacts)');
        $cols   = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $row['name'];
        }
        return $cols;
    }

    private function validateColumnName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Nome de coluna inválido '{$name}': use apenas letras, dígitos e underscores."
            );
        }

        if (in_array($name, self::META_COLS, true)) {
            throw new \InvalidArgumentException(
                "Coluna '{$name}' é reservada para uso interno e não pode vir de uma fonte."
            );
        }
    }

    // ---------------------------------------------------------------
    // Import
    // ---------------------------------------------------------------

    /**
     * Faz UPSERT de todas as linhas de uma fonte no estado local.
     * Atualiza APENAS as colunas desta fonte — preserva colunas de outras fontes.
     *
     * @param array[]              $rows        Linhas retornadas pela query da fonte (FETCH_ASSOC)
     * @param array<string,string> $columnTypes Mapa nome => tipo (TEXT | INTEGER | REAL)
     */
    public function upsertFromSource(array $rows, array $columnTypes): void
    {
        // Colunas de dados (excluindo 'email' que é a PK)
        $dataCols = array_keys($columnTypes);

        // Monta o SQL dinamicamente:
        // INSERT INTO contacts (email, col1, col2, ..., last_import)
        //   VALUES (:email, :col1, :col2, ..., datetime('now'))
        //   ON CONFLICT(email) DO UPDATE SET col1=excluded.col1, ..., last_import=excluded.last_import

        $insertCols = array_merge(['email'], $dataCols, ['last_import']);
        $valueParts = array_map(
            fn($c) => $c === 'last_import' ? "datetime('now')" : ":{$c}",
            $insertCols
        );
        $dataUpdateParts = array_map(
            fn($c) => "\"{$c}\" = excluded.\"{$c}\"",
            $dataCols
        );

        if (empty($dataCols)) {
            // nenhuma coluna de dado → nada pode mudar → preserva last_import
            $updateParts = ['"last_import" = "last_import"'];
        } else {
            $changeCondition = implode(
                ' OR ',
                array_map(fn($c) => "\"{$c}\" IS NOT excluded.\"{$c}\"", $dataCols)
            );
            $updateParts = array_merge(
                $dataUpdateParts,
                ["\"last_import\" = CASE WHEN {$changeCondition} THEN datetime('now') ELSE \"last_import\" END"]
            );
        }

        $quotedInsertCols = array_map(fn($c) => "\"{$c}\"", $insertCols);

        $sql = sprintf(
            'INSERT INTO contacts (%s) VALUES (%s) ON CONFLICT(email) DO UPDATE SET %s',
            implode(', ', $quotedInsertCols),
            implode(', ', $valueParts),
            implode(', ', $updateParts)
        );

        $stmt = $this->db->prepare($sql);

        $this->db->exec('BEGIN');
        try {
            foreach ($rows as $row) {
                $stmt->reset();
                $stmt->bindValue(':email', strtolower(trim($row['email'])), SQLITE3_TEXT);

                foreach ($dataCols as $col) {
                    $value = $row[$col] ?? null;

                    if ($value === null) {
                        $stmt->bindValue(":{$col}", null, SQLITE3_NULL);
                        continue;
                    }

                    // Usa o tipo declarado no config para binding correto —
                    // garante que a affinity da coluna SQLite seja respeitada
                    // e que ORDER BY numérico funcione corretamente.
                    $declaredType = strtoupper($columnTypes[$col] ?? 'TEXT');

                    match ($declaredType) {
                        'INTEGER' => $stmt->bindValue(":{$col}", (int)   $value, SQLITE3_INTEGER),
                        'REAL'    => $stmt->bindValue(":{$col}", (float) $value, SQLITE3_FLOAT),
                        default   => $stmt->bindValue(":{$col}", (string)$value, SQLITE3_TEXT),
                    };
                }

                $stmt->execute();
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Sanitize
    // ---------------------------------------------------------------

    /**
     * Retorna emails com sanitize_status = NULL, ordenados e limitados pelo batch.
     *
     * @return string[]
     */
    public function fetchUnsanitized(int $batchSize, string $orderBy, string $orderDir, ?string $where = null): array
    {
        $orderBy  = $this->allowedColumn($orderBy);
        $orderDir = $this->allowedDirection($orderDir);
        $extra    = $where !== null && $where !== '' ? " AND ({$where})" : '';

        $stmt = $this->db->prepare(
            "SELECT email FROM contacts
             WHERE sanitize_status IS NULL{$extra}
             ORDER BY \"{$orderBy}\" {$orderDir}
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $batchSize, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $emails = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $emails[] = $row['email'];
        }
        return $emails;
    }

    /** Grava o resultado da verificação NeverBounce para um email. */
    public function markSanitized(string $email, string $status): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contacts
             SET sanitize_status = :status,
                 last_sanitize   = datetime('now')
             WHERE email = :email"
        );
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':email',  $email,  SQLITE3_TEXT);
        $stmt->execute();
    }

    // ---------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------

    /**
     * Retorna contatos válidos para exportação
     *
     * @return array[]
     */
    public function fetchExportable(int $batchSize, string $orderBy, string $orderDir, ?string $where = null): array
    {
        $orderBy  = $this->allowedColumn($orderBy);
        $orderDir = $this->allowedDirection($orderDir);
        $extra    = $where !== null && $where !== '' ? " AND ({$where})" : '';

        $stmt = $this->db->prepare(
            "SELECT * FROM contacts
             WHERE sanitize_status = 'valid'{$extra}
             ORDER BY \"{$orderBy}\" {$orderDir}
             LIMIT :limit"
        );
        $stmt->bindValue(':limit',    $batchSize,            SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Marca um contato como exportado com o timestamp atual. */
    public function markExported(string $email): void
    {
        $stmt = $this->db->prepare(
            "UPDATE contacts
             SET last_export = datetime('now')
             WHERE email = :email"
        );
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->execute();
    }

    // ---------------------------------------------------------------
    // Proteção contra SQL injection via configuração
    // ---------------------------------------------------------------

    private function allowedColumn(string $col): string
    {
        // Valida apenas o formato para prevenir SQL injection vindo do config.
        // Não verificamos existência em tempo de execução: se a coluna não existir,
        // o SQLite lança "no such column" com mensagem clara.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
            throw new \InvalidArgumentException(
                "Coluna de ordenação inválida '{$col}': use apenas letras, dígitos e underscores."
            );
        }
        return $col;
    }

    private function allowedDirection(string $dir): string
    {
        $dir = strtoupper($dir);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(
                "Direção de ordenação deve ser ASC ou DESC, recebido: '{$dir}'."
            );
        }
        return $dir;
    }

    // ---------------------------------------------------------------
    // Estatísticas
    // ---------------------------------------------------------------

    /**
     * Retorna contadores agregados para o modo --statistics.
     * Leitura pura — não altera nenhum dado.
     */
    public function getStatistics(): array
    {
        $total = (int) $this->db->querySingle('SELECT COUNT(*) FROM contacts');

        $pendingSanitize = (int) $this->db->querySingle(
            "SELECT COUNT(*) FROM contacts WHERE sanitize_status IS NULL"
        );

        // Breakdown por resultado de higienização
        $byStatus = [];
        $result   = $this->db->query(
            "SELECT sanitize_status, COUNT(*) AS cnt
             FROM contacts
             WHERE sanitize_status IS NOT NULL
             GROUP BY sanitize_status
             ORDER BY cnt DESC"
        );
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $byStatus[$row['sanitize_status']] = (int) $row['cnt'];
        }

        $exported = (int) $this->db->querySingle(
            "SELECT COUNT(*) FROM contacts WHERE last_export IS NOT NULL"
        );

        $exportable = (int) $this->db->querySingle(
            "SELECT COUNT(*) FROM contacts
             WHERE sanitize_status = 'valid'
               AND (last_export IS NULL OR last_import > last_export)"
        );

        return [
            'total'            => $total,
            'pending_sanitize' => $pendingSanitize,
            'by_status'        => $byStatus,
            'exported'         => $exported,
            'exportable'       => $exportable,
        ];
    }

    // ---------------------------------------------------------------
    // Utilitários
    // ---------------------------------------------------------------

    public function close(): void
    {
        $this->db->close();
    }
}
