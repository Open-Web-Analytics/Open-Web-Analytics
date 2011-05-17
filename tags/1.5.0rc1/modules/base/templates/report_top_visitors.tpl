<?php if (!empty($top_visitors)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Visitor</th>
			<th>Visits</th>
		</tr>
	</thead>	
	<tbody>		
		<?php foreach($top_visitors as $vis): ?>		
		<TR>
			<TD>
				<a href="<?php echo $this->makeLink(array('visitor_id' => $vis['vis_id'], 'do' => 'base.reportVisitor'), true);?>">
					<span class="">
						<?php if (!empty($vis['user_name'])):?>
							<?php echo $vis['user_name'];?>
						<?php elseif (!empty($vis['user_email'])):?>
							<?php echo $vis['user_email'];?>
						<?php else: ?>
							<?php echo $vis['vis_id'];?>
						<?php endif; ?>
					</span>
				</a>		
			</TD>
			<TD>
				<?php echo $vis['count']?>
			</TD>
		</TR>
					
	    <?php endforeach; ?>
	</tbody>    
</table>	

<?php else:?>
There are no visitors for this time period.
<?php endif;?>