# Guia de uso

[README](../README.md) · Veja também: [Desenvolvimento e testes](DESENVOLVIMENTO.md) · [Automação do repositório](AUTOMACAO.md)

Armazenamento S3 opcional para o framework Elavora. Esta página é a especificação do pacote: a API pública, as regras
e os erros.

## Instalação

```bash
composer require elavora/api-storage-s3
```

## Quando usar

- Salvar e ler arquivos por contrato comum (`Elavora\Api\Framework\Contracts\Storage`).
- Trocar o storage local por S3 sem alterar services de domínio.
- Centralizar a configuração de bucket, região e credenciais.

## Exemplo rápido

```php
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use Elavora\Api\Framework\Contracts\Storage;

$application->extend(new S3StorageExtension([
    'bucket' => 'uploads',
    'region' => 'us-east-1',
    'version' => 'latest',
]));
$storage = $application->container()->get(Storage::class);

$storage->put('reports/example.txt', 'conteudo');
$url = $storage->temporaryUrl('reports/example.txt');
```

## Principais pontos de entrada

Todas as classes estão no namespace `Elavora\Api\Extension\StorageS3`.

- `S3StorageExtension`: registra o `Storage` no container (o ponto de entrada normal).
- `S3Storage`: a implementação do `Storage` sobre o `Aws\S3\S3Client`.
- `S3ClientConfig`: a configuração do cliente, com a identificação usada para reaproveitar clientes.
- `S3ClientManager`: guarda e reaproveita clientes por configuração.
- `S3ServiceRegistrar`: registra no container, uma única vez, a factory de clientes compartilhada.
- `NativeS3ClientFactory` e `FixedS3ClientFactory`: as duas factories de cliente do pacote.
- `Contracts\S3ClientFactory`: a interface das factories, para quem quer criar o cliente por conta própria.

## API pública

### `S3StorageExtension`

`final class S3StorageExtension implements Elavora\Api\Framework\Contracts\Extension`

```php
public function __construct(
    array $config,
    ?Aws\S3\S3Client $client = null,
    ?Elavora\Api\Extension\StorageS3\Contracts\S3ClientFactory $clientFactory = null
)
public function register(Elavora\Api\Framework\Application $application): void
```

