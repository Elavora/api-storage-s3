<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\StorageS3;

use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Container;
use Elavora\Api\Framework\Contracts\Extension;
use Elavora\Api\Framework\Contracts\Storage;
use LogicException;

final class S3StorageExtension implements Extension
{
    private readonly S3ClientConfig $clientConfig;
    private readonly string $bucket;

    /**
     * @param array<string, mixed> $config Configuracao do bucket e do cliente S3.
     * @param S3Client|null $client Cliente pronto, mantido para compatibilidade.
     * @param S3ClientFactory|null $clientFactory Factory customizada para criar clientes S3.
     */
    public function __construct(
        array $config,
        private readonly ?S3Client $client = null,
        private readonly ?S3ClientFactory $clientFactory = null
    ) {
        $this->bucket = S3BucketName::validate($config['bucket'] ?? null);
        $this->clientConfig = S3ClientConfig::fromStorageConfig($config);
    }

    /**
     * Registra Storage e a factory compartilhada de cliente S3.
     */
    public function register(Application $application): void
    {
        $clientFactory = $this->clientFactory;
        if ($clientFactory === null && $this->client !== null) {
            $clientFactory = new FixedS3ClientFactory($this->client);
        }

        S3ServiceRegistrar::register($application, $clientFactory);

        $application->container()->bind(
            Storage::class,
            fn (Container $container): S3Storage => $this->createStorage($container)
        );
    }

    private function createStorage(Container $container): S3Storage
    {
        $factory = $container->get(S3ClientFactory::class);
        if (!$factory instanceof S3ClientFactory) {
            throw new LogicException('O container retornou uma factory S3 invalida.');
        }

        return new S3Storage(
            client: $factory->client($this->clientConfig),
            bucket: $this->bucket
        );
    }
}
