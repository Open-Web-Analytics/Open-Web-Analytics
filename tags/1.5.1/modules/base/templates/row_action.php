<td style="width:inherit;">
	<div class="action_infobox" style="width:inherit;">
		<div class="action_name" style="min-width: <?php $this->out($this->get('width'));?>;">
			<?php 
			
			if (isset( $row['action_group'] ) ) {
				$this->out( $row['action_group'] ) . ' > ' . $this->out( $row['action_name'] );
			} else {
				$this->out( $row['action_name'] );
			}
			
			?>
	
						
		</div>
		<div style="float:" class="action_valu">
			<span class="info_text">Label:</span> <?php $this->out( $row['action_label'] );?></div>
		<div style="float:" class="action_valu"><span class="info_text">Value:</span> <?php $this->out( $row['numeric_value'] );?></div>
		<div style="clear:both"></div>
	</div>
	
</td>