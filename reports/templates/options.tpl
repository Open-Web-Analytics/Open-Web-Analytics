<div class=wrap>
  <form method="post">
    <h2><?=$page_title?></h2>
     <fieldset name="set1">
	<legend><?php _e('Options set 1', 'Localization name') ?></legend>
	
	<? foreach ($config as $name => $value):?>
		
		<?=$name?>: <input type="text" name="<?=$name?>" value="<?=$value?>"><BR>
		
	<?endforeach;?>
	
     </fieldset>
     <fieldset name="set2">
	<legend><?php _e('Options set 2', 'Localization name') ?></legend>
	Put some more form input areas here.
     </fieldset>
<div class="submit">
  <input type="submit" name="info_update" value="<?php
    _e('Update options', 'Localization name')
	?>" /></div>
  </form>
 </div>