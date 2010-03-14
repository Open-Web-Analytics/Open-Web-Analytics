<?php
/*
 * test_cookies.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/test_cookies.php,v 1.1 2007/02/07 22:20:07 mlemos Exp $
 *
 */

?><HTML>
<HEAD>
<TITLE>Test for Manuel Lemos' PHP HTTP class to save and restore cookies</TITLE>
</HEAD>
<BODY>
<H1><CENTER>Test for Manuel Lemos' PHP HTTP class to save and restore cookies</CENTER></H1>
<HR>
<UL>
<?php
	require("http.php");


	set_time_limit(0);
	$http=new http_class;
	$http->debug=0;
	$http->html_debug=1;
	$http->follow_redirect=1;

	$url="http://my.yahoo.com/";

	$error=$http->GetRequestArguments($url,$arguments);

	echo "<H2><LI>Opening connection to:</H2>\n<PRE>",HtmlEntities($arguments["HostName"]),"</PRE>\n";
	flush();
	$error=$http->Open($arguments);

	if($error=="")
	{
		echo "<H2><LI>Sending request for page:</H2>\n";
		echo "<PRE>",HtmlSpecialChars($arguments["RequestURI"]),"</PRE>\n";
		flush();
		$error=$http->SendRequest($arguments);

		if($error=="")
		{
			echo "<H2><LI>Getting response headers ...</H2>\n";
			flush();
			$headers=array();
			$error=$http->ReadReplyHeaders($headers);
			if($error=="")
			{
				echo "<H2><LI>Response status code:</LI</H2>\n<PRE>".$http->response_status."</PRE>\n";
				flush();

				echo "<H2><LI>Getting the response body ...</LI</H2>\n";
				for(;;)
				{
					$error=$http->ReadReplyBody($body,1000);
					if($error!=""
					|| strlen($body)==0)
						break;
				}
				flush();
			}
		}
		$http->Close();
	}
	if(strlen($error)==0)
	{
		echo "<H2><LI>Test saving and restoring cookies...</LI</H2>\n";
		flush();
		$http->SaveCookies($site_cookies);
		if(strlen($error=$http->RestoreCookies($site_cookies, 1))==0)
		{
			$http->SaveCookies($saved_cookies);
			if(strcmp(serialize($saved_cookies), serialize($site_cookies)))
			{
				echo "<H2>FAILED: the saved cookies do not match the restored cookies.</H2>\n";
			}
			else
				echo "<H2>OK: the saved cookies match the restored cookies.</H2>\n";
		}
	}
	if(strlen($error))
		echo "<CENTER><H2>Error: ",$error,"</H2><CENTER>\n";
?>
</UL>
<HR>
</BODY>
</HTML>
