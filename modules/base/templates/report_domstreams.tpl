<?php if (!empty($document)): require('item_document.php'); endif;?>


<?php if (!empty($domstreams)):?>
<table class="simpleTable">
	<thead>
		<tr>
			<th><?php echo $domstreams->getLabel('timestamp');?></th>
			<th><?php echo $domstreams->getLabel('page_url');?></th>
			<th><?php echo $domstreams->getLabel('duration');?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>			
		<?php foreach($domstreams->rows as $ds): ?>
			
		<TR>
			<TD class="data_cell">
				<?php echo date("F j, Y, g:i a",$ds['timestamp']);?>
			</TD>
			<TD class="data_cell">
			<a href="<?php echo $ds['page_url'];?>">
				<?php echo $this->truncate($ds['page_url'], 150);?>			
			</a>
			</TD>
			
			<TD class="data_cell">
				<?php echo date("H:i:s", mktime(0,0,$ds['duration']));?>
			</TD>
			<TD class="data_cell">
				<a href="<?php $api_url = owa_coreAPI::getSetting('base', 'api_url'); echo $this->makeLink(array(
						'do' => 'base.overlayLauncher', 
						'document_id' => $ds['document_id'], 
						'overlay_params' => base64_encode(  
								$this->makeParamString( 
									array(
										'action' => 'loadPlayer', 
										'api_url' => trim( owa_coreAPI::getSetting( 'base', 'api_url' ) ),
										'domstream_guid' => $ds['domstream_guid']), 
									true, 
									'json'))));?>" target="_blank">Play</a>
			</TD>
		</TR>		
		<?php endforeach; ?>
	</tbody>
</table>

<?php echo $this->makePaginationFromResultSet($domstreams, array('do' => 'base.reportDomstreams'), true);?>

<?php else:?>
	There are no refering web pages for this time period.
<?php endif;?>