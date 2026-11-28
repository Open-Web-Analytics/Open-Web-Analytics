<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * What was checked, and what each check found.
 *
 * Every check is listed, passed or failed, so a check that passed can be told
 * from one that was never made; the ones that failed carry the fix. The layout
 * rules live in owa.css.
 */
$owa_failed = count( (array) $view->errors );
?>
<p class="owa_publicIntro">
<?php $view->out( sprintf( \OWA\Core\CoreAPI::tn( 'One thing needs fixing before OWA can be installed. Sort it out and reload this page.',
    '%d things need fixing before OWA can be installed. Sort them out and reload this page.', $owa_failed ), $owa_failed ) ); ?>
</p>

<ul class="owa_installChecks">
<?php foreach ( (array) $view->checks as $owa_check ): ?>
<?php $owa_warning = (string) ( $owa_check['warning'] ?? '' ); ?>
    <li class="owa_installCheck <?php echo ! $owa_check['passed'] ? 'is-bad' : ( $owa_warning !== '' ? 'is-warn' : 'is-ok' );?>">
        <span class="owa_installCheckMark" aria-hidden="true"><?php
            echo ! $owa_check['passed'] ? '&#10007;' : ( $owa_warning !== '' ? '!' : '&#10003;' );?></span>
        <span class="owa_installCheckName"><?php $view->out( $owa_check['name'] );?></span>
        <span class="owa_installCheckValue"><?php $view->out( $owa_check['value'] );?></span>
        <?php if ( ! $owa_check['passed'] ): ?>
        <span class="owa_installCheckFix"><?php $view->out( $owa_check['msg'] );?></span>
        <?php elseif ( $owa_warning !== '' ): ?>
        <span class="owa_installCheckFix"><?php $view->out( $owa_warning );?></span>
        <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
