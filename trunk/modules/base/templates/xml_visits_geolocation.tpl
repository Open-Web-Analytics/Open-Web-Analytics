<markers>
<? if ($visits):?>    
<? foreach ($visits as $visit):?>  
	<? if (!empty($visit['host_latitude']) && !empty($visit['host_longitude'])):?>
	<marker lat="<?=$visit['host_latitude'];?>" lng="<?=$visit['host_longitude'];?>" label="<?=$visit['session_month'];?>/<?=$visit['session_day'];?> at <?=$visit['session_hour'];?>:<?=$visit['session_minute'];?> - <?=$visit['host_host'];?>">
		<infowindow>
			<![CDATA[<? include('report_visit_summary_balloon.tpl');?>]]>
		</infowindow>
	</marker>
	<?endif;?>
<? endforeach;?>
<? endif; ?>
</markers> 