<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3ClientConfig;
use Elavora\Api\Extension\StorageS3\S3Storage;
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use PHPUnit\Framework\TestCase;

final class S3BucketValidationTest extends TestCase
{
    public function testPreservesValidBucketAndRemovesItFromClientOptions(): void
    {
        $handler = new MockHandler([new Result([])]);
        $storage = new S3Storage($this->client($handler), 'uploads');

        $storage->put('reports/example.txt', 'content');

        self::assertSame('uploads', $handler->getLastCommand()['Bucket']);
        self::assertArrayNotHasKey(
            'bucket',
            S3ClientConfig::fromStorageConfig([
                'bucket' => 'uploads',
                'region' => 'us-east-1',
            ])->options()
        );
    }

    public function testRejectsInvalidBucketWhenStorageIsCreatedDirectly(): void
    {
        foreach ([' uploads ', '   ', 123, null, false] as $bucket) {
            try {
                new S3Storage($this->client(new MockHandler()), $bucket);
                self::fail('O bucket invalido deveria ser rejeitado.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testExtensionRejectsInvalidBucketBeforeCreatingClient(): void
    {
        $factory = new class implements S3ClientFactory {
            public int $calls = 0;

            public function client(S3ClientConfig $config): S3Client
            {
                $this->calls++;

                return new S3Client([
                    'credentials' => ['key' => 'key', 'secret' => 'secret'],
                    'region' => 'us-east-1',
                    'version' => 'latest',
                ]);
            }
        };

        foreach ([' uploads ', '   ', 123, null, false] as $bucket) {
            try {
                new S3StorageExtension(
                    config: ['bucket' => $bucket],
                    clientFactory: $factory
                );
                self::fail('A extensao deveria rejeitar o bucket invalido.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, $factory->calls);
    }

    private function client(MockHandler $handler): S3Client
    {
        return new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => $handler,
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
    }
}
