<? if (!empty($se_hosts)):?>
<table width="100%">
	<tr>
		<th scope="col">Search Engine</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($se_hosts as $host): ?>
		
	<TR>
		<TD><? if ($host['site_name']): ?>
				<?=$host['site_name'];?> (<?=$host['site'];?>) 
			<? else:?>
				<?=$host['site'];?>
			<?endif;?>
		</td>
		<TD><?=$host['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering search engines for this time period.
<?endif;?>