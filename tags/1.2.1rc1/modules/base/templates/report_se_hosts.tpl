<?php if (!empty($se_hosts)):?>
<table width="100%">
	<tr>
		<th scope="col">Search Engine</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($se_hosts as $host): ?>
		
	<TR>
		<TD><?php if ($host['site_name']): ?>
				<?php echo $host['site_name'];?> (<?php echo $host['site'];?>) 
			<?php else:?>
				<?php echo $host['site'];?>
			<?php endif;?>
		</td>
		<TD><?php echo $host['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?php else:?>
	There are no refering search engines for this time period.
<?php endif;?>