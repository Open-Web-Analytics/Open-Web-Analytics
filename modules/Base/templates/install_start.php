<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p class="owa_publicIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'The next few screens set up the framework: a check of this server, your database details, and an account to sign in with.' ) );?>
<?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'If you need help, the %s covers each step.' ),
    '<a href="' . htmlspecialchars( (string) $view->owaTemplate()->config['wiki_url'], ENT_QUOTES, 'UTF-8' ) . '">'
    . htmlspecialchars( \OWA\Core\CoreAPI::t( 'documentation wiki' ), ENT_QUOTES, 'UTF-8' ) . '</a>' );?></p>

<?php
    /*
     * The href was unquoted -- href=<?php echo ... ?> -- so any URL with a
     * character needing quoting broke the tag.
     */
?>
<p>
    <a class="owa-button owa_publicSubmit"
       href="<?php echo $view->makeLink( array( 'action' => 'base.installCheckEnv' ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Get started' ) );?></a>
</p>
