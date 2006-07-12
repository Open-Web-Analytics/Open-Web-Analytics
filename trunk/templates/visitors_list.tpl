<H2><?=$headline;?></H2>

<table>
	<? foreach ($visitors_list as $visitor):?>
	<TR>
		<TD>
			<a href="<?=$this->make_report_link('visitor_report.php', array('visitor_id' => $visitor['visitor_id']));?>">
			<?if(!empty($visitor['user_name'])): 
				echo $visitor['user_name'];
			elseif(!empty($visitor['user_email'])):
			    echo $visitor['user_email'];
			else:
			    echo $visitor['visitor_id'];
			endif;?>
			</a>
		</TD>
	</TR>
	<? endforeach;?>
</table>