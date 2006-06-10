<? if($status_msg):?>
<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>
<? endif;?>

<h1><?=$page_h1;?></h1>
    
This will be some intro text about installing OWA.
    
	  


    <DIV class="centered_buttons">	
    	<? if($db_state == true):?><a href="<?=$_SERVER['PHP_SELF'];?>?page=db_info">Continue... >></a><?endif;?>
    </DIV>
    

 