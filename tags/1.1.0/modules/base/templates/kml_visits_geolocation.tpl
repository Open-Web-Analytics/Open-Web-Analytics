<?=$xml;?>

<kml xmlns="http://earth.google.com/kml/2.1">
	<Document>
		<name>OWA: Visits to <?=$site_name;?></name>
		<description>Site visits for <?=$period_label;?><?=$date_label;?></description>  
		<? if ($visits):?>    
    	<? foreach ($visits as $visit):?>  
    	<Placemark id="<?=$visit['session_id'];?>">
        	<name><?=$visit['host_host'];?> - <?=$visit['session_month'];?>/<?=$visit['session_day'];?> at <?=$visit['session_hour'];?>:<?=$visit['session_minute'];?></name>
        	<description><![CDATA[<? include('report_visit_summary_balloon.tpl');?>]]></description>
	        <Point>
	            <coordinates><?=trim($visit['host_longitude']);?>,<?=trim($visit['host_latitude']);?>,5000</coordinates>
	        </Point>
	        <styleUrl>#defaultStyle</styleUrl>
    	</Placemark>
    	<? endforeach;?>
	<? endif; ?>
	</Document>
</kml>