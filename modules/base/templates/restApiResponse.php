<?php
	
echo json_encode( array(
		    
		    'requestId'			=> $request_id,
		    'httpResponse'		=> $http_response,
		    'error'				=> $error,
		    'data'				=> $response_data
		    //'hasNextPage'		=> $hasNextPage,
		    //'nextPageRequest'	=> ''
	    ));


?>