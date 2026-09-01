<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * How this Profile watches its site.
 * 
 * An Observation Profile IS a way of observing, so these are the settings
 * that define it. Split out of the old three-form site page.
 */
?>
<DIV class="panel_headline">Observation Settings</DIV>
<div id="panel">
<div class="owa_panelIntro">How this Observation Profile watches its Property. A Profile is one way of observing a website &mdash; these settings define what it records and what it ignores. They apply to this Profile only; another Profile on the same Property can observe it differently.</div>
<form method="post" name="owa_options">

    <fieldset name="owa-options" class="options">

        <div class="setting" id="p3p_policy">
            <div class="title">P3P Compact Privacy Policy</div>
            <div class="description">This setting controls the P3P compact privacy policy that is returned to the browser when OWA sets cookies. Click <a href="https://www.w3.org/P3P/">here</a> for more information on compact privacy policies and choosing the right one for your website.</div>
            <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[p3p_policy]" value="<?php $view->out( $view->config['p3p_policy'] ?? '' );?>"></div>
        </div>

        <div class="setting" id="domain_aliases">
            <div class="title">Domain Aliases</div>
            <div class="description">This setting allows you to specify additional domain names that you want OWA to treat as the same as the one you are using for this tracked website. For example, if the domain of your website is "www.mydomain.com" you could add an alias here for "mydomain.com". Aliases should be separated by comma.</div>
            <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[domain_aliases]" value="<?php $view->out( $view->config['domain_aliases'] ?? '' );?>"></div>
        </div>


        <div class="setting" id="url_params">
            <div class="title">URL Parameters</div>
            <div class="description">This setting controls the URL parameters that OWA should ignore when processing requests. This is useful for avoiding duplicate URLs due to the use of tracking or others state parameters in your URLs. Parameter names should be separated by comma.</div>
            <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[query_string_filters]" value="<?php $view->out( $view->config['query_string_filters'] ?? '' );?>"></div>
        </div>

        <div class="setting" id="default_page">
            <div class="title">Default Page</div>
            <div class="description">This is the page that your web server defaults to when there is no page specified in your URL (e.g. index.html). Use this setting to combine page views for www.domain.com and www.domain.com/index.html.</div>
            <div class="field"><input type="text" size="50" name="<?php echo $view->getNs();?>config[default_page]" value="<?php $view->out( $view->config['default_page'] ?? '' );?>"></div>
        </div>

        <div class="setting" id="ecommerce_reporting">
            <div class="title">e-commerce Reporting</div>
            <div class="description">Adds e-commerce metrics/statistics to reports.</div>
            <div class="field">
                <select name="<?php echo $view->getNs();?>config[enableEcommerceReporting]">
                    <option value="0" <?php if ( ! $view->getValue( 'enableEcommerceReporting', $view->config ) ):?>SELECTED<?php endif;?>>Off</option>
                    <option value="1" <?php if ( $view->getValue( 'enableEcommerceReporting', $view->config ) ):?>SELECTED<?php endif;?>>On</option>
                </select>
            </div>
        </div>

        <BR>

        <?php echo $view->createNonceFormField('base.sitesEditSettings');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] ?? '' );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesEditSettings">
        <input type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Settings" class="owa-button">
    </fieldset>
</form>
</div>
