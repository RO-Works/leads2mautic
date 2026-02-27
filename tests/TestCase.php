<?php
declare(strict_types=1);

/**
 * Classe base leve para testes — sem dependências externas.
 *
 * Cada método público cujo nome começa com "test" é descoberto automaticamente
 * e executado pelo runner. Falhas de asserção são acumuladas por método;
 * exceções não capturadas são reportadas como erros.
 */
abstract class TestCase
{
    private int   $assertions = 0;
    private array $failures   = [];

    // ---------------------------------------------------------------
    // Runner
    // ---------------------------------------------------------------

    public function run(): array
    {
        $methods = array_filter(
            get_class_methods($this),
            fn(string $m) => str_starts_with($m, 'test')
        );
        sort($methods);

        $results = [];

        foreach ($methods as $method) {
            $this->assertions = 0;
            $this->failures   = [];

            try {
                $this->$method();

                $results[$method] = empty($this->failures)
                    ? ['status' => 'pass', 'assertions' => $this->assertions]
                    : ['status' => 'fail', 'failures' => $this->failures, 'assertions' => $this->assertions];

            } catch (\Throwable $e) {
                $results[$method] = [
                    'status' => 'error',
                    'error'  => $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine(),
                ];
            }
        }

        return $results;
    }

    // ---------------------------------------------------------------
    // Asserções
    // ---------------------------------------------------------------

    protected function assertTrue(bool $value, string $msg = ''): void
    {
        $this->assertions++;
        if (!$value) {
            $this->failures[] = $msg ?: 'Expected true, got false';
        }
    }

    protected function assertFalse(bool $value, string $msg = ''): void
    {
        $this->assertions++;
        if ($value) {
            $this->failures[] = $msg ?: 'Expected false, got true';
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $exp = var_export($expected, true);
            $act = var_export($actual, true);
            $this->failures[] = ($msg ? "{$msg}\n  " : '') . "expected: {$exp}\n  actual:   {$act}";
        }
    }

    protected function assertNotEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            $this->failures[] = ($msg ?: 'Expected values to differ') . ': ' . var_export($actual, true);
        }
    }

    protected function assertNull(mixed $value, string $msg = ''): void
    {
        $this->assertions++;
        if ($value !== null) {
            $this->failures[] = ($msg ?: 'Expected null') . ', got: ' . var_export($value, true);
        }
    }

    protected function assertNotNull(mixed $value, string $msg = ''): void
    {
        $this->assertions++;
        if ($value === null) {
            $this->failures[] = $msg ?: 'Expected non-null, got null';
        }
    }

    protected function assertCount(int $expected, array $array, string $msg = ''): void
    {
        $this->assertions++;
        $actual = count($array);
        if ($expected !== $actual) {
            $this->failures[] = ($msg ?: 'assertCount') . ": expected {$expected} items, got {$actual}";
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $haystack, true)) {
            $this->failures[] = ($msg ?: 'assertContains') . ': ' . var_export($needle, true) . ' not found in array';
        }
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (in_array($needle, $haystack, true)) {
            $this->failures[] = ($msg ?: 'assertNotContains') . ': ' . var_export($needle, true) . ' found in array';
        }
    }

    /**
     * Verifica que $fn lança uma exceção da classe esperada.
     */
    protected function assertThrows(string $exceptionClass, callable $fn, string $msg = ''): void
    {
        $this->assertions++;
        $thrown = null;
        try {
            $fn();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        if ($thrown === null) {
            $this->failures[] = ($msg ?: "Expected {$exceptionClass}") . ', none was thrown';
        } elseif (!($thrown instanceof $exceptionClass)) {
            $this->failures[] = ($msg ?: "Expected {$exceptionClass}") . ', got ' . get_class($thrown) . ': ' . $thrown->getMessage();
        }
    }
}
