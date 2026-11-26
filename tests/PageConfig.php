<?php

/**
 * What config_dom.php hands the reporting bundle: its JSON block, rendered
 * and decoded (OWA.readPageConfig() reads the same block).
 *
 * @return array{html: string, config: array<string, mixed>}
 */
function owa_test_page_config(): array
{
    ob_start();

    try {
        include \OWA\Core\Env::root() . '/modules/Base/templates/config_dom.php';
    } finally {
        $html = (string) ob_get_clean();
    }

    preg_match( '~<script type="application/json" data-ui="owa:config">(.*?)</script>~s', $html, $m );

    return [ 'html' => $html, 'config' => (array) json_decode( $m[1] ?? '', true ) ];
}
