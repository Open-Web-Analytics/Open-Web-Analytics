<fieldset>
	<legend>Javascript</legend>
	<div style="padding:10px;">	
		<P>To track page views using Javascript, cut and paste this tracking tag into the HTML of your web pages. Learn more about how to use OWA's  <a href="<?php echo $this->makeWikiLink('Javascript_Invocation');?>">Javascript tracking API</a> to track your web site and pages.</P>
	
		<textarea cols="110" rows="18">
				
<?php echo $tracking_code; ?>
				
		</textarea>
	</div>		
</fieldset>
			
<fieldset>
	<legend>PHP</legend>
	<div style="padding:10px;">
	
		<P>To track page views using PHP, cut and paste the following code to your PHP script/application. Learn more about how to use OWA's <a href="<?php echo $this->makeWikiLink('PHP_Invocation');?>">PHP Tracking API</a> to track your web site and pages.</P>
			
		<textarea cols="75" rows="12">
		
require_once('<?php echo OWA_BASE_CLASSES_DIR;?>owa_php.php');
		
$owa = new owa_php();
// Set the site id you want to track
$owa->setSiteId('<?php echo $site_id;?>');
// Uncomment the next line to set your page title
//$owa->setPageTitle('somepagetitle');
// Set other page properties
//$owa->setProperty('foo', 'bar');
$owa->trackPageView();
		</textarea>
	
	</div>
</fieldset>
			
