<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * What was checked, and what each check found.
 *
 * This listed the FAILURES only, under "Uh-oh. We found a few issues." -- so
 * there was no way to tell a check that passed from one that was never made,
 * which on a screen describing the environment is most of what you want to
 * know. Every check is listed, and the ones that failed carry the fix.
 *
 * The <style> block that used to be here positioned the columns absolutely at
 * left:620px and left:850px; the rules live in owa.css with the rest now.
 */
$owa_failed = count( (array) $view->errors );
?>
<p class="owa_publicIntro">
<?php if ( $owa_failed === 1 ): ?>
One thing needs fixing before OWA can be installed. Sort it out and reload this page.
<?php else: ?>
<?php $view->out( (string) $owa_failed ); ?> things need fixing before OWA can be
installed. Sort them out and reload this page.
<?php endif; ?>
</p>

<ul class="owa_installChecks">
<?php foreach ( (array) $view->checks as $owa_check ): ?>
    <li class="owa_installCheck <?php echo $owa_check['passed'] ? 'is-ok' : 'is-bad';?>">
        <span class="owa_installCheckMark" aria-hidden="true"><?php
            echo $owa_check['passed'] ? '&#10003;' : '&#10007;';?></span>
        <span class="owa_installCheckName"><?php $view->out( $owa_check['name'] );?></span>
        <span class="owa_installCheckValue"><?php $view->out( $owa_check['value'] );?></span>
        <?php if ( ! $owa_check['passed'] ): ?>
        <span class="owa_installCheckFix"><?php $view->out( $owa_check['msg'] );?></span>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
