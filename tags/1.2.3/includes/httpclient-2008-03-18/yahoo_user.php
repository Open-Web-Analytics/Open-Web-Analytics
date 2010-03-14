<?php
/*
 * yahoo_user.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/yahoo_user.php,v 1.4 2007/02/07 21:28:27 mlemos Exp $
 *
 */

class yahoo_user_class
{
	var $error = '';
	var $user = '';
	var $password = '';
	var $logged_user = '';
	var $http;
	var $response_buffer_length = 1000;
	var $user_agent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)';


	Function SetupHTTP()
	{
		if(!IsSet($this->http))
		{
			$this->http = new http_class;
			$this->http->follow_redirect = 1;
			$this->http->debug = 0;
			$this->http->debug_response_body = 0;
			$this->http->html_debug = 1;
			$this->http->user_agent = $this->user_agent;
		}
	}

	Function OpenRequest($arguments, &$headers)
	{
		if(strlen($this->error=$this->http->Open($arguments)))
			return(0);
		if(strlen($this->error=$this->http->SendRequest($arguments))
		|| strlen($this->error=$this->http->ReadReplyHeaders($headers)))
		{
			$this->http->Close();
			return(0);
		}
		if($this->http->response_status!=200)
		{
			$this->error = 'the HTTP request returned the status '.$this->http->response_status;
			$this->http->Close();
			return(0);
		}
		return(1);
	}

	Function GetRequestResponse(&$response)
	{
		for($response = ''; ; )
		{
			if(strlen($this->error=$this->http->ReadReplyBody($body, $this->response_buffer_length)))
			{
				$this->http->Close();
				return(0);
			}
			if(strlen($body)==0)
				break;
			$response .= $body;
		}
		$this->http->Close();
		return(1);
	}

	Function GetRequest($arguments, &$response)
	{
		if(!$this->OpenRequest($arguments, $headers))
			return(0);
		return($this->GetRequestResponse($response));
	}

	Function Login(&$parameters)
	{
		if(strlen($this->user)
		&& !strcmp($this->logged_user, $this->user))
			return(1);
		$this->logged_user = '';
		$this->SetupHTTP();
		$url='http://login.yahoo.com/';
		$this->http->GetRequestArguments($url, $arguments);
		$arguments['RequestMethod']='GET';
		if(!$this->GetRequest($arguments, $response))
			return(0);
		$redirect = (IsSet($parameters['GetPage']) ? $parameters['GetPage'] : 'http://my.yahoo.com/');
		if(!preg_match('/<input type="hidden" name="\\.u" value="([^"]*)">.*<input type="hidden" name="\\.challenge" value="([^"]*)">/s', $response, $matches))
		{
			$this->error = 'unexpected Yahoo login page contents';
			return(0);
		}
		$u = $matches[1];
		$challenge = $matches[2];
		$url='https://login.yahoo.com/config/login?';
		$this->http->GetRequestArguments($url, $arguments);
		$arguments['RequestMethod']='POST';
		$arguments['PostValues']=array(
			'.tries'=>'1',
			'.src'=>'',
			'.md5'=>'',
			'.hash'=>'',
			'.js'=>'',
			'.last'=>'',
			'promo'=>'',
			'.intl'=>'us',
			'.bypass'=>'',
			'.partner'=>'',
			'.u'=>$u,
			'.v'=>'0',
			'.challenge'=>$challenge,
			'.yplus'=>'',
			'.emailCode'=>'',
			'pkg'=>'',
			'stepid'=>'',
			'.ev'=>'',
			'hasMsgr'=>'0',
			'.chkP'=>'Y',
			'.done'=>$redirect,
			'login'=>$this->user,
			'passwd'=>$this->password,
			'.persistent'=>'y',
		);
		$arguments['Headers']['Referer']= 'http://login.yahoo.com/';
		if(!$this->GetRequest($arguments, $response))
			return(0);
		if(GetType(strpos($response, '<meta http-equiv="Refresh" content="0; url='.$redirect.'">'))!='integer')
		{
			$this->error = 'the login page does not redirect to the expected page';
			return(0);
		}
		$this->http->GetRequestArguments($redirect, $arguments);
		$arguments['RequestMethod']='GET';
		$arguments['Headers']['Referer']= $url;
		if(!$this->GetRequest($arguments, $response))
			return(0);
		if(IsSet($parameters['GetPage']))
		{
			$parameters['Response']=$response;
			$parameters['GetPage']=$this->http->protocol.'://'.$this->http->host_name.$this->http->request_uri;
		}
		$this->logged_user = $this->user;
		return(1);
	}

	Function ExportAddressBook(&$parameters)
	{
		$login_parameters = array();
		if(!$this->Login($login_parameters))
			return(0);
		if(strlen($this->logged_user)==0)
			return(1);
		$url='http://address.yahoo.com/yab/us/Yahoo.csv?A=Y&Yahoo.csv';
		$this->http->GetRequestArguments($url, $arguments);
		$arguments['RequestMethod']='POST';
		$arguments['PostValues']=array(
			'submit[action_export_yahoo]'=>'Export Now'
		);
		$arguments['Headers']['Referer']= 'http://address.yahoo.com/?A=B';
		if(!$this->OpenRequest($arguments, $headers))
			return(0);
		if(!IsSet($headers['content-type'])
		|| strcmp(trim(strtok($headers['content-type'], ';')), 'text/csv'))
		{
			$this->error = 'Yahoo did not return the address book in CSV format as expected';
			return(0);
		}
		if(!$this->GetRequestResponse($response))
			return(0);
		$parameters['Data']=$response;
		return(1);
	}

	Function InviteToGroup($group, $users, $message, &$parameters)
	{
		$url='http://groups.yahoo.com/group/'.$group.'/subs_invite';
		$login_parameters = array('GetPage'=>$url);
		if(!$this->Login($login_parameters))
			return(0);
		if(strlen($this->logged_user)==0)
			return(1);
		$url=$login_parameters['GetPage'];
		$this->http->GetRequestArguments($url, $arguments);
		$arguments['RequestMethod']='POST';
		$arguments['PostValues']=array(
			'email'=>implode($users,"\n"),
			'welcome'=>$message,
			'submit_request'=>'Submit Invite'
		);
		$arguments['Headers']['Referer']= $url;
		if(!$this->GetRequest($arguments, $response))
			return(0);
		if(!preg_match('/<input type="hidden" name="ycb" value="([^"]*)">/', $response, $matches))
		{
			if(GetType(strpos($response, 'No valid email addresses found in your request.'))=='integer')
				$this->error = 'it were not specified any users with valid e-mail addresses';
			elseif(GetType(strpos($response, 'Please enter message to introduce these people to your group.'))=='integer')
				$this->error = 'it was not specified a valid invitation welcome message';
			elseif(GetType(strpos($response, '<h3>Group Not Found</h3>'))=='integer')
				$this->error = 'it was specified an inexisting group';
			elseif(GetType(strpos($response, '<h4>You\'ve reached an Age-Restricted Area of Yahoo! Groups</h4>'))=='integer')
				$this->error = 'it was specified an age restricted group';
			elseif(GetType(strpos($response, 'You are not a moderator of the group <b>'.$group.'</b>.'))=='integer')
				$this->error = 'it was specified a group of which the user '.$this->logged_user.' is not moderator';
			elseif(preg_match('|<p>([0-9]+) members need to be fixed for the following reasons:</p>|', $response, $matches))
				$this->error = 'it were specified invalid users ('.$matches[1].')';
			else
				$this->error = 'it was not possible to send the group invitations';
			return(0);
		}
		$ycb = $matches[1];
		$arguments['PostValues']=array(
			'email'=>implode($users,"\n"),
			'welcome'=>$message,
			'ycb' => $ycb,
			'submit_invite'=>'Invite People'
		);
		if(!$this->GetRequest($arguments, $response))
			return(0);
		if(!preg_match('|<li>Total invited: &nbsp;([0-9]+)</li>|', $response, $matches))
		{
			if(GetType(strpos($response, '<h4>You\'ve reached an Age-Restricted Area of Yahoo! Groups</h4>'))=='integer')
				$this->error = 'it was specified an age restricted group';
			else
				$this->error = 'it was not possible to send the group invitations';
			return(0);
		}
		$parameters['Invited'] = intval($matches[1]);
		return(1);
	}
};

?>