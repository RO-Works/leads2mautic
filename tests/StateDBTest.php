<?php
declare(strict_types=1);

class StateDBTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeDB(): StateDB
    {
        return new StateDB(':memory:');
    }

    /** Lê um campo direto do SQLite via reflexão, sem passar pela API pública. */
    private function queryField(StateDB $db, string $field, string $email): ?string
    {
        $rp     = new ReflectionProperty(StateDB::class, 'db');
        $rp->setAccessible(true);
        $sqlite = $rp->getValue($db);
        $stmt   = $sqlite->prepare("SELECT \"{$field}\" FROM contacts WHERE email = :e");
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return ($row !== false) ? $row[$field] : null;
    }

    /** Força um campo timestamp para simular estados temporais sem sleep(). */
    private function setField(StateDB $db, string $field, string $email, string $value): void
    {
        $rp     = new ReflectionProperty(StateDB::class, 'db');
        $rp->setAccessible(true);
        $sqlite = $rp->getValue($db);
        $stmt   = $sqlite->prepare("UPDATE contacts SET \"{$field}\" = :v WHERE email = :e");
        $stmt->bindValue(':v', $value, SQLITE3_TEXT);
        $stmt->bindValue(':e', $email, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function setLastImport(StateDB $db, string $email, string $value): void
    {
        $this->setField($db, 'last_import', $email, $value);
    }

    private function countRows(StateDB $db): int
    {
        $rp     = new ReflectionProperty(StateDB::class, 'db');
        $rp->setAccessible(true);
        $sqlite = $rp->getValue($db);
        $row    = $sqlite->query('SELECT COUNT(*) AS c FROM contacts')->fetchArray(SQLITE3_ASSOC);
        return (int) $row['c'];
    }

    // ---------------------------------------------------------------
    // last_import — comportamento condicional no UPSERT
    // ---------------------------------------------------------------

    public function testNewEmailGetsLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 1]], ['score' => 'INTEGER']);

        $this->assertNotNull(
            $this->queryField($db, 'last_import', 'a@b.com'),
            'novo email deve ter last_import definido'
        );
    }

    public function testUpsertSameDataPreservesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 10]], ['score' => 'INTEGER']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'a@b.com', $sentinel);

        // Mesmo dado — last_import não deve mudar
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 10]], ['score' => 'INTEGER']);

        $this->assertEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'a@b.com'),
            'last_import deve ser preservado quando os dados são idênticos'
        );
    }

    public function testUpsertChangedIntegerUpdatesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 10]], ['score' => 'INTEGER']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'a@b.com', $sentinel);

        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 11]], ['score' => 'INTEGER']);

        $this->assertNotEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'a@b.com'),
            'last_import deve ser atualizado quando coluna INTEGER muda'
        );
    }

    public function testUpsertChangedTextUpdatesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([['email' => 'a@b.com', 'name' => 'Alice']], ['name' => 'TEXT']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'a@b.com', $sentinel);

        $db->upsertFromSource([['email' => 'a@b.com', 'name' => 'Bob']], ['name' => 'TEXT']);

        $this->assertNotEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'a@b.com'),
            'last_import deve ser atualizado quando coluna TEXT muda'
        );
    }

    public function testNullToValueUpdatesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => null]], ['score' => 'INTEGER']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'a@b.com', $sentinel);

        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 5]], ['score' => 'INTEGER']);

        $this->assertNotEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'a@b.com'),
            'last_import deve ser atualizado quando NULL vira valor'
        );
    }

    public function testValueToNullUpdatesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'a@b.com', 'score' => 5]], ['score' => 'INTEGER']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'a@b.com', $sentinel);

        $db->upsertFromSource([['email' => 'a@b.com', 'score' => null]], ['score' => 'INTEGER']);

        $this->assertNotEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'a@b.com'),
            'last_import deve ser atualizado quando valor vira NULL'
        );
    }

    public function testUpsertMultipleColumnsAllSamePreservesLastImport(): void
    {
        $db  = $this->makeDB();
        $db->ensureColumns(['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']);
        $row = ['email' => 'x@y.com', 'a' => 'foo', 'b' => 42, 'c' => 3.14];
        $db->upsertFromSource([$row], ['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']);

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'x@y.com', $sentinel);

        $db->upsertFromSource([$row], ['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']);

        $this->assertEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'x@y.com'),
            'last_import não deve mudar quando todas as colunas são idênticas'
        );
    }

    public function testUpsertMultipleColumnsOneDifferentUpdatesLastImport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']);
        $db->upsertFromSource(
            [['email' => 'x@y.com', 'a' => 'foo', 'b' => 42, 'c' => 3.14]],
            ['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']
        );

        $sentinel = '2000-01-01 00:00:00';
        $this->setLastImport($db, 'x@y.com', $sentinel);

        // Apenas 'b' muda
        $db->upsertFromSource(
            [['email' => 'x@y.com', 'a' => 'foo', 'b' => 99, 'c' => 3.14]],
            ['a' => 'TEXT', 'b' => 'INTEGER', 'c' => 'REAL']
        );

        $this->assertNotEquals(
            $sentinel,
            $this->queryField($db, 'last_import', 'x@y.com'),
            'last_import deve ser atualizado quando qualquer coluna muda'
        );
    }

    // ---------------------------------------------------------------
    // Normalização de email
    // ---------------------------------------------------------------

    public function testEmailNormalizedToLowercase(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([['email' => 'USER@EXAMPLE.COM', 'name' => 'A']], ['name' => 'TEXT']);

        $this->assertNotNull(
            $this->queryField($db, 'last_import', 'user@example.com'),
            'email deve ser armazenado em lowercase'
        );
    }

    public function testEmailTrimmedOnInsert(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([['email' => '  user@example.com  ', 'name' => 'A']], ['name' => 'TEXT']);

        $this->assertNotNull(
            $this->queryField($db, 'last_import', 'user@example.com'),
            'espaços ao redor do email devem ser removidos'
        );
        $this->assertEquals(1, $this->countRows($db), 'deve existir exatamente uma linha');
    }

    public function testDuplicateEmailByCaseMergesIntoOneRow(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'User@Example.com', 'score' => 1]], ['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'user@example.com', 'score' => 2]], ['score' => 'INTEGER']);

        $this->assertEquals(
            1,
            $this->countRows($db),
            'variantes de capitalização do mesmo email devem resultar em uma única linha'
        );
    }

    // ---------------------------------------------------------------
    // Schema (ensureColumns)
    // ---------------------------------------------------------------

    public function testEnsureColumnsAddsNewColumn(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['logincount' => 'INTEGER']);

        $this->assertContains('logincount', $db->existingColumns(), 'ensureColumns deve adicionar a coluna declarada');
    }

    public function testEnsureColumnsIsIdempotent(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['logincount' => 'INTEGER']);
        $db->ensureColumns(['logincount' => 'INTEGER']); // segunda chamada não deve lançar

        $this->assertTrue(true, 'ensureColumns deve ser seguro para chamar múltiplas vezes');
    }

    public function testEnsureColumnsAcceptsAllValidTypes(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['t' => 'TEXT', 'i' => 'INTEGER', 'r' => 'REAL']);

        $cols = $db->existingColumns();
        $this->assertContains('t', $cols);
        $this->assertContains('i', $cols);
        $this->assertContains('r', $cols);
    }

    public function testEnsureColumnsRejectsReservedNameLastImport(): void
    {
        $db = $this->makeDB();
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $db->ensureColumns(['last_import' => 'TEXT']),
            'last_import é um nome reservado e deve ser rejeitado'
        );
    }

    public function testEnsureColumnsRejectsReservedNameEmail(): void
    {
        $db = $this->makeDB();
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $db->ensureColumns(['sanitize_status' => 'TEXT']),
            'sanitize_status é um nome reservado e deve ser rejeitado'
        );
    }

    public function testEnsureColumnsRejectsInvalidColumnName(): void
    {
        $db = $this->makeDB();
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $db->ensureColumns(['has-dash' => 'TEXT']),
            'nomes com hífen devem ser rejeitados'
        );
    }

    public function testEnsureColumnsRejectsInvalidType(): void
    {
        $db = $this->makeDB();
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $db->ensureColumns(['score' => 'FLOAT']),
            'FLOAT não é um tipo válido — deve ser rejeitado'
        );
    }

    // ---------------------------------------------------------------
    // Fila de sanitização (fetchUnsanitized / markSanitized)
    // ---------------------------------------------------------------

    public function testFetchUnsanitizedExcludesSanitizedEmails(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([
            ['email' => 'pending@example.com', 'name' => 'A'],
            ['email' => 'done@example.com',    'name' => 'B'],
        ], ['name' => 'TEXT']);

        $db->markSanitized('done@example.com', 'valid');

        $emails = $db->fetchUnsanitized(10, 'last_import', 'DESC');

        $this->assertCount(1, $emails);
        $this->assertEquals('pending@example.com', $emails[0]);
    }

    public function testFetchUnsanitizedRespectsBatchSize(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $rows = array_map(
            fn(int $i) => ['email' => "u{$i}@example.com", 'name' => "User{$i}"],
            range(1, 10)
        );
        $db->upsertFromSource($rows, ['name' => 'TEXT']);

        $emails = $db->fetchUnsanitized(3, 'last_import', 'DESC');

        $this->assertCount(3, $emails, 'batch_size deve ser respeitado');
    }

    public function testFetchUnsanitizedRejectsInvalidOrderDir(): void
    {
        $db = $this->makeDB();
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $db->fetchUnsanitized(10, 'last_import', 'SIDEWAYS'),
            'direção de ordenação inválida deve ser rejeitada'
        );
    }

    public function testFetchUnsanitizedRespectsWhereFilter(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([
            ['email' => 'high@example.com', 'score' => 100],
            ['email' => 'low@example.com',  'score' => 1],
        ], ['score' => 'INTEGER']);

        $emails = $db->fetchUnsanitized(10, 'last_import', 'DESC', 'score >= 50');

        $this->assertCount(1, $emails);
        $this->assertEquals('high@example.com', $emails[0]);
    }

    public function testMarkSanitizedSetsStatusAndTimestamp(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([['email' => 'test@example.com', 'name' => 'A']], ['name' => 'TEXT']);

        $db->markSanitized('test@example.com', 'valid');

        $this->assertEquals('valid', $this->queryField($db, 'sanitize_status', 'test@example.com'));
        $this->assertNotNull(
            $this->queryField($db, 'last_sanitize', 'test@example.com'),
            'last_sanitize deve ser definido após markSanitized'
        );
    }

    // ---------------------------------------------------------------
    // Fila de exportação (fetchExportable / markExported)
    // ---------------------------------------------------------------

    public function testFetchExportableReturnsOnlyValidStatus(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([
            ['email' => 'valid@example.com',   'name' => 'A'],
            ['email' => 'invalid@example.com', 'name' => 'B'],
        ], ['name' => 'TEXT']);

        $db->markSanitized('valid@example.com',   'valid');
        $db->markSanitized('invalid@example.com', 'invalid');

        $rows = $db->fetchExportable(10, 'last_import', 'DESC');

        $this->assertCount(1, $rows);
        $this->assertEquals('valid@example.com', $rows[0]['email']);
    }

    public function testFetchExportableExcludesAllNonValidStatuses(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);

        $statuses = ['invalid', 'disposable', 'catchall', 'unknown'];
        $db->upsertFromSource(
            array_map(fn($s) => ['email' => "{$s}@example.com", 'name' => $s], $statuses),
            ['name' => 'TEXT']
        );
        foreach ($statuses as $s) {
            $db->markSanitized("{$s}@example.com", $s);
        }

        $this->assertCount(
            0,
            $db->fetchExportable(10, 'last_import', 'DESC'),
            'nenhum status diferente de valid deve ser exportável'
        );
    }

    public function testFetchExportableRespectsWhereFilter(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([
            ['email' => 'fresh@example.com',    'name' => 'A'],
            ['email' => 'exported@example.com', 'name' => 'B'],
        ], ['name' => 'TEXT']);

        $db->markSanitized('fresh@example.com',    'valid');
        $db->markSanitized('exported@example.com', 'valid');
        $db->markExported('exported@example.com');

        $rows = $db->fetchExportable(10, 'last_import', 'DESC', 'last_export IS NULL');

        $this->assertCount(1, $rows);
        $this->assertEquals('fresh@example.com', $rows[0]['email']);
    }

    public function testFetchExportableExcludesUnchangedSinceLastExport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'test@example.com', 'score' => 1]], ['score' => 'INTEGER']);
        $db->markSanitized('test@example.com', 'valid');

        // Simula last_import antigo, depois registra exportação
        $this->setLastImport($db, 'test@example.com', '2000-01-01 00:00:00');
        $db->markExported('test@example.com'); // last_export = now (> last_import)

        // Re-upsert com dado IDÊNTICO → last_import permanece em 2000-01-01
        $db->upsertFromSource([['email' => 'test@example.com', 'score' => 1]], ['score' => 'INTEGER']);

        $rows = $db->fetchExportable(10, 'last_import', 'DESC', 'last_export IS NULL OR last_import > last_export');
        $this->assertCount(0, $rows, 'contato inalterado não deve entrar na fila de exportação');
    }

    public function testFetchExportableIncludesChangedSinceLastExport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['score' => 'INTEGER']);
        $db->upsertFromSource([['email' => 'test@example.com', 'score' => 1]], ['score' => 'INTEGER']);
        $db->markSanitized('test@example.com', 'valid');
        $db->markExported('test@example.com');

        // Recua last_export para o passado para garantir que last_import (now) > last_export
        // sem depender de sleep() — evita flakiness por resolução de 1 segundo do datetime('now')
        $this->setField($db, 'last_export', 'test@example.com', '2000-01-01 00:00:00');

        // Re-upsert com dado DIFERENTE → last_import = datetime('now') > '2000-01-01'
        $db->upsertFromSource([['email' => 'test@example.com', 'score' => 99]], ['score' => 'INTEGER']);

        $rows = $db->fetchExportable(10, 'last_import', 'DESC', 'last_export IS NULL OR last_import > last_export');
        $this->assertCount(1, $rows, 'contato com dado alterado deve entrar na fila de exportação');
    }

    public function testMarkExportedSetsLastExport(): void
    {
        $db = $this->makeDB();
        $db->ensureColumns(['name' => 'TEXT']);
        $db->upsertFromSource([['email' => 'test@example.com', 'name' => 'A']], ['name' => 'TEXT']);

        $this->assertNull(
            $this->queryField($db, 'last_export', 'test@example.com'),
            'last_export deve começar como NULL'
        );

        $db->markExported('test@example.com');

        $this->assertNotNull(
            $this->queryField($db, 'last_export', 'test@example.com'),
            'last_export deve ser definido após markExported'
        );
    }
}
