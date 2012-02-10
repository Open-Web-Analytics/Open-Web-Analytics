<div style="background-image: url('<?php echo $this->makeImageLink('base/i/document_icon.gif');?>');background-repeat: no-repeat; padding:5px 5px 5px 35px; background-position:13px 5px;">
	<span class="inline_h4">
		<a href="<?php echo $row['document_url'];?>"><?php echo $row['document_page_title'];?></a> &nbsp;
		(<?php if ( $row['document_page_type'] ): echo $row['document_page_type']; endif;?>)
		
		<BR>
		<span class="externalUrl">
			<?php $this->out( $this->truncate( $row['document_url'], 80, '…') );?>
		</span>
	</span>
</div>