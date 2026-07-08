<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\StorageS3;

use Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory;
use Elavora\Api\Framework\Application;

/**
 * Garante um unico registro de cliente S3 compartilhado entre extensoes.
 */
final class S3ServiceRegistrar
{
    /**
     * Registra a factory S3 compartilhada se ainda nao houver uma no container.
     */
    public static function register(Application $application, ?S3ClientFactory $factory = null): void
    {
        if ($application->container()->has(S3ClientFactory::class)) {
            return;
        }

        $application->container()->bind(
            S3ClientFactory::class,
            new S3ClientManager($factory ?? new NativeS3ClientFactory())
        );
    }
}
