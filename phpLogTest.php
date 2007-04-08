<?php

require_once('../public/set_env.php');

// OWA run time configuration
$config['site_id'] = 'a35bb2f862d7e85842c365e7d5e6266d'; // Your site's tracking id
  
// New Instance of OWA
$owa = new owa_php($config);
  
// Application specific params
$app_params['page_title'] = 'PHP Invocation Test page'; //The title of the web page
  
// Logs the page request request
$owa->log($app_params);

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
	</head>

<BODY>

<?php

//$owa = & new owa_php();

// Place helper page tags to track clicks and first hit
$owa->placeHelperPageTags();

//print_r($owa->config);

?>




<a href="http://www.yahoo.com">httplogTest</a>

<UL>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
	<LI> <a href="http://www.yahoo.com">httplogTest</a>
</UL>

</BODY>

</HTML>

