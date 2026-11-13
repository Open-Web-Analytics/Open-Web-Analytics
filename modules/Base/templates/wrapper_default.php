<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<?php $cu = $view->getCurrentUser(); ?>
<html xmlns="http://www.w3.org/1999/xhtml" lang="<?php $view->out( \OWA\Core\Strings::htmlLang() ); ?>" xml:lang="<?php $view->out( \OWA\Core\Strings::htmlLang() ); ?>">

    <head>
        <title>Open Web Analytics - <?php echo $view->page_title ?? '';?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <?php include($view->getTemplatePath('base','head.php'));?>
    </head>

    <body class="<?php if ($cu->user->isOWAAdmin()) echo 'owaadmin'; ?>">
        <style>
            html {background-color: #ffffff;}
        </style>

        <div class="owa">
        <?php
            // Drawn first, as before; printed after the skip link, which needs the body.
            ob_start();
            include($view->getTemplatePath('base', 'header.php'));
            $owa_header = ob_get_clean();
        ?>

        <?php
            /*
             * The page's messages and the scheduler warning. A page with a left
             * column marks where its content starts (View::BANNER_SLOT), and
             * they go there, so they do not push the navigation down. Any other
             * page gets them above its body.
             */
            ob_start();
            include($view->getTemplatePath('base', 'msgs.php'));
            include($view->getTemplatePath('base', 'scheduler_nag.php'));
            $owa_banners = ob_get_clean();

            $owa_body = (string) $view->body;
            $owa_slot = strpos( $owa_body, \OWA\Core\View::BANNER_SLOT );

            if ( $owa_slot !== false ) {

                // Wrapped only when there is something to show, so a page with
                // no message keeps its layout exactly.
                $owa_in_column = trim( $owa_banners ) === ''
                    ? '' : '<div class="owa_columnBanners">' . $owa_banners . '</div>';

                $owa_body    = substr_replace( $owa_body, $owa_in_column, $owa_slot, strlen( \OWA\Core\View::BANNER_SLOT ) );
                $owa_banners = '';
            }

            /*
             * The footer, at the foot of the content column on a page that
             * marks one (View::FOOTER_SLOT), and below the page otherwise.
             */
            ob_start();
            include($view->getTemplatePath('base', 'footer.php'));
            $owa_footer = ob_get_clean();

            $owa_slot = strpos( $owa_body, \OWA\Core\View::FOOTER_SLOT );

            if ( $owa_slot !== false ) {
                $owa_body   = substr_replace( $owa_body, $owa_footer, $owa_slot, strlen( \OWA\Core\View::FOOTER_SLOT ) );
                $owa_footer = '';
            }

            // A page with a <main id="owa_main"> (report.php, options_hierarchy.php) gets a link to it ahead of the header.
            if ( strpos( $owa_body, 'id="owa_main"' ) !== false ) {
                echo '<a class="owa_skipLink" href="#owa_main">Skip to content</a>';
            }

            echo $owa_header;
            echo $owa_banners;
            echo $owa_body;
            echo $owa_footer;
        ?>
        </div>
    </body>

</html>