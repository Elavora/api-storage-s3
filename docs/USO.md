# Guia de uso

Armazenamento S3 opcional para o framework Elavora.

## Instalacao

```bash
composer require elavora/api-storage-s3
```

## Quando usar

- Salvar e ler arquivos por contrato comum.
- Trocar storage local por S3 sem alterar services de dominio.
- Centralizar configuracao de paths, buckets e chaves.

## Exemplo rapido

```php
use Elavora\Api\Extension\StorageS3\FixedS3ClientFactory;

$application->extend(new FixedS3ClientFactory([
    // Configure caminho, bucket ou credenciais conforme o driver.
]));
```

## Principais pontos de entrada

- `Elavora\Api\Extension\StorageS3\FixedS3ClientFactory`
- `Elavora\Api\Extension\StorageS3\NativeS3ClientFactory`
- `Elavora\Api\Extension\StorageS3\S3ClientConfig`
- `Elavora\Api\Extension\StorageS3\S3ClientManager`
- `Elavora\Api\Extension\StorageS3\S3ServiceRegistrar`

## Dependencias de runtime

- `aws/aws-sdk-php` `^3.381`
- `elavora/api-framework` `^0.3.1`

## Validacao no projeto consumidor

Depois de instalar o pacote, rode os testes da aplicacao consumidora. Para uma verificacao isolada do pacote, use container:

```bash
docker run --rm -v "${PWD}:/workspace" -w "/workspace/api-storage-s3" composer:2 composer validate --strict --no-check-publish
docker run --rm -v "${PWD}:/workspace" -w "/workspace/api-storage-s3" composer:2 sh -lc "find . \\( -path ./.git -o -path ./vendor \\) -prune -o -name '*.php' -print0 | xargs -0 -r -n1 php -l"
```

## Observacoes

- Mantenha regras de produto fora deste pacote.
- Prefira configurar extensoes no bootstrap da aplicacao.
- Instale apenas os modulos que a aplicacao realmente usa.