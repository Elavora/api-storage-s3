<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\FixedS3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3Storage;
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Storage;
use PHPUnit\Framework\TestCase;

final class S3DocumentationExampleTest extends TestCase
{
    public function testDocumentedFactoryInjectionRegistersStorage(): void
    {
        $client = new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler(),
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
        $application = Application::create();

        $application->extend(new S3StorageExtension(
            config: [
                'bucket' => 'uploads',
                'region' => 'us-east-1',
                'version' => 'latest',
            ],
            clientFactory: new FixedS3ClientFactory($client)
        ));
        $storage = $application->container()->get(Storage::class);

        self::assertInstanceOf(S3Storage::class, $storage);
        self::assertSame($client, $storage->client());
    }
}
