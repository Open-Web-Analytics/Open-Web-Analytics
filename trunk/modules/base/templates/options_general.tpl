<div class="panel_headline"><?=$headline?></div>

<div class="subview_content">

<form method="post" name="owa_options">

	<fieldset name="owa-options" class="options">
	<legend>Request Processing Options</legend>
			
	<div class="setting" id="resolve_hosts">
		<div class="title">Resolve Host Names</div> 
		<div class="description">Controls the resolution of host names (e.g. verizon.com) from visitor's raw IP addresses.</div>
		<div class="field">
			<select name="<?=$this->getNs();?>config[resolve_hosts]">
				<option value="0" <? if ($config['resolve_hosts'] == false):?>SELECTED<?endif;?>>Off</option>
				<option value="1" <? if ($config['resolve_hosts'] == true):?>SELECTED<?endif;?>>On</option>		
			</select>
		</div>
	</div> 
	
	<div class="setting" id="log_feedreaders">	
		<div class="title">Log Requests From Feed Readers</div> 
		<div class="description">Controls the logging of page requests made by Feed Readers. This setting must be enabled in order to compile statistics about your site's feeds.</div>
		<div class="field">
			<select name="<?=$this->getNs();?>config[log_feedreaders]">
				<option value="0" <? if ($config['log_feedreaders'] == false):?>SELECTED<?endif;?>>Off</OPTION>					<option value="1" <? if ($config['log_feedreaders'] == true):?>SELECTED<?endif;?>>On</OPTION>	
			</select>
		</div>
	</div>
	
	<div class="setting" id="log_robots">	
		<div class="title">Log Requests From Known Robots</div>
		<div class="description">Controls the logging of page requests made by known robots and spiders. Turning this feature on will dramatically increase the number of requests that are processed and logged.</div>
		<div class="field">
			<SELECT NAME="<?=$this->getNs();?>config[log_robots]">
				<OPTION VALUE="0" <? if ($config['log_robots'] == false):?>SELECTED<?endif;?>>Off</OPTION>
				<OPTION VALUE="1" <? if ($config['log_robots'] == true):?>SELECTED<?endif;?>>On</OPTION>
			</SELECT>
		</div>
	</div>	
	
	<div class="setting" id="fetch_refering_page_info">	
		<div class="title">Fetch Referring Web Page Info</div> 
		<div class="description">Controls whether OWA should crawl the web pages that refer visitors to your web site and extract descriptive meta-data that will be used in reporting.</div>
		<div class="field">
			<select name="<?=$this->getNs();?>config[fetch_refering_page_info]">
				<option value="0" <? if ($config['fetch_refering_page_info'] == false):?>SELECTED<?endif;?>>
		Off</option>
				<option value="1" <? if ($config['fetch_refering_page_info'] == true):?>SELECTED<?endif;?>>
		On</option>
			</select>
		</div>
	</div>		
	
	<div class="setting" id="first_hit">	
		<div class="title">Delay First Hit</div>
		<div class="description">This setting controls whether or not OWA should dealy logging the first hit of new visitors untill a secondary http request for a special web bug is made. This tactic is used to foil spiders/robots that spoof their user agents in an attempt to appear like a normal web browser.</div> 
		<div class="field">
			<select name="<?=$this->getNs();?>config[delay_first_hit]">
				<option value="0" <? if ($config['delay_first_hit'] == false):?>SELECTED<?endif;?>>Off</option>
				<option value="1" <? if ($config['delay_first_hit'] == true):?>SELECTED<?endif;?>>On</option>	
			</select>
		</div>
	</div>	
	
	<div class="setting" id="p3p_policy">	
		<div class="title">P3P Compact Privacy Policy</div>
		<div class="description">This setting controls the P3P compact privacy policy that is returned to the browser when OWA sets cookies. Click <a href="http://www.p3pwriter.com/LRN_111.asp">here</a> for more information on compact privacy policies and choosing the right one for your web site.</div>
		<div class="field"><input type="text" size="50" name="<?=$this->getNs();?>config[p3p_policy]" value="<?=$config['p3p_policy']?>"></div>
	</div>
	
    </fieldset>
    
    <BR>
    
    <fieldset name="owa-options" class="options">
		<legend>Visitor Announcements</legend>
	
		<div class="setting" id="announce_visitors">	
			<div class="title">Announce New Visitors Via E-mail</div>
			<div class="description">Announces each new visitor to your web site via e-mail. If you have a lot of visitors then you probably want to keep this feature turned off.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[announce_visitors]">
					<option value="0" <? if ($config['announce_visitors'] == false):?>SELECTED<?endif;?>>Off</OPTION>	
					<option value="1" <? if ($config['announce_visitors'] == true):?>SELECTED<?endif;?>>On</OPTION>
				</select>
			</div>
		</div>
	
		<div class="setting" id="notice_email">	
			<div class="title">Notice E-mail Address</div>
			<div class="description">This is the e-mail address that new visitor e-mails will be sent to.</div>
			<div class="field"><input size="50" type="text" name="<?=$this->getNs();?>config[notice_email]" value="<?=$config['notice_email']?>"></div>

		</div>
	
	</fieldset>
    
    
    <BR>
    
    <fieldset name="owa-geolocation-options" class="options">
		
		<legend>Geo-location</legend>
	
		<div class="setting" id="geolocation_lookup">	
			<div class="title">Perform Geo-location Lookup</div>
			<div class="description">Lookup the geographic location of visitors.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[geolocation_lookup]">
					<option value="0" <? if ($config['geolocation_lookup'] == false):?>SELECTED<?endif;?>>Off</OPTION>
					<option value="1" <? if ($config['geolocation_lookup'] == true):?>SELECTED<?endif;?>>On</OPTION>
				</select>
			</div>
		</div>
	
		<div class="setting" id="geolocation_service">	
			<div class="title">Geo-location Service</div> 
			<div class="description">Select the geo-location service to use.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[geolocation_service]">
					<option value="hostip" <? if ($config['geolocation_service'] == 'hostip'):?>SELECTED<?endif;?>>Hostip.info Web Service (free)</OPTION>
				</select>
			</div>
		</div>
	
		<div class="setting" id="google_maps_api_key">
			<div class="title">Google Maps API Key</div>
			<div class="description">Google maps API key is needed to produce Google maps of visitor geo-locations. You may obtain an API key from <a href="http://www.google.com/apis/maps/signup.html">this Google web site</a> for free.</div>
			<div class="field"><input type="text" size="90" name="<?=$this->getNs();?>config[google_maps_api_key]" value="<?=$config['google_maps_api_key']?>"></div>
		</div>
    
	</fieldset>
		
	<BR>

	<fieldset name="owa-feed-options" class="options">
		<legend>Feed Tracking</legend>
		
		<div class="setting" id="feeds">	
			<div class="title">Feed Link Tracking</div> 
			<div class="description">Adds tracking parameters to RSS or Atom feeds links. This provides a way to track how many visitors come from your feeds.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[track_feed_links]">
	
					<option value="0" <? if ($config['track_feed_links'] == false):?>SELECTED<?endif;?>>Off</OPTION>
					<option value="1" <? if ($config['track_feed_links'] == true):?>SELECTED<?endif;?>>On</OPTION>
				</select>
			</div>
		</div>
		
	</fieldset>
	
    <BR>
	
    <fieldset name="owa-event-options" class="options">
		<legend>Event Handling</legend>
	
		<div class="setting" id="async_db">	
			<div class="title">Asynchronous Event Handling Mode</div> 
			<div class="description">This mode allows tracking events to be logged to the database ansychronously. This mode requires a seperate process to be run at set intervals in order for statistics to be processed. See <a href="http://wiki.openwebanalytics.com/index.php?title=Event_processing">this page on the OWA wiki</a> for more information about how to use this feature.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[async_db]">
	
					<option value="0" <? if ($config['async_db'] == false):?>SELECTED<?endif;?>>Off</OPTION>
					<option value="1" <? if ($config['async_db'] == true):?>SELECTED<?endif;?>>On</OPTION>
				</select>
			</div>
		</div>
	
		<div class="setting" id="async_log_dir">	
			<div class="title">Event Log File Directory</div>
			<div class="description">This is the location of log file that OWA will store events in untill they are processed (e.g. /path/to/owa/log/file/)</div>
			<div class="field"><input type="text" size="80" name="<?=$this->getNs();?>config[async_log_dir]" value="<?=$config['async_log_dir']?>"></div>
		</div>
	
	
    </fieldset>
	
    <BR>
    
    <fieldset name="owa-error-options" class="options">
		<legend>Error Logging</legend>
	
		<div class="setting" id="error_handler">	
			<div class="title">Error Logging Mode</div> 
			<div class="description">Controls the level of detail that OWA will log to its error log file.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[error_handler]">
	
					<option value="production" <? if ($config['error_handler'] == 'production'):?>SELECTED<?endif;?>>Production (Errors logged to file)</option>
					<option value="development" <? if ($config['error_handler'] == 'development'):?>SELECTED<?endif;?>>Development (Debug and Error messages logged to file)</option>
				</select>
			</div>
		</div>
	
	</fieldset>
	
	<BR>
	
	<fieldset name="owa-cache-options" class="options">
		<legend>Object Cache</legend>
	
		<div class="setting" id="object_cache">	
			<div class="title">Cache Control</div> 
			<div class="description">Enables and disables object caching. This will improve performance under high load conditions. The object cache can be turned on/off via your config file.
