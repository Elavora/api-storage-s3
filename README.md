# elavora/api-storage-s3

[![Packagist Version](https://img.shields.io/packagist/v/elavora/api-storage-s3.svg?style=flat-square)](https://packagist.org/packages/elavora/api-storage-s3)
[![PHP Version](https://img.shields.io/packagist/php-v/elavora/api-storage-s3.svg?style=flat-square)](https://packagist.org/packages/elavora/api-storage-s3)
[![Qualidade](https://github.com/Elavora/api-storage-s3/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/Elavora/api-storage-s3/actions/workflows/quality.yml)
[![CodeQL](https://github.com/Elavora/api-storage-s3/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/Elavora/api-storage-s3/actions/workflows/codeql.yml)
[![License](https://img.shields.io/packagist/l/elavora/api-storage-s3.svg?style=flat-square)](LICENSE)

Armazenamento S3 opcional para o framework Elavora.

Registre `S3StorageExtension` com `bucket` e as configurações aceitas pelo
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
mesma configuração via `S3ClientManager`. Para trocar a criação do cliente,
registre uma factory própria antes da extensão ou informe `clientFactory` no
construtor.

Este pacote é aditivo. Ele não substitui `Elavora\Api\Interface\Storage`,
`Elavora\Api\Integration\Storage\S3Storage` ou o alias legado
`Elavora\Api\Integration\S3Storage` do módulo `elavora/api-api`.

Mais exemplos: [docs/USO.md](docs/USO.md).

## Desenvolvimento e testes

Você só precisa de Docker. O ambiente é a imagem `ghcr.io/elavora/php-dev:8.3-latest`: abra o repositório no VS Code
com **Dev Containers: Reopen in Container**, ou rode no PowerShell:

```powershell
docker run --rm --mount "type=bind,source=$($PWD.Path),target=/workspace" -w /workspace ghcr.io/elavora/php-dev:8.3-latest sh -lc 'git config --global --add safe.directory /workspace && composer install --no-interaction --prefer-dist && composer check'
```

Scripts do Composer:

```bash
composer check     # lint, testes e PHPStan
composer test      # testes PHPUnit
composer lint      # sintaxe (elavora-standards lint)
composer analyse   # PHPStan, nível 8
```

Os testes não precisam de S3, de rede nem de credenciais: usam o `Aws\MockHandler` do SDK.

O código segue o padrão PER-CS, com as regras do pacote `elavora/code-standards`. No devcontainer, o arquivo é
formatado ao salvar; no PR, o CI formata o que faltou e commita a correção (`style: organiza o código`).
Os testes rodam no CI, no workflow **Qualidade**, em todo PR. O passo a passo, o que roda no CI e como reproduzi-lo
estão em [Desenvolvimento e testes](docs/DESENVOLVIMENTO.md).

## Como contribuir

1. Crie uma branch a partir da `main` (`feat/...`, `fix/...` ou `docs/...`).
2. Faça commits em português do Brasil, no padrão Conventional Commits (`feat: ...`, `fix: ...`, `docs: ...`).
3. Rode `composer check` antes de abrir o PR.
4. Abra o PR para a `main`, preencha o template e aplique as labels que definem a versão
   ([Versões e release](docs/AUTOMACAO.md#versões-e-release)).
5. Confira o comentário do pipeline de qualidade no PR e só integre com os checks verdes.

## Versões

Cada PR integrado na `main` gera uma versão automaticamente, conforme as labels do PR: sem a label `release` sai uma
beta (`1.0.1-beta.1`), com ela sai a estável (`1.0.1`). As tags novas não têm o prefixo `v`, e as notas de cada
GitHub Release são geradas em português a partir dos PRs. Veja [Versões e release](docs/AUTOMACAO.md#versões-e-release).

## Documentação

1. [Guia de uso](docs/USO.md): o uso do pacote e a API pública.
2. [Desenvolvimento e testes](docs/DESENVOLVIMENTO.md): ambiente, como rodar os testes e o que roda no CI.
3. [Automação do repositório](docs/AUTOMACAO.md): workflows, Dependabot, estilo de código, labels e versões.

## Licença

MIT
