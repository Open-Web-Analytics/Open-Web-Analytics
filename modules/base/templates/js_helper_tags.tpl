<!-- OWA Helper Tag Tags -->

<?php if (isset($first_hit_tag) && $first_hit_tag === true):?>

<script type="text/javascript">
//<![CDATA[
document.write('<img src="<?php echo $this->makeAbsolutelink(array('action' => 'base.processFirstRequest', 'site_id' => $site_id), '', $this->config['action_url']);?>">');
//]]>
</script>

<?php endif;?>

<?php include('js_log_tag.tpl'); ?>