<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Requirements;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Core\Requirements: what this version needs of a server, as constants and
 * as release.json's requirements (toArray()).
 */
final class RequirementsTest extends TestCase
{
    /** composer.json does not ship in a release, so the floor is written twice and held equal here. */
    public function testThePhpFloorIsComposersToo(): void
    {
        $composer = json_decode((string) file_get_contents(\OWA\Core\Env::root() . '/composer.json'), true);

        $this->assertSame('>=' . Requirements::PHP_MIN, $composer['require']['php']);
        $this->assertStringStartsWith(Requirements::PHP_MIN . '.', $composer['config']['platform']['php'],
            'composer resolves dependencies for the oldest PHP OWA runs on');
    }

    public function testTheArrayIsTheConstants(): void
    {
        $this->assertSame([
            'php' => [
                'min'              => Requirements::PHP_MIN,
                'int_size'         => 8,
                'extensions'       => ['json', 'mbstring', 'pcre', 'zlib'],
                'database_drivers' => ['pdo_mysql', 'mysqli+mysqlnd'],
            ],
            'database' => [
                'mysql'   => Requirements::MYSQL_MIN,
                'mariadb' => Requirements::MARIADB_MIN,
            ],
            'config' => [
                'database_constants' => Requirements::DATABASE_CONSTANTS,
                'url_constants'      => ['OWA_PUBLIC_URL'],
                'secret_constants'   => ['OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT'],
                'secret_min_length'  => 32,
                'retired_constants'  => array_keys(Requirements::RETIRED_CONSTANTS),
            ],
        ], Requirements::toArray());
    }

    /** Every secret is among the constants a role with a database needs. */
    public function testTheSecretsAreRequiredConstants(): void
    {
        $this->assertSame([], array_diff(Requirements::SECRET_CONSTANTS, Requirements::DATABASE_CONSTANTS));
        $this->assertCount(count(Requirements::SECRET_CONSTANTS), Requirements::SECRET_PLACEHOLDERS);
    }

    /**
     * It answers with nothing booted: a release check reads a NEWER release's
     * requirements, and the build writes release.json in a checkout with no
     * owa-config.php.
     */
    public function testItAnswersWithNothingBooted(): void
    {
        $out = shell_exec(PHP_BINARY . ' -n -r ' . escapeshellarg('require ' . var_export(\OWA\Core\Env::root() . '/Core/Requirements.php', true)
            . '; echo json_encode(\\OWA\\Core\\Requirements::toArray());') . ' 2>&1');

        $this->assertSame(Requirements::toArray(), json_decode((string) $out, true), (string) $out);
    }

    /** The readers read it: the checks name the same floors. */
    public function testTheChecksReadIt(): void
    {
        $this->assertNull(\OWA\Module\Base\Classes\DatabaseRequirement::problemFor(Requirements::MYSQL_MIN));
        $this->assertNotNull(\OWA\Module\Base\Classes\DatabaseRequirement::problemFor('8.0.20'));
        $this->assertNull(\OWA\Module\Base\Classes\DatabaseRequirement::problemFor(Requirements::MARIADB_MIN . '-MariaDB'));

        $this->assertFalse(\OWA\Module\Base\Classes\EnvironmentCheck::integerSize(4)['passed']);
        $this->assertTrue(\OWA\Module\Base\Classes\EnvironmentCheck::integerSize(Requirements::PHP_INT_SIZE)['passed']);

        $missing = (new \OWA\Core\ServerRole('full'))->configProblems([], []);
        $this->assertStringContainsString(implode(', ', Requirements::DATABASE_CONSTANTS), $missing[0]);
    }
}
