<? if (!empty($data)):?>
<table>
	<tr>
		<th scope="col">Visitor</th>
		<th scope="col">Visits</th>
	</tr>
			
	<?php foreach($data as $vis): ?>
				
	<TR>
		<TD>
			<a href="<?=$this->make_report_link('visitor_report.php', array($this->config['ns'].$this->config['visitor_param'] => $vis['vis_id']));?>">
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