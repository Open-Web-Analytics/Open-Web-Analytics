<?php

namespace OWA\Module\Base\Entity;

/**
 * The middle tier: the website or app being measured.
 *
 * Holds what the thing IS -- name, domain, description -- as opposed to how it
 * is measured, which belongs to the observation profiles beneath it. A property
 * may have several profiles, which lets one website be measured more than one
 * way.
 *
 * ACCESS IS GRANTED HERE, not on the profile: someone who may see a website
 * should see its measurements.
 */
class Property extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'property' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $organization_id = new \OWA\Module\Base\Classes\DbColumn( 'organization_id', OWA_DTD_BIGINT );
        $organization_id->setIndex();
        $this->setProperty( $organization_id );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /*
         * The domain describes the website, so it lives here rather than on the
         * profile -- two profiles measuring one website necessarily share it.
         */
        $domain = new \OWA\Module\Base\Classes\DbColumn( 'domain', OWA_DTD_VARCHAR255 );
        $domain->setIndex();
        $this->setProperty( $domain );

        /*
         * What KIND of thing this Property is: a website or an app.
         *
         * The Profile answers a version of this too -- it decides whether that
         * Profile needs a domain or a bundle id -- but the domain lives HERE,
         * because it describes the thing being measured rather than one way of
         * watching it. So this is where "this is a website" has to be said, and
         * it is what makes the domain requirable: a web Property with no domain
         * cannot have its origin checked, which is what a tracking request is
         * accepted or refused on.
         *
         * Chosen once, at creation, and not editable afterwards. Changing it
         * would invalidate the identifier every Profile beneath it was set up
         * with, and the Profiles are the things actually recording.
         */
        $property_type = new \OWA\Module\Base\Classes\DbColumn( 'property_type', OWA_DTD_VARCHAR255 );
        $this->setProperty( $property_type );

        $description = new \OWA\Module\Base\Classes\DbColumn( 'description', OWA_DTD_TEXT );
        $this->setProperty( $description );

        $settings = new \OWA\Module\Base\Classes\DbColumn( 'settings', OWA_DTD_BLOB );
        $this->setProperty( $settings );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );

        /*
         * When this was archived. FALSY means live.
         *
         * A timestamp rather than a boolean flag, because a restore wants to
         * know when -- and because a tinyint in this schema holds 1, 0 and
         * NULL, which group as three distinct things.
         *
         * Read it as FALSY, never as `IS NULL`. The column genuinely holds
         * three values: NULL for a row that has never been archived, a stamp
         * for one that is, and 0 for one that was restored. Restoring cannot
         * write NULL back through the entity layer -- setting '' on a numeric
         * column is treated as "no value given" and skipped, so 0 is what
         * lands. All three are answered correctly by empty()/(bool); an
         * `IS NULL` test would quietly classify every restored row as archived.
         */
        $archived_date = new \OWA\Module\Base\Classes\DbColumn( 'archived_date', OWA_DTD_BIGINT );
        $this->setProperty( $archived_date );

        /*
         * The domain's host (Spam\Hosts::of()) while this is a live web
         * Property, NULL otherwise: archived, an app, or no domain. UNIQUE, so
         * two live Properties cannot share a host, and any number of archived
         * ones can. create() and update() keep it in step with domain,
         * property_type and archived_date (liveHost()); Update103 adds it to an
         * existing table.
         */
        $live_host = new \OWA\Module\Base\Classes\DbColumn( 'live_host', OWA_DTD_VARCHAR255 );
        $live_host->setNullable();
        $this->setProperty( $live_host );
        $this->addUniqueIndex( self::LIVE_HOST_INDEX, [ 'live_host' ] );
    }

    /** The UNIQUE index on live_host. */
    const LIVE_HOST_INDEX = 'live_host_unique';

    /** Web is the default, and what every Property migrated from 1.x is. */
    const TYPE_WEB = 'web';
    const TYPE_APP = 'app';

    /**
     * What a person is shown for each kind.
     *
     * @return array<string, string> type => label
     */
    public static function types() {

        return [
            self::TYPE_WEB => 'A website',
            self::TYPE_APP => 'An app',
        ];
    }

    /**
     * What kind of thing this Property is.
     *
     * Falsy means web: a NULL is a row minted from a 1.x site, which had a
     * domain.
     *
     * @return string
     */
    public function getPropertyType() {

        $type = (string) $this->get( 'property_type' );

        return $type !== '' ? $type : self::TYPE_WEB;
    }

    /**
     * Does this Property describe a website?
     *
     * @return bool
     */
    public function isWebProperty() {

        return $this->getPropertyType() === self::TYPE_WEB;
    }

    /**
     * Has this Property been archived?
     *
     * See Site::isArchived(). Archiving a Property archives its Profiles too,
     * because a Property is how a person says "this website" and removing it
     * means removing the ways it was being watched.
     *
     * @return bool
     */
    public function isArchived() {

        return (bool) $this->get('archived_date');
    }

    /**
     * What live_host holds for this row: the domain's host while this is a
     * live web Property, else null.
     *
     * @return string|null
     */
    public function liveHost() {

        if ( $this->isArchived() || ! $this->isWebProperty() ) {

            return null;
        }

        $host = \OWA\Module\Base\Classes\Spam\Hosts::of( (string) $this->get( 'domain' ) );

        return $host !== '' ? $host : null;
    }

    /**
     * @return bool
     */
    public function create() {

        $this->syncLiveHost();

        return parent::create();
    }

    /**
     * @param  string $where
     * @return bool
     */
    public function update( $where = '' ) {

        $this->syncLiveHost();

        return parent::update( $where );
    }

    /**
     * Set live_host from the row's other columns before a write.
     *
     * Before Update103 the table has no such column, and cmd=property runs
     * then (to archive the duplicates Update103 refuses): the column is left
     * out of this object's writes.
     *
     * @return void
     */
    protected function syncLiveHost() {

        if ( ! in_array( 'live_host', \OWA\Core\CoreAPI::dbSingleton()->listColumns( $this->getTableName() ), true ) ) {

            unset( $this->properties['live_host'] );

            return;
        }

        $host = $this->liveHost();

        if ( $host === null ) {

            $this->clear( 'live_host' );

        } else {

            $this->set( 'live_host', $host );
        }
    }
}
