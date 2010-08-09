<?
/*
 * test_http_cookies.php
 *
 * @(#) $Header: /cvsroot/PHPlibrary/test_http_cookies.php,v 1.1 1999/12/27 00:24:10 mlemos Exp $
 *
 */

	$user=""; /* Define your PHP Classes site access name here */  $user_line=__LINE__;
	$password=""; /* Define your PHP Classes site access name here */  $password_line=__LINE__;
	$host_name="phpclasses.UpperDesign.com";
	$uri="/browse.html/file/5/download/1/name/http.php";

	if($user=="")
	{
		echo "PHP Classes site user was not specified in script ".__FILE__." line $user_line\n";
		exit;
	}

	if($password=="")
	{
		echo "PHP Classes site password was not specified in script ".__FILE__." line $password_line\n";
		exit;
	}

	require("http.php");

	set_time_limit(0);
	$http_connection=new http_class;
	$error=$http_connection->Open(array(
		"HostName"=>$host_name
	));
	if($error=="")
	{
		$error=$http_connection->SendRequest(array(
			"RequestURI"=>$uri,
			"RequestMethod"=>"POST",
			"PostValues"=>array(
				"alias"=>$user,
				"password"=>$password,
				"Submit"=>"Login",
				"dologin"=>1
			)
		));
		if($error=="")
		{
			$error=$http_connection->ReadReplyHeaders(&$headers);
			if($error=="")
			{
				for($header=0,Reset($headers);$header<count($headers);Next($headers),$header++)
				{
					if(Key($headers)=="set-cookie")
						break;
				}
				if($header<count($headers))
				{
					for(;;)
					{
						$error=$http_connection->ReadReplyBody(&$body,1000);
						if($error!=""
						|| strlen($body)==0)
							break;
					}
				}
				else
					$error="This page did not set a cookie";
			}
			if($error==""
			&& ($error=$http_connection->Close())==""
			&& ($error=$http_connection->Open(array(
				"HostName"=>$host_name
			)))==""
			&& ($error=$http_connection->SendRequest(array(
					"RequestURI"=>$uri,
					"RequestMethod"=>"GET"
			)))==""
			&& ($error=$http_connection->ReadReplyHeaders(&$headers))=="")
			{
				for(;;)
				{
					$error=$http_connection->ReadReplyBody(&$body,1000);
					if($error!=""
					|| strlen($body)==0)
						break;
					echo $body;
				}
			}
		}
		$close_error=$http_connection->Close();
		if($error=="")
			$error=$close_error;
	}
	if($error!="")
		echo "Error: $error\n";
?>
