<!-- OWA Helper Tag Tags -->
<?php if ( $this->getValue( 'first_hit_tag', $options ) ):?>
<script type="text/javascript">
//<![CDATA[
document.write('<img src="<?php echo $this->makeAbsolutelink(array('action' => 'base.processFirstRequest', 'site_id' => $site_id), '', $this->config['action_url']);?>">');
//]]>
</script>
<?php endif;?>

<?php include($this->getTemplatePath('base','js_log_tag.tpl')); ?>