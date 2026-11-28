<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Templates and views read only event and session fields that exist.
 *
 * Scans every PHP template (modules/<Module>/templates/) and View class
 * (modules/<Module>/View/, Core/View/) for a read of a key off event or
 * session data, and checks the key is a declared tracking property
 * (config/tracking_properties.json, or a module's trackingProperties()), an
 * owa_event_raw column, or a cube column. A key that is none of those names a
 * field ingest never fills, so the template prints an empty value.
 *
 * What counts as event or session data, by the name it is read through:
 *
 *   $view->event[...]  $view->session[...]       a template's view scope
 *   $event[...]  $session[...]  $owa_event[...]   a template local
 *   $this->event[...]  $this->session[...]        a view's property
 *   $view->get( 'event' )[...]  $this->get( 'session' )[...]
 *
 * each followed by ['key'] or ->get( 'key' ). Reads off other data -- a site
 * row's domain, a user's email_address, a cube's partitions -- are not checked.
 * Static: the source is read, nothing is rendered.
 */
final class TemplateEventFieldsTest extends TestCase
{

    /** The variable forms that hold event or session data. */
    private const DATA = '(?:\$(?:view->|this->|owa_)?(?:event|session)\b'
        . '|\$(?:view|this)->get\(\s*[\'"](?:event|session)[\'"]\s*\))';

    /** A literal key read off one of them. */
    private const KEY = '(?:\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]|->get\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\))';

    /**
     * The event and session keys a source reads, in order.
     *
     * @return list<string>
     */
    private static function reads( string $source ): array
    {

        preg_match_all( '/' . self::DATA . '\s*' . self::KEY . '/', $source, $m, PREG_SET_ORDER );

        return array_map( fn ( $hit ) => $hit[1] !== '' ? $hit[1] : $hit[2], $m );
    }

    /**
     * Every field an event or session row can carry.
     *
     * @return array<string, true>
     */
    private static function declaredFields(): array
    {

        $fields = array_keys( \OWA\Module\Base\Classes\TrackingEventHelpers::allProperties() );

        foreach ( \OWA\Core\CoreAPI::getPresentModules() as $dir ) {

            $class = 'OWA\\Module\\' . $dir . '\\Module';

            if ( class_exists( $class ) && method_exists( $class, 'trackingProperties' ) ) {

                $fields = array_merge( $fields, array_keys( $class::trackingProperties() ) );
            }
        }

        $fields = array_merge( $fields,
            ( new \OWA\Module\Base\Entity\EventRaw() )->getColumns(),
            ( new \OWA\Module\Base\Entity\Event() )->getColumns() );

        return array_fill_keys( $fields, true );
    }

    /** @return list<string> templates and View classes, relative to the root */
    private static function sources(): array
    {

        $root  = \OWA\Core\Env::root() . '/';
        $files = array_merge(
            glob( $root . 'modules/*/templates/*.php' ) ?: [],
            glob( $root . 'modules/*/templates/*/*.php' ) ?: [],
            glob( $root . 'modules/*/View/*.php' ) ?: [],
            glob( $root . 'Core/View/*.php' ) ?: [] );

        sort( $files );

        return array_map( fn ( $f ) => substr( $f, strlen( $root ) ), $files );
    }

    public function testEveryEventAndSessionReadIsADeclaredField(): void
    {

        $declared = self::declaredFields();
        $sources  = self::sources();

        $this->assertArrayHasKey( 'page_title', $declared, 'the declared fields were not read' );
        $this->assertGreaterThan( 50, count( $sources ), 'the templates and views were not found' );

        $unknown = [];

        foreach ( $sources as $file ) {

            $code = (string) file_get_contents( \OWA\Core\Env::root() . '/' . $file );

            foreach ( self::reads( $code ) as $key ) {

                if ( ! isset( $declared[ $key ] ) ) {

                    $unknown[] = $file . ': ' . $key;
                }
            }
        }

        $this->assertSame( [], array_values( array_unique( $unknown ) ),
            'these templates read an event or session field that is neither a tracking property nor a raw or cube column' );
    }

    /**
     * The scan finds what it is meant to find: the reads of the 1.x new-visit
     * e-mail (new_session_email.php, removed with the announcement), three of
     * which name fields v2 has no longer.
     */
    public function testTheScanFindsReadsOffEventAndSessionData(): void
    {

        $source = <<<'PHP'
<p><?php $view->out( sprintf( 'There was a new visit to site: %s.', $view->site['domain'] ) );?></p>
<p><?php $view->out( sprintf( 'Visitor ID: %s', $view->session['visitor_id'] ) );?></p>
<p><?php $view->out( sprintf( '%1$s (%2$s)', $view->session['user_name'],
    isset( $view->session['user_email'] ) ? $view->session['user_email'] : 'not set' ) );?></p>
<p><?php $view->out( sprintf( 'Host: %s', $view->session[ 'host' ] ) );?></p>
<p><?php $view->out( sprintf( 'Entry page: %1$s - %2$s', $view->session['page_title'], $view->session['page_url'] ) );?></p>
<?php $owa_event['city']; $event->get( 'country' ); $this->get( 'session' )['visitor_id']; $event_type['x']; $view->sessions['y'];
PHP;

        $reads = self::reads( $source );

        $this->assertSame( [ 'visitor_id', 'user_name', 'user_email', 'user_email', 'host', 'page_title',
            'page_url', 'city', 'country', 'visitor_id' ], $reads,
            'a site read and look-alike names ($event_type, $view->sessions) are not event or session data' );

        $declared = self::declaredFields();

        $this->assertSame( [ 'user_name', 'user_email', 'page_url' ],
            array_values( array_unique( array_filter( $reads, fn ( $k ) => ! isset( $declared[ $k ] ) ) ) ) );
    }
}
