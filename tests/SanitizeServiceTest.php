<?php
declare(strict_types=1);

class SanitizeServiceTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeService(): SanitizeService
    {
        return new SanitizeService(
            new StateDB(':memory:'),
            new SilentLogger(),
            'fake_api_key',
            []
        );
    }

    /** Invoca o método privado localValidate via reflexão. */
    private function localValidate(SanitizeService $service, string $email): ?string
    {
        $method = new ReflectionMethod(SanitizeService::class, 'localValidate');
        $method->setAccessible(true);
        return $method->invoke($service, $email);
    }

    /** Retorna a constante DISPOSABLE_DOMAINS via reflexão. */
    private function disposableDomains(): array
    {
        $rc = new ReflectionClassConstant(SanitizeService::class, 'DISPOSABLE_DOMAINS');
        return $rc->getValue();
    }

    // ---------------------------------------------------------------
    // Validação de sintaxe (filter_var)
    // ---------------------------------------------------------------

    public function testValidEmailReturnsNull(): void
    {
        $svc = $this->makeService();
        $this->assertNull($this->localValidate($svc, 'user@example.com'),        'email válido deve passar para o NeverBounce');
        $this->assertNull($this->localValidate($svc, 'user+tag@sub.domain.com'), 'email com tag e subdomínio deve passar');
        $this->assertNull($this->localValidate($svc, 'nome.sobrenome@empresa.com.br'), 'email com ponto no local-part deve passar');
    }

    public function testEmailWithoutAtReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, 'notanemail'), 'string sem @ deve ser inválida');
    }

    public function testEmailMissingLocalPartReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, '@example.com'), 'local-part ausente deve ser inválido');
    }

    public function testEmailMissingDomainReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, 'user@'), 'domínio ausente deve ser inválido');
    }

    public function testEmailWithSpaceReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, 'user @example.com'), 'espaço no email deve ser inválido');
        $this->assertEquals('invalid', $this->localValidate($svc, 'user@ example.com'), 'espaço no domínio deve ser inválido');
    }

    public function testEmailWithDoubleAtReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, 'a@@b.com'), 'duplo @ deve ser inválido');
    }

    public function testEmptyStringReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, ''), 'string vazia deve ser inválida');
    }

    public function testPlainDomainWithoutLocalPartReturnsInvalid(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('invalid', $this->localValidate($svc, 'example.com'), 'domínio sem @ deve ser inválido');
    }

    // ---------------------------------------------------------------
    // Blocklist de domínios descartáveis
    // ---------------------------------------------------------------

    public function testMailinatorIsDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@mailinator.com'));
    }

    public function testGuerrillaMailIsDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@guerrillamail.com'));
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@guerrillamail.net'));
    }

    public function testTenMinuteMailIsDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@10minutemail.com'));
    }

    public function testYopMailIsDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@yopmail.com'));
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@yopmail.fr'));
    }

    public function testTrashMailIsDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@trashmail.com'));
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@trashmail.at'));
    }

    public function testGmailIsNotDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertNull($this->localValidate($svc, 'user@gmail.com'), 'gmail.com deve passar para o NeverBounce');
    }

    public function testYahooIsNotDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertNull($this->localValidate($svc, 'user@yahoo.com'), 'yahoo.com deve passar para o NeverBounce');
    }

    public function testHotmailIsNotDisposable(): void
    {
        $svc = $this->makeService();
        $this->assertNull($this->localValidate($svc, 'user@hotmail.com'), 'hotmail.com deve passar para o NeverBounce');
    }

    public function testDomainCheckIsCaseInsensitive(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@MAILINATOR.COM'),     'domínio em maiúsculas deve ser detectado');
        $this->assertEquals('disposable', $this->localValidate($svc, 'test@Guerrillamail.Com'),  'domínio em capitalização mista deve ser detectado');
        $this->assertEquals('disposable', $this->localValidate($svc, 'TEST@TRASHMAIL.COM'),      'local-part em maiúsculas não interfere na detecção do domínio');
    }

    public function testSubdomainOfDisposableIsNotBlocked(): void
    {
        $svc = $this->makeService();
        // Correspondência exata — subdomínios não estão na lista
        $this->assertNull(
            $this->localValidate($svc, 'test@mail.mailinator.com'),
            'subdomínio de domínio descartável não está na lista e deve passar para NeverBounce'
        );
    }

    public function testAllListedDisposableDomainsAreClassified(): void
    {
        $svc     = $this->makeService();
        $domains = $this->disposableDomains();
        $missed  = [];

        foreach ($domains as $domain) {
            if ($this->localValidate($svc, "test@{$domain}") !== 'disposable') {
                $missed[] = $domain;
            }
        }

        $this->assertCount(
            0,
            $missed,
            'domínios listados em DISPOSABLE_DOMAINS que não foram classificados: ' . implode(', ', $missed)
        );
    }
}
