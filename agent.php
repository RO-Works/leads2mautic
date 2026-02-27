#!/usr/bin/php
<?php
declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

// ============================================================
// AUTOLOADER
// ============================================================

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . basename(str_replace('\\', '/', $class)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// ============================================================
// .ENV
// ============================================================

(function (): void {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        exit("Erro: arquivo .env não encontrado. Copie .env.example para .env e preencha as variáveis.\n");
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        if (str_starts_with(trim($linha), '#') || !str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $valor = trim($valor);
        if (strlen($valor) >= 2 &&
            (($valor[0] === '"' && $valor[-1] === '"') ||
             ($valor[0] === "'" && $valor[-1] === "'"))) {
            $valor = substr($valor, 1, -1);
        } elseif (($pos = strpos($valor, ' #')) !== false) {
            $valor = rtrim(substr($valor, 0, $pos));
        }
        putenv(trim($chave) . '=' . $valor);
    }
})();

// ============================================================
// CONFIGURAÇÃO
// ============================================================

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    exit("Erro: arquivo config.php não encontrado.\n");
}

$config = require $configFile;

// ============================================================
// ARGUMENTOS CLI
// ============================================================

$mode = null;

foreach (array_slice($argv, 1) as $arg) {
    match ($arg) {
        '--import'     => $mode = 'import',
        '--sanitize'   => $mode = 'sanitize',
        '--export'     => $mode = 'export',
        '--statistics' => $mode = 'statistics',
        default        => exit("Argumento desconhecido: {$arg}\n"),
    };
}

if ($mode === null) {
    exit("Uso: php agent.php --import | --sanitize | --export | --statistics\n");
}

// Fontes são necessárias apenas para --import
if ($mode === 'import' && empty($config['sources'])) {
    exit("Aviso: nenhuma fonte configurada em config.php.\n");
}

// ============================================================
// VARIÁVEIS OBRIGATÓRIAS (por modo)
// ============================================================

$requiredEnvVars = match ($mode) {
    'import'     => [],
    'sanitize'   => ['NEVERBOUNCE_API_KEY'],
    'export'     => ['MAUTIC_BASE_URL', 'MAUTIC_USER', 'MAUTIC_PASS'],
    'statistics' => [],
};

foreach ($requiredEnvVars as $var) {
    $val = getenv($var);
    if ($val === false || $val === '') {
        exit("Erro: variável de ambiente '{$var}' não definida no .env.\n");
    }
}

// ============================================================
// STATISTICS — leitura pura, sem lock, sem Logger
// ============================================================

if ($mode === 'statistics') {
    $stateDb = new StateDB($config['state_db']);
    $s       = $stateDb->getStatistics();
    $stateDb->close();

    $fmt = fn(int $n): string => number_format($n, 0, ',', '.');
    $pct = fn(int $n, int $total): string =>
        $total > 0 ? sprintf('%3d%%', (int) round($n / $total * 100)) : '  — ';

    $valid      = $s['by_status']['valid'] ?? 0;
    $sanitized  = $s['total'] - $s['pending_sanitize'];
    $bar        = str_repeat('━', 46);

    echo "\n{$bar}\n ESTATÍSTICAS — leads2mautic\n{$bar}\n\n";

    printf(" %-36s %7s\n", 'Total de emails na base', $fmt($s['total']));

    echo "\n ── Higienização " . str_repeat('─', 29) . "\n";
    printf(" %-36s %7s   %s\n", 'Pendentes (fila)',  $fmt($s['pending_sanitize']), $pct($s['pending_sanitize'], $s['total']));
    printf(" %-36s %7s   %s\n", 'Higienizados',      $fmt($sanitized),             $pct($sanitized, $s['total']));
    foreach ($s['by_status'] as $status => $count) {
        printf("   %-34s %7s\n", $status, $fmt($count));
    }

    echo "\n ── Exportação " . str_repeat('─', 31) . "\n";
    printf(" %-36s %7s   %s\n", 'Exportados',              $fmt($s['exported']),   $pct($s['exported'],   $valid) . ' dos válidos');
    printf(" %-36s %7s   %s\n", 'Elegíveis para exportar', $fmt($s['exportable']), $pct($s['exportable'], $valid) . ' dos válidos');

    echo "\n{$bar}\n\n";
    exit(0);
}

// ============================================================
// LOCK DE INSTÂNCIA ÚNICA (por modo)
// ============================================================

// Garante que data/ existe antes de criar o arquivo de lock.
// (StateDB também cria o diretório, mas é instanciado depois.)
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$lockFile = __DIR__ . '/data/agent-' . $mode . '.lock';
$lockFp   = fopen($lockFile, 'c');

if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit("Já existe uma instância de --{$mode} em execução. Aguarde o término ou remova {$lockFile}.\n");
}

register_shutdown_function(function () use ($lockFp, $lockFile): void {
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    @unlink($lockFile);
});

// ============================================================
// BOOTSTRAP
// ============================================================

$logger  = new Logger(__DIR__ . '/agent.log');
$stateDb = new StateDB($config['state_db']);

// ============================================================
// EXECUÇÃO
// ============================================================

try {
    $service = match ($mode) {
        'import'   => new ImportService(
            $stateDb,
            $logger,
            $config['sources']
        ),
        'sanitize' => new SanitizeService(
            $stateDb,
            $logger,
            (string) getenv('NEVERBOUNCE_API_KEY'),
            $config['sanitize']
        ),
        'export'   => new ExportService(
            $stateDb,
            $logger,
            (string) getenv('MAUTIC_BASE_URL'),
            (string) getenv('MAUTIC_USER'),
            (string) getenv('MAUTIC_PASS'),
            $config['export']
        ),
    };

    $service->run();

} catch (\Throwable $e) {
    $logger->error('Erro fatal', '', [
        'modo'    => $mode,
        'erro'    => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha'   => $e->getLine(),
        'trace'   => $e->getTraceAsString(),
    ]);
    exit(1);
} finally {
    $stateDb->close();
}
