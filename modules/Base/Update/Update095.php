<?php
namespace OWA\Module\Base\Update;

/**
 * severity, email_status and emailed_at arrive on owa_notification: how much
 * a notification matters, and what happened to the admin e-mail the process
 * that raised it sent (NotificationManager::notify()).
 *
 * Rows stored before this are release announcements: no severity, and no
 * e-mail asked for, which is what the empty and zero defaults say.
 *
 * Idempotent: a column already there is left alone. down() drops all three,
 * with what they hold, and finds nothing to drop when run again.
 *
 * NOT CLI-ONLY. Three columns appended to a table of tens of rows.
 */
class Update095 extends \OWA\Core\Update {

    public $schema_version = 95;

    /** In the entity's order, which is the order ADD COLUMN appends them in. */
    const COLUMNS = [ 'severity', 'email_status', 'emailed_at' ];

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.notification' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addColumnIfMissing( $table, $column ) === false ) {

                $this->e->notice( sprintf( 'Adding %s.%s failed', $table->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }

    /** The exact inverse: the columns go, with what they held. */
    public function down() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.notification' );

        foreach ( array_reverse( self::COLUMNS ) as $column ) {

            if ( $this->dropColumnIfPresent( $table, $column ) === false ) {

                $this->e->notice( sprintf( 'Dropping %s.%s failed', $table->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}
