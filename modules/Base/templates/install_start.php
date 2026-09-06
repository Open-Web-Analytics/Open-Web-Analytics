<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p class="owa_publicIntro">The next few screens set up the framework: a check of this
server, your database details, and an account to sign in with. If you need help, the
<a href="<?php $view->out( $this->config['wiki_url'] );?>">documentation wiki</a> covers
each step.</p>

<?php
    /*
     * The href was unquoted -- href=<?php echo ... ?> -- so any URL with a
     * character needing quoting broke the tag.
     */
?>
<p>
    <a class="owa-button owa_publicSubmit"
       href="<?php echo $view->makeLink( array( 'action' => 'base.installCheckEnv' ) );?>">Get started</a>
</p>
