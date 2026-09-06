<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The installer's frame.
 *
 * A fixed 800px block with a centred <h1> and a <br>. The card and the column
 * come from the signed-out page styles in owa.css, which this wrapper already
 * loads -- the installer is one of the screens that uses wrapper_public.php.
 */
?>
<div class="owa_publicCard owa_installCard">
    <h1 class="owa_publicTitle">Install Open Web Analytics</h1>
    <div class="owa_installBody"><?php echo $view->subview;?></div>
</div>
