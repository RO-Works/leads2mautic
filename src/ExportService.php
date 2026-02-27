<?php
declare(strict_types=1);

class ExportService
{
    private const MAUTIC_MAX_RETRIES = 4;

    // Colunas internas que NÃO devem ser enviadas ao Mautic como campos de contato
    private const META_COLS = [
        'email',
        'last_import',
        'last_export',
        'last_sanitize',
        'sanitize_status',
    ];

    public function __construct(
        private StateDB $state,
        private Logger  $logger,
        private string  $mauticBaseUrl,
        private string  $mauticUser,
        private string  $mauticPass,
        private array   $exportConfig,
    ) {}

    public function run(): void
    {
        $batchId    = uniqid('export_', true);
        $batchSize  = (int) ($this->exportConfig['batch_size']  ?? 100);
        $orderBy    = (string) ($this->exportConfig['order_by']   ?? 'last_import');
        $orderDir   = (string) ($this->exportConfig['order_dir']  ?? 'DESC');

        $where    = ($this->exportConfig['where'] ?? null) ?: null;
        $contacts = $this->state->fetchExportable($batchSize, $orderBy, $orderDir, $where);

        if (empty($contacts)) {
            $this->logger->info('Nenhum contato para exportar', $batchId);
            return;
        }

        $this->logger->info('Exportação iniciada', $batchId, ['total' => count($contacts)]);

        $synced = $failed = 0;

        foreach ($contacts as $row) {
            $email = $row['email'];

            try {
                $this->syncContactToMautic($row, $batchId);
                $this->state->markExported($email);
                $synced++;
            } catch (\Throwable $e) {
                $this->logger->warn("Exportação falhou para {$email}", $batchId, [
                    'erro' => $e->getMessage(),
                ]);
                $failed++;
                // last_export NÃO é atualizado: o contato será reprocessado na próxima execução
            }
        }

        $this->logger->info('Exportação concluída', $batchId, compact('synced', 'failed'));
    }

    // ---------------------------------------------------------------
    // Mautic
    // ---------------------------------------------------------------

    private function syncContactToMautic(array $row, string $batchId): void
    {
        $email = $row['email'];

        // Monta payload com todos os campos de dados (non-meta, non-null)
        $payload = ['email' => $email];

        foreach ($row as $col => $value) {
            if (in_array($col, self::META_COLS, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$col] = $value;
        }

        // Busca contato existente pelo email
        $search   = $this->mauticRequest('GET', '/api/contacts?search=' . urlencode("email:{$email}"));
        $contacts = $search['contacts'] ?? [];

        // Confirma que o primeiro resultado é exatamente o email buscado (evita
        // falso-positivo se o Mautic retornar outros contatos por busca parcial)
        $existingId = null;
        foreach ($contacts as $id => $contact) {
            if (strtolower($contact['fields']['core']['email']['value'] ?? '') === strtolower($email)) {
                $existingId = $id;
                break;
            }
        }

        if ($existingId !== null) {
            $this->mauticRequest('PATCH', "/api/contacts/{$existingId}/edit?overwriteWithBlank=false", $payload);
        } else {
            $this->mauticRequest('POST', '/api/contacts/new', $payload);
        }
    }

    /**
     * Executa uma requisição HTTP para a API Mautic com retry e backoff exponencial.
     */
    private function mauticRequest(string $method, string $path, array $data = []): array
    {
        $url       = rtrim($this->mauticBaseUrl, '/') . $path;
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAUTIC_MAX_RETRIES; $attempt++) {
            $ch = curl_init();

            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "{$this->mauticUser}:{$this->mauticPass}",
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
            ];

            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            } elseif ($method === 'PATCH') {
                $opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $opts[CURLOPT_POSTFIELDS]    = json_encode($data);
            }

            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300) {
                return json_decode((string) $response, true) ?? [];
            }

            $lastError = "HTTP {$code}";

            // Erros transientes: rede (0) ou servidor (5xx)
            if ($code === 0 || $code >= 500) {
                if ($attempt < self::MAUTIC_MAX_RETRIES) {
                    sleep(2 ** $attempt);
                }
                continue;
            }

            // Erro permanente (4xx)
            throw new \RuntimeException("Mautic HTTP {$code} em {$path}");
        }

        throw new \RuntimeException(
            "Mautic indisponível após " . self::MAUTIC_MAX_RETRIES . " tentativas em {$path}. Último erro: {$lastError}"
        );
    }
}
