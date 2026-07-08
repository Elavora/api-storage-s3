# elavora/api-storage-s3

Armazenamento S3 opcional para o framework Elavora.

Registre `S3StorageExtension` com `bucket` e as configuracoes aceitas pelo
`Aws\S3\S3Client`, como `region`, `version`, `credentials`, `endpoint` e
`use_path_style_endpoint`.

```php
$application->extend(new S3StorageExtension([
    'bucket' => 'uploads',
    'region' => 'us-east-1',
    'version' => 'latest',
]));
$storage = $application->container()->get(Storage::class);

$url = $storage->temporaryUrl('reports/example.txt');
```

O pacote registra `S3ClientFactory` internamente e reutiliza clientes com a
mesma configuracao via `S3ClientManager`. Para trocar a criacao do cliente,
registre uma factory propria antes da extensao ou informe `clientFactory` no
construtor.

Este pacote e aditivo. Ele nao substitui `Elavora\Api\Interface\Storage`,
`Elavora\Api\Integration\Storage\S3Storage` ou o alias legado
`Elavora\Api\Integration\S3Storage` do modulo `elavora/api-api`.
