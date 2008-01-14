
<? if ($first_hit_tag == true):?>

<script type="text/javascript">
//<![CDATA[
document.write('<img src="<?=$this->makeAbsolutelink(array('action' => 'base.processFirstRequest', 'site_id' => $this->config['site_id']), '', $this->config['action_url']);?>">');
//]]>
</script>

<? endif;?>

<? if ($click_tag == true):?>

<script type="text/javascript" src="<?=$this->makeAbsoluteLink(array('view' => 'base.jsDomClickLib', 'random' => rand()), '', $this->config['action_url'], true);?>"></script><div id="owa_click_bug"></div>
 						
<? endif;?>