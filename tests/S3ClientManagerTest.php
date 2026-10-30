<?php

declare(strict_types=1);

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory;
use Elavora\Api\Extension\StorageS3\FixedS3ClientFactory;
use Elavora\Api\Extension\StorageS3\NativeS3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3ClientConfig;
use Elavora\Api\Extension\StorageS3\S3ClientManager;
use Elavora\Api\Extension\StorageS3\S3ServiceRegistrar;
use Elavora\Api\Framework\Application;
use PHPUnit\Framework\TestCase;

/**
 * Gerenciador de clientes, registrador e factories.
 *
 * @see docs/USO.md#s3clientmanager
 * @see docs/USO.md#s3serviceregistrar
 * @see docs/USO.md#factories-de-cliente
 */
final class S3ClientManagerTest extends TestCase
{
    public function testManagerIsAnS3ClientFactory(): void
    {
        self::assertInstanceOf(S3ClientFactory::class, new S3ClientManager(new TrackingS3ClientFactory()));
    }

    public function testManagerCreatesTheClientOnFirstSightOfAConfiguration(): void
    {
        $factory = new TrackingS3ClientFactory();
        $manager = new S3ClientManager($factory);
        $config = S3ClientConfig::fromStorageConfig(['bucket' => 'uploads']);

        $client = $manager->client($config);

        self::assertSame(1, $factory->calls);
        self::assertSame($config, $factory->lastConfig);
        self::assertSame($factory->created[0], $client);
    }

    public function testManagerReusesTheClientForEqualConfigurationsEvenWithDifferentOrder(): void
    {
        $factory = new TrackingS3ClientFactory();
        $manager = new S3ClientManager($factory);

        $first = $manager->client(new S3ClientConfig(['region' => 'us-east-1', 'version' => 'latest']));
        $second = $manager->client(new S3ClientConfig(['version' => 'latest', 'region' => 'us-east-1']));

        self::assertSame($first, $second);
        self::assertSame(1, $factory->calls);
    }

    public function testManagerCallsTheFactoryAgainForDifferentConfigurations(): void
    {
        $factory = new TrackingS3ClientFactory();
        $manager = new S3ClientManager($factory);

        $first = $manager->client(new S3ClientConfig(['region' => 'us-east-1']));
        $second = $manager->client(new S3ClientConfig(['region' => 'sa-east-1']));
        $again = $manager->client(new S3ClientConfig(['region' => 'us-east-1']));

        self::assertNotSame($first, $second);
        self::assertSame($first, $again);
        self::assertSame(2, $factory->calls);
    }

    public function testManagerUsesTheNativeFactoryByDefault(): void
    {
        $manager = new S3ClientManager();

        $client = $manager->client(S3ClientConfig::fromStorageConfig([
            'bucket' => 'uploads',
            'region' => 'sa-east-1',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
        ]));

        self::assertInstanceOf(S3Client::class, $client);
        self::assertSame('sa-east-1', $client->getRegion());
    }

    public function testNativeFactoryCreatesANewClientOnEveryCall(): void
    {
        $factory = new NativeS3ClientFactory();
        $config = S3ClientConfig::fromStorageConfig([
            'bucket' => 'uploads',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
        ]);

        $first = $factory->client($config);
        $second = $factory->client($config);

        self::assertInstanceOf(S3Client::class, $first);
        self::assertNotSame($first, $second);
        self::assertSame('us-east-1', $first->getRegion());
    }

    public function testNativeFactoryPassesTheOptionsToTheClient(): void
    {
        $client = (new NativeS3ClientFactory())->client(S3ClientConfig::fromStorageConfig([
            'bucket' => 'uploads',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
        ]));

        self::assertStringContainsString('minio:9000', (string) $client->getEndpoint());
    }

    public function testFixedFactoryAlwaysReturnsTheSameClientIgnoringTheConfiguration(): void
    {
        $client = $this->client();
        $factory = new FixedS3ClientFactory($client);

        self::assertSame($client, $factory->client(new S3ClientConfig(['region' => 'sa-east-1'])));
        self::assertSame($client, $factory->client(new S3ClientConfig(['region' => 'eu-west-1'])));
    }

    public function testFactoriesImplementTheContract(): void
    {
        self::assertInstanceOf(S3ClientFactory::class, new NativeS3ClientFactory());
        self::assertInstanceOf(S3ClientFactory::class, new FixedS3ClientFactory($this->client()));
    }

    public function testRegistrarRegistersAManagerThatUsesTheGivenFactory(): void
    {
        $application = Application::create();
        $factory = new TrackingS3ClientFactory();

        S3ServiceRegistrar::register($application, $factory);

        $registered = $application->container()->get(S3ClientFactory::class);
        self::assertInstanceOf(S3ClientManager::class, $registered);

        $config = new S3ClientConfig(['region' => 'us-east-1']);
        self::assertSame($registered->client($config), $registered->client($config));
        self::assertSame(1, $factory->calls);
    }

    public function testRegistrarRegistersAManagerWithTheNativeFactoryWhenNoneIsGiven(): void
    {
        $application = Application::create();

        S3ServiceRegistrar::register($application);

        $registered = $application->container()->get(S3ClientFactory::class);
        self::assertInstanceOf(S3ClientManager::class, $registered);

        $client = $registered->client(new S3ClientConfig([
            'region' => 'sa-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
        ]));
        self::assertSame('sa-east-1', $client->getRegion());
    }

    public function testRegistrarKeepsTheFactoryAlreadyInTheContainer(): void
    {
        $application = Application::create();
        $existing = new TrackingS3ClientFactory();
        $application->container()->instance(S3ClientFactory::class, $existing);
        $ignored = new TrackingS3ClientFactory();

        S3ServiceRegistrar::register($application, $ignored);

        self::assertSame($existing, $application->container()->get(S3ClientFactory::class));
        self::assertSame(0, $ignored->calls);
    }

    public function testRegistrarCalledTwiceKeepsTheFirstManager(): void
    {
        $application = Application::create();
        $first = new TrackingS3ClientFactory();
        $second = new TrackingS3ClientFactory();

        S3ServiceRegistrar::register($application, $first);
        $manager = $application->container()->get(S3ClientFactory::class);
        S3ServiceRegistrar::register($application, $second);

        self::assertSame($manager, $application->container()->get(S3ClientFactory::class));

        $config = new S3ClientConfig(['region' => 'us-east-1']);
        $manager->client($config);
        self::assertSame(1, $first->calls);
        self::assertSame(0, $second->calls);
    }

    private function client(): S3Client
    {
        return new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([new Result([])]),
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
    }
}

final class TrackingS3ClientFactory implements S3ClientFactory
{
    public int $calls = 0;
    public ?S3ClientConfig $lastConfig = null;

    /** @var list<S3Client> */
    public array $created = [];

    public function client(S3ClientConfig $config): S3Client
    {
        $this->calls++;
        $this->lastConfig = $config;

        $client = new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([new Result([])]),
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
        $this->created[] = $client;

        return $client;
    }
}
