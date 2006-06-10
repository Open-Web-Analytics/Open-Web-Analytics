<h1><?=$page_h1;?></h1>

<? if(!empty($status_msg)):?>
<?=$status_msg;?>
<? endif;?>

The Site ID for your new site is: <span class="id_box"><?=$site_id?></span>

<h3>Add this tag to your web pages:</h3>
<textarea cols="40" rows="10"><?=$tag;?></textarea>

<h3>or invoke OWA from your PHP script using:</h3>

<pre><code>

$config['site_id'] = <?$site_id?>;
$owa = new owa_php($config);
$owa->log();

</code></pre>


