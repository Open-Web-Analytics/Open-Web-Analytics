<?php 
	
if ( isset($msgs) && ! empty($msgs) ) { 
	
	owa_coreAPI::notice( json_encode( $msgs, JSON_PRETTY_PRINT ) );
}

if( isset( $status_msg ) && ! empty( $status_msg ) ) {

	owa_coreAPI::notice( $status_msg );

}

if ( isset( $error ) && ! empty( $error) ) {
	
	owa_coreAPI::notice("Command failed. There were some errors:". "\n" . json_encode( $error, JSON_PRETTY_PRINT ) );
}


?>