<h1><?=$page_h1;?></h1>
    
This will be some intro text about installing OWA.
    
    <DIV class="centered_buttons">	
    	<a href="<?=$_SERVER['PHP_SELF'];?><? if($db_state == false):?>?page=db_info<? else:?>?page=package_selection<?endif;?>">Continue... >></a>
    </DIV>
    

 