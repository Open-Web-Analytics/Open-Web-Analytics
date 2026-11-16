<?php

/**
 * For an update test that runs against the install's own settings table and
 * settings singleton, so the save the update run ends with can be part of it.
 *
 * keepInstallRows() copies the install rows of the named base settings and the
 * singleton's state; putBackInstallRows() restores both exactly. The table and
 * the singleton must agree afterwards, or the shutdown save rewrites the table.
 */
trait InstallSettingRowsFixture
{
    /** @var array<int,array> */
    private array $kept_rows = [];

    /** @var string[] */
    private array $kept_names = [];

    private ?array $kept_db_settings = null;

    private ?array $kept_effective = null;

    private bool $kept_dirty = false;

    private function installTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory('base.setting')->getTableName();
    }

    private function namesIn(array $names): string
    {
        return implode(', ', array_fill(0, count($names), '?'));
    }

    protected function keepInstallRows(array $names): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->kept_names = $names;
        $this->kept_rows = array_map(fn ($row) => (array) $row, (array) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf("SELECT * FROM %s WHERE scope_type = 'install' AND module = 'base' AND name IN ( %s )",
                $this->installTable(), $this->namesIn($names)), $names));
        $this->kept_db_settings = $c->db_settings;
        $this->kept_effective = $c->config ? $c->config->get('settings') : null;
        $this->kept_dirty = (bool) $c->is_dirty;
    }

    protected function putBackInstallRows(): void
    {
        if ($this->kept_names === []) {
            return;
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query(sprintf("DELETE FROM %s WHERE scope_type = 'install' AND module = 'base' AND name IN ( %s )",
            $this->installTable(), $this->namesIn($this->kept_names)), $this->kept_names);

        foreach ($this->kept_rows as $row) {
            $db->query(sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->installTable(), implode(', ', array_keys($row)),
                $this->namesIn($row)), array_values($row));
        }

        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->db_settings = $this->kept_db_settings;

        if ($c->config && $this->kept_effective !== null) {
            $c->config->set('settings', $this->kept_effective);
        }

        $c->is_dirty = $this->kept_dirty;
        $this->kept_names = [];
    }

    /** The install row of base.<name> in the install's table, unserialized, or null. */
    protected function storedInstallValue(string $name)
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf(
            "SELECT value FROM %s WHERE scope_type = 'install' AND module = 'base' AND name = ?", $this->installTable()), [$name]);

        return $row ? unserialize((string) ((array) $row)['value']) : null;
    }

    /** Write an install row of base.<name> straight to the install's table. */
    protected function writeInstallValue(string $name, $value): void
    {
        $id = \OWA\Core\CoreAPI::entityFactory('base.setting')->makeId('install', '1', 'base', $name);

        \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
            "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)"
          . " VALUES (?, 'install', '1', 'base', ?, ?, 1, 1) ON DUPLICATE KEY UPDATE value = VALUES(value)", $this->installTable()),
            [$id, $name, serialize($value)]);
    }
}
