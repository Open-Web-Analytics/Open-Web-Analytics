<?php

use PHPUnit\Framework\TestCase;

/**
 * The shipped .htaccess's cache headers: the tracker keeps one URL for life and
 * revalidates (no-cache); a script or stylesheet a screen loads with ?version=
 * (View::assetVersion()) is a new URL per build and is kept a year. Checked by
 * hand against Apache on loopback; this keeps the rules from drifting.
 */
final class HtaccessCacheTest extends TestCase
{
    private static function htaccess(): string
    {
        return (string) file_get_contents(__DIR__ . '/../.htaccess');
    }

    public function testTheTrackerRevalidatesUnlessTheUrlIsVersioned(): void
    {
        $this->assertStringContainsString(
            '<If "%{REQUEST_URI} =~ m#/public/(tracker|base/dist)/[^/]+\.js$# && %{QUERY_STRING} !~ /(^|&)version=/">',
            self::htaccess());
        $this->assertStringContainsString('Header set Cache-Control "no-cache"', self::htaccess());
    }

    public function testAVersionedAssetIsKeptAYear(): void
    {
        $this->assertMatchesRegularExpression(
            '#<If "%\{REQUEST_URI\} =~ m\#/public/\.\+\\\\\.\(js\|css\)\$\# && %\{QUERY_STRING\} =~ /\(\^\|&\)version=/">\s*<IfModule mod_headers\.c>\s*Header set Cache-Control "public, max-age=31536000, immutable"#',
            self::htaccess());
    }

    /** JSON is left uncompressed: a REST answer can hold a secret beside text the request chose (BREACH). */
    public function testScriptsAndStylesheetsAreCompressedAndJsonIsNot(): void
    {
        $this->assertMatchesRegularExpression('/AddOutputFilterByType DEFLATE [^\n]*text\/css/', self::htaccess());
        $this->assertDoesNotMatchRegularExpression('/AddOutputFilterByType DEFLATE [^\n]*json/', self::htaccess());
    }
}
