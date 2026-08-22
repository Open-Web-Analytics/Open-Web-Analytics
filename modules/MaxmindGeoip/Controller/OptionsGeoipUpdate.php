<?php

namespace OWA\Module\MaxmindGeoip\Controller;

/**
 * Saves the GeoIP settings and returns to the GeoIP settings page.
 *
 * All of the work is inherited. The only thing this adds is knowing where to
 * go afterwards, which is the one thing the shared save controller cannot know:
 * it is a single action that every settings form in OWA posts to, so on its own
 * it can only send everyone to the same place.
 *
 * A controller per page rather than a field in the form, because the
 * destination is then a fact about the code instead of a value posted by the
 * browser. Nothing needs validating, nothing can be tampered with, and it reads
 * the way the rest of OWA does -- every other controller passes a literal to
 * setRedirectAction().
 */
class OptionsGeoipUpdate extends \OWA\Module\Base\Controller\OptionsUpdate {

    protected function returnAction() {

        return 'maxmind_geoip.optionsGeoip';
    }

    /**
     * This form edits this module's settings and nothing else.
     *
     * The module a setting is saved under comes from the field name --
     * config[module.setting] -- so without this it is the browser that decides,
     * and a field posted to this page could write a setting belonging to
     * somewhere else entirely.
     */
    protected function allowedModule() {

        return 'maxmind_geoip';
    }
}
