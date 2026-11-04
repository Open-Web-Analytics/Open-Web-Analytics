<?php
/**
 * The campaign an untagged visit is given, by its untagged medium, from
 * Cube\CampaignStep::PLACEHOLDERS and FALLBACK.
 */

use OWA\Module\Base\Classes\Cube\CampaignStep;

return function () {

    $out = "| Untagged medium | Campaign |\n|---|---|\n";

    foreach ( CampaignStep::PLACEHOLDERS as $medium => $campaign ) {
        $out .= sprintf( "| `%s` | `%s` |\n", $medium, $campaign );
    }

    return $out . sprintf( "| any other | `%s` |\n", CampaignStep::FALLBACK );
};
