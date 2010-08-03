<?php
/*
 * yahoo_group_invite.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/yahoo_group_invite.php,v 1.1 2006/04/07 21:44:23 mlemos Exp $
 *
 */

	require('http.php');
	require('yahoo_user.php');

	$yahoo = new yahoo_user_class;
	$yahoo->user = 'yahoouser';
	$yahoo->password = 'yahoopassword';
	$group='groupname';
	$users=array(
		"peter@gabriel.org",
		"paul@simon.net",
		"mary@chain.com"
 );
	$message = "Hello,\n\n".
		"This is an invitation to join our group at Yahoo!\n\n".
		"Please follow the instructions to accept the invitation and start participating.\n\n".
		"Regards,\n\n".
		"the moderator\n";
	$parameters = array();
	$success = $yahoo->InviteToGroup($group, $users, $message, $parameters);
?>
<html>
<head>
<title>Invite users to a Yahoo group</TITLE>
</head>
<body>
<h1 style="text-align: center">Invite users to a Yahoo group</h1>
<hr />
<?php
	if($success)
	{
		if(strlen($yahoo->logged_user))
		{
?>
<h2 style="text-align: center">The user '<?php echo $yahoo->logged_user; ?>' has logged in Yahoo successfully.</h2>
<?php
			if(IsSet($parameters['Invited']))
			{
?>
<h2 style="text-align: center"><?php echo $parameters['Invited']; ?> users were successfully invited to Yahoo group <?php echo $group; ?> .</h2>
<?php
			}
		}
		else
		{
?>
<h2 style="text-align: center">The Yahoo user '<?php echo $yahoo->user; ?>' login attempt failed.</h2>
<?php
		}
	}
	else
	{
?>
<h2 style="text-align: center">Error: <?php echo $yahoo->error ?></h2>
<?php
	}
?>
<hr />
</body>
</html>
