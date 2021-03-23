<div class="panel_headline"><?php echo $headline?></div>

<div class="subview_content">

<form method="post" name="owa_options">

    <fieldset name="owa-options" class="options">
    <legend>Request Processing Options</legend>

    <div class="setting" id="resolve_hosts">
        <div class="title">Resolve Host Names</div>
        <div class="description">Controls the resolution of host names (e.g. verizon.com) from visitor's raw IP addresses.</div>
        <div class="field">
            <select name="<?php echo $this->getNs();?>config[base.resolve_hosts]">
                <option value="0" <?php if ($config['resolve_hosts'] == false):?>SELECTED<?php endif;?>>Off</option>
                <option value="1" <?php if ($config['resolve_hosts'] == true):?>SELECTED<?php endif;?>>On</option>
            </select>
        </div>
    </div>

    <div class="setting" id="log_feedreaders">
        <div class="title">Log Requests From Feed Readers</div>
        <div class="description">Controls the logging of page requests made by Feed Readers. This setting must be enabled in order to compile statistics about your site's feeds.</div>
        <div class="field">
            <select name="<?php echo $this->getNs();?>config[base.log_feedreaders]">
                <option value="0" <?php if ($config['log_feedreaders'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <option value="1" <?php if ($config['log_feedreaders'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </select>
        </div>
    </div>

    <div class="setting" id="log_robots">
        <div class="title">Log Requests From Known Robots</div>
        <div class="description">Controls the logging of page requests made by known robots and spiders. Turning this feature on will dramatically increase the number of requests that are processed and logged.</div>
        <div class="field">
            <SELECT NAME="<?php echo $this->getNs();?>config[base.log_robots]">
                <OPTION VALUE="0" <?php if ($config['log_robots'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($config['log_robots'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>

    <div class="setting" id="log_named_users">
        <div class="title">Log Requests From Named Users</div>
        <div class="description">Controls the logging of requests made by named users.</div>
        <div class="field">
            <SELECT NAME="<?php echo $this->getNs();?>config[base.log_named_users]">
                <OPTION VALUE="0" <?php if ($config['log_named_users'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($config['log_named_users'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>

    <div class="setting" id="excluded_ips">
        <div class="title">Excluded IP Addresses</div>
        <div class="description">Enter a comma seperated list of the IP addresses that you wish to exclude from tracking.</div>
        <div class="field"><input type="text" size="50" name="<?php echo $this->getNs();?>config[base.excluded_ips]" value="<?php $this->out( $config['excluded_ips'] );?>"></div>
    </div>

    <div class="setting" id="anonymize_ips">
        <div class="title">Anonymize IP Addresses</div>
        <div class="description">Anonymizes the IP addresses of visitors by removing the last octet from their IP address.</div>
        <div class="field">
            <SELECT NAME="<?php echo $this->getNs();?>config[base.anonymize_ips]">
                <OPTION VALUE="0" <?php if ($config['anonymize_ips'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($config['anonymize_ips'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>



    <div class="setting" id="fetch_refering_page_info">
        <div class="title">Fetch Referring Web Page Info</div>
        <div class="description">Controls whether OWA should crawl the web pages that refer visitors to your web site and extract descriptive meta-data that will be used in reporting.</div>
        <div class="field">
            <select name="<?php echo $this->getNs();?>config[base.fetch_refering_page_info]">
                <option value="0" <?php if ($config['fetch_refering_page_info'] == false):?>SELECTED<?php endif;?>>
        Off</option>
                <option value="1" <?php if ($config['fetch_refering_page_info'] == true):?>SELECTED<?php endif;?>>
        On</option>
            </select>
        </div>
    </div>

    <div class="setting" id="p3p_policy">
        <div class="title">P3P Compact Privacy Policy</div>
        <div class="description">This setting controls the P3P compact privacy policy that is returned to the browser when OWA sets cookies. Click <a href="https://www.w3.org/P3P/">here</a> for more information on compact privacy policies and choosing the right one for your web site.</div>
        <div class="field"><input type="text" size="50" name="<?php echo $this->getNs();?>config[base.p3p_policy]" value="<?php $this->out( $config['p3p_policy'] );?>"></div>
    </div>

    <div class="setting" id="url_params">
        <div class="title">URL Parameters</div>
        <div class="description">This setting controls the URL parameters that OWA should ignore when processing requests. This is useful for avoiding duplicate URLs due to the use of tracking or others state parameters in your URLs. Parameter names should be separated by comma.</div>
        <div class="field"><input type="text" size="50" name="<?php echo $this->getNs();?>config[base.query_string_filters]" value="<?php $this->out( $config['query_string_filters']);?>"></div>
    </div>

    </fieldset>
    
    <BR>
    
    <fieldset name="owa-options" class="options">
        <legend>Visitor Announcements</legend>

        <div class="setting" id="announce_visitors">
            <div class="title">Announce New Visitors Via E-mail</div>
            <div class="description">Announces each new visitor to your web site via e-mail. If you have a lot of visitors then you probably want to keep this feature turned off.</div>
            <div class="field">
                <select name="<?php echo $this->getNs();?>config[base.announce_visitors]">
                    <option value="0" <?php if ($config['announce_visitors'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                    <option value="1" <?php if ($config['announce_visitors'] == true):?>SELECTED<?php endif;?>>On</OPTION>
                </select>
            </div>
        </div>

        <div class="setting" id="notice_email">
            <div class="title">Notice E-mail Address</div>
            <div class="description">This is the e-mail address that new visitor e-mails will be sent to.</div>
            <div class="field"><input size="50" type="text" name="<?php echo $this->getNs();?>config[base.notice_email]" value="<?php $this->out( $config['notice_email']);?>"></div>

        </div>

    </fieldset>
    
    
    <BR>
    
    <fieldset name="owa-geolocation-options" class="options">

        <legend>Geo-location</legend>

        <div class="setting" id="geolocation_lookup">
            <div class="title">Perform Geo-location Lookup</div>
            <div class="description">Lookup the geographic location of visitors.</div>
            <div class="field">
                <select name="<?php echo $this->getNs();?>config[base.geolocation_lookup]">
                    <option value="0" <?php if ($config['geolocation_lookup'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                    <option value="1" <?php if ($config['geolocation_lookup'] == true):?>SELECTED<?php endif;?>>On</OPTION>
                </select>
            </div>
        </div>

    </fieldset>

    <BR>

    <fieldset name="owa-feed-options" class="options">
        <legend>Feed Tracking</legend>

        <div class="setting" id="feeds">
            <div class="title">Feed Link Tracking</div>
            <div class="description">Adds tracking parameters to RSS or Atom feeds links. This provides a way to track how many visitors come from your feeds.</div>
            <div class="field">
                <select name="<?php echo $this->getNs();?>config[base.track_feed_links]">

                    <option value="0" <?php if ($config['track_feed_links'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                    <option value="1" <?php if ($config['track_feed_links'] == true):?>SELECTED<?php endif;?>>On</OPTION>
                </select>
            </div>
        </div>

    </fieldset>

    <BR>

    <fieldset name="owa-event-options" class="options">
        <legend>Event Queueing</legend>

        <div class="setting" id="async_log_dir">
            <div class="title">Event Log File Directory</div>
            <div class="description">This is the file system path of the file that OWA will write queued events to when Event Queuing mode is turned on. (e.g. /path/to/owa/log/file.txt)</div>
            <div class="field"><input type="text" size="80" name="<?php echo $this->getNs();?>config[base.async_log_dir]" value="<?php $this->out( $config['async_log_dir'] );?>"></div>
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
            Status: <?php if ($config['cache_objects'] == true):?><B>ON</B><?php else:?><B>OFF</B><?php endif;?> </div>
        </div>

        <div class="setting" id="object_cache_flush">
            <div class="title">Flush Cache</div>
            <div class="description">Flushes the object cache</div>
            <div class="field">

                <a href="<?php echo $this->makeLink(array('do' => 'base.optionsFlushCache')); ?>">Flush Cache Now</a>
            </div>
        </div>


    </fieldset>

    <BR>

    <fieldset name="owa-reporting-options" class="options">

        <legend>Reporting</legend>
        <!--
        <div class="setting" id="ecommerce_reporting">
            <div class="title">E-commerce Reporting</div>
            <div class="description">Adds e-commerce metrics/statistics to reports.</div>
            <div class="field">
                <select name="<?php echo $this->getNs();?>config[base.enableEcommerceReporting]">
                    <option value="0" <?php if ($config['enableEcommerceReporting'] == false):?>SELECTED<?php endif;?>>Off</option>
                    <option value="1" <?php if ($config['enableEcommerceReporting'] == true):?>SELECTED<?php endif;?>>On</option>
                </select>
            </div>
        </div>
        -->

        <div class="setting" id="timezone">
            <div class="title">Reporting Timezone</div>
            <div class="description">This is the timezone that should be used to generate statistics for a specific time period.</div>
            <div class="field">


                <select id="TIMEZONE" name="<?php echo $this->getNs();?>config[base.timezone]">
                <?php
                require_once(OWA_DIR.'conf/country2Timezones.php');
                require_once(OWA_DIR.'conf/countryCodes2Names.php');
                $selected_already = false;
                $selected = '';
                foreach( $timezones as $country => $zones){

                    if (isset($countryCode2Name[$country])) {
                         $country_name = $countryCode2Name[$country];
                    } else {
                        $country_name = 'unknown - '.$country;
                    }

                    echo sprintf('<optgroup label="%s">%s</optgroup>',$country_name, $country_name);

                    foreach ($zones as $value) {

                           $display_value = str_replace('_', ' ', $value);
                           $selected = '';
                        if ( ! $selected_already && $config['timezone'] === $value ) {
                            $selected_already = true;
                            echo sprintf('<option selected="yes" value="%s" >%s</option>', $value, $display_value);
                        } else {
                            echo sprintf('<option value="%s">%s</option>', $value, $display_value);
                        }

                    }
                }
                ?>
                    </optgroup>
                </select>
            </div>
        </div>

    </fieldset>

    <BR>

    <?php echo $this->createNonceFormField('base.optionsUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
    <input type="hidden" name="<?php echo $this->getNs();?>module" value="base">
    <BUTTON class="owa-button" type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsReset">Reset Configuration to Default Values</BUTTON>

</form>
</div>