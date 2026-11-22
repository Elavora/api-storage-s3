<?php

declare(strict_types=1);

namespace Elavora\Api\Extension\StorageS3;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Elavora\Api\Framework\Contracts\InspectsObjects;
use Elavora\Api\Framework\Contracts\Storage;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class S3Storage implements Storage, InspectsObjects
{
    /**
     * @param S3Client $client Cliente S3 usado nas operacoes.
     * @param string $bucket Bucket padrao do storage.
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
    ) {
        if (trim($this->bucket) === '') {
            throw new InvalidArgumentException('O bucket S3 e obrigatorio.');
        }
    }

    /**
     * Envia um objeto para o bucket S3.
     *
     * @param string $key Chave do objeto.
     * @param string $body Conteudo do objeto.
     * @param array<string, mixed> $options Opcoes adicionais aceitas pelo AWS SDK.
     * @return array<string, mixed>
     */
    public function put(string $key, string $body, array $options = []): array
    {
        return $this->run('putObject', array_merge(
            $options,
            ['Bucket' => $this->bucket, 'Key' => $this->normalizedKey($key), 'Body' => $body],
        ));
    }

    /**
     * Recupera um objeto do bucket S3.
     *
     * @param string $key Chave do objeto.
     * @param array<string, mixed> $options Opcoes adicionais aceitas pelo AWS SDK.
     * @return array<string, mixed>
     */
    public function get(string $key, array $options = []): array
    {
        return $this->run('getObject', array_merge(
            $options,
            ['Bucket' => $this->bucket, 'Key' => $this->normalizedKey($key)],
        ));
    }

    /**
     * Remove um objeto do bucket S3.
     *
     * @param string $key Chave do objeto.
     * @param array<string, mixed> $options Opcoes adicionais aceitas pelo AWS SDK.
     * @return array<string, mixed>
     */
    public function delete(string $key, array $options = []): array
    {
        return $this->run('deleteObject', array_merge(
            $options,
            ['Bucket' => $this->bucket, 'Key' => $this->normalizedKey($key)],
        ));
    }

    /**
     * Cria uma URL temporaria assinada para leitura do objeto.
     *
     * @param string $key Chave do objeto.
     * @param DateTimeImmutable|null $expiresAt Data de expiracao. Padrao: 15 minutos.
     * @param array<string, mixed> $options Opcoes adicionais aceitas pelo AWS SDK.
     */
    public function temporaryUrl(
        string $key,
        ?DateTimeImmutable $expiresAt = null,
        array $options = [],
    ): string {
        $expiresAt ??= new DateTimeImmutable('+15 minutes');

        if ($expiresAt <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('A expiracao da URL S3 deve estar no futuro.');
        }

        $command = $this->client->getCommand('GetObject', array_merge(
            $options,
            ['Bucket' => $this->bucket, 'Key' => $this->normalizedKey($key)],
        ));
        $request = $this->client->createPresignedRequest($command, $expiresAt);

        return (string) $request->getUri();
    }

    /**
     * Informa se o objeto existe, consultando apenas os metadados (HeadObject).
     *
     * Somente a ausencia do objeto resulta em false; as demais falhas do S3 sao propagadas.
     *
     * @param string $key Chave do objeto.
     */
    public function exists(string $key): bool
    {
        return $this->head($key) !== null;
    }

    /**
     * Retorna o tamanho do objeto em bytes, ou null se ele nao existe (HeadObject).
     *
     * @param string $key Chave do objeto.
     */
    public function size(string $key): ?int
    {
        $result = $this->head($key);

        return $result === null ? null : (int) ($result['ContentLength'] ?? 0);
    }

    /**
     * Retorna o cliente S3 usado pela instancia.
     */
    public function client(): S3Client
    {
        return $this->client;
    }

    /**
     * Consulta os metadados do objeto. Devolve null quando o S3 informa que ele nao existe.
     *
     * @return array<string, mixed>|null
     */
    private function head(string $key): ?array
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->normalizedKey($key)];

        try {
            return $this->client->headObject($arguments)->toArray();
        } catch (AwsException $exception) {
            if ($this->isNotFound($exception)) {
                return null;
            }

            throw new RuntimeException(
                'Nao foi possivel executar a operacao no storage S3: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function isNotFound(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NotFound', 'NoSuchKey'], true);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function run(string $method, array $arguments): array
    {
        try {
            return $this->client->{$method}($arguments)->toArray();
        } catch (AwsException $exception) {
            throw new RuntimeException(
                'Nao foi possivel executar a operacao no storage S3: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function normalizedKey(string $key): string
    {
        $normalizedKey = str_replace('\\', '/', trim($key));

        if ($normalizedKey === '' || str_starts_with($normalizedKey, '/')) {
            throw new InvalidArgumentException('A chave de storage e invalida.');
        }

        foreach (explode('/', $normalizedKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new InvalidArgumentException('A chave de storage e invalida.');
            }
        }

        return $normalizedKey;
    }
}
