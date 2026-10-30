<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3ClientConfig;
use Elavora\Api\Extension\StorageS3\S3ClientManager;
use Elavora\Api\Extension\StorageS3\S3Storage;
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Extension;
use Elavora\Api\Framework\Contracts\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Registro do Storage pela extensão: bucket, cliente e factories.
 *
 * @see docs/USO.md#s3storageextension
 * @see docs/USO.md#opções-de-configuração
 * @see docs/USO.md#trocar-a-criação-do-cliente
 */
final class S3StorageExtensionTest extends TestCase
{
    public function testIsAFinalExtension(): void
    {
        $extension = new S3StorageExtension(['bucket' => 'uploads']);

        self::assertInstanceOf(Extension::class, $extension);
        self::assertTrue((new ReflectionClass(S3StorageExtension::class))->isFinal());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testMissingOrInvalidBucketOnlyFailsWhenStorageIsResolved(array $config): void
    {
        $application = Application::create();

        $extension = new S3StorageExtension($config, new S3Client($this->clientOptions()));
        $application->extend($extension);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A configuracao S3 deve informar bucket.');

        $application->container()->get(Storage::class);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'sem bucket' => [[]],
            'bucket vazio' => [['bucket' => '']],
            'bucket so com espacos' => [['bucket' => '   ']],
            'bucket numerico' => [['bucket' => 123]],
            'bucket nulo' => [['bucket' => null]],
            'bucket array' => [['bucket' => ['uploads']]],
        ];
    }

    public function testConstructorAndExtendDoNotValidateTheBucket(): void
    {
        $application = Application::create();

        $application->extend(new S3StorageExtension([]));

        self::assertInstanceOf(Application::class, $application);
    }

    public function testStorageIsCreatedOnFirstResolutionAndReused(): void
    {
        $factory = new ExtensionCountingFactory();
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads'],
            clientFactory: $factory,
        ));

        self::assertSame(0, $factory->calls);

        $first = $application->container()->get(Storage::class);
        $second = $application->container()->get(Storage::class);

        self::assertInstanceOf(S3Storage::class, $first);
        self::assertSame($first, $second);
        self::assertSame(1, $factory->calls);
    }

    public function testStorageUsesTheConfiguredBucket(): void
    {
        $factory = new ExtensionCountingFactory();
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'meu-bucket'],
            clientFactory: $factory,
        ));

        $application->container()->get(Storage::class)->put('a.txt', 'x');

        self::assertCount(1, $factory->commands);
        self::assertSame('meu-bucket', $factory->commands[0]['Bucket']);
    }

    public function testBucketIsNotPassedToTheClientConfiguration(): void
    {
        $factory = new ExtensionCountingFactory();
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads', 'endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true],
            clientFactory: $factory,
        ));

        $application->container()->get(Storage::class);

        $options = $factory->lastConfig?->options() ?? [];
        self::assertArrayNotHasKey('bucket', $options);
        self::assertSame('http://minio:9000', $options['endpoint']);
        self::assertTrue($options['use_path_style_endpoint']);
    }

    public function testClientConfigurationDefaultsAndOverrides(): void
    {
        $defaults = new ExtensionCountingFactory();
        Application::create()
            ->extend(new S3StorageExtension(['bucket' => 'uploads'], clientFactory: $defaults))
            ->container()
            ->get(Storage::class);

        $custom = new ExtensionCountingFactory();
        Application::create()
            ->extend(new S3StorageExtension(['bucket' => 'uploads', 'region' => 'sa-east-1'], clientFactory: $custom))
            ->container()
            ->get(Storage::class);

        self::assertSame('us-east-1', $defaults->lastConfig?->options()['region']);
        self::assertSame('latest', $defaults->lastConfig?->options()['version']);
        self::assertSame('sa-east-1', $custom->lastConfig?->options()['region']);
    }

    public function testClientFactoryTakesPriorityOverInjectedClient(): void
    {
        $injected = new S3Client($this->clientOptions());
        $factory = new ExtensionCountingFactory();
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads'],
            client: $injected,
            clientFactory: $factory,
        ));

        $storage = $application->container()->get(Storage::class);

        self::assertInstanceOf(S3Storage::class, $storage);
        self::assertSame(1, $factory->calls);
        self::assertSame($factory->created[0], $storage->client());
        self::assertNotSame($injected, $storage->client());
    }

    public function testInjectedClientIsUsedThroughAFixedFactoryIgnoringTheConfiguration(): void
    {
        $injected = new S3Client($this->clientOptions());
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads', 'region' => 'sa-east-1'],
            client: $injected,
        ));

        $storage = $application->container()->get(Storage::class);

        self::assertInstanceOf(S3Storage::class, $storage);
        self::assertSame($injected, $storage->client());
        self::assertSame('us-east-1', $storage->client()->getRegion());
    }

    public function testContainerFactoryRegisteredBeforeTheExtensionIgnoresClientAndClientFactory(): void
    {
        $application = Application::create();
        $fromContainer = new ExtensionCountingFactory();
        $application->container()->instance(S3ClientFactory::class, $fromContainer);
        $injected = new S3Client($this->clientOptions());
        $ignored = new ExtensionCountingFactory();

        $application->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads'],
            client: $injected,
            clientFactory: $ignored,
        ));

        $storage = $application->container()->get(Storage::class);

        self::assertInstanceOf(S3Storage::class, $storage);
        self::assertSame($fromContainer, $application->container()->get(S3ClientFactory::class));
        self::assertSame(1, $fromContainer->calls);
        self::assertSame(0, $ignored->calls);
        self::assertSame($fromContainer->created[0], $storage->client());
        self::assertNotSame($injected, $storage->client());
    }

    public function testContainerFactoryIsUsedAsIsAndReceivesTheDerivedConfiguration(): void
    {
        $application = Application::create();
        $fromContainer = new ExtensionCountingFactory();
        $application->container()->instance(S3ClientFactory::class, $fromContainer);

        $application->extend(new S3StorageExtension(['bucket' => 'uploads']));
        $application->container()->get(Storage::class);

        self::assertNotInstanceOf(S3ClientManager::class, $application->container()->get(S3ClientFactory::class));
        self::assertSame('us-east-1', $fromContainer->lastConfig?->options()['region']);
        self::assertArrayNotHasKey('bucket', $fromContainer->lastConfig?->options() ?? []);
    }

    public function testExtensionRegistersASharedManagerWhenTheContainerHasNoFactory(): void
    {
        $factory = new ExtensionCountingFactory();
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads'],
            clientFactory: $factory,
        ));

        $registered = $application->container()->get(S3ClientFactory::class);

        self::assertInstanceOf(S3ClientManager::class, $registered);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        return [
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([new Result([])]),
            'region' => 'us-east-1',
            'version' => 'latest',
        ];
    }
}

final class ExtensionCountingFactory implements S3ClientFactory
{
    public int $calls = 0;
    public ?S3ClientConfig $lastConfig = null;

    /** @var list<S3Client> */
    public array $created = [];

    /** @var list<CommandInterface> */
    public array $commands = [];

    public function client(S3ClientConfig $config): S3Client
    {
        $this->calls++;
        $this->lastConfig = $config;

        $client = new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => new MockHandler([
                function (CommandInterface $command, RequestInterface $request): Result {
                    $this->commands[] = $command;

                    return new Result([]);
                },
            ]),
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
        $this->created[] = $client;

        return $client;
    }
}
