<?php

declare(strict_types=1);

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Elavora\Api\Extension\StorageS3\S3Storage;
use Elavora\Api\Extension\StorageS3\S3StorageExtension;
use Elavora\Api\Framework\Application;
use Elavora\Api\Framework\Contracts\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Regras de S3Storage: operações, chaves, opções, erros e URL temporária.
 *
 * @see docs/USO.md#s3storage
 */
final class S3StorageTest extends TestCase
{
    private const ERROR_PREFIX = 'Nao foi possivel executar a operacao no storage S3: ';

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->commands = [];
    }

    public function testExecutesObjectOperations(): void
    {
        $storage = new S3Storage($this->clientWithResults(
            new Result(['ETag' => '"etag"']),
            new Result(['Body' => 'content', 'ContentLength' => 7]),
            new Result([]),
        ), 'uploads');

        self::assertSame('"etag"', $storage->put('reports/example.txt', 'content')['ETag']);
        self::assertSame('content', $storage->get('reports/example.txt')['Body']);
        self::assertIsArray($storage->delete('reports/example.txt'));
    }

    public function testCreatesTemporaryUrl(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $url = $storage->temporaryUrl('reports/example.txt');

        self::assertStringContainsString('reports/example.txt', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testRegistersStorageContractWithInjectedClient(): void
    {
        $application = Application::create()->extend(new S3StorageExtension(
            config: ['bucket' => 'uploads'],
            client: $this->clientWithResults(),
        ));

        self::assertInstanceOf(Storage::class, $application->container()->get(Storage::class));
    }

    public function testImplementsStorageContractAndIsFinal(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        self::assertInstanceOf(Storage::class, $storage);
        self::assertTrue((new ReflectionClass(S3Storage::class))->isFinal());
    }

    public function testClientReturnsTheSameClientGivenToTheConstructor(): void
    {
        $client = $this->clientWithResults();
        $storage = new S3Storage($client, 'uploads');

        self::assertSame($client, $storage->client());
    }

    #[DataProvider('blankBuckets')]
    public function testConstructorRejectsBlankBucket(string $bucket): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('O bucket S3 e obrigatorio.');

        new S3Storage($this->clientWithResults(), $bucket);
    }

    public function testConstructorDoesNotSendAnythingToS3(): void
    {
        new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        self::assertCount(1, $this->mock);
        self::assertSame([], $this->commands);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankBuckets(): array
    {
        return [
            'vazio' => [''],
            'espacos' => ['   '],
            'tabs e quebras de linha' => ["\t\n "],
        ];
    }

    // put

    public function testPutSendsBucketKeyAndBodyToPutObject(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->put('reports/example.txt', 'conteudo');

        self::assertCount(1, $this->commands);
        self::assertSame('PutObject', $this->commands[0]->getName());
        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('reports/example.txt', $this->commands[0]['Key']);
        self::assertSame('conteudo', (string) $this->commands[0]['Body']);
    }

    public function testPutReturnsTheSdkResultAsArrayWithoutRenamingKeys(): void
    {
        $storage = new S3Storage(
            $this->clientWithResults(new Result(['ETag' => '"etag"', 'VersionId' => 'v1'])),
            'uploads',
        );

        $result = $storage->put('reports/example.txt', 'conteudo');

        self::assertSame('"etag"', $result['ETag']);
        self::assertSame('v1', $result['VersionId']);
        self::assertArrayNotHasKey('Key', $result);
        self::assertArrayNotHasKey('Deleted', $result);
    }

    public function testPutPassesOptionsToTheSdk(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->put('a.txt', 'x', ['ContentType' => 'text/plain', 'Metadata' => ['autor' => 'ana']]);

        self::assertSame('text/plain', $this->commands[0]['ContentType']);
        self::assertSame(['autor' => 'ana'], $this->commands[0]['Metadata']);
    }

    public function testPutPackageArgumentsAlwaysWinOverOptions(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->put('  reports\\a.txt  ', 'do-pacote', [
            'Bucket' => 'outro-bucket',
            'Key' => 'outra/chave.txt',
            'Body' => 'da-opcao',
            'ContentType' => 'text/plain',
        ]);

        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('reports/a.txt', $this->commands[0]['Key']);
        self::assertSame('do-pacote', (string) $this->commands[0]['Body']);
        self::assertSame('text/plain', $this->commands[0]['ContentType']);
    }

    // get

    public function testGetSendsBucketAndKeyToGetObject(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result(['Body' => 'x'])), 'uploads');

        $storage->get('reports/example.txt');

        self::assertCount(1, $this->commands);
        self::assertSame('GetObject', $this->commands[0]->getName());
        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('reports/example.txt', $this->commands[0]['Key']);
    }

    public function testGetReturnsTheSdkResultAsArrayWithoutRenamingKeys(): void
    {
        $storage = new S3Storage(
            $this->clientWithResults(new Result(['Body' => 'content', 'ContentLength' => 7, 'Custom' => 'valor'])),
            'uploads',
        );

        $result = $storage->get('reports/example.txt');

        self::assertSame('content', $result['Body']);
        self::assertSame(7, $result['ContentLength']);
        self::assertSame('valor', $result['Custom']);
        self::assertArrayNotHasKey('Key', $result);
    }

    public function testGetPassesOptionsAndPackageArgumentsWin(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->get('a.txt', ['Range' => 'bytes=0-9', 'Bucket' => 'outro-bucket', 'Key' => 'outra.txt']);

        self::assertSame('bytes=0-9', $this->commands[0]['Range']);
        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('a.txt', $this->commands[0]['Key']);
    }

    // delete

    public function testDeleteSendsBucketAndKeyToDeleteObject(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->delete('reports/example.txt');

        self::assertCount(1, $this->commands);
        self::assertSame('DeleteObject', $this->commands[0]->getName());
        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('reports/example.txt', $this->commands[0]['Key']);
    }

    public function testDeleteReturnsTheSdkResultAsArrayWithoutKeyOrDeleted(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result(['DeleteMarker' => true])), 'uploads');

        $result = $storage->delete('reports/example.txt');

        self::assertTrue($result['DeleteMarker']);
        self::assertArrayNotHasKey('Key', $result);
        self::assertArrayNotHasKey('Deleted', $result);
    }

    public function testDeletePassesOptionsAndPackageArgumentsWin(): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $storage->delete('a.txt', ['VersionId' => 'v9', 'Bucket' => 'outro-bucket', 'Key' => 'outra.txt']);

        self::assertSame('v9', $this->commands[0]['VersionId']);
        self::assertSame('uploads', $this->commands[0]['Bucket']);
        self::assertSame('a.txt', $this->commands[0]['Key']);
    }

    // chaves

    #[DataProvider('validKeys')]
    public function testValidKeysAreNormalizedBeforeBeingSent(string $given, string $sent): void
    {
        $storage = new S3Storage($this->clientWithResults(
            new Result([]),
            new Result(['Body' => 'x']),
            new Result([]),
        ), 'uploads');

        $storage->put($given, 'x');
        $storage->get($given);
        $storage->delete($given);

        self::assertCount(3, $this->commands);
        foreach ($this->commands as $command) {
            self::assertSame($sent, $command['Key']);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validKeys(): array
    {
        return [
            'caminho simples' => ['reports/example.txt', 'reports/example.txt'],
            'arquivo na raiz' => ['a.txt', 'a.txt'],
            'barra invertida' => ['reports\\2026\\a.txt', 'reports/2026/a.txt'],
            'espacos nas pontas' => ['  reports/a.txt  ', 'reports/a.txt'],
            'tab e quebra de linha nas pontas' => ["\t reports/a.txt \n", 'reports/a.txt'],
            'byte nulo nas pontas' => ["\0reports/a.txt\0", 'reports/a.txt'],
            'pontos que nao sao segmentos' => ['v1.0/..a/arquivo..txt', 'v1.0/..a/arquivo..txt'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyIsRejectedBeforeAnyS3Call(string $key): void
    {
        $storage = new S3Storage($this->clientWithResults(new Result([])), 'uploads');

        $operations = [
            'put' => fn() => $storage->put($key, 'x'),
            'get' => fn() => $storage->get($key),
            'delete' => fn() => $storage->delete($key),
            'temporaryUrl' => fn() => $storage->temporaryUrl($key),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                self::fail("{$name} deveria rejeitar a chave.");
            } catch (InvalidArgumentException $exception) {
                self::assertSame('A chave de storage e invalida.', $exception->getMessage(), $name);
            }
        }

        self::assertCount(1, $this->mock, 'nenhuma requisicao deveria ter sido feita');
        self::assertSame([], $this->commands);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'vazia' => [''],
            'so espacos' => ['   '],
            'comeca com barra' => ['/etc/passwd'],
            'comeca com barra invertida' => ['\\etc\\passwd'],
            'sobe um nivel' => ['../fora.txt'],
            'sobe no meio' => ['a/../b.txt'],
            'termina em ..' => ['a/..'],
            'sobe com barra invertida' => ['a\\..\\b.txt'],
            'ponto no inicio' => ['./a.txt'],
            'ponto no meio' => ['a/./b.txt'],
            'segmento vazio' => ['a//b.txt'],
            'termina em barra' => ['a/b/'],
            'byte nulo no meio' => ["a\0b.txt"],
        ];
    }

    // erros do S3

    public function testPutGetAndDeleteConvertAwsExceptionToRuntimeException(): void
    {
        $original = new AwsException('Falha simulada', new Command('PutObject'));
        $storage = new S3Storage($this->clientWithResults($original, $original, $original), 'uploads');

        $operations = [
            'put' => fn() => $storage->put('a.txt', 'x'),
            'get' => fn() => $storage->get('a.txt'),
            'delete' => fn() => $storage->delete('a.txt'),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                self::fail("{$name} deveria lancar RuntimeException.");
            } catch (RuntimeException $exception) {
                self::assertNotInstanceOf(AwsException::class, $exception, $name);
                self::assertSame(self::ERROR_PREFIX . 'Falha simulada', $exception->getMessage(), $name);
                self::assertSame($original->getCode(), $exception->getCode(), $name);
                self::assertSame($original, $exception->getPrevious(), $name);
            }
        }
    }

    public function testOtherExceptionsPassThroughWithoutConversion(): void
    {
        $original = new LogicException('opcao invalida');
        $storage = new S3Storage($this->clientWithResults($original, $original, $original), 'uploads');

        $operations = [
            'put' => fn() => $storage->put('a.txt', 'x'),
            'get' => fn() => $storage->get('a.txt'),
            'delete' => fn() => $storage->delete('a.txt'),
        ];

        foreach ($operations as $name => $operation) {
            try {
                $operation();
                self::fail("{$name} deveria deixar a excecao subir.");
            } catch (Throwable $exception) {
                self::assertSame($original, $exception, $name);
            }
        }
    }

    public function testTemporaryUrlDoesNotConvertSdkErrors(): void
    {
        $client = $this->clientWithResults();
        $original = new AwsException('Falha ao assinar', new Command('GetObject'));
        $client->getHandlerList()->appendInit(
            static fn(callable $next): callable => static function () use ($original): never {
                throw $original;
            },
            'falha-ao-assinar',
        );
        $storage = new S3Storage($client, 'uploads');

        try {
            $storage->temporaryUrl('a.txt');
            self::fail('A AwsException deveria subir como esta.');
        } catch (Throwable $exception) {
            self::assertSame($original, $exception);
        }
    }

    // temporaryUrl

    public function testTemporaryUrlDoesNotCallTheNetwork(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $storage->temporaryUrl('reports/example.txt');

        self::assertSame([], $this->commands);
    }

    public function testTemporaryUrlContainsBucketKeyAndSignature(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $url = $storage->temporaryUrl('reports\\2026\\a.txt');

        self::assertStringContainsString('uploads', $url);
        self::assertStringContainsString('reports/2026/a.txt', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testTemporaryUrlExpiresInFifteenMinutesByDefault(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $expires = $this->expiresOf($storage->temporaryUrl('a.txt'));

        self::assertGreaterThanOrEqual(895, $expires);
        self::assertLessThanOrEqual(900, $expires);
    }

    public function testTemporaryUrlUsesTheGivenExpiration(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $expires = $this->expiresOf($storage->temporaryUrl('a.txt', new DateTimeImmutable('+1 hour')));

        self::assertGreaterThanOrEqual(3595, $expires);
        self::assertLessThanOrEqual(3600, $expires);
    }

    public function testTemporaryUrlRejectsExpirationInThePast(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A expiracao da URL S3 deve estar no futuro.');

        $storage->temporaryUrl('a.txt', new DateTimeImmutable('-1 second'));
    }

    public function testTemporaryUrlRejectsExpirationEqualToNow(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A expiracao da URL S3 deve estar no futuro.');

        $storage->temporaryUrl('a.txt', new DateTimeImmutable());
    }

    public function testTemporaryUrlChecksExpirationBeforeTheKey(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A expiracao da URL S3 deve estar no futuro.');

        $storage->temporaryUrl('../invalida.txt', new DateTimeImmutable('-1 minute'));
    }

    public function testTemporaryUrlPassesOptionsToTheGetObjectCommand(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $url = $storage->temporaryUrl('a.txt', null, ['ResponseContentType' => 'text/plain']);

        self::assertStringContainsString('response-content-type=text%2Fplain', $url);
    }

    public function testTemporaryUrlPackageBucketAndKeyWinOverOptions(): void
    {
        $storage = new S3Storage($this->clientWithResults(), 'uploads');

        $url = $storage->temporaryUrl('reports/a.txt', null, [
            'Bucket' => 'outro-bucket',
            'Key' => 'outra/chave.txt',
        ]);

        self::assertStringContainsString('uploads', $url);
        self::assertStringContainsString('reports/a.txt', $url);
        self::assertStringNotContainsString('outro-bucket', $url);
        self::assertStringNotContainsString('outra/chave.txt', $url);
    }

    private function expiresOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertArrayHasKey('X-Amz-Expires', $query);

        return (int) $query['X-Amz-Expires'];
    }

    /**
     * Cliente com MockHandler. Cada resposta da fila registra o comando recebido em $this->commands.
     */
    private function clientWithResults(Result|Throwable ...$results): S3Client
    {
        $queue = [];
        foreach ($results as $result) {
            $queue[] = function (CommandInterface $command, RequestInterface $request) use ($result) {
                $this->commands[] = $command;

                return $result;
            };
        }
        $this->mock = new MockHandler($queue);

        return $this->client();
    }

    private function client(): S3Client
    {
        return new S3Client([
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler' => $this->mock,
            'region' => 'us-east-1',
            'version' => 'latest',
        ]);
    }
}
