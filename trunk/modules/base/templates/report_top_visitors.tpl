<? if (!empty($top_visitors)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Most Frequent Visitors</td>
		<td class="col_label">Visits</td>
	</tr>
			
	<?php foreach($top_visitors as $vis): ?>
				
	<TR>
		<TD class="item_cell">
			<a href="<?=$this->makeLink(array('visitor_id' => $vis['vis_id'], 'do' => 'base.reportVisitor'), true);?>">
				<span class="">
					<? if (!empty($vis['user_name'])):?>
						<?=$vis['user_name'];?>
					<?elseif (!empty($vis['user_email'])):?>
						<?=$vis['user_email'];?>
					<? else: ?>
						<?=$vis['vis_id'];?>
					<? endif; ?>
				</span>
			</a>		
		</TD>
		<TD class="data_cell">
			<?=$vis['count']?>
		</TD>
	</TR>
				
    <?php endforeach; ?>
    
</table>	
<?else:?>
	There are no visitors for this time period.
<?endif;?>