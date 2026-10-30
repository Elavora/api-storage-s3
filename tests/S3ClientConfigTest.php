<?php

declare(strict_types=1);

use Elavora\Api\Extension\StorageS3\S3ClientConfig;
use PHPUnit\Framework\TestCase;

/**
 * @see docs/USO.md#s3clientconfig
 */
final class S3ClientConfigTest extends TestCase
{
    public function testOptionsReturnsTheOptionsGivenToTheConstructor(): void
    {
        $config = new S3ClientConfig(['region' => 'sa-east-1', 'version' => '2006-03-01']);

        self::assertSame(['region' => 'sa-east-1', 'version' => '2006-03-01'], $config->options());
    }

    public function testFromStorageConfigRemovesBucketAndAppliesDefaults(): void
    {
        $config = S3ClientConfig::fromStorageConfig(['bucket' => 'uploads']);

        self::assertSame(['version' => 'latest', 'region' => 'us-east-1'], $config->options());
    }

    public function testFromStorageConfigKeepsExplicitRegionAndVersion(): void
    {
        $config = S3ClientConfig::fromStorageConfig([
            'bucket' => 'uploads',
            'region' => 'sa-east-1',
            'version' => '2006-03-01',
        ]);

        self::assertSame('sa-east-1', $config->options()['region']);
        self::assertSame('2006-03-01', $config->options()['version']);
    }

    public function testFromStorageConfigTreatsNullRegionAndVersionAsAbsent(): void
    {
        $config = S3ClientConfig::fromStorageConfig(['bucket' => 'uploads', 'region' => null, 'version' => null]);

        self::assertSame('us-east-1', $config->options()['region']);
        self::assertSame('latest', $config->options()['version']);
    }

    public function testFromStorageConfigPassesOtherOptionsUnchanged(): void
    {
        $credentials = ['key' => 'key', 'secret' => 'secret'];

        $config = S3ClientConfig::fromStorageConfig([
            'bucket' => 'uploads',
            'endpoint' => 'http://minio:9000',
            'use_path_style_endpoint' => true,
            'credentials' => $credentials,
        ]);

        $options = $config->options();
        self::assertArrayNotHasKey('bucket', $options);
        self::assertSame('http://minio:9000', $options['endpoint']);
        self::assertTrue($options['use_path_style_endpoint']);
        self::assertSame($credentials, $options['credentials']);
    }

    public function testFingerprintIsSha256InHex(): void
    {
        $fingerprint = (new S3ClientConfig(['region' => 'us-east-1']))->fingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }

    public function testSameConfigurationGivesTheSameFingerprint(): void
    {
        $first = new S3ClientConfig(['region' => 'us-east-1', 'version' => 'latest']);
        $second = new S3ClientConfig(['region' => 'us-east-1', 'version' => 'latest']);

        self::assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testDifferentConfigurationsGiveDifferentFingerprints(): void
    {
        $first = new S3ClientConfig(['region' => 'us-east-1']);
        $second = new S3ClientConfig(['region' => 'sa-east-1']);
        $third = new S3ClientConfig(['region' => 'us-east-1', 'version' => 'latest']);

        self::assertNotSame($first->fingerprint(), $second->fingerprint());
        self::assertNotSame($first->fingerprint(), $third->fingerprint());
    }

    public function testFingerprintIgnoresKeyOrder(): void
    {
        $first = new S3ClientConfig(['a' => 1, 'b' => 2]);
        $second = new S3ClientConfig(['b' => 2, 'a' => 1]);

        self::assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testFingerprintIgnoresKeyOrderInNestedArrays(): void
    {
        $first = new S3ClientConfig(['credentials' => ['key' => 'k', 'secret' => 's'], 'region' => 'us-east-1']);
        $second = new S3ClientConfig(['region' => 'us-east-1', 'credentials' => ['secret' => 's', 'key' => 'k']]);

        self::assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testFingerprintDistinguishesNestedValues(): void
    {
        $first = new S3ClientConfig(['credentials' => ['key' => 'k', 'secret' => 's']]);
        $second = new S3ClientConfig(['credentials' => ['key' => 'k', 'secret' => 'outro']]);

        self::assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function testFingerprintOfTheSameObjectInstanceIsStable(): void
    {
        $provider = new stdClass();

        $first = new S3ClientConfig(['credentials' => $provider]);
        $second = new S3ClientConfig(['credentials' => $provider]);

        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame($first->fingerprint(), $first->fingerprint());
    }

    public function testFingerprintOfDifferentObjectInstancesDiffers(): void
    {
        $first = new S3ClientConfig(['credentials' => new stdClass()]);
        $second = new S3ClientConfig(['credentials' => new stdClass()]);

        self::assertNotSame($first->fingerprint(), $second->fingerprint());
    }
}
