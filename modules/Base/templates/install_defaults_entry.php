<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h2>Default Site & User Information</h2>
<div id="configSettings">
    <form method="POST">
        
        <p class="form-row">
            <span class="form-label">Site Domain</span>
            <span class="form-field">
                <select name="<?php echo $view->getNs();?>protocol">
                    <option value="http://">http://</option>
                    <option value="https://">https://</option>
                </select>
                <input type="text"size="30" name="<?php echo $view->getNs();?>domain" value="<?php $view->out( $view->defaults['domain'] );?>">
            </span>
            <span class="form-instructions">This is the domain of the site to track.</span>
        </p>

        <p class="form-row">
            <span class="form-label">Reporting Timezone</span>
            <span class="form-field">
                <select name="<?php echo $view->getNs();?>timezone">
                <?php
                /*
                 * Asked HERE, at install, because it is not retroactive.
                 *
                 * yyyymmdd and the nine date-part columns are derived in this
                 * timezone and written INTO each fact row, so changing it later
                 * re-buckets new rows while history keeps the old boundaries --
                 * and nothing records which zone a row was derived under. The
                 * shift can be up to 21 hours. Choosing it once, before any data
                 * exists, is the only time the choice is free.
                 *
                 * These two conf files exist only to declare their arrays.
                 */
                /** @var array<string, array<string>> $timezones */
                /** @var array<string, string> $countryCode2Name */
                require_once(OWA_DIR.'conf/country2Timezones.php');
                require_once(OWA_DIR.'conf/countryCodes2Names.php');

                // Falls back to the CONFIGURED default, not to empty. 'defaults'
                // is only populated when this form re-renders after a validation
                // error, so on the first render an empty fallback would preselect
                // whichever zone happens to sort first -- silently choosing for
                // the operator, which is the failure this field exists to prevent.
                $current = $view->defaults['timezone']
                    ?? \OWA\Core\CoreAPI::getSetting('base', 'timezone');
                $selected_already = false;
                ksort($timezones);

                foreach ( $timezones as $country => $zones ) {

                    $country_name = isset($countryCode2Name[$country])
                        ? $countryCode2Name[$country]
                        : 'unknown - '.$country;

                    echo sprintf('<optgroup label="%s">', htmlspecialchars($country_name));

                    foreach ( $zones as $value ) {

                        $display_value = str_replace('_', ' ', $value);

                        if ( ! $selected_already && $current === $value ) {
                            $selected_already = true;
                            echo sprintf('<option selected="yes" value="%s">%s</option>',
                                htmlspecialchars($value), htmlspecialchars($display_value));
                        } else {
                            echo sprintf('<option value="%s">%s</option>',
                                htmlspecialchars($value), htmlspecialchars($display_value));
                        }
                    }

                    echo '</optgroup>';
                }
                ?>
                </select>
            </span>
            <span class="form-instructions">Statistics are bucketed into days using this
            timezone. <strong>Changing it later is not retroactive</strong> — existing data
            keeps the day boundaries it was recorded with.</span>
        </p>

        <p class="form-row">
            <span class="form-label">Your Admin Name</span>
            <span class="form-field">
                <input type="text"size="30" name="<?php echo $view->getNs();?>user_id" value="<?php $view->out( $view->defaults['user_id'] );?>">
            </span>
            <span class="form-instructions">This is name of the admin user.</span>
        </p>

        <p class="form-row">
            <span class="form-label">Your E-mail Address</span>
            <span class="form-field">
                <input type="text"size="30" name="<?php echo $view->getNs();?>email_address" value="<?php $view->out( $view->defaults['email_address'] );?>">
            </span>
            <span class="form-instructions">This is the e-mail address of the admin user.</span>
        </p>
        
        <p class="form-row">
            <span class="form-label">Your Password</span>
            <span class="form-field">
                <input type="password"size="30" name="<?php echo $view->getNs();?>password" value="">
            </span>
            <span class="form-instructions">This will be the password of the admin user.</span>
        </p>
                
        <p>
            <?php echo $view->createNonceFormField('base.installBase');?>
            <input type="hidden" value="base.installBase" name="<?php echo $view->getNs();?>action">
            <input class="owa-button" type="submit" value="Continue..." name="<?php echo $view->getNs();?>save_button">
        </p>
        
    </form>
    
</div>
    