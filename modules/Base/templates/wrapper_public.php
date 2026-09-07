<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The signed-out pages: login, password reset, set password, install, updates.
 *
 * This was a table with a spacer column, a run of <BR> tags and a 150px JPEG
 * logo, which is why these screens looked like a different application from the
 * one they let you into. It is a centred column now, and the card the forms sit
 * in is styled in owa.css beside everything else.
 *
 * Core\View loads base/css/owa.css on every view, so these pages already have a
 * stylesheet -- they were simply not using it.
 */
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title><?php if (isset($view->page_title)) { $view->out( $view->page_title . ' - '); } ?>Open Web Analytics</title>
        <?php include($view->getTemplatePath('base','head.php'));?>
    </head>

    <body>

        <div class="owa owa_publicPage">

            <?php
                /*
                 * The same logo the signed-in header uses, from the same
                 * setting, rather than a second hard-coded file -- an install
                 * that has set its own was showing it everywhere except on the
                 * way in.
                 */
            ?>
            <?php $owa_logo = $view->makeImageLinkIfPresent( \OWA\Core\CoreAPI::getSetting( 'base', 'logo_image_path' ) ); ?>
            <div class="owa_publicLogo">
                <?php if ( $owa_logo ): ?>
                    <img src="<?php echo $owa_logo; ?>" alt="Open Web Analytics">
                <?php else: ?>
                    <span class="owa_publicLogoText">Open Web Analytics</span>
                <?php endif; ?>
            </div>

            <div class="owa_publicMain">
                <?php include($view->setTemplate('msgs.php'));?>
                <?php if (isset($content)) { echo $content; }?>
                <?php echo $view->body;?>
            </div>

            <div class="owa_publicFooter">
                <a href="http://www.openwebanalytics.com">Web Analytics</a>
                powered by <a href="http://www.openwebanalytics.com">Open Web Analytics</a>
            </div>
        </div>

    </body>

</html>
