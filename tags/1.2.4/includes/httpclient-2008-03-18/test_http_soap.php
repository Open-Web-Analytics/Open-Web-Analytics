<?php
/*
 * test_http_soap.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/test_http_soap.php,v 1.6 2008/01/20 19:23:43 mlemos Exp $
 *
 */

?><HTML>
<HEAD>
<TITLE>Test for Manuel Lemos's PHP HTTP class making a SOAP request</TITLE>
</HEAD>
<BODY>
<H1><CENTER>Test for Manuel Lemos's PHP HTTP class making a SOAP request</CENTER></H1>
<HR>
<UL>
<?php
	require("http.php");

	set_time_limit(0);
	$http=new http_class;
	$url="http://www.atlaz.net/webservices/GetCurrencyExchange.php";
	$http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"]="POST";
	$arguments["Headers"]["SoapAction"]="";
	$arguments["Headers"]["EndPointURL"]="http://www.atlaz.net/webservices/GetCurrencyExchange.php";
	$arguments["Headers"]["Content-Type"]="text/xml; charset=\"utf-8\"";
	$arguments["Body"]="<?xml version='1.0' encoding='UTF-8'?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:xsi=\"http://www.w3.org/1999/XMLSchema-instance\" xmlns:xsd=\"http://www.w3.org/1999/XMLSchema\">
  <SOAP-ENV:Body>
    <GetCurrencyExchange SOAP-ENV:encodingStyle=\"http://schemas.xmlsoap.org/soap/encoding/\">
      <number xsi:type=\"xsd:float\">1000</number>
      <currency1 xsi:type=\"xsd:string\">GBP</currency1>
      <currency2 xsi:type=\"xsd:string\">JPY</currency2>
    </GetCurrencyExchange>
  </SOAP-ENV:Body>
</SOAP-ENV:Envelope>";
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
				echo "<H2>Response headers:</H2>\n<PRE>\n";
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
				echo "</PRE>\n<H2>Response body:</H2>\n<PRE>\n";
				for(;;)
				{
					$error=$http->ReadReplyBody($body,1000);
					if($error!=""
					|| strlen($body)==0)
						break;
					echo HtmlSpecialChars($body);
				}
				echo "</PRE>\n</UL>\n";
			}
		}
		$http->Close();
	}
	if(strcmp($error,""))
		echo "<H2><CENTER>Error: $error</CENTER></H2>\n";
?>
</UL>
<HR>
</BODY>
</HTML>
