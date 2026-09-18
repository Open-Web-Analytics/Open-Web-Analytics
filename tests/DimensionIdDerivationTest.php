<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Core\Lib;

/**
 * One derivation of a dimension id, owned by the dimension.
 *
 * These tests exist because the derivation used to live in three places that
 * disagreed, and the disagreement was invisible: a handler wrote a row at one
 * hash while the fact table pointed at another, and the report simply showed
 * fewer rows than it should. Nothing errored. So the assertions here are mostly
 * equivalence against the OLD derivation and against ids already in live data --
 * a green suite proving the new code agrees with itself would be worth nothing.
 */
final class DimensionIdDerivationTest extends TestCase
{
    /** Every content-derived dimension, its key, and what absence means. */
    private const DIMENSIONS = array(
        'base.source_dim'      => array( array( 'source' ),          'unknown' ),
        'base.host'            => array( array( 'host' ),            'unknown' ),
        'base.ua'              => array( array( 'HTTP_USER_AGENT' ), 'unknown' ),
        'base.os'              => array( array( 'os' ),              'unknown' ),
        'base.document'        => array( array( 'page_url' ),        'unknown' ),
        'base.location_dim'    => array( array( 'country', 'state', 'city' ), 'unknown' ),
        'base.referer'         => array( array( 'session_referer' ), 'not_applicable' ),
        'base.search_term_dim' => array( array( 'search_terms' ),    'not_applicable' ),
        'base.campaign_dim'    => array( array( 'campaign' ),        'not_applicable' ),
        'base.ad_dim'          => array( array( 'ad' ),              'not_applicable' ),
    );

    /** OWA entity name -> class. deriveId() is static, so nothing is instantiated. */
    private const CLASSES = array(
        'base.source_dim'      => \OWA\Module\Base\Entity\SourceDim::class,
        'base.host'            => \OWA\Module\Base\Entity\Host::class,
        'base.ua'              => \OWA\Module\Base\Entity\Ua::class,
        'base.os'              => \OWA\Module\Base\Entity\Os::class,
        'base.document'        => \OWA\Module\Base\Entity\Document::class,
        'base.location_dim'    => \OWA\Module\Base\Entity\LocationDim::class,
        'base.referer'         => \OWA\Module\Base\Entity\Referer::class,
        'base.search_term_dim' => \OWA\Module\Base\Entity\SearchTermDim::class,
        'base.campaign_dim'    => \OWA\Module\Base\Entity\CampaignDim::class,
        'base.ad_dim'          => \OWA\Module\Base\Entity\AdDim::class,
    );

    private function entity( string $name ): string
    {
        return self::CLASSES[ $name ];
    }

    public static function dimensions(): array
    {
        $out = array();
        foreach ( self::DIMENSIONS as $name => $spec ) { $out[ $name ] = array( $name, $spec[0], $spec[1] ); }
        return $out;
    }

    /** @dataProvider dimensions */
    public function testTheDeclaredKeyIsWhatTheDimensionUses( string $name, array $key ): void
    {
        $e = $this->entity( $name );
        $this->assertSame( $key, $e::CONTENT_KEY, "$name declares an unexpected content key" );
    }

    /** @dataProvider dimensions */
    public function testAbsenceIsDeclaredAsExpected( string $name, array $key, string $absence ): void
    {
        $e = $this->entity( $name );
        $this->assertSame( $absence, $e::ABSENCE, "$name declares the wrong absence semantics" );
    }

