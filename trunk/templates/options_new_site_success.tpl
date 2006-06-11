<h1><?=$page_h1;?></h1>

<? if(!empty($status_msg)):?>
<?=$status_msg;?>
<? endif;?>

The Site ID for the web site is: <span class="id_box"><?=$site_id?></span>

<h3>Add this tracking tag to your web pages:</h3>
<textarea cols="75" rows="10"><?=$tag;?></textarea>

<h3>Or, invoke OWA from within your PHP script using:</h3>

<div class="code">
<pre><code>
$config['site_id'] = <?=$site_id?>;
$owa = new owa_php($config);
$owa->log();
</code></pre>
</div>
<P>For more information on configuring OWA or tracking tags, visit the <a href="http://wiki.openwebanalytics.com">OWA Wiki</a>.</P>

