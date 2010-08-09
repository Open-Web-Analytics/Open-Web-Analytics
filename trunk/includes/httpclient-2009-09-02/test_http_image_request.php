<?php
/*
 * test_http_image_request.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/test_http_image_request.php,v 1.4 2003/10/28 22:09:35 mlemos Exp $
 *
 */

	require("http.php");

	set_time_limit(0);
	$http=new http_class;
	$url="http://www.phpclasses.org/graphics/logo.gif";
	$error=$http->GetRequestArguments($url,$arguments);
	$error=$http->Open($arguments);
	if($error=="")
	{
		$error=$http->SendRequest($arguments);
		if($error=="")
		{
			$headers=array();
			$error=$http->ReadReplyHeaders($headers);
			if($error=="")
			{
				for(Reset($headers),$header=0;$header<count($headers);Next($headers),$header++)
				{
					$header_name=Key($headers);
					if(GetType($headers[$header_name])!="array")
					{
						switch(strtolower($header_name))
						{
							case "content-type":
							case "content-length":
								Header($header_name.": ".$headers[$header_name]);
								break;
						}
					}
				}
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
		$http->Close();
	}
	if($error!="")
	{
?>
<HTML>
<HEAD>
<TITLE>Error</TITLE>
</HEAD>
<BODY>
<H1><CENTER>Could not retrieve the resource. Error: <? echo $error; ?></CENTER><H1>
<HR>
</BODY>
</HTML>
<?php
	}
?>