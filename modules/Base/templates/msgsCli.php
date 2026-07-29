<?php
	
if ( isset($msgs) && ! empty($msgs) ) {
	
	\OWA\Core\CoreAPI::notice( json_encode( $msgs, JSON_PRETTY_PRINT ) );
}

if( isset( $status_msg ) && ! empty( $status_msg ) ) {

	\OWA\Core\CoreAPI::notice( $status_msg );

}

if ( isset( $error ) && ! empty( $error ) ) {
	
	\OWA\Core\CoreAPI::notice("Command failed. There were some errors:". "\n" . json_encode( $error, JSON_PRETTY_PRINT ) );
	
} else {
	
	if ( isset( $response_data ) && ! empty( $response_data ) ) {
	
		\OWA\Core\CoreAPI::notice( "\n" . json_encode( $response_data, JSON_PRETTY_PRINT ) );
	}
}

?>