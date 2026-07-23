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

    /** @var array<string, array<int, string>>|null cached beacon contract fixture */
    private static $contracts = null;

    /** @var object|null the event object most recently fired via fireEvent() */
    private $lastEvent = null;

    /** @var string|null saved server HTTP_USER_AGENT to restore in tearDown */
    private $savedServerUa = null;

    /** @var bool whether setServerUserAgent() overrode the server UA this test */
    private $serverUaOverridden = false;

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

        // Restore any server-side UA override so it can't leak into the next
        // test (the browscap object is memoized on the service singleton for
        // the whole process — see setServerUserAgent).
        if ($this->serverUaOverridden) {
            owa_coreAPI::requestContainerSingleton()->server['HTTP_USER_AGENT'] = $this->savedServerUa;
            owa_coreAPI::serviceSingleton()->setBrowscap(null);
            $this->serverUaOverridden = false;
            $this->savedServerUa      = null;
        }
    }

    /**
     * Register a row for deletion in tearDown.
     */
    protected function trackForCleanup(string $entity, string $pk, string $col = 'id'): void
    {
        $this->cleanup[] = [$entity, $pk, $col];
    }

    /**
     * Override OWA's authoritative server-assigned event time.
     *
     * OWA does not trust the client-supplied `timestamp`: the environmental
     * `timestampDefault` filter always overwrites it with the request
     * container's receive time (set once per process). Tests that need to
     * order events in time (e.g. a session update, which only fires when a
     * later request's time exceeds the session's last_req) must move this
     * server clock forward between beacons — the client `timestamp` alone has
     * no effect on what gets persisted.
     */
    protected function setServerTime(int $timestamp): void
    {
        owa_coreAPI::requestContainerSingleton()->timestamp = $timestamp;
    }

    /**
     * Drive the SERVER-SIDE user-agent the ingestion pipeline sees.
     *
     * The `os` (and `ua_family`, robot, etc.) property is derived by browscap
     * (the ua-parser library) from the user-agent — but resolveOs reads that UA
     * from the request container's HTTP_USER_AGENT *server param*, NOT from the
     * event's HTTP_USER_AGENT property, and the parsed browscap object is
     * memoized on the service singleton for the whole process. So a test that
     * wants a realistic os to resolve must set the server param here (which also
     * clears the memoized browscap so the next lookup re-parses). tearDown
     * restores the original UA and resets browscap so this can't bleed into
     * another test in the same process.
     */
    protected function setServerUserAgent(string $ua): void
    {
        $rc = owa_coreAPI::requestContainerSingleton();
        if (!$this->serverUaOverridden) {
            $this->savedServerUa      = $rc->server['HTTP_USER_AGENT'] ?? null;
            $this->serverUaOverridden = true;
        }
        $rc->server['HTTP_USER_AGENT'] = $ua;
        // Drop the memoized browscap so the next getBrowscap() re-parses this UA.
        owa_coreAPI::serviceSingleton()->setBrowscap(null);
    }

    /**
     * A per-run unique GUID in the SAME format the tracker emits.
     *
     * This must be numeric: the tracker's Util.generateRandomGuid() builds
     * "<unix time><6-digit rand><3-digit rand>" (a ~19-digit number) and the
     * entity id / session_id columns are BIGINT. A non-numeric GUID is silently
     * cast to 0 by MySQL — every row lands at id=0, PKs collide, and load/delete
     * match the wrong row. Mirroring the real format keeps the test honest and
     * gives each row a distinct PK. Uniqueness (time + 9 random digits) makes the
     * handler idempotency guard a no-op and lets cleanup target exactly this row.
     */
    protected function uniqueGuid(): string
    {
        $time   = (string) time();
        $rand   = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $client = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        return $time . $rand . $client;
    }

    /**
     * A unique, numeric session id in the tracker's GUID format. Same BIGINT
     * requirement as uniqueGuid() — see there. Alias kept separate for
     * readability at call sites that group requests into a session.
     */
    protected function uniqueSessionId(): string
    {
        return $this->uniqueGuid();
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

        // logEvent mutates the event in place, running every property callback
        // (defaults, derivations, filters). Keep a handle so a test can read the
        // server-DERIVED property values (e.g. the `source` inferred from the
        // referrer) for content-based dimension lookups/cleanup.
        $this->lastEvent = $event;

        return owa_coreAPI::logEvent($event_type, $event);
    }

    /**
     * The event most recently fired via fireEvent(), after logEvent() has run
     * its property callbacks. Use this to read server-derived property values.
     */
    protected function lastEvent()
    {
        return $this->lastEvent;
    }

    /**
     * Load the shared beacon contract fixture (the same file the JS
     * BeaconContract test writes/asserts). Maps event_type -> emitted property
     * names.
     *
     * @return array<string, array<int, string>>
     */
    protected static function beaconContracts(): array
    {
        if (self::$contracts === null) {
            $json = file_get_contents(__DIR__ . '/fixtures/beacon_contracts.json');
            $data = json_decode($json, true);
            unset($data['_comment']);
            self::$contracts = $data;
        }
        return self::$contracts;
    }

    /**
     * Anti-drift guard. Assert that every property this test feeds a handler is
     * one the tracker actually emits for that event_type, per the shared
     * contract fixture. If a handler starts consuming a field the tracker
     * doesn't send (or a field gets renamed on one side only), this fails —
     * so the PHP contract can't silently drift from the JS beacon.
     *
     * @param array<int, string> $consumed handler-consumed property names
     */
    protected function assertFieldsInContract(string $event_type, array $consumed): void
    {
        $contracts = self::beaconContracts();
        $this->assertArrayHasKey(
            $event_type,
            $contracts,
            "No beacon contract for {$event_type} in tests/fixtures/beacon_contracts.json."
        );
        $emitted = $contracts[$event_type];
        foreach ($consumed as $field) {
            $this->assertContains(
                $field,
                $emitted,
                "Handler for {$event_type} consumes '{$field}', but the tracker "
                . "does not emit it (see tests/fixtures/beacon_contracts.json). "
                . "The tracker and server contract have drifted."
            );
        }
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
