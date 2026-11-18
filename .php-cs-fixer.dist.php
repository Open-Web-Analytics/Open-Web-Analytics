<?php

/**
 * PHP-CS-Fixer: OWA's own PHP. Four rules and no preset, so nothing else
 * about the layout changes -- in particular the blank line after an opening
 * brace stays.
 *
 *   composer cs       check: lists what would change, with the diff
 *   composer cs:fix   applies it
 *
 * Templates mix PHP and HTML: the rules only touch PHP tokens, never the
 * inline HTML between them.
 */

// Git-ignored paths (vendor/, owa-data/, public/ and the other build output,
// owa-config.php) are skipped; vendor and node_modules are named as well, for a
// checkout without the .gitignore.
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCSIgnored(true)
    ->exclude(['vendor', 'node_modules'])
    // Written by `php cli.php cmd=strings-extract` (Core\Strings);
    // StringsCatalogueTest compares them with the generator's output.
    ->notPath([
        'conf/strings/en.php',
        'conf/strings/script-keys.php',
    ])
    // Tracked although owa-data/ is ignored.
    ->append([
        __DIR__ . '/owa-data/index.php',
        __DIR__ . '/owa-data/logs/index.php',
    ]);

return (new PhpCsFixer\Config())
    // One process: the default spreads the run over every CPU.
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::sequential())
    ->setRiskyAllowed(false)
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setRules([
        // Indentation is spaces: a tab in leading whitespace (code or comment)
        // becomes four spaces.
        'indentation_type' => true,
        // [] rather than array().
        'array_syntax' => ['syntax' => 'short'],
        // No closing tag at the end of a file that is only PHP.
        'no_closing_tag' => true,
        // The file ends in one newline. Without it, a file whose closing tag
        // went would end on its last brace.
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
