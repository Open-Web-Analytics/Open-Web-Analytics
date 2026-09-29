<?php

/**
 * Fixtures for the Domstream module.
 *
 * The module may not be active where tests run -- a fresh install, which is
 * what CI provisions, activates Base alone -- so a test creates the two tables
 * from the module's entities when they are missing, and registers the module's
 * report for itself. The entities and controllers resolve by name whether or
 * not the module is active.
 */
final class DomstreamFixtures
{
    /**
     * The recordings report and its action, as the module registers them.
     *
     * Returns a function that puts both maps back, so a test that runs with the
     * module inactive leaves nothing registered behind it.
     */
    public static function registerReport(): callable
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();

        \OWA\Core\CoreAPI::getReportRegistry();

        $reports = $s->getMap('reports');
        $actions = $s->getMap('actions');

        if (!$s->getMapValue('reports', 'domstreams')) {
            $s->setMapValue('reports', 'domstreams',
                ['controller' => 'domstream.reportDomstreams', 'module' => 'domstream']);
        }

        if (!$s->getMapValue('actions', 'domstream.reportDomstreams')) {
            $s->setMapValue('actions', 'domstream.reportDomstreams', [
                'class_name' => \OWA\Module\Domstream\Controller\ReportDomstreams::class,
                'file'       => OWA_BASE_MODULE_DIR,
            ]);
        }

        return static function () use ($s, $reports, $actions): void {
            $s->setMap('reports', $reports);
            $s->setMap('actions', $actions);
        };
    }

    public static function ensure(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (['domstream.domstream_chunk', 'domstream.domstream_payload'] as $name) {
            $entity = \OWA\Core\CoreAPI::entityFactory($name);

            if (!$db->tableExists($entity->getTableName())) {
                $entity->createTable();
            }
        }
    }

    public static function deleteSite(string $site): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $chunk = \OWA\Core\CoreAPI::entityFactory('domstream.domstream_chunk')->getTableName();
        $payload = \OWA\Core\CoreAPI::entityFactory('domstream.domstream_payload')->getTableName();

        if (!$db->tableExists($chunk)) {
            return;
        }

        $db->query(sprintf('DELETE p FROM %s p JOIN %s c ON c.id = p.id AND c.yyyymmdd = p.yyyymmdd WHERE c.site_id = ?',
            $payload, $chunk), [$site]);
        $db->query(sprintf('DELETE FROM %s WHERE site_id = ?', $chunk), [$site]);
    }

    /**
     * One chunk row, with defaults for what a test does not care about. The
     * counts are the samples' own unless the row gives them.
     *
     * Also used by the e2e seeders, so it depends on nothing from PHPUnit.
     */
    public static function chunk(string $site, array $row, ?array $samples = null): void
    {
        if ($samples !== null) {
            $types = array_column($samples, 1);
            $row += [
                'sample_count'   => count($samples),
                'click_count'    => count(array_keys($types, 'c', true)),
                'keypress_count' => count(array_keys($types, 'k', true)),
            ];
        }

        $row += [
            'site_id'        => $site,
            'visitor_id'     => '9100000000000000201',
            'session_id'     => '9100000000000000101',
            'seq'            => 1,
            'page_view_seq'  => 1,
            'page_location'  => 'https://domstream.test/page',
            'page_path'      => '/page',
            'ts'             => time() * 1000000,
            'offset_ms'      => 0,
            'duration_ms'    => 0,
            'sample_count'   => 0,
            'click_count'    => 0,
            'keypress_count' => 0,
            'viewport_w'     => 1280,
            'viewport_h'     => 800,
        ];
        $row['yyyymmdd'] = $row['yyyymmdd'] ?? (int) date('Ymd', intdiv((int) $row['ts'], 1000000));
        $row['id'] = \OWA\Module\Domstream\Classes\Chunk::id($site, $row['recording_id'], $row['seq']);

        $payload = gzencode(json_encode($samples ?? []), 6);
        $row['bytes'] = strlen($payload);

        \OWA\Module\Domstream\Controller\ProcessEvent::store([
            'chunk'   => $row,
            'payload' => ['id' => $row['id'], 'yyyymmdd' => $row['yyyymmdd'], 'payload' => $payload],
        ]);
    }
}