- `$config`: precisa da chave `bucket` (veja [Opções](#opções-de-configuração)). O construtor monta a
  `S3ClientConfig` com `S3ClientConfig::fromStorageConfig($config)` e **não valida o bucket**.
- `$client`: um cliente S3 já pronto, mantido por compatibilidade. Quando informado (e sem `$clientFactory`), o pacote o
  usa por meio de uma `FixedS3ClientFactory`.
- `$clientFactory`: uma factory própria para criar os clientes. Tem **prioridade** sobre `$client`: se os dois forem
  informados, vale a factory.
- `register()`:
  1. chama `S3ServiceRegistrar::register($application, <factory escolhida>)`;
  2. registra no container, para `Elavora\Api\Framework\Contracts\Storage`, uma factory que cria um
     `S3Storage` usando o cliente que `S3ClientFactory` do container devolve para a configuração, e o bucket.
- O `Storage` é criado **na primeira vez** que o container o resolve
  (`$application->container()->get(Storage::class)`), e a mesma instância é devolvida nas vezes seguintes. Por isso o
  erro de bucket ausente só aparece nessa primeira resolução, e não em `new S3StorageExtension(...)` nem em `extend()`.
- Se o container **já tem** um `S3ClientFactory` registrado antes da extensão (por outra extensão ou pela aplicação),
  ele é mantido e os argumentos `$client` e `$clientFactory` da extensão são ignorados. É assim que se troca a criação
  do cliente "antes da extensão" (veja [Trocar a criação do cliente](#trocar-a-criação-do-cliente)).

### `S3Storage`

`final class S3Storage implements Elavora\Api\Framework\Contracts\Storage`

| Método | Retorno |
| --- | --- |
| `__construct(Aws\S3\S3Client $client, string $bucket)` | |
| `put(string $key, string $body, array $options = []): array` | o resultado do `putObject`, como array |
| `get(string $key, array $options = []): array` | o resultado do `getObject`, como array |
| `delete(string $key, array $options = []): array` | o resultado do `deleteObject`, como array |
| `temporaryUrl(string $key, ?DateTimeImmutable $expiresAt = null, array $options = []): string` | URL assinada de leitura |
| `client(): Aws\S3\S3Client` | o cliente usado pela instância |

Regras:

- `__construct`: `$bucket` vazio ou só com espaços lança `InvalidArgumentException`
  (`O bucket S3 e obrigatorio.`). Nada é enviado ao S3 no construtor.
- `put`, `get` e `delete` chamam, no cliente, `putObject`, `getObject` e `deleteObject`, e devolvem o `Aws\Result`
  convertido com `toArray()`. O pacote **não acrescenta nem renomeia chaves**: o array é o do SDK. Por isso, ao
  contrário do `elavora/api-storage-local`, o retorno **não** tem `Key` nem `Deleted`.
  - `put` envia `Bucket`, `Key` (já normalizada) e `Body`. Exemplo de retorno: `['ETag' => '"etag"']`.
  - `get` envia `Bucket` e `Key`. O retorno traz o que o SDK devolve, como `Body` e `ContentLength`. Num S3 de verdade,
    `Body` é um objeto de stream (`Psr\Http\Message\StreamInterface`): use `(string) $result['Body']` para obter o
    conteúdo. Com um `MockHandler`, é o valor que o teste colocou no `Result`.
  - `delete` envia `Bucket` e `Key`. O S3 não falha ao apagar um objeto que não existe.
- `$options` é repassado ao SDK junto com os argumentos do pacote (por exemplo `ContentType`, `ACL` ou `Metadata` no
  `put`). Os argumentos `Bucket`, `Key` e, no `put`, `Body` do pacote **sempre vencem**: se `$options` trouxer
  `Bucket`, `Key` ou `Body`, o valor informado é descartado e o do pacote é usado.
- `temporaryUrl`: gera uma URL assinada (pré-assinada) de **leitura** (`GetObject`) com
  `$client->createPresignedRequest(...)`, sem chamar a rede. A URL contém a chave do objeto e `X-Amz-Signature=`.
  - `$expiresAt` `null`: expira em 15 minutos.
  - `$expiresAt` igual ou anterior ao momento atual lança `InvalidArgumentException`
    (`A expiracao da URL S3 deve estar no futuro.`). Essa checagem vem **antes** da validação da chave.
  - `$options` é repassado ao comando `GetObject` (por exemplo `ResponseContentType`). `Bucket` e `Key` do pacote
    vencem, como nos outros métodos.
- `client()` devolve o mesmo `S3Client` recebido no construtor.

### Chaves

Toda chave passa pela mesma normalização, em `put`, `get`, `delete` e `temporaryUrl`: espaços, tabs, quebras de linha
e bytes nulos nas pontas são removidos (o `trim` padrão do PHP) e `\` vira `/`. A chave **normalizada** é a enviada ao
S3. Chave inválida lança `InvalidArgumentException` (`A chave de storage e invalida.`), **antes** de qualquer chamada
ao S3 (nenhuma requisição é feita).

| Chave informada | Resultado |
| --- | --- |
| `reports/example.txt` | válida, enviada como `reports/example.txt` |
| `a.txt` | válida |
| `reports\2026\a.txt` | válida, enviada como `reports/2026/a.txt` |
| `  reports/a.txt  ` | válida, enviada como `reports/a.txt` |
| `v1.0/..a/arquivo..txt` | válida: só os segmentos `.` e `..` inteiros são proibidos |
| `` (vazia) ou só espaços | `InvalidArgumentException` |
| `/etc/passwd` | `InvalidArgumentException` (começa com `/`) |
| `\etc\passwd` | `InvalidArgumentException` (vira `/etc/passwd`) |
| `../fora.txt`, `a/../b.txt`, `a/..` | `InvalidArgumentException` (segmento `..`) |
| `./a.txt`, `a/./b.txt` | `InvalidArgumentException` (segmento `.`) |
| `a//b.txt` | `InvalidArgumentException` (segmento vazio) |
| `a/b/` | `InvalidArgumentException` (termina em `/`, segmento vazio) |
| `a\0b.txt` (byte nulo no meio) | `InvalidArgumentException` |

### Erros do S3

Uma falha do SDK (`Aws\Exception\AwsException`: objeto ou bucket inexistente, acesso negado, erro de rede do SDK etc.)
em `put`, `get` ou `delete` é convertida em `RuntimeException`:

- mensagem: `Nao foi possivel executar a operacao no storage S3: ` seguida da mensagem do SDK;
- código: o código da exceção original, como inteiro;
- `getPrevious()`: a `AwsException` original, para quem precisa do código de erro do S3.

Só `AwsException` é convertida. Outras exceções (por exemplo, `InvalidArgumentException` de uma opção inválida para o
SDK) passam sem conversão. `temporaryUrl` não converte nada: erros do SDK ao assinar a URL sobem como estão.

Exemplo: `get('inexistente.txt')` num bucket sem esse objeto lança `RuntimeException` com a mensagem
`Nao foi possivel executar a operacao no storage S3: ...` e `getPrevious()` é uma `AwsException`.

### Opções de configuração

O array `$config` da extensão aceita `bucket` e qualquer opção do construtor do `Aws\S3\S3Client` (`region`, `version`,
`credentials`, `endpoint`, `use_path_style_endpoint`, `handler` etc.).

| Chave | Obrigatória | Regra |
| --- | --- | --- |
| `bucket` | sim | string não vazia (depois de `trim`). Não vai para o cliente S3 |
| `region` | não | padrão `us-east-1` (também quando vier `null`) |
| `version` | não | padrão `latest` (também quando vier `null`) |
| demais chaves | não | repassadas sem mudança ao `S3Client` |

`bucket` ausente, que não seja string ou vazio/só com espaços lança `InvalidArgumentException`
(`A configuracao S3 deve informar bucket.`) quando o container resolve o `Storage` pela primeira vez.

Exemplos:

| `$config` | Resultado ao resolver `Storage` |
| --- | --- |
| `['bucket' => 'uploads']` | ok, cliente com `region` `us-east-1` e `version` `latest` |
| `['bucket' => 'uploads', 'region' => 'sa-east-1']` | ok, `region` `sa-east-1` |
| `['bucket' => 'uploads', 'endpoint' => 'http://minio:9000', 'use_path_style_endpoint' => true]` | ok (S3 compatível) |
| `[]` | `InvalidArgumentException` |
| `['bucket' => '']` ou `['bucket' => '   ']` | `InvalidArgumentException` |
| `['bucket' => 123]` | `InvalidArgumentException` |

### `S3ClientConfig`

`final class S3ClientConfig`

| Método | Retorno |
| --- | --- |
| `__construct(array $options)` | |
| `static fromStorageConfig(array $config): self` | a configuração do cliente, a partir da do storage |
| `options(): array` | as opções que vão ao `S3Client` |
| `fingerprint(): string` | identificação (hash sha256, 64 caracteres hexadecimais) das opções |

- `fromStorageConfig`: remove `bucket`, define `version` como `latest` e `region` como `us-east-1` quando ausentes (ou
  `null`) e devolve o resultado em `options()`. Exemplo: `['bucket' => 'uploads']` resulta em
  `['version' => 'latest', 'region' => 'us-east-1']`.
- `fingerprint`: a mesma configuração dá a mesma identificação; configurações diferentes dão identificações diferentes.
  A **ordem das chaves não importa**, nem nos arrays aninhados (`['a' => 1, 'b' => 2]` e `['b' => 2, 'a' => 1]` dão a
  mesma). Objetos (como um provedor de `credentials`) entram pela classe e pelo identificador da instância: duas
  instâncias diferentes dão identificações diferentes, e a mesma instância dá sempre a mesma.

### `S3ClientManager`

`final class S3ClientManager implements Contracts\S3ClientFactory`

- `__construct(Contracts\S3ClientFactory $factory = new NativeS3ClientFactory())`
- `client(S3ClientConfig $config): Aws\S3\S3Client`: cria o cliente pela factory na primeira vez que vê uma
  configuração (pela `fingerprint()`) e devolve **a mesma instância** nas chamadas seguintes com a mesma configuração.
  Configurações diferentes chamam a factory de novo. Exemplo: duas chamadas com a mesma `S3ClientConfig` resultam em
  uma só chamada à factory e `assertSame` entre os dois clientes.

### `S3ServiceRegistrar`

`final class S3ServiceRegistrar`

- `static register(Application $application, ?Contracts\S3ClientFactory $factory = null): void`: se o container
  **não** tem `Contracts\S3ClientFactory`, registra um `S3ClientManager` que usa `$factory` (ou uma
  `NativeS3ClientFactory`, se `$factory` for `null`). Se o container já tem, **não faz nada** (o existente é mantido,
  e `$factory` é ignorada). Várias extensões podem chamá-lo e todas compartilham o mesmo gerenciador de clientes.

### Factories de cliente

`Contracts\S3ClientFactory`:

```php
interface S3ClientFactory
{
    public function client(S3ClientConfig $config): Aws\S3\S3Client;
}
```

- `NativeS3ClientFactory`: `client($config)` devolve `new S3Client($config->options())` (um cliente novo a cada
  chamada; o reaproveitamento é do `S3ClientManager`).
- `FixedS3ClientFactory`: `__construct(S3Client $client)`; `client($config)` devolve sempre o mesmo cliente do
  construtor, **ignorando** a configuração. Serve para clientes já criados pela aplicação.

### Trocar a criação do cliente

Três formas, da mais simples à mais controlada:

```php
// 1. Cliente pronto (compatibilidade).
new S3StorageExtension(['bucket' => 'uploads'], client: $s3Client);

// 2. Factory própria, só para esta extensão.
new S3StorageExtension(['bucket' => 'uploads'], clientFactory: new MinhaFactory());

// 3. Factory registrada no container antes da extensão (vale para todas as extensões S3).
$application->container()->instance(S3ClientFactory::class, new MinhaFactory());
$application->extend(new S3StorageExtension(['bucket' => 'uploads']));
```

Na forma 3, a factory do container é usada como está (sem passar pelo `S3ClientManager`) e é chamada com a
configuração derivada do `$config` (por exemplo, com `region` `us-east-1` quando o `$config` não informa).

## Dependências de runtime

- PHP `>=8.3`
- `aws/aws-sdk-php` `^3.381`
- `elavora/api-framework` `^2.0`

O pacote não exige extensão de PHP além das que o SDK já usa. Os testes não chamam o S3: usam o `Aws\MockHandler`.

## Versão e compatibilidade

A versão `1.0` exige PHP `>=8.3` e `elavora/api-framework` `^2.0`. Antes (`0.x`), o pacote aceitava PHP `>=8.1` e
`elavora/api-framework` `^0.3.1`. Para atualizar a partir da `0.2.0`, troque as restrições no `composer.json` do seu
projeto e rode `composer update elavora/api-framework elavora/api-storage-s3`:

```json
{
  "require": {
    "php": ">=8.3",
    "elavora/api-framework": "^2.0",
    "elavora/api-storage-s3": "^1.0"
  }
}
```

A API do pacote não muda: as classes e a interface acima mantêm namespaces, assinaturas e comportamento. Só a
dependência do framework sobe de major (e o PHP mínimo sobe para 8.3). `S3Storage` implementa o
`Elavora\Api\Framework\Contracts\Storage` do framework 2, e a extensão usa o `Elavora\Api\Framework\Application`, o
`Elavora\Api\Framework\Container` e o `Elavora\Api\Framework\Contracts\Extension` do framework 2. No framework 0.3 esses
tipos não existem nesse namespace, então o pacote `0.x` não funciona com o framework 2, nem a `1.0` com o 0.3:
atualize os dois juntos. A dependência `aws/aws-sdk-php` continua em `^3.381`.

Este pacote é aditivo: ele não substitui `Elavora\Api\Interface\Storage`, `Elavora\Api\Integration\Storage\S3Storage`
nem o alias legado `Elavora\Api\Integration\S3Storage` do módulo `elavora/api-api`.

## Validação no projeto consumidor

Depois de instalar o pacote, rode os testes da aplicação consumidora. Para uma verificação isolada do pacote, use container:

```bash
docker run --rm -v "${PWD}:/workspace" -w "/workspace/api-storage-s3" composer:2 composer validate --strict --no-check-publish
docker run --rm -v "${PWD}:/workspace" -w "/workspace/api-storage-s3" composer:2 sh -lc "find . \\( -path ./.git -o -path ./vendor \\) -prune -o -name '*.php' -print0 | xargs -0 -r -n1 php -l"
```

## Observações

- Mantenha regras de produto fora deste pacote.
- Prefira configurar extensões no bootstrap da aplicação.
- Instale apenas os módulos que a aplicação realmente usa.
