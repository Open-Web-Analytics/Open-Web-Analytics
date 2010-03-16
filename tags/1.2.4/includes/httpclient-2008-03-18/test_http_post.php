<?php
/*
 * test_http_post.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/test_http_post.php,v 1.5 2004/08/11 00:46:11 mlemos Exp $
 *
 */

?><HTML>
<HEAD>
<TITLE>Test for Manuel Lemos' PHP HTTP class to simulate a HTTP POST form submission</TITLE>
</HEAD>
<BODY>
<H1><CENTER>Test for Manuel Lemos' PHP HTTP class to simulate a HTTP POST form submission</CENTER></H1>
<HR>
<UL>
<?php
	require("http.php");

	set_time_limit(0);
	$http=new http_class;
	$http->timeout=0;
	$http->data_timeout=0;
	$http->debug=0;
	$http->html_debug=1;

	$url="http://www.cs.tut.fi/cgi-bin/run/~jkorpela/echoraw.cgi";
	$error=$http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"]="POST";
	$arguments["PostValues"]=array(
		"somefield"=>"Upload forms",
		"MAX_FILE_SIZE"=>"1000000"
	);
	$arguments["PostFiles"]=array(
		"userfile"=>array(
			"Data"=>"This is just a plain text attachment file named attachment.txt .",
			"Name"=>"attachment.txt",
			"Content-Type"=>"automatic/name",
		),
		"anotherfile"=>array(
			"FileName"=>"test_http_post.php",
			"Content-Type"=>"automatic/name",
		)
	);
	$arguments["Referer"]="http://www.alltheweb.com/";
	echo "<H2><LI>Opening connection to:</H2>\n<PRE>",HtmlEntities($arguments["HostName"]),"</PRE>\n";
	flush();
	$error=$http->Open($arguments);

	if($error=="")
	{
		$error=$http->SendRequest($arguments);
		if($error=="")
		{
			echo "<H2><LI>Request:</LI</H2>\n<PRE>\n".HtmlEntities($http->request)."</PRE>\n";
			echo "<H2><LI>Request headers:</LI</H2>\n<PRE>\n";
			for(Reset($http->request_headers),$header=0;$header<count($http->request_headers);Next($http->request_headers),$header++)
			{
				$header_name=Key($http->request_headers);
				if(GetType($http->request_headers[$header_name])=="array")
				{
					for($header_value=0;$header_value<count($http->request_headers[$header_name]);$header_value++)
						echo $header_name.": ".$http->request_headers[$header_name][$header_value],"\r\n";
				}
				else
					echo $header_name.": ".$http->request_headers[$header_name],"\r\n";
			}
			echo "</PRE>\n";
			echo "<H2><LI>Request body:</LI</H2>\n<PRE>\n".HtmlEntities($http->request_body)."</PRE>\n";
			flush();

			$headers=array();
			$error=$http->ReadReplyHeaders($headers);
			if($error=="")
			{
				echo "<H2><LI>Response headers:</LI</H2>\n<PRE>\n";
				for(Reset($headers),$header=0;$header<count($headers);Next($headers),$header++)
				{
					$header_name=Key($headers);
					if(GetType($headers[$header_name])=="array")
					{
						for($header_value=0;$header_value<count($headers[$header_name]);$header_value++)
							echo $header_name.": ".$headers[$header_name][$header_value],"\r\n";
					}
					else
						echo $header_name.": ".$headers[$header_name],"\r\n";
				}
				echo "</PRE>\n";
				flush();

				echo "<H2><LI>Response body:</LI</H2>\n<PRE>\n";
				for(;;)
				{
					$error=$http->ReadReplyBody($body,1000);
					if($error!=""
					|| strlen($body)==0)
						break;
					echo HtmlSpecialChars($body);
				}
				echo "</PRE>\n";
				flush();
			}
		}
		$http->Close();
	}
	if(strlen($error))
		echo "<CENTER><H2>Error: ",$error,"</H2><CENTER>\n";
?>
</UL>
<HR>
</BODY>
</HTML>
