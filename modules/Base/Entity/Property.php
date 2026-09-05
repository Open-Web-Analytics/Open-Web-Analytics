<?php

namespace OWA\Module\Base\Entity;

/**
 * The middle tier: the website or app being measured.
 *
 * Holds what the thing IS -- name, domain, description -- as opposed to how it
 * is measured, which belongs to the observation profiles beneath it. A property
 * may have several profiles, which is what lets two schema generations run side
 * by side and what lets one website be measured more than one way.
 *
 * ACCESS IS GRANTED HERE, not on the profile. Someone who may see a website
 * should see its measurements: a per-profile grant would mostly express "you
 * may read the v1 data but not the v2 data", which nobody wants and which would
 * silently split a user's view at cutover (PLAN.html §3.5).
 */
class Property extends \OWA\Core\Entity {

    function __construct() {

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
         * profile -- two profiles measuring one website necessarily share it,
         * which is exactly what md5( domain ) as an identifier made impossible
         * before identity was decoupled from it.
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
    }

    /** Web is the default: every Property that existed before types did is one. */
    const TYPE_WEB = 'web';
    const TYPE_APP = 'app';

    /** What a person is shown for each kind. */
    public static function types() {

        return array(
            self::TYPE_WEB => 'A website',
            self::TYPE_APP => 'An app',
        );
    }

    /**
     * What kind of thing this Property is.
     *
     * Falsy means web. The column is added by a migration and is NULL on rows
     * that predate it, and every one of those is a website -- they were minted
     * from sites that had domains.
     */
    public function getPropertyType() {

        $type = (string) $this->get( 'property_type' );

        return $type !== '' ? $type : self::TYPE_WEB;
    }

    /** Does this Property describe a website? */
    public function isWebProperty() {

        return $this->getPropertyType() === self::TYPE_WEB;
    }

    /**
     * Has this Property been archived?
     *
     * See Site::isArchived(). Archiving a Property archives its Profiles too,
     * because a Property is how a person says "this website" and removing it
     * means removing the ways it was being watched.
     */
    public function isArchived() {

        return (bool) $this->get('archived_date');
    }
}

?>
