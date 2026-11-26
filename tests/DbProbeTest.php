<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_test_db_available(): no database is a skip where none was promised,
 * and a failure where a job promised one (OWA_E2E_DB_HOST), so a database
 * job cannot pass green with its database tests skipped.
 */
final class DbProbeTest extends TestCase
{
    public function testAPromisedDatabaseThatDoesNotAnswerIsAFailure(): void
    {
        $was = getenv('OWA_E2E_DB_HOST');
        putenv('OWA_E2E_DB_HOST=127.0.0.1');

        try {
            $answered = owa_test_db_available();
            $this->assertTrue($answered, 'a probe that returns answers true: the database is there');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OWA_E2E_DB_HOST is set, but the database does not answer', $e->getMessage());
        } finally {
            putenv($was === false ? 'OWA_E2E_DB_HOST' : 'OWA_E2E_DB_HOST=' . $was);
        }
    }

    public function testNoPromiseNoDatabaseIsASkip(): void
    {
        $was = getenv('OWA_E2E_DB_HOST');
        putenv('OWA_E2E_DB_HOST');

        try {
            $this->assertIsBool(owa_test_db_available(), 'never throws where no database was promised');
        } finally {
            if ($was !== false) {
                putenv('OWA_E2E_DB_HOST=' . $was);
            }
        }
    }
}