</div>
			<div class="field">
			Status: <? if ($config['cache_objects'] == true):?><B>ON</B><?else:?><B>OFF</B><?endif;?> </div>
		</div>
		
		<div class="setting" id="object_cache_flush">	
			<div class="title">Flush Cache</div> 
			<div class="description">Flushes the object cache</div>
			<div class="field">
				
				<a href="<?=$this->makeLink(array('do' => 'base.optionsFlushCache')); ?>">Flush Cache Now</a>
			</div>
		</div>
		
	
	</fieldset>
	
	<BR>

	
	
	<fieldset name="owa-reports-options" class="options">
		
		<legend>Presenation</legend>
		<? if ($this->config['is_embedded'] == false):?>	
		<div class="setting" id="reporting_wrapper">	
			<div class="title">Reporting Template Wrapper</div>
			<div class="description">This is the name of the template file used to wrap reports and admin screens</div>
			<div class="field"><input type="text" name="<?=$this->getNs();?>config[report_wrapper]" value="<?=$config['report_wrapper']?>"></div>
			
		</div>
    	<?endif;?>
		
		<div class="setting" id="click_drawing_mode">	
			<div class="title">Click Drawing Mode</div>
			<div class="description">Controls the layout mode that will be used to plot clicks when producing scatter-plots and heatmaps.</div>
			<div class="field">
				<select name="<?=$this->getNs();?>config[click_drawing_mode]">
					<option value="center_on_page" <? if ($config['click_drawing_mode'] == 'center_on_page'):?>SELECTED<?endif;?>>Content centered</option>
					<option value="expandable" <? if ($config['click_drawing_mode'] == 'expandable'):?>SELECTED<?endif;?>>Content resizable</option>
				</select>
			</div>
		</div>
	</fieldset>
	
	<BR>
	
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
	
</form>
</div>