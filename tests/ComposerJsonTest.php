<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Campos do composer.json do pacote depois do upgrade para o framework 2.
 *
 * @see docs/USO.md#versão-e-compatibilidade
 * @see docs/AUTOMACAO.md#composerjson
 */
final class ComposerJsonTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $composer;

    protected function setUp(): void
    {
        $this->composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function testRequiresPhp83(): void
    {
        self::assertSame('>=8.3', $this->composer['require']['php'] ?? null);
    }

    public function testRequiresFramework2(): void
    {
        self::assertSame('^2.0', $this->composer['require']['elavora/api-framework'] ?? null);
    }

    public function testKeepsAwsSdkRequirement(): void
    {
        self::assertSame('^3.381', $this->composer['require']['aws/aws-sdk-php'] ?? null);
    }

    public function testBranchAliasIs10Dev(): void
    {
        self::assertSame('1.0-dev', $this->composer['extra']['branch-alias']['dev-main'] ?? null);
    }

    public function testAutoloadIsUnchanged(): void
    {
        self::assertSame(
            ['Elavora\\Api\\Extension\\StorageS3\\' => 'src/'],
            $this->composer['autoload']['psr-4'] ?? null,
        );
    }

    public function testHasNoRepositories(): void
    {
        self::assertArrayNotHasKey('repositories', $this->composer);
    }
}