    /**
     * A CONTENT_KEY naming a property that does not exist would hash '' forever
     * and quietly send every row to the unresolved bucket. A typo here is
     * exactly the kind of silent defect this whole change is about.
     */
    /** @dataProvider dimensions */
    public function testEveryKeyPropertyIsARealTrackingProperty( string $name, array $key ): void
    {
        $config = json_decode(
            (string) file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

        $known = array();
        foreach ( (array) $config as $scope => $properties ) {
            $known = array_merge( $known, array_keys( (array) $properties ) );
        }

        foreach ( $key as $property ) {
            $this->assertContains( $property, $known,
                "$name keys on '$property', which no module registers as a tracking property" );
        }
    }

    /**
     * The derivation must agree with the one it replaces, or every existing row
     * becomes unreachable and the dimension silently doubles.
     *
     * @dataProvider legacyEquivalents
     */
    public function testItReproducesTheLegacyId( string $name, array $props, string $legacy ): void
    {
        $e = $this->entity( $name );
        $this->assertSame( $legacy, (string) $e::deriveId( $props ) );
    }

    public static function legacyEquivalents(): array
    {
        // The legacy derivations, spelled out as they appeared at each old site:
        // Source/Campaign/Ad trimmed and lowercased first; the rest hashed raw.
        $lower = function ( $v ) { return Lib::setStringGuid( trim( strtolower( $v ) ) ); };
        $raw   = function ( $v ) { return Lib::setStringGuid( $v ); };

        return array(
            'source'        => array( 'base.source_dim',      array( 'source' => 'Google' ),                   (string) $lower( 'Google' ) ),
            'source spaced' => array( 'base.source_dim',      array( 'source' => ' google ' ),                 (string) $lower( ' google ' ) ),
            'campaign'      => array( 'base.campaign_dim',    array( 'campaign' => 'Spring Sale' ),            (string) $lower( 'Spring Sale' ) ),
            'ad'            => array( 'base.ad_dim',          array( 'ad' => 'Banner A' ),                     (string) $lower( 'Banner A' ) ),
            'document'      => array( 'base.document',        array( 'page_url' => 'https://x.test/a' ),       (string) $raw( 'https://x.test/a' ) ),
            'ua'            => array( 'base.ua',              array( 'HTTP_USER_AGENT' => 'Mozilla/5.0' ),     (string) $raw( 'Mozilla/5.0' ) ),
            'os'            => array( 'base.os',              array( 'os' => 'windows' ),                      (string) $raw( 'windows' ) ),
            'host'          => array( 'base.host',            array( 'host' => 'example.test' ),               (string) $raw( 'example.test' ) ),
            'referer'       => array( 'base.referer',         array( 'session_referer' => 'https://r.test/p' ),(string) $raw( 'https://r.test/p' ) ),
            'terms'         => array( 'base.search_term_dim', array( 'search_terms' => 'shoes' ),              (string) $raw( 'shoes' ) ),
            'location'      => array( 'base.location_dim',
                array( 'country' => 'United States', 'state' => 'Virginia', 'city' => 'Ashburn' ),
                (string) Lib::setStringGuid( 'united states' . 'virginia' . 'ashburn' ) ),
        );
    }

    /**
     * The reserved key is the historical one, so old and new rows coincide.
     *
     * Asserted against the spelling every install already stores, not against
     * the constant -- change the constant and old data silently stops joining
     * to new data. These exact ids are present on demo today.
     */
    /** @dataProvider dimensions */
    public function testAbsenceLandsOnTheHistoricalRow( string $name, array $key, string $absence ): void
    {
        $e  = $this->entity( $name );
        $id = $e::deriveId( array() );

        if ( $absence === 'not_applicable' ) {

            $this->assertNull( $id, "$name should mint no row when the thing does not exist" );
            return;
        }

        $this->assertSame(
            (string) Lib::setStringGuid( str_repeat( '(not set)', count( $key ) ) ),
            (string) $id,
            "$name's unresolved id no longer matches what existing installs store" );
    }

    /** Every flavour of absence collapses to one row, not several. */
    public function testEveryFlavourOfAbsenceAgrees(): void
    {
        $e = $this->entity( 'base.location_dim' );

        $canonical = $e::deriveId( array() );

        $this->assertSame( $canonical, $e::deriveId( array( 'country' => '', 'state' => '', 'city' => '' ) ) );
        $this->assertSame( $canonical, $e::deriveId( array( 'country' => null, 'state' => null, 'city' => null ) ) );
        $this->assertSame( $canonical, $e::deriveId( array( 'country' => '  ', 'city' => ' ' ) ) );
    }

    /**
     * Normalisation happens once, here.
     *
     * Case was always safe (wideStringGuid lowercases internally); whitespace
     * was not, and that asymmetry is what split one dimension row into two
     * depending on which code path derived the id.
     */
    public function testWhitespaceAndCaseCollapseToOneRow(): void
    {
        $e = $this->entity( 'base.source_dim' );

        $canonical = $e::deriveId( array( 'source' => 'google' ) );

        foreach ( array( 'Google', 'GOOGLE', ' google', 'google ', "  Google\t" ) as $variant ) {
            $this->assertSame( $canonical, $e::deriveId( array( 'source' => $variant ) ),
                "'$variant' should resolve to the same source row as 'google'" );
        }
    }

    /**
     * deriveId() reads ONLY its declared key.
     *
     * This is the anti-over-hashing invariant. Handing it a bag that already
     * carries a derived id must not change the answer -- hashing an
     * already-derived id is the defect fixed once in registerCallbacks and
     * still present in LocationHandlers.
     */
    /** @dataProvider dimensions */
    public function testADerivedIdInTheBagIsIgnored( string $name, array $key ): void
    {
        $e = $this->entity( $name );

        $content = array();
        foreach ( $key as $property ) { $content[ $property ] = 'x'; }

        $clean = $e::deriveId( $content );

        $polluted = $content + array(
            'location_id' => '123', 'source_id' => '456', 'host_id' => '789',
            'ua_id' => '101', 'referer_id' => '112', 'document_id' => '131',
            'id' => '415', 'site_id' => 'OWA-deadbeefdeadbeef',
        );

        $this->assertSame( $clean, $e::deriveId( $polluted ),
            "$name::deriveId() read something outside its CONTENT_KEY" );
    }

    /**
     * No dimension reimplements the derivation.
     *
     * The whole point is that there is one. A subclass that overrides deriveId()
     * is how the codebase got three of them in the first place.
     */
    /** @dataProvider dimensions */
    public function testNoDimensionOverridesTheDerivation( string $name ): void
    {
        $e = $this->entity( $name );

        $method = new ReflectionMethod( $e, 'deriveId' );

        $this->assertSame( 'OWA\Core\Entity\DimensionEntity', $method->getDeclaringClass()->getName(),
            $e . ' overrides deriveId() instead of declaring a CONTENT_KEY' );
    }

    /** A dimension with no declared key must say so rather than hash nothing. */
    public function testADimensionWithNoKeyRefusesToDerive(): void
    {
        $anon = new class extends \OWA\Core\Entity\DimensionEntity {};

        $this->assertFalse( $anon::isContentDerived() );
        $this->expectException( \LogicException::class );
        $anon::deriveId( array( 'source' => 'x' ) );
    }
}
