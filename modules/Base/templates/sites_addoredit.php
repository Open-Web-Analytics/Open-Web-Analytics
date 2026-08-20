<?php /** @var \OWA\Core\ViewScope $view */ ?>
<DIV class="panel_headline"><?php $view->out( $view->headline );?></DIV>
<div id="panel">
<fieldset>

    <legend>Site Profile</legend>

    <form method="POST">

    <table class="management">
        <?php if ($view->edit == true):?>
        <TR>
            <TH>Site ID:</TH>
            <TD><?php $view->out( $view->site['site_id'] );?></TD>
            <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] );?>">

        </TR>
        <?php endif;?>
        <TR>
            <TH>Domain:</TH>
            <?php if ($view->edit == true):?>
            <input type="hidden" name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->site['domain'] );?>">
            <TD><?php $view->out( $view->site['domain'] );?></TD>
            <?php else:?>
            <TD>  
                <input type="text" name="<?php echo $view->getNs();?>domain" size="52" maxlength="70" value="<?php $view->out( $view->site['domain'] ?? '' );?>"><BR>
                Example: http://some.domain.com<BR>
                <span class="validation_error"><?php $view->out( $view->validation_errors['domain'] ?? '' );?></span>
            </TD>
            <?php endif;?>
        </TR>
        <TR>
            <TH>Site Name:</TH>
            <TD><input type="text" name="<?php echo $view->getNs();?>name" size="52" maxlength="70" value="<?php $view->out( $view->site['name'] ?? '' );?>">
				<span class="form-instructions">Example: My Website</span>            
            </TD>
        </TR>
        <TR>
            <TH>Description:</TH>
            <TD>
                <textarea name="<?php echo $view->getNs();?>description" cols="52" rows="3"><?php $view->out( $view->site['description'] ?? '' );?></textarea>
            </TD>
        </TR>



    </table>
    <BR>
    <?php echo $view->createNonceFormField($view->action);?>
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="<?php $view->out( $view->action, false );?>">
    <input class="owa-button" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Profile">

    </form>

</fieldset>


<form method="post" name="owa_options">

    <fieldset name="owa-options" class="options">
    <legend>Site Settings</legend>

        <div class="setting" id="p3p_policy">
            <div class="title">P3P Compact Privacy Policy</div>
            <div class="description">This setting controls the P3P compact privacy policy that is returned to the browser when OWA sets cookies. Click <a href="https://www.w3.org/P3P/">here</a> for more information on compact privacy policies and choosing the right one for your web site.</div>
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
<form method="post" name="owa-allowedusersform">
    <fieldset name="owa-allowedusers" class="options">
    <legend>Allowed Users</legend>

        <div class="description">
            Users ticked here can see this site's reports. Access controls three
            capabilities &mdash; viewing reports, viewing e-commerce reports, and
            editing this site. Administrators always have access and cannot be
            changed here.
        </div>

        <?php if ( $view->edit ): ?>

        <div class="field">
            <input type="text" id="owa-user-filter" class="owa-user-filter"
                placeholder="Filter by name, login or role" autocomplete="off">
        </div>

        <table class="management owa-allowed-users">
            <tr>
                <th style="width:1%"></th>
                <th>Login</th>
                <th>Name</th>
                <th>Role</th>
            </tr>
            <?php foreach ($view->users as $user):
                $isAdmin   = ( $user['role'] ?? '' ) === 'admin';
                $isAllowed = $view->siteEntity->isUserAssigned( $user['id'] );
            ?>
            <tr class="owa-user-row">
                <td>
                    <?php if ( $isAdmin ): ?>
                        <input type="checkbox" checked disabled title="Administrators always have access">
                    <?php else: ?>
                        <input type="checkbox"
                            name="<?php echo $view->getNs();?>allowed_users[]"
                            value="<?php $view->out( $user['id'] );?>"
                            id="owa-user-<?php $view->out( $user['id'] );?>"
                            <?php if ( $isAllowed ): ?>checked<?php endif;?>>
                        <input type="hidden"
                            name="<?php echo $view->getNs();?>rendered_users[]"
                            value="<?php $view->out( $user['id'] );?>">
                    <?php endif;?>
                </td>
                <td><label for="owa-user-<?php $view->out( $user['id'] );?>"><?php $view->out( $user['user_id'] );?></label></td>
                <td><?php $view->out( $user['real_name'] );?></td>
                <td>
                    <?php $view->out( $user['role'] );?>
                    <?php if ( $isAdmin ): ?><span class="owa-always">always has access</span><?php endif;?>
                </td>
            </tr>
            <?php endforeach;?>
        </table>

        <br>
        <?php echo $view->createNonceFormField('base.sitesEditAllowedUsers');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] ?? '' );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesEditAllowedUsers">
        <input type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Users" class="owa-button">

        <?php else: ?>

        <div class="description">
            Save this site first, then choose which users can see its reports.
        </div>

        <?php endif;?>

    </fieldset>
</form>

<style>
.owa-allowed-users td { vertical-align: middle; }
.owa-user-filter { width: 22em; }
.owa-always { color: #767676; font-size: 0.9em; margin-left: 0.5em; }
</style>

<script>
jQuery(document).ready(function() {
    jQuery('#owa-user-filter').on('keyup', function() {
        var needle = jQuery(this).val().toLowerCase();
        jQuery('.owa-allowed-users tr.owa-user-row').each(function() {
            jQuery(this).toggle( jQuery(this).text().toLowerCase().indexOf( needle ) !== -1 );
        });
    });
});
</script>
</div>
