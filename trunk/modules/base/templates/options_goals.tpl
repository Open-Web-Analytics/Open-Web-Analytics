<div class="panel_headline"><?php echo $headline?></div>

<div class="subview_content">

	<table class="management">
		<thead>
			<tr>
				<th>Goal Number</th>
				<th>Goal Name</th>
				<th>Goal Group</th>
				<th>Goal Type</th>
				<th>Status</th>
			</tr>
		</thead>
		
		<tbody>
			
			<?php foreach ($goals as $k => $goal): ?>
			<tr>
				<td>Goal <?php $this->out($k);?> <a class="" href="<?php echo $this->makeLink(array('do' => 'base.optionsGoalEntry', 'goal_number' => $k, 'siteId' => $siteId));?>">Edit</a></p></td>
				<td><?php $this->out($goal['goal_name']);?></td>
				<td>
				<?php 
					if ( isset( $goal['goal_group'] ) ) {
						if ( !empty( $goal_groups[$goal['goal_group']] ) ) {
							$this->out($goal_groups[$goal['goal_group']] );
						} else {
							$this->out( $goal['goal_group'] );
						}
					}
				?>
				</td>
				<td><?php $this->out($goal['goal_type']);?></td>
				<td><?php $this->out($goal['goal_status']);?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
		
		<tfoot>
		
		</tfoot>		
	</table>
	
</div>