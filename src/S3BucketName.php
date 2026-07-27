<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\StorageS3;

use InvalidArgumentException;

final class S3BucketName
{
    public static function validate(mixed $bucket): string
    {
        if (!is_string($bucket) || $bucket === '') {
            throw new InvalidArgumentException('O bucket S3 e obrigatorio e deve ser uma string.');
        }

        if (trim($bucket) !== $bucket) {
            throw new InvalidArgumentException('O bucket S3 nao pode conter espacos externos.');
        }

        return $bucket;
    }
}
