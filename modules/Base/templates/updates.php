<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The signed-out card the login and install screens use, at the installer's
 * width. It was an 800px box of its own, wider than a phone.
 *
 * Only reached with updates pending: base.updates redirects to the start page
 * when there are none.
 */
?>
<div class="owa_publicCard owa_updatesCard">

    <h1 class="owa_publicTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Open Web Analytics Updater' ) ); ?></h1>

    <p class="owa_publicIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'Some modules need to create or update their database tables.' ) ); ?></p>

    <h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Modules with updates to apply' ) ); ?></h2>

    <ul class="owa_updatesModules">
        <?php foreach ($view->modules as $module): ?>
            <li><?php $view->out( $module ); ?></li>
        <?php endforeach;?>
    </ul>

    <p class="owa_publicIntro"><i><?php $view->out( \OWA\Core\CoreAPI::t( 'It is recommended that you back up your database before applying updates.' ) ); ?></i></p>

    <div class="owa_installActions">
        <?php // No nonce: base.updatesApply takes none, see UpdatesApply (#979). ?>
        <a class="owa-button" href="<?php echo $view->makeLink(array('do' => 'base.updatesApply'));?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Apply updates' ) ); ?></a>
    </div>

</div>
