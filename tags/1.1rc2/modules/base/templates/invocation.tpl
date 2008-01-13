<fieldset>
	<legend>Javascript</legend>
				
		<P>Cut and paste this tracking tag into the HTML of your web pages. For more information on to invoke OWA from within your PHP script, visit the <a href="<?=$this->makeWikiLink('Javascript_Invocation');?>">this page on the OWA Wiki</a>.</P>

<textarea cols="75" rows="10">
		
<? include('js_log_tag.tpl');?>
		
</textarea>
			
</fieldset>
			
<fieldset>
	<legend>PHP</legend>
	<P>Invoke OWA from within your PHP script, add the following code to your script/application. For more information on to invoke OWA from within your PHP, visit the <a href="<?=$this->makeWikiLink('PHP_Invocation');?>">this page on the OWA Wiki</a>.</P>
			
	<div class="code">
		<pre><code>
		require_once('<?=OWA_BASE_CLASSES_DIR;?>owa_php.php');
		
		$config['site_id'] = '<?=$site_id;?>';
		$owa = new owa_php($config);
		$owa->log();
		</code></pre>
	</div>
</fieldset>
			
