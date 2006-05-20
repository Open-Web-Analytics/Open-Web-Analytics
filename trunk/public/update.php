<?

require_once('../owa_update.php');

$u = new owa_update;

$version = $u->check_schema_version();

print "Schema version is ".$version['value']."\n";

if ($version['value'] == '1.0'):
	print "starting updated to 1.0.1";
	$u->to_1_rc2();
endif;

print "upgrade complete";



?>