<div class=wrap>
  <form method="post">
    <h2><?=$page_title?></h2>

    <fieldset name="owa-options" class="options">
	<legend>Request Processing Options</legend>
			
	<DIV class="setting">	
		Resolve Host Names: 
		<SELECT NAME="<?=$config['resolve_hosts']?>">
	
		<OPTION VALUE="0" <? if ($config['resolve_hosts'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['resolve_hosts'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV> 
	
	<DIV class="setting">	
		Log Requests from Feed Readers: 
		<SELECT NAME="<?=$config['log_feedreaders']?>">
	
		<OPTION VALUE="0" <? if ($config['log_feedreaders'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_feedreaders'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	
		Log Requests from Known Robots: 
		<SELECT NAME="<?=$config['log_robots']?>">
	
		<OPTION VALUE="0" <? if ($config['log_robots'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_robots'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>	
	
    </fieldset>
     
    <fieldset name="owa-db-options" class="options">
	<legend>Database Options</legend>
	
	<DIV class="setting">	
		Asynchronous Database Mode: 
		<SELECT NAME="<?=$config['async_db']?>">
	
		<OPTION VALUE="0" <? if ($config['async_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['async_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	
    </fieldset>
    
    <fieldset name="owa-error-options" class="options">
	<legend>Error Logging</legend>
	
	<DIV class="setting">	

	Notice Email Address: <input type="text" name="notice_email" value="<?=$config['notice_email']?>"><BR>
	
	</DIV>
    
     
     
<BR>
   <input type="submit" name="options_update" value="Save Options" />
   <input type="submit" name="options_reset" value="Reset Options to Default" />
  </form>
 </div>
 