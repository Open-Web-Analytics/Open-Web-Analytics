<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php echo $view->headline?></div>

<?php
/*
 * #panel, like every other settings screen.
 *
 * This one alone used .subview_content, which is padding and nothing else --
 * so the general options were the only settings screen with no white card
 * under them, sitting straight on the page's grey with each `.setting` drawing
 * its own box. See .owa_hierarchyContent #panel in owa.report.css, which is
 * what gives these screens their ground.
 */
?>
<div id="panel">

<form method="post" name="owa_options">

    <fieldset name="owa-options" class="options">
    <legend>Tracking Request Processing</legend>

    <div class="setting" id="resolve_hosts">
        <div class="title">Resolve Host Names</div>
        <div class="description">Controls the resolution of host names (e.g. verizon.com) from visitor's raw IP addresses.</div>
        <div class="field">
            <select name="<?php echo $view->getNs();?>config[base.resolve_hosts]">
                <option value="0" <?php if ($view->config['resolve_hosts'] == false):?>SELECTED<?php endif;?>>Off</option>
                <option value="1" <?php if ($view->config['resolve_hosts'] == true):?>SELECTED<?php endif;?>>On</option>
            </select>
        </div>
    </div>

    <div class="setting" id="log_robots">
        <div class="title">Log Requests From Known Robots</div>
        <div class="description">Controls the logging of page requests made by known robots and spiders. Turning this feature on will dramatically increase the number of requests that are processed and logged.</div>
        <div class="field">
            <SELECT NAME="<?php echo $view->getNs();?>config[base.log_robots]">
                <OPTION VALUE="0" <?php if ($view->config['log_robots'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($view->config['log_robots'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>

    <div class="setting" id="log_named_users">
        <div class="title">Log Requests From Named Users</div>
        <div class="description">Controls the logging of requests made by named users.</div>
        <div class="field">
            <SELECT NAME="<?php echo $view->getNs();?>config[base.log_named_users]">
                <OPTION VALUE="0" <?php if ($view->config['log_named_users'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($view->config['log_named_users'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>

    <div class="setting" id="excluded_ips">
        <div class="title">Excluded IP Addresses</div>
        <div class="description">Enter a comma seperated list of the IP addresses that you wish to exclude from tracking.</div>
        <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[base.excluded_ips]" value="<?php $view->out( $view->config['excluded_ips'] );?>"></div>
    </div>

    <div class="setting" id="anonymize_ips">
        <div class="title">Anonymize IP Addresses</div>
        <div class="description">Anonymizes the IP addresses of visitors by removing the last octet from their IP address.</div>
        <div class="field">
            <SELECT NAME="<?php echo $view->getNs();?>config[base.anonymize_ips]">
                <OPTION VALUE="0" <?php if ($view->config['anonymize_ips'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                <OPTION VALUE="1" <?php if ($view->config['anonymize_ips'] == true):?>SELECTED<?php endif;?>>On</OPTION>
            </SELECT>
        </div>
    </div>




    <div class="setting" id="url_params">
        <div class="title">URL Parameters</div>
        <div class="description">This setting controls the URL parameters that OWA should ignore when processing requests. This is useful for avoiding duplicate URLs due to the use of tracking or others state parameters in your URLs. Parameter names should be separated by comma.</div>
        <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[base.query_string_filters]" value="<?php $view->out( $view->config['query_string_filters']);?>"></div>
    </div>

    </fieldset>
    
    <BR>
    
    <fieldset name="owa-options" class="options">
        <legend>Visitor Announcements</legend>

        <div class="setting" id="announce_visitors">
            <div class="title">Announce New Visitors Via E-mail</div>
            <div class="description">Announces each new visitor to your web site via e-mail. If you have a lot of visitors then you probably want to keep this feature turned off.</div>
            <div class="field">
                <select name="<?php echo $view->getNs();?>config[base.announce_visitors]">
                    <option value="0" <?php if ($view->config['announce_visitors'] == false):?>SELECTED<?php endif;?>>Off</OPTION>
                    <option value="1" <?php if ($view->config['announce_visitors'] == true):?>SELECTED<?php endif;?>>On</OPTION>
                </select>
            </div>
        </div>

        <div class="setting" id="notice_email">
            <div class="title">Notice E-mail Address</div>
            <div class="description">This is the e-mail address that new visitor e-mails will be sent to.</div>
            <div class="field"><input size="50" type="text" name="<?php echo $view->getNs();?>config[base.notice_email]" value="<?php $view->out( $view->config['notice_email']);?>"></div>

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
                <select name="<?php echo $view->getNs();?>config[base.enableEcommerceReporting]">
                    <option value="0" <?php if ($view->config['enableEcommerceReporting'] == false):?>SELECTED<?php endif;?>>Off</option>
                    <option value="1" <?php if ($view->config['enableEcommerceReporting'] == true):?>SELECTED<?php endif;?>>On</option>
                </select>
            </div>
        </div>
        -->

        <div class="setting" id="timezone">
            <div class="title">Reporting Timezone</div>
            <div class="description">This is the timezone that should be used to generate statistics for a specific time period.
            <br><br>
            <strong>Changing this is not retroactive.</strong> Each request is filed under a
            calendar day when it is recorded, using the timezone set at that moment. Data already
            collected keeps the day boundaries it was recorded with, so a change applies only to
            traffic from this point on &mdash; and reports spanning the change will mix the two.
            Depending on how far the zones are apart, a day boundary can move by up to 21 hours.</div>
            <div class="field">


                <?php
                /*
                 * A setting supplied by a config-file constant is NOT editable
                 * here: the constant beats the stored value on every boot, so an
                 * editable field would accept a change that never takes effect.
                 * Rendered disabled, showing the value in force, and naming the
                 * constant so the operator knows where to change it. A disabled
                 * field is not submitted by the browser, and OptionsUpdate
                 * refuses the key regardless -- disabling is the courtesy, the
                 * server-side refusal is the guarantee.
                 */
                $tz_constant = $view->configFileConstantFor( 'base', 'timezone' );
                ?>
                <select id="TIMEZONE" name="<?php echo $view->getNs();?>config[base.timezone]"<?php
                    echo $tz_constant ? ' disabled="disabled"' : ''; ?>>
                <?php
                // These two conf files exist only to declare their arrays, so the
                // variables below come from the require, not from a view var.
                // PHPStan cannot follow a require path built from a constant.
                /** @var array<string, array<string>> $timezones */
                /** @var array<string, string> $countryCode2Name */
                // require, NOT require_once: these files exist only to assign
                // $timezones and $countryCode2Name into this scope, so a second
                // render in the same process would find both undefined and the
                // page would come out with an empty picker. Re-assigning two
                // arrays is cheap; silently losing 285 options is not.
                require(OWA_DIR.'conf/country2Timezones.php');
                require(OWA_DIR.'conf/countryCodes2Names.php');
                $selected_already = false;
                $selected = '';
                ksort($timezones);
                //print_r($timezones);
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
                        if ( ! $selected_already && $view->config['timezone'] === $value ) {
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
                <?php if ( $tz_constant ) { ?>
                    <div class="description" style="opacity:.75">
                        Set by <code><?php $view->out( $tz_constant ); ?></code> in
                        <code>owa-config.php</code>, which overrides any value stored here.
                        Change it there, or remove the constant to edit it from this page.
                    </div>
                <?php } ?>
            </div>
        </div>

    </fieldset>

    <BR>

    <?php echo $view->createNonceFormField('base.optionsUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="base.optionsReset">Reset Configuration to Default Values</BUTTON>

</form>
</div>