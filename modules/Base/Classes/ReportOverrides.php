<?php
namespace OWA\Module\Base\Classes;

/**
 * A user's own version of a shipped report: the Dashboard as they arranged
 * it, drawn in the shipped one's place for them, on one Property.
 *
 * Stored as a custom report row that names the report it replaces
 * (owa_custom_report.replaces). That gives it the custom report's builder,
 * validation, ownership and delete for nothing; what differs is that it is
 * never on the roster, never shared, and is reached by the shipped report's
 * own id, so every link and nav entry to that report shows it.
 *
 * PER USER, PER PROPERTY (Peter 2026-11-26): an edit made on one Property
 * leaves the others on the shipped report, as a custom report is of one
 * Property.
 *
 * WHICH REPORTS. A shipped report is editable when the builder could have
 * made it: it takes no parameters and passes the builder's own rules
 * (CustomReports::validate()). That leaves out the drill-downs that read a
 * page or a source from the URL, and the reports that name no metrics and
 * draw the Property's metric sets as tabs, which the builder does not author.
 *
 * THE SHIPPED TITLE. It is the same report, reached by the same nav entry,
 * so it keeps the shipped title and description: neither is stored from the
 * editor, and both are drawn from the shipped definition as it is now --
 * translated, and as a release last worded them. A different name is a
 * custom report.
 *
 * A CHANGED DEFAULT. An override is a copy, so a release that changes the
 * shipped report does not reach it. The hash of the shipped definition it
 * was made from is stored with it (replaces_hash), and the report says when
 * the two differ; Reset deletes the override.
 *
 * @phpstan-import-type Definition from CustomReports
 * @phpstan-import-type Report from CustomReports
 * @phpstan-import-type SaveResult from CustomReports
 */
final class ReportOverrides {

    /**
     * A shipped report's definition, decoded; null for an id that is not a
     * shipped definition file (a custom report, a controller, nothing).
     *
     * @param  string $report_id
     * @return Definition|null
     */
    public static function shipped( $report_id ) {

        $registered = \OWA\Core\CoreAPI::getReportDefinition( (string) $report_id );
        $path       = is_array( $registered ) ? (string) ( $registered['json'] ?? '' ) : '';

        if ( $path === '' || ! is_readable( $path ) ) {

            return null;
        }

        $definition = json_decode( (string) file_get_contents( $path ), true );

        return is_array( $definition ) ? $definition : null;
    }

    /**
     * Whether a shipped report may be edited: one the builder could have made.
     *
     * @param  string $report_id
     * @return bool
     */
    public static function isEditable( $report_id ) {

        $report_id = (string) $report_id;

        // Asked several times for one report on one request: the file and the rules do not change within it.
        if ( ! array_key_exists( $report_id, self::$editable ) ) {

            $definition = self::shipped( $report_id );

            self::$editable[ $report_id ] = $definition !== null
                && ! isset( $definition['params'] )
                && ! isset( $definition['capability'] )
                && CustomReports::validate( $definition ) === '';
        }

        return self::$editable[ $report_id ];
    }

    /** @var array<string, bool> isEditable()'s answers, this request */
    private static $editable = [];

    /**
     * The fingerprint of a shipped definition, to tell when it has changed.
     *
     * @param  Definition $definition
     * @return string
     */
    public static function hashOf( array $definition ) {

        return hash( 'sha256', (string) json_encode( $definition ) );
    }

    /**
     * A user's override of a report on a Property, or null.
     *
     * @param  string $report_id
     * @param  string $user_id
     * @param  string $property_id
     * @return Report|null
     */
    public static function find( $report_id, $user_id, $property_id ) {

        if ( (string) $report_id === '' || (string) $user_id === '' || (string) $property_id === '' ) {

            return null;
        }

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT id FROM %s WHERE replaces = ? AND user_id = ? AND property_id = ? ORDER BY last_updated_timestamp DESC LIMIT 1',
            \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' )->getTableName() ),
            [ (string) $report_id, (string) $user_id, (string) $property_id ] );

        return $row ? CustomReports::load( (string) $row['id'] ) : null;
    }

    /**
     * Store a user's version of a shipped report, replacing theirs if they
     * have one on that Property.
     *
     * @param  string     $report_id   the shipped report
     * @param  string     $name        ignored: it keeps the shipped title
     * @param  mixed      $definition  as posted: JSON or decoded
     * @param  string     $user_id
     * @param  string     $property_id
     * @return SaveResult
     */
    public static function save( $report_id, $name, $definition, $user_id, $property_id ) {

        $shipped = self::shipped( $report_id );

        if ( $shipped === null || ! self::isEditable( $report_id ) ) {

            return [ 'ok' => false, 'id' => '', 'error' => \OWA\Core\CoreAPI::t( 'That report cannot be edited.' ) ];
        }

        $mine = self::find( $report_id, $user_id, $property_id );

        return CustomReports::save( [
            'id'            => $mine ? (string) $mine['id'] : '',
            'name'          => (string) ( $shipped['title'] ?? $report_id ),
            'definition'    => $definition,
            'is_shared'     => false,
            'replaces'      => (string) $report_id,
            'replaces_hash' => self::hashOf( $shipped ),
        ], $user_id, $property_id );
    }

    /**
     * What to draw for this reader: their override of the report on this
     * Property, ready to render, or null for the shipped report.
     *
     * `error` is set, and `definition` empty, when their override no longer
     * passes the rules on this Property (a custom dimension it used was
     * removed): the shipped report is drawn and the reader told why.
     *
     * @param  string $report_id
     * @param  string $user_id
     * @param  string $property_id
     * @return array{id: string, definition: Definition, stale: bool, error: string}|null
     */
    public static function forReader( $report_id, $user_id, $property_id ) {

        if ( ! self::isEditable( $report_id ) ) {

            return null;
        }

        $mine = self::find( $report_id, $user_id, $property_id );

        if ( ! $mine ) {

            return null;
        }

        $definition = CustomReports::drawable( (array) $mine['definition'] );
        $shipped    = self::shipped( $report_id );

        // The shipped report's title and description, as it is now.
        foreach ( [ 'title', 'description' ] as $key ) {

            unset( $definition[ $key ] );

            if ( isset( $shipped[ $key ] ) ) {

                $definition[ $key ] = $shipped[ $key ];
            }
        }

        $error = CustomReports::validate( $definition, $property_id );

        return [
            'id'         => (string) $mine['id'],
            'definition' => $error === '' ? $definition : [],
            'stale'      => $shipped !== null && (string) ( $mine['replaces_hash'] ?? '' ) !== self::hashOf( $shipped ),
            'error'      => $error,
        ];
    }
}
