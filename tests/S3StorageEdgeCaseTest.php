<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\NativeS3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3ClientConfig;
use Elavora\Api\Extension\StorageS3\S3Storage;
use PHPUnit\Framework\TestCase;

final class S3StorageEdgeCaseTest extends TestCase
{
    public function testNativeFactoryCreatesClientFromConfiguration(): void
    {
        $client = (new NativeS3ClientFactory())->client(S3ClientConfig::fromStorageConfig([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'region' => 'sa-east-1',
        ]));

        self::assertSame('sa-east-1', $client->getRegion());
    }

    public function testRejectsExpiredTemporaryUrl(): void
    {
        $storage = new S3Storage($this->client(), 'uploads');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A expiracao da URL S3 deve estar no futuro.');

        $storage->temporaryUrl('reports/example.txt', new DateTimeImmutable('-1 minute'));
    }

    public function testRejectsInvalidObjectKeysBeforeCallingS3(): void
    {
        $storage = new S3Storage($this->client(), 'uploads');

        foreach (['', '/', 'folder//file', 'folder/./file', 'folder/../file', "folder/\0file"] as $key) {
            try {
                $storage->put($key, 'content');
                self::fail('A chave S3 invalida deveria ser rejeitada.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function client(): S3Client
    {
        return new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler(),
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
    }
}
