<?php

use PHPUnit\Framework\TestCase;

/**
 * A table an entity declares is named by the entity (getTableName()), never
 * written out as owa_<name>: the prefix and the name live in one place.
 *
 * Scans string literals only (token_get_all), so a comment naming a table does
 * not count. Update scripts and the v1 migration are exempt: each records a
 * schema at one moment, and the migration reads 1.x tables no entity declares.
 */
final class TableNamesFromEntitiesTest extends TestCase
{
    private const EXEMPT_DIRS = [
        'modules/Base/Update/',
        'modules/Domstream/Update/',
        'modules/Base/Classes/Migration/',
    ];

    /** @return string[] owa_<name> for every table an entity declares */
    private function entityTables(): array
    {
        $tables = [];

        foreach (glob(dirname(__DIR__) . '/modules/*/Entity/*.php') as $file) {
            if (preg_match_all("/setTableName\\(\\s*'([a-z_]+)'/", (string) file_get_contents($file), $m)) {
                foreach ($m[1] as $name) {
                    $tables[] = \OWA\Core\Entity::TABLE_PREFIX . $name;
                }
            }
        }

        return array_unique($tables);
    }

    /** @return array<string, string> relative path => absolute path */
    private function sourceFiles(): array
    {
        $root  = dirname(__DIR__);
        $files = [];

        foreach (['Core', 'modules', 'api'] as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                $relative = str_replace($root . '/', '', $file->getPathname());

                if ($file->getExtension() !== 'php' || strpos($relative, '/vendor/') !== false) {
                    continue;
                }

                foreach (self::EXEMPT_DIRS as $exempt) {
                    if (strpos($relative, $exempt) === 0) {
                        continue 2;
                    }
                }

                $files[$relative] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testNoDeclaredTableIsWrittenOut(): void
    {
        require_once dirname(__DIR__) . '/Core/Entity.php';

        $tables  = $this->entityTables();
        $pattern = '/(^|[\s`\'"(])(' . implode('|', array_map('preg_quote', $tables)) . ')($|[\s`\'",)])/';

        $this->assertNotEmpty($tables);

        $found = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match($pattern, $token[1], $m)) {
                    $found[] = $relative . ': ' . $m[2];
                }
            }
        }

        $this->assertSame([], array_values(array_unique($found)),
            "Name these tables through their entity's getTableName().");
    }
}
