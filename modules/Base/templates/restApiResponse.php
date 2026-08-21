<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
	
$_response = [
		    
		    'requestId'			=> $view->request_id,
		    'httpResponse'		=> $view->http_response,
		    'error'				=> $view->error,
		    'data'				=> null

];


if ( isset( $view->response_data ) ) {
	
	$_response['data'] = $view->response_data;
}

echo json_encode( $_response );
?>