
<table id= "" class="owa_dataGrid<?php //echo $class; ?>" summary="">
	<!-- <CAPTION>Result set for <?php echo $rs->timePeriod['label'];?>.</CAPTION> -->
	<thead>
		<tr>
<?php if ($rs->resultsRows):?>
<?php foreach ($rs->resultsRows[0] as $k => $v):?>
			<th class="<?php if($v['result_type'] === 'dimension') { echo 'dimensionColumn';} else { echo 'metricColumn';}?>"><?php echo $v['label'];?></th>
<?php endforeach;?>
<?php endif;?>
		</tr>
	</thead>

	<tfoot>
	
	</tfoot>
		
	<tbody>
<?php if ($rs->resultsRows):?>
<?php foreach ($rs->resultsRows as $row):?>
		<tr>
<?php foreach ($row as $k => $v):?>
			<td class="<?php if($v['result_type'] === 'dimension') { echo 'dimensionColumn';} else { echo 'metricColumn';}?>">
				<?php if (array_key_exists('link', $v)):?>
				<a href="<?php echo $v['link'];?>"><?php echo $v['value'];?></a>
				<?php else: ?>
				<?php echo $v['value'];?>
				<?php endif;?>
			</td>
<?php endforeach;?>
		</tr>
<?php endforeach;?>
<?php endif;?>
	</tbody>
</table>
