<h1><?=$page_h1;?></h1>

<? if(!empty($status_msg)):?>
<?=$status_msg;?>
<? endif;?>

<P>Below is the list of Web Sites that can be tracked by OWA. A site must appear in this list with a unique Site_id
if it is to be tracked seperately.</P>

<TABLE border = "1">
	<TR>
		<TH>Site Name & Description</TH>
		
		<TH>Site Family</TH>
		<TH>Site ID</TH>
	</TR>
<? foreach ($sites as $site =>$value):?>
	<TR>
		<form action="<?=$_SERVER['PHP_SELF'];?>" method="POST">
		<TD>Name: <input type="text" name="name" size="30" maxlength="="70" value="<?=$value['name'];?>"><BR>
		Description: <textarea name="description" cols="32" rows="3"><?=$value['description'];?></textarea></TD>
		<TD><input type="text" name="site_family" size="15" value="<?=$value['site_family'];?>"></TD>
		<TD class="id_box"><?=$value['site_id'];?></TD>
		<input type="hidden" name="admin" value="options.php">
		<TD><input type="submit" name="edit_site" value="Save Edit" ></TD>
		<TD><a href="<?=$this->make_admin_link('options.php', array('action' => 'get_tag', 'site_id' => $value['site_id']));?>">Get Tracking Tag</TD>
		<input type="hidden" name="action" value="edit_site">
		</form>
	</TR>
<? endforeach;?>
	<TR>
		<form action="<?=$_SERVER['PHP_SELF'];?>" method="POST">
		<TD>Name: <input type="text" size="30" maxlength="="70" name="name" value=""><BR>
		Description: <textarea name="description" cols="32" rows="3"></textarea></TD>
		<TD><input type="text" name="site_family" size="15" value=""></TD>
		<TD></TD>
		<TD><input type="submit" name="edit_site" value="Add New Site" ></TD>
		<input type="hidden" name="admin" value="options.php">
		<input type="hidden" name="action" value="add_site">
		</form>
	</TR>


</TABLE>


