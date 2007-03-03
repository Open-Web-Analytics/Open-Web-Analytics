
<? if ($first_hit_tag == true):?>

<script language="JavaScript" type="text/javascript">
	document.write('<img src="<?=$this->makeAbsolutelink(array('action' => 'base.processFirstRequest'), '', $this->config['action_url']);?>">');
</script>

<? endif;?>

<? if ($click_tag == true):?>

<SCRIPT TYPE="text/javascript" SRC="<?=$this->makeAbsoluteLink(array('view' => 'base.jsDomClickLib', 'random' => rand()), '', $this->config['action_url']);?>"></SCRIPT><DIV ID="owa_click_bug"></DIV>
 						
<? endif;?>