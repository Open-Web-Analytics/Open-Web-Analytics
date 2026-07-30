<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
	
if ( isset($view->msgs) && ! empty($view->msgs) ) {
	
	\OWA\Core\CoreAPI::notice( json_encode( $view->msgs, JSON_PRETTY_PRINT ) );
}

if( isset( $view->status_msg ) && ! empty( $view->status_msg ) ) {

	\OWA\Core\CoreAPI::notice( $view->status_msg );

}

if ( isset( $view->error ) && ! empty( $view->error ) ) {
	
	\OWA\Core\CoreAPI::notice("Command failed. There were some errors:". "\n" . json_encode( $view->error, JSON_PRETTY_PRINT ) );
	
} else {
	
	if ( isset( $view->response_data ) && ! empty( $view->response_data ) ) {
	
		\OWA\Core\CoreAPI::notice( "\n" . json_encode( $view->response_data, JSON_PRETTY_PRINT ) );
	}
}

?>