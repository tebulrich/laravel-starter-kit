<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->ensureDotenvFileExists();

        parent::setUp();

        $this->ensurePassportKeysExist();
    }

    /**
     * phpdotenv reads .env with file_get_contents; a missing file is an E_WARNING
     * that Pest surfaces even though Laravel's safeLoad would otherwise continue.
     */
    private function ensureDotenvFileExists(): void
    {
        $envPath = dirname(__DIR__) . '/.env';

        if (is_file($envPath) === true) {
            return;
        }

        $example = $envPath . '.example';

        if (is_file($example) === false) {
            return;
        }

        if (copy($example, $envPath) === false) {
            throw new RuntimeException('Unable to copy .env.example to .env for tests.');
        }
    }

    /**
     * Passport's api guard needs a key pair even for actingAs / unauthenticated 401 paths.
     */
    private function ensurePassportKeysExist(): void
    {
        $privatePath = storage_path('oauth-private.key');
        $publicPath  = storage_path('oauth-public.key');

        if (is_file($privatePath) === true && is_file($publicPath) === true) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Unable to generate Passport test keys.');
        }

        $exported = openssl_pkey_export($resource, $privateKey);

        if ($exported === false || is_string($privateKey) === false) {
            throw new RuntimeException('Unable to export Passport private key for tests.');
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || isset($details['key']) === false || is_string($details['key']) === false) {
            throw new RuntimeException('Unable to export Passport public key for tests.');
        }

        if (file_put_contents($privatePath, $privateKey) === false) {
            throw new RuntimeException("Unable to write {$privatePath}");
        }

        if (file_put_contents($publicPath, $details['key']) === false) {
            throw new RuntimeException("Unable to write {$publicPath}");
        }

        chmod($privatePath, 0600);
        chmod($publicPath, 0600);
    }
}
