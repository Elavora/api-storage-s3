# api-storage-s3

[![Packagist Version](https://img.shields.io/packagist/v/elavora/api-storage-s3.svg?style=flat-square)](https://packagist.org/packages/elavora/api-storage-s3)
[![PHP Version](https://img.shields.io/packagist/php-v/elavora/api-storage-s3.svg?style=flat-square)](https://packagist.org/packages/elavora/api-storage-s3)
[![Composer Quality](https://github.com/Elavora/api-storage-s3/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/Elavora/api-storage-s3/actions/workflows/quality.yml)
[![CodeQL](https://github.com/Elavora/api-storage-s3/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/Elavora/api-storage-s3/actions/workflows/codeql.yml)
[![License](https://img.shields.io/packagist/l/elavora/api-storage-s3.svg?style=flat-square)](https://packagist.org/packages/elavora/api-storage-s3)

Implementacao S3 do contrato `Storage`, baseada no SDK oficial da AWS.

## Requisitos

- PHP 8.3 ou superior.
- `elavora/api-framework` 1.x.

## Instalacao

```bash
composer require elavora/api-storage-s3
```

## Inicio rapido

```php
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Storage;

$application = Application::create()->extend(new S3StorageExtension([
    'bucket' => 'uploads',
    'region' => 'us-east-1',
    'version' => 'latest',
]));
$storage = $application->container()->get(Storage::class);
```

O bucket e validado antes da criacao do cliente. As demais opcoes sao encaminhadas ao `S3Client`.

## Documentacao

Consulte o [guia de uso](docs/USO.md) para operacoes e injecao de cliente.
