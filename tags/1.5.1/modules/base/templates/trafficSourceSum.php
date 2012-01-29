<div style="background-image: url('<?php echo $this->makeImageLink('base/i/referer_icon.gif');?>');background-repeat: no-repeat; padding:5px 5px 5px 35px; background-position:0px 5px;">
	<span class="inline_h4"> 
		<a href="<?php $this->out( $this->makeLink( 
			array(
				'do' => 'base.reportSourceDetail', 
				'source' => urlencode($row['source']), 
				'site_id' => $this->get('site_id')
				),
			true 
		) );?>"><?php $this->out( $row['source']);?></a> (<?php $this->out( $row['medium'] );?>)
	</span>
		
	<?php if ( $row['medium'] === 'referral' ):?>
	<div style="line-height:120%; width:inherit; padding-left:20px; padding-top:15px;">
		<span class="inline_h4">
			<a href="<?php echo $row['referer_url'];?>">
				<?php if (!empty($row['referer_page_title'])):?><?php echo $this->truncate($row['referer_page_title'], 80, '…');?></span></a><BR><span class="externalUrl"><?php echo $this->truncate($row['referer_url'], 80, '…');?><?php else:?><?php echo $this->truncate($row['referer_url'], 80, '…');?><?php endif;?>
			</a>
		</span>
		
		<?php if ( ! empty( $row['referer_snippet'] ) ):?>			
		<br><span class="snippet_text"><?php echo $row['referer_snippet'];?></span>
		<?php endif;?>
	</div>					
	<?php endif;?>
</div>