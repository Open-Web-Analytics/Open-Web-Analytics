<? if (!empty($top_visitors)):?>
<table width="100%">
	<tr>
		<th scope="col">Visitor</th>
		<th scope="col">Visits</th>
	</tr>
			
	<?php foreach($top_visitors as $vis): ?>
				
	<TR>
		<TD>
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
		<TD>
			<?=$vis['count']?>
		</TD>
	</TR>
				
    <?php endforeach; ?>
    
</table>	
<?else:?>
	There are no visitors for this time period.
<?endif;?>