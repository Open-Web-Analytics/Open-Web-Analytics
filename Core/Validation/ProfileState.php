<?php
namespace OWA\Core\Validation;

/**
 * Profile State Validation
 *
 * The Profile a site_id names is live (`live`: not archived) and observes
 * the `stream` configured (Entity\Site::STREAM_*). A site_id that names no
 * Profile passes: entityExists answers for that.
 */
class ProfileState extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $site_id = is_scalar( $this->getValues() ) ? (string) $this->getValues() : '';
        $site    = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

        if ( ! $site->wasPersisted() ) {

            return;
        }

        if ( $this->getConfig( 'live' ) && $site->isArchived() ) {

            $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'Profile %s is archived.' ), $site_id ) );
            $this->hasError();

            return;
        }

        $stream = $this->getConfig( 'stream' );

        if ( $stream !== null && $site->getStreamType() !== $stream ) {

            $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'Profile %1$s observes %2$s, not %3$s.' ),
                $site_id, $site->getStreamType(), $stream ) );
            $this->hasError();
        }
    }
}
