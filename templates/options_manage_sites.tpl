<h1><?=$page_h1;?></h1>

<? if(!empty($status_msg)):?>
<?=$status_msg;?>
<? endif;?>



<TABLE border = "1">
	<TR>
		<TH>Site Name</TH>
		<TH>Description</TH>
		<TH>Site Family</TH>
		<TH>Site ID</TH>
	</TR>
<? foreach ($sites as $site =>$value):?>
	<TR>
		<form action="<?=$_SERVER['PHP_SELF'];?>" method="POST">
		<TD><input type="text" name="name" value="<?=$value['name'];?>"></TD>
		<TD><textarea name="description" cols="35" rows="3"><?=$value['description'];?></textarea></TD>
		<TD><input type="text" name="site_family" value="<?=$value['site_family'];?>"></TD>
		<TD class="id_box"><?=$value['site_id'];?></TD>
		<TD><input type="submit" name="edit_site" value="Save Edit" ></TD>
		<input type="hidden" name="action" value="edit_site">
		</form>
	</TR>
<? endforeach;?>
	<TR>
		<form action="<?=$_SERVER['PHP_SELF'];?>" method="POST">
		<TD><input type="text" name="name" value=""></TD>
		<TD><textarea name="description" cols="35" rows="3"></textarea></TD>
		<TD><input type="text" name="site_family" value=""></TD>
		<TD></TD>
		<TD><input type="submit" name="edit_site" value="Add New Site" ></TD>
		<input type="hidden" name="action" value="add_site">
		</form>
	</TR>


</TABLE>


