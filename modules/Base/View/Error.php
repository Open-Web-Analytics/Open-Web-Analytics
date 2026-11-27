<?php
namespace OWA\Module\Base\View;

/**
 * View
 */

class Error extends \OWA\Core\View {


    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        // Set Page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'Error' ));

        if($this->is_subview === true):
            $this->t->set_template('wrapper_blank.php');
        endif;

        /*
         * The message is shown ONCE, in the page's error banner (msgs.php in
         * the wrapper), which reads a headline and a message. Controllers pass
         * either getMsg()'s array or a plain string, which is turned into the
         * one shape here.
         */
        $this->t->set( 'error_msg', self::bannerMessage( $data['error_msg'] ?? null ) );

        $this->body->set( 'back_url', (string) ( $data['error_back_url'] ?? '' ) );
        $this->body->set_template('generic_error.php');

        /*
         * A signed-in user refused a page keeps the menu
         * (Controller::authenticatedButNotCapableAction() names its Property),
         * so a phone, where the menu button is the way to every other screen,
         * is not left at a dead end.
         */
        $navProperty = (string) ( $data['nav_property_id'] ?? '' );

        if ( $this->is_subview !== true && $navProperty !== '' ) {

            $this->t->set( 'nav_rail', true );
            $this->body->set( 'nav_property_id', $navProperty );
            $this->body->set( 'report_nav', \OWA\Core\ReportController::reportNavigation( $navProperty ) );
        }

        // The page's header controls -- the menus and the bell -- are in the bundle (owa.header.js).
        if ( $this->is_subview !== true ) {

            $this->setJs( 'owa.reporting', 'base/dist/owa.reporting-combined-min.js' );
        }

        // The admin chrome's styles: the header's icons and the banner itself.
        $this->setCss( 'base/css/font-awesome/css/all.min.css' );
        $this->setCss( 'base/css/owa.reporting-css-combined.css' );
    }

    /**
     * An error message in the banner's shape.
     *
     * @param  mixed $msg getMsg()'s array, an array with 'msg', a string, or nothing
     * @return array{headline: string, message: string, detail?: string} headline, message, and for an array its detail line
     */
    public static function bannerMessage( $msg ) {

        if ( is_array( $msg ) ) {

            return [
                'headline' => (string) ( $msg['headline'] ?? \OWA\Core\CoreAPI::t( 'Error' ) ),
                'message'  => (string) ( $msg['message'] ?? $msg['msg'] ?? '' ),
                'detail'   => (string) ( $msg['detail'] ?? '' ),
            ];
        }

        return [
            'headline' => \OWA\Core\CoreAPI::t( 'Error' ),
            'message'  => $msg !== null && $msg !== '' ? (string) $msg : \OWA\Core\CoreAPI::t( 'Something went wrong.' ),
        ];
    }
}
