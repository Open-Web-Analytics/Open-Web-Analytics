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

echo json_encode( $_response );

?>