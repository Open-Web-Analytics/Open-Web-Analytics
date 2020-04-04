<?php 
	
if ( isset($msgs) && ! empty($msgs) ) { 
	
	owa_coreAPI::notice( $msgs );

	
}

if( ! empty( $status_msg ) ) {

	owa_coreAPI::notice( $status_msg );

}

if ( isset($error_msg) && !isset($validation_errors)) {

    owa_coreAPI::notice( $error_msg );
}

if ( isset( $validation_errors ) && ! empty( $validation_errors ) ) {

    owa_coreAPI::notice('The command parameters had some validation errors:');
   
    foreach ($validation_errors as $validation_error) {
	    
	        owa_coreAPI::notice( $validation_error );
    }
}

?>