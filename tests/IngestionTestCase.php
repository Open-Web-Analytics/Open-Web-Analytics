<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Shared base for tracker beacon-contract tests (Layer 1 of the tracker test
 * harness).
 *
 * Each subclass fires one synthetic tracker event through the real ingestion
 * pipeline exactly as log.php does — owa_coreAPI::logEvent() ->
 * base.processRequest controller -> owa_eventDispatch -> the registered
 * handler -> entity ->create() — and then asserts the resulting fact/dimension
 * row. This locks the "event in -> correct row out" contract for each event
 * type so a refactor of the dispatch/handler/entity layer can't silently break
 * logging.
 *
 * Cleanup: tests write to the configured OWA database (the dev/test schema in
 * owa-config.php). Every row a test creates is registered with
 * trackForCleanup() and deleted in tearDown regardless of assertion outcome,
 * so residue is bounded to the rows the test itself created. Firing a primary
 * event also triggers the *_logged dimension fan-out (owa_document, owa_ua,
 * owa_host, ...); those shared, content-hashed dimension rows are intentionally
 * NOT cleaned (they are keyed by content, may pre-exist, and are safe to leave).
 */
abstract class IngestionTestCase extends TestCase
{
    /** @var array<int, array{0:string,1:string,2:string}> [entityFactory, pk, load-by column] */
    private $cleanup = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping ingestion test.');
        }
    }

    protected function tearDown(): void
    {
        // Remove every row this test created, regardless of assertion outcome.
        foreach ($this->cleanup as [$entity, $pk, $col]) {
            try {
                $e = owa_coreAPI::entityFactory($entity);
                $e->delete($pk, $col);
            } catch (\Throwable $ex) {
                // best-effort cleanup
            }
        }
        $this->cleanup = [];
    }

    /**
     * Register a row for deletion in tearDown.
     */
    protected function trackForCleanup(string $entity, string $pk, string $col = 'id'): void
    {
        $this->cleanup[] = [$entity, $pk, $col];
    }

    /**
     * A per-run unique GUID. Unique so the handler idempotency guard
     * (load-by-guid) never short-circuits and cleanup targets exactly this row.
     */
    protected function uniqueGuid(): string
    {
        return 'owatest_' . uniqid('', true);
    }

    /**
     * Fire an event through the real ingestion pipeline, exactly as log.php's
     * beacon endpoint does. Returns logEvent's result (false = the event was
     * dropped before persistence).
     *
     * @param array<string, mixed> $props
     */
    protected function fireEvent(string $event_type, array $props)
    {
        // Anonymous, non-robotic request so logEvent does not drop the event.
        if (!isset($props['HTTP_USER_AGENT'])) {
            $props['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'];
        }

        $event = owa_coreAPI::supportClassFactory('base', 'event');
        $event->setEventType($event_type);
        $event->setProperties($props);

        return owa_coreAPI::logEvent($event_type, $event);
    }

    /**
     * Load a persisted row back through the entity layer and assert it exists.
     * Returns the loaded entity for further property assertions.
     */
    protected function assertRowPersisted(string $entity, string $pk, string $col = 'id')
    {
        $row = owa_coreAPI::entityFactory($entity);
        $row->load($pk, $col);
        $this->assertTrue(
            $row->wasPersisted(),
            "No {$entity} row was written for the fired event (pk={$pk}, col={$col})."
        );
        return $row;
    }
}
