<?php
namespace OWA\Core\View;

class Cli extends \OWA\Core\View {

    /**
     * @return void
     */
    public function pre() {
        
        $this->t->set_template('wrapper_blank.php');
        $this->body->set_template('msgsCli.php');
        
        $error = [];
        
        // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
            
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
            
            $error[] = $this->data['validation_errors'];
        }
        
        $this->body->set('response_data', '');
        $this->body->set('error', $error);
    }
    
    /**
     * Sets the data payload of the response
     *
     * @param  mixed $data the payload, any type: msgsCli.php prints it as JSON
     * @return void
     */
    public function setResponseData( $data ) {
        
        $this->body->set( 'response_data', $data );
    }
}
