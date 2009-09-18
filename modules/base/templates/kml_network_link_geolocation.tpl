<?php echo $xml;?>

<kml xmlns="http://earth.google.com/kml/2.1">
<Folder>
    <name>Open Web Analytics Links</name>
    <visibility>1</visibility>
    <open>1</open>
    <description>These are network links for OWA.</description>
    <NetworkLink>
      <name><?php echo $site_name;?></name>
      <visibility>1</visibility>
      <open>0</open>
      <description>Visits for <?php echo $period_label;?><?php echo $date_label;?></description>
      <refreshVisibility>0</refreshVisibility>
      <flyToView>1</flyToView>
      <Link>
        <href><?php echo $this->makeAbsoluteLink(array('do' => 'base.kmlVisitsGeolocation', 'rand' => rand()), true, '', true);?></href>
      </Link>
    </NetworkLink>
  </Folder>
</kml> 