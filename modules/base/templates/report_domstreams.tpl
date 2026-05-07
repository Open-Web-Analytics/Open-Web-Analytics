<?php if (!empty($document)): require('item_document.php'); endif;?>


<?php if ( $domstreams ):?>
<table class="simpleTable">
    <thead>
        <tr>
            <th><?php echo $domstreams->labels->timestamp;?></th>
            <th><?php echo $domstreams->labels->page_url;?></th>
            <th><?php echo $domstreams->labels->duration;?></th>
            <th></th>
        </tr>
        <TR><BR></TR>
    </thead>
    <tbody>
        <?php  $d2 = $domstreams; $domstreams = (array) $domstreams; foreach($domstreams['resultsRows'] as $k => $ds): $ds = (array) $ds; ?>

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
                <a class="play" data-overlay="<?php echo trim( base64_encode(
                                $this->makeParamString(
                                    [
                                        'action' => 'loadPlayer',
                                        'domstream_guid' => $ds['domstream_guid'],
	                                    'api_url' 		=> $this->makeApiLink(
									                	
										                [
										                	'domstream_guid' => $ds['domstream_guid'],
														    'module' 		=> 'domstream',
														    'version'		=> 'v1',
														    'do'			=> 'domstreams'	
									                	], 
										                true, 
											            true
									                ), 
                                    ],
                                    true,
                                    'json')), '\u0000' );?>" 
                                    
                                    data-height="<?php echo $ds['page_height'];?>" 
                                    data-width="<?php echo $ds['page_width'];?>" 
                                    data-webpage="<?php 
	                                    
	                                    if ( strpos( $ds['page_url'], '#' ) ) {
								            $parts = explode( '#', $ds['page_url'] );
								            $ds['page_url'] = $parts[0];
								        }
	                                    
	                                    echo $ds['page_url'];?>" 
                                    href="#">Play</a>
            </TD>
        </TR>
        <?php endforeach; ?>
    </tbody>
</table>

<?php echo $this->makePaginationFromResultSet($d2, array('do' => 'base.reportDomstreams'), true);?>

<?php else:?>
    There are no Dom Streams this time period.
<?php endif;?>

<script> 
jQuery(document).ready(function(){  
	
	jQuery('.play').click( function() {
		
		var url = jQuery(this).data('webpage');
		
		url = url + '#owa_overlay.' + jQuery(this).data('overlay');
		var height = jQuery(this).data('height');
		var width = jQuery(this).data('width'); 
		
		var windowFeatures = "menubar=yes,location=yes,resizable=no,scrollbars=yes,status=yes,height=" + height + ",width=" + width;
		window.open( url, "OWA Dom Stream", windowFeatures);

		
		
	});
});

</script>