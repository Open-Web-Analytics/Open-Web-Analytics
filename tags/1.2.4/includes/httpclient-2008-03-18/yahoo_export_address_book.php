<?php
/*
 * yahoo_export_address_book.php
 *
 * @(#) $Header: /home/mlemos/cvsroot/http/yahoo_export_address_book.php,v 1.4 2006/04/10 06:51:43 mlemos Exp $
 *
 */

	require('http.php');
	require('yahoo_user.php');

	$yahoo = new yahoo_user_class;
	$yahoo->user = 'yahoouser';
	$yahoo->password = 'yahoopassword';
	$parameters = array();
	$success = $yahoo->ExportAddressBook($parameters);
?>
<html>
<head>
<title>Export Yahoo user address book</TITLE>
</head>
<body>
<h1 style="text-align: center">Export Yahoo user address book</h1>
<hr />
<?php
	if($success)
	{
		if(strlen($yahoo->logged_user))
		{
?>
<h2 style="text-align: center">The user '<?php echo $yahoo->logged_user; ?>' has logged in Yahoo successfully.</h2>
<?php
			if(IsSet($parameters['Data']))
			{
?>
<h2 style="text-align: center">The address book was exported to CSV format.</h2>
<center><table>
<tr>
<td><pre><?php echo HtmlSpecialChars(WordWrap($parameters['Data'], 75, "\n", 1)); ?></pre>
</td>
</tr>
</table>
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
