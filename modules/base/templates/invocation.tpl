<fieldset>
	<legend>Javascript</legend>
	<div style="padding:10px;">	
		<P>To track page views using Javascript, cut and paste this tracking tag into the HTML of your web pages. For more information on to use OWA's Javascript tracking API, visit <a href="<?php echo $this->makeWikiLink('Javascript_Invocation');?>">this page on the OWA Wiki</a>.</P>
	
		<textarea cols="75" rows="18">
				
<?php include('js_log_tag.tpl');?>
				
		</textarea>
	</div>		
</fieldset>
			
<fieldset>
	<legend>PHP</legend>
	<div style="padding:10px;">
	
		<P>To track page views using PHP, cut and paste the following code to your PHP script/application. For more information on how to use OWA's PHP API to track your web site/pages, see <a href="<?php echo $this->makeWikiLink('PHP_Invocation');?>">this page on the OWA Wiki</a>.</P>
			
		<textarea cols="75" rows="12">
		
require_once('<?php echo OWA_BASE_CLASSES_DIR;?>owa_php.php');
		
$owa = new owa_php();
$event = $owa->makeEvent();
// Set the site id you want to track
$event->setSiteId('<?php echo $site_id;?>');
// Uncomment the next line to set your page title
//$event->setPageTitle('somepagetitle');
// Set other page properties
//$event->set('foo', 'bar');
$owa->trackPageView($event);
		</textarea>
	
	</div>
</fieldset>
			
