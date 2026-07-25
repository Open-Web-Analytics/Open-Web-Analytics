<kml xmlns="http://earth.google.com/kml/2.1">
    <Document>
        <name>OWA: Visits to <?php echo $site_name;?></name>
            <description>Site visits for <?php echo $period_label;?><?php echo $date_label;?></description>  
<?php if ($visits):?>
<?php foreach ($visits as $visit):?>
<?php if (!empty($visit['host_longitude'])):?>
            <Placemark id="<?php echo $visit['session_id'];?>">
            <name><?php echo $visit['host_host'];?> - <?php echo $visit['session_month'];?>/<?php echo $visit['session_day'];?> at <?php echo $visit['session_hour'];?>:<?php echo $visit['session_minute'];?></name>
            <description><![CDATA[<?php include('report_visit_summary_balloon.tpl');?>]]></description>
            <Point>
                <coordinates><?php echo trim($visit['host_longitude']);?>,<?php echo trim($visit['host_latitude']);?>,5000</coordinates>
            </Point>
            <styleUrl>#defaultStyle</styleUrl>
        </Placemark>
    <?php endif; ?>
        <?php endforeach;?>
    <?php endif; ?>

    </Document>
</kml>