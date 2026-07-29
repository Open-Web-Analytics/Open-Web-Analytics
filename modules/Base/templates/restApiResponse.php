<?php
	
$_response = [
		    
		    'requestId'			=> $request_id,
		    'httpResponse'		=> $http_response,
		    'error'				=> $error,
		    'data'				=> null

];


if ( isset( $response_data ) ) {
	
	$_response['data'] = $response_data;
}

if ( isset( $callback ) && ! empty( $callback) ) {
	
	echo sprintf("%s(%s);", $callback, json_encode( $_response ) );	
} else {

	echo json_encode( $_response );
}
?>