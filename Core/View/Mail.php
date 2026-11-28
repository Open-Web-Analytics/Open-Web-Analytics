<?php
namespace OWA\Core\View;

class Mail extends \OWA\Core\View {

    /**
     * The post office.
     *
     * @var \OWA\Module\Base\Classes\Mailer
     */
    public $po;

    /** @var bool */
    public $postProcessView = true;

    public function __construct() {

        // make this a service
        $this->po = new \OWA\Module\Base\Classes\Mailer;
        parent::__construct();
    }

    /**
     * @return bool whether the transport accepted the mail
     */
    public function postProcess() {

        $this->po->setHtmlBody( $this->t->fetch() );

        if ( $this->get( 'plainTextView' ) ) {
            $this->po->setAltBody( \OWA\Core\CoreAPI::displayView( $this->get( 'plain_text_view' ) ) );
        }

        return $this->po->sendMail();
    }

    /**
     * @param  string $sbj
     * @return void
     */
    public function setMailSubject($sbj) {

        $this->po->setSubject( $sbj );
    }

    /**
     * Add the recipients: one address, or a comma-separated list of them, as
     * admin_notice_email may hold. An address the mailer refuses is logged and
     * skipped (Mailer::addAddress()).
     *
     * @param  string $email one address or a comma-separated list
     * @param  string $name  the recipient's name, used for a single address
     * @return int how many were added
     */
    public function addMailToAddress($email, $name = '') {

        $added     = 0;
        $addresses = array_values( array_filter( array_map( 'trim', explode( ',', (string) $email ) ), static fn ( $address ) => $address !== '' ) );

        foreach ( $addresses as $address ) {

            // A name belongs to one person: a list is addressed by address alone.
            $label = count( $addresses ) === 1 && ! empty( $name ) ? $name : $address;

            if ( $this->po->addAddress( $address, $label ) ) {

                $added++;
            }
        }

        return $added;
    }
}
