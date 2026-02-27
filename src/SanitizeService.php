<?php
declare(strict_types=1);

class SanitizeService
{
    private const POLL_TIMEOUT   = 300; // segundos máximos aguardando NeverBounce
    private const NB_MAX_RETRIES = 5;

    // Domínios de email descartável/temporário — classificados localmente sem consumir créditos
    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'mailinator2.com',
        'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org',
        'guerrillamail.biz', 'guerrillamail.de', 'guerrillamail.info',
        'sharklasers.com', 'guerrillamailblock.com', 'grr.la', 'spam4.me',
        '10minutemail.com', '10minutemail.net', '10minutemail.org',
        'minutemail.com', 'tempr.email', 'tempmail.com', 'tempmail.net',
        'temp-mail.org', 'temp-mail.ru', 'tempmailer.com',
        'yopmail.com', 'yopmail.fr',
        'trashmail.com', 'trashmail.net', 'trashmail.at',
        'trashmail.io', 'trashmail.me', 'trashmail.org',
        'dispostable.com', 'maildrop.cc', 'discard.email',
        'mailnesia.com', 'mailnull.com', 'spamgourmet.com',
        'fakeinbox.com', 'throwaway.email', 'jetable.net',
        'jetable.com', 'jetable.org', 'nomail.com',
    ];

    public function __construct(
        private StateDB $state,
        private Logger  $logger,
        private string  $apiKey,
        private array   $sanitizeConfig,
    ) {}

    public function run(): void
    {
        $batchId   = uniqid('sanitize_', true);
        $batchSize = (int) ($this->sanitizeConfig['batch_size'] ?? 100);
        $orderBy   = (string) ($this->sanitizeConfig['order_by']   ?? 'last_import');
        $orderDir  = (string) ($this->sanitizeConfig['order_dir']  ?? 'DESC');

        $where   = ($this->sanitizeConfig['where'] ?? null) ?: null;
        $emails = $this->state->fetchUnsanitized($batchSize, $orderBy, $orderDir, $where);

        if (empty($emails)) {
            $this->logger->info('Nenhum email para sanitizar', $batchId);
            return;
        }

        $this->logger->info('Sanitização iniciada', $batchId, ['total' => count($emails)]);

        // Filtro local: classifica sem consumir créditos NeverBounce
        $localResults = [];
        $remoteEmails = [];

        foreach ($emails as $email) {
            $localStatus = $this->localValidate($email);
            if ($localStatus !== null) {
                $localResults[$email] = $localStatus;
            } else {
                $remoteEmails[] = $email;
            }
        }

        if (!empty($localResults)) {
            foreach ($localResults as $email => $status) {
                $this->state->markSanitized($email, $status);
            }
            $this->logger->info('Filtro local concluído', $batchId, [
                'descartados' => count($localResults),
                'resultados'  => array_count_values(array_values($localResults)),
            ]);
        }

        // Envia para NeverBounce apenas o que passou pelo filtro local
        $remoteResults = [];

        if (!empty($remoteEmails)) {
            $nbResults = $this->callNeverBounce($remoteEmails, $batchId);
            foreach ($remoteEmails as $email) {
                $status = $nbResults[$email] ?? 'unknown';
                $remoteResults[$email] = $status;
                $this->state->markSanitized($email, $status);
            }
        }

        $allResults = array_merge($localResults, $remoteResults);
        $this->logger->info('Sanitização concluída', $batchId, [
            'processados'  => count($emails),
            'local'        => count($localResults),
            'neverbounce'  => count($remoteEmails),
            'resultados'   => array_count_values(array_values($allResults)),
        ]);
    }

    // ---------------------------------------------------------------
    // Filtro local
    // ---------------------------------------------------------------

    /**
     * Classifica o email localmente sem consumir créditos NeverBounce.
     * Retorna o status final se determinável; null se precisar de verificação remota.
     */
    private function localValidate(string $email): ?string
    {
        // Sintaxe inválida segundo RFC
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        // Domínio descartável/temporário conhecido
        $domain = strtolower(substr($email, strpos($email, '@') + 1));
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return 'disposable';
        }

        return null;
    }

    // ---------------------------------------------------------------
    // NeverBounce
    // ---------------------------------------------------------------

    private function callNeverBounce(array $emails, string $batchId): array
    {
        $input = array_map(fn(string $e) => ['email' => $e], $emails);

        // Cria o job
        $job = $this->nbRequest('POST', 'https://api.neverbounce.com/v4/jobs/create', [
            'key'            => $this->apiKey,
            'input_location' => 'supplied',
            'auto_parse'     => 1,
            'auto_start'     => 1,
            'input'          => $input,
        ]);

        if (!isset($job['job_id'])) {
            throw new \RuntimeException('NeverBounce não retornou job_id.');
        }

        $jobId = $job['job_id'];
        $this->logger->info('Job NeverBounce criado', $batchId, ['job_id' => $jobId]);

        // Aguarda conclusão (poll a cada 3s)
        $pollStart = time();
        while (true) {
            sleep(3);

            if ((time() - $pollStart) >= self::POLL_TIMEOUT) {
                throw new \RuntimeException(
                    "Job NeverBounce {$jobId} excedeu o timeout de " . self::POLL_TIMEOUT . "s."
                );
            }

            $status = $this->nbRequest(
                'GET',
                "https://api.neverbounce.com/v4/jobs/status?key={$this->apiKey}&job_id={$jobId}"
            );

            if ($status['job_status'] === 'failed') {
                throw new \RuntimeException(
                    "Job NeverBounce {$jobId} falhou: " . ($status['failure_reason'] ?? 'motivo desconhecido')
                );
            }

            if ($status['job_status'] === 'complete') {
                $this->logger->info('Job NeverBounce concluído', $batchId, ['job_id' => $jobId]);
                break;
            }

            $this->logger->info('Aguardando NeverBounce', $batchId, [
                'job_id' => $jobId,
                'status' => $status['job_status'],
            ]);
        }

        // Coleta resultados paginados
        $results = [];
        $page    = 1;

        do {
            $resp = $this->nbRequest(
                'GET',
                "https://api.neverbounce.com/v4/jobs/results?key={$this->apiKey}&job_id={$jobId}&page={$page}"
            );

            foreach ($resp['results'] ?? [] as $item) {
                $itemEmail  = $item['data']['email']          ?? null;
                $itemResult = $item['verification']['result'] ?? null;
                if ($itemEmail === null || $itemResult === null) {
                    $this->logger->warn('Item NeverBounce com estrutura inesperada', $batchId, ['item' => $item]);
                    continue;
                }
                $results[$itemEmail] = $itemResult;
            }

            $totalPages = (int) ($resp['total_pages'] ?? 1);
            $page++;

        } while ($page <= $totalPages);

        return $results;
    }

    /**
     * Executa uma requisição HTTP para a API NeverBounce com retry e backoff exponencial.
     */
    private function nbRequest(string $method, string $url, array $data = []): array
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::NB_MAX_RETRIES; $attempt++) {
            $ch = curl_init();

            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ];

            if ($method === 'POST') {
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
            }

            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $decoded = json_decode((string) $response, true);
                if (($decoded['status'] ?? '') !== 'success') {
                    throw new \RuntimeException(
                        'NeverBounce erro: ' . ($decoded['message'] ?? 'resposta inesperada')
                    );
                }
                return $decoded;
            }

            $lastError = "HTTP {$code}";

            // Erros transientes: rede (0), servidor (5xx), rate limit (429)
            if ($code === 0 || $code >= 500 || $code === 429) {
                if ($attempt < self::NB_MAX_RETRIES) {
                    sleep(2 ** $attempt);
                }
                continue;
            }

            // Erro permanente (4xx, exceto 429)
            throw new \RuntimeException("NeverBounce HTTP {$code} em {$url}");
        }

        throw new \RuntimeException(
            "NeverBounce indisponível após " . self::NB_MAX_RETRIES . " tentativas. Último erro: {$lastError}"
        );
    }
}
