<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


/**
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesEditSettings extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);
        $this->setRequiredCapability('edit_sites');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that siteId is present
        $this->addValidation('siteId', $this->getParam('siteId'), 'required');

        // Check site exists
        $siteEntityConf = [
            'entity'    => 'base.site',
            'column'    => 'site_id',
            'errorMsg'  => $this->getMsg(3208)
        ];

        $this->addValidation('siteId', $this->getParam('siteId'), 'entityExists', $siteEntityConf);
    }

    function action() {

        $site_id = $this->getParam( 'siteId' );

        $new_settings = $this->getParam( 'config' );

        if ($new_settings) {

            /*
             * One scoped row per key, rather than one merged blob on owa_site.
             *
             * The merge was load-bearing when everything lived in one column:
             * a form posting three keys had to not erase the other twenty.
             * Per-key rows give that for free, and give two things the blob
             * could not -- a stored false that survives (the blob's writer
             * prunes a value equal to the code default) and inheritance from
             * the Property above.
             */
            $saved = true;

            foreach ( $new_settings as $name => $value ) {

                $inherited   = $this->inheritedValue( $site_id, 'base', $name );
                $hasOverride = \OWA\Core\CoreAPI::getScopedSettingRow(
                                   'profile', $site_id, 'base', $name ) !== null;

                switch ( self::overrideAction( $value, $inherited, $hasOverride ) ) {

                    case 'set':

                        if ( ! \OWA\Core\CoreAPI::setScopedSetting(
                                  'profile', $site_id, 'base', $name, $value ) ) {

                            $saved = false;
                        }
                        break;

                    case 'clear':

                        if ( ! \OWA\Core\CoreAPI::clearScopedSetting(
                                  'profile', $site_id, 'base', $name ) ) {

                            $saved = false;
                        }
                        break;

                    // 'none': the posted value already matches what this
                    // Profile inherits and it holds no row of its own. Writing
                    // one would pin the value silently.
                }
            }

            // Only on a clean write, as before: the success message used to be
            // conditional on $site->update() returning true.
            if ( $saved ) {

                $this->setStatusCode( 3201 );
            }

            $this->set('siteId', $site_id);
            $this->set('edit', true);
            $this->setRedirectAction( 'base.sitesProfile' );
        }
    }

    /**
     * What to do with one posted setting: write an override, remove one, or
     * leave the Profile inheriting.
     *
     * The screen renders EFFECTIVE values -- a key this Profile does not set
     * shows the Property's, Organization's or install's value, which is the
     * point of the screen. So every save posts the whole form back, inherited
     * values included, and writing each one as an override meant that opening
     * the screen and pressing Save with no changes detached the Profile from
     * everything above it. Silently, and with no way back: clearScopedSetting()
     * existed for exactly that and nothing called it.
     *
     * Pure, and separate from the loop, because this is the decision worth
     * testing and it needs no database to make.
     *
     * @param  mixed $posted      the value the form sent
     * @param  mixed $inherited   what this scope would see with no row of its own
     * @param  bool  $hasOverride whether this scope currently holds a row
     * @return string 'set' | 'clear' | 'none'
     */
    public static function overrideAction( $posted, $inherited, $hasOverride ) {

        if ( ! self::isSameSettingValue( $posted, $inherited ) ) {

            return 'set';
        }

        return $hasOverride ? 'clear' : 'none';
    }

    /**
     * Whether two setting values mean the same thing.
     *
     * A form posts strings -- '0', '1', '30' -- against stored values that may
     * be int, bool or string, so this cannot be ===. It is the same rule
     * Settings::isEquivalentToDefault() uses for the same reason: loose, except
     * between two strings, where loose would compare '1e2' and '100' as equal.
     */
    private static function isSameSettingValue( $a, $b ) {

        if ( is_string( $a ) && is_string( $b ) ) {

            return $a === $b;
        }

        return $a == $b;
    }

    /**
     * What this Profile would see for a setting if it held no row of its own.
     *
     * Walks the scope chain from the tier ABOVE the Profile -- its Property,
     * then the Organization -- and falls back to the install-wide value. Not
     * getEffectiveSettings(), which includes the Profile's own override and so
     * would always compare a value against itself.
     */
    private function inheritedValue( $site_id, $module, $name ) {

        $chain = (array) \OWA\Core\CoreAPI::settingScopeChain( 'profile', $site_id );

        foreach ( array_slice( $chain, 1 ) as $scope ) {

            $row = \OWA\Core\CoreAPI::getScopedSettingRow(
                       $scope['type'], $scope['id'], $module, $name );

            if ( $row !== null ) {

                return $row;
            }
        }

        return \OWA\Core\CoreAPI::getSetting( $module, $name );
    }

    function errorAction() {

        /*
         * The hierarchy wrapper. There is one settings nav now -- the old
         * base.options menu is gone -- so every settings screen carries the tile
         * and the tier groups, module screens included.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.sitesProfile');
        /*
         * 3002 -- "the form had errors" -- not 3311.
         *
         * 3311 has been the CLI-updates message since 2010, and this screen has
         * set 3311 since 2009: the later commit took a code three form screens
         * were already using. Latent rather than visible, because
         * Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none --
         * so the CLI text was suppressed on the ordinary path and would have
         * appeared the moment that branch changed.
         */
        $this->set('error_code', 3002);
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $owa_site_id ) );
        $this->set('site', $site->_getProperties());

        /*
         * The values the form sent, over the ones it would have rendered.
         *
         * This was $this->params -- the raw request bag, which holds siteId,
         * do, nonce and nothing the form edits, because the fields arrive
         * nested under config[]. So a validation failure redrew every field
         * from an array that did not contain it: the selects fell to their
         * first option and the numbers to their defaults. Correcting the one
         * reported error and pressing Save again then wrote those defaults
         * over everything else on the screen.
         *
         * Merged rather than replaced so a field the form did not send still
         * shows what the Profile actually observes with.
         */
        $this->set( 'config', array_merge(
            (array) \OWA\Core\CoreAPI::getEffectiveSettings( 'profile', $owa_site_id, 'base' ),
            (array) $this->getParam( 'config' )
        ) );

        // Also the resolved id, so the form posts back to the site it is showing.
        $this->set( 'siteId', $owa_site_id );
    }
}

?>