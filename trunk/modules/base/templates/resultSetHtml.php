
<table id= "" class="owa_resultSetTable" summary="">
	<!-- <CAPTION>Result set for <?php echo $rs->timePeriod['label'];?>.</CAPTION> -->
	<thead>
<? if ($rs->resultsRows):?>
<?php foreach ($rs->resultsRows[0] as $k => $v):?>
		<th class="<?php if($v['result_type'] === 'dimension') { echo 'dimensionColumn';} else { echo 'metricColumn';}?>"><?php echo $v['label'];?></th>
<?php endforeach;?>
<?php endif;?>
	</thead>

	<tfoot>
	
	</tfoot>
		
	<tbody>
<? if ($rs->resultsRows):?>
<?php foreach ($rs->resultsRows as $row):?>
		<tr>
<?php foreach ($row as $k => $v):?>
			<td class="<?php if($v['result_type'] === 'dimension') { echo 'dimensionColumn';} else { echo 'metricColumn';}?>"><?php echo $v['value'];?></td>
<?php endforeach;?>
		</tr>
<?php endforeach;?>
<?php endif;?>
	</tbody>
</table>