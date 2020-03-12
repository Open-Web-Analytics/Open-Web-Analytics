<?php
/*
 * test_http_cookies.php
 *
 * @(#) $Header: /opt2/ena/metal/http/test_http_cookies.php,v 1.3 2003/10/28 22:09:35 mlemos Exp $
 *
 */

	$user=""; /* Define your PHP Classes site access name here */  $user_line=__LINE__;
	$password=""; /* Define your PHP Classes site access name here */  $password_line=__LINE__;
	$url="http://www.phpclasses.org/login.html?page=/browse.html/file/5/download/1/name/http.php";
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
	$http=new http_class;
	$http->GetRequestArguments($url,$arguments);
	$error=$http->Open($arguments);
	if($error=="")
	{
		$arguments["RequestMethod"]="POST";
		$arguments["PostValues"]=array(
			"alias"=>$user,
			"password"=>$password,
			"Submit"=>"Login",
			"dologin"=>"1"
		);
		$error=$http->SendRequest($arguments);
		if($error=="")
		{
			$error=$http->ReadReplyHeaders($headers);
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
						$error=$http->ReadReplyBody($body,1000);
						if($error!=""
						|| strlen($body)==0)
							break;
					}
				}
				else
					$error="This page did not set a cookie";
			}
			if($error==""
			&& ($error=$http->Close())==""
			&& ($error=$http->Open(array(
				"HostName"=>$arguments["HostName"]
			)))==""
			&& ($error=$http->SendRequest(array(
					"RequestURI"=>$arguments["RequestURI"],
					"RequestMethod"=>"GET"
			)))==""
			&& ($error=$http->ReadReplyHeaders($headers))=="")
			{
				for(;;)
				{
					$error=$http->ReadReplyBody($body,1000);
					if($error!=""
					|| strlen($body)==0)
						break;
					echo $body;
				}
			}
		}
		$close_error=$http->Close();
		if($error=="")
			$error=$close_error;
	}
	if($error!="")
		echo "Error: $error\n";
?>