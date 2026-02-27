#!/usr/bin/php
<?php
declare(strict_types=1);

// ---------------------------------------------------------------
// Source classes
// ---------------------------------------------------------------

require __DIR__ . '/../src/Logger.php';
require __DIR__ . '/../src/StateDB.php';
require __DIR__ . '/../src/SanitizeService.php';

// ---------------------------------------------------------------
// Test infrastructure
// ---------------------------------------------------------------

require __DIR__ . '/TestCase.php';

/**
 * Logger silencioso para testes: descarta toda saída para não poluir o relatório.
 */
class SilentLogger extends Logger
{
    public function __construct() {}
    public function info(string $msg, string $batchId = '', array $context = []): void {}
    public function warn(string $msg, string $batchId = '', array $context = []): void {}
    public function error(string $msg, string $batchId = '', array $context = []): void {}
}

// ---------------------------------------------------------------
// Test suites
// ---------------------------------------------------------------

require __DIR__ . '/StateDBTest.php';
require __DIR__ . '/SanitizeServiceTest.php';

// ---------------------------------------------------------------
// Runner
// ---------------------------------------------------------------

$suites = [
    new StateDBTest(),
    new SanitizeServiceTest(),
];

$totalPassed = $totalFailed = $totalErrors = 0;

foreach ($suites as $suite) {
    $className = get_class($suite);
    $results   = $suite->run();

    echo "\n\033[1m{$className}\033[0m\n";
    echo str_repeat('─', 64) . "\n";

    foreach ($results as $testName => $result) {
        if ($result['status'] === 'pass') {
            echo "  \033[32m✓\033[0m {$testName}";
            echo " \033[90m({$result['assertions']})\033[0m\n";
            $totalPassed++;

        } elseif ($result['status'] === 'fail') {
            echo "  \033[31m✗\033[0m {$testName}\n";
            foreach ($result['failures'] as $failure) {
                foreach (explode("\n", $failure) as $line) {
                    echo "      \033[31m{$line}\033[0m\n";
                }
            }
            $totalFailed++;

        } else {
            echo "  \033[33m!\033[0m {$testName}\n";
            foreach (explode("\n", $result['error']) as $line) {
                echo "      \033[33m{$line}\033[0m\n";
            }
            $totalErrors++;
        }
    }
}

$total = $totalPassed + $totalFailed + $totalErrors;

echo "\n" . str_repeat('─', 64) . "\n";

if ($totalFailed === 0 && $totalErrors === 0) {
    echo "\033[32mAll {$total} tests passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m{$totalFailed} failed · {$totalErrors} errors · {$totalPassed} passed · {$total} total\033[0m\n\n";
    exit(1);
}
