# Guia de uso

`S3StorageExtension` e a extensao registrada na aplicacao. Ela cria o storage e usa uma factory para obter o cliente S3.

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
$storage->put('documents/report.txt', 'conteudo');
$url = $storage->temporaryUrl('documents/report.txt');
```

Para usar um cliente pronto:

```php
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\FixedS3ClientFactory;
use Elavora\Api\Extension\StorageS3\S3StorageExtension;

$client = new S3Client([
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$application->extend(new S3StorageExtension(
    config: [
        'bucket' => 'uploads',
        'region' => 'us-east-1',
        'version' => 'latest',
    ],
    clientFactory: new FixedS3ClientFactory($client)
));
```

`S3StorageExtension` integra o pacote ao framework, `S3ClientFactory` controla a criacao ou reutilizacao do cliente e `S3Client` executa as chamadas ao servico.

O bucket deve ser uma string nao vazia e sem whitespace externo. Ele nao e repassado nas opcoes do cliente.

## Validacao do pacote

Execute a partir da raiz do clone:

```bash
docker run --rm -v "${PWD}:/workspace" -w /workspace composer:2 composer update --no-interaction --no-progress --prefer-dist
docker run --rm -v "${PWD}:/workspace" -w /workspace composer:2 composer check
```
