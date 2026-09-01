<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The wrapper for the Organization / Property / Profile edit screens.
 *
 * Same two columns as options.php, but the left one carries the SITE CONTROL
 * rather than the settings panels. These screens are reached from that control
 * and belong to the hierarchy, not to the install's settings -- putting the
 * settings menu beside them would offer a way out that has nothing to do with
 * where you are.
 *
 * The settings nav stays for install-wide settings, reached from the top nav.
 */
?>
<table width="100%" cellpadding="0" cellspacing="0" class="owa_hierarchyPage">
    <TR>
        <TD valign="top" class="owa_reportLeftNavColumn">
            <?php include('site_control.php');?>
            <?php include('hierarchy_nav.php');?>
        </TD>
        <TD class="layout_subview owa_hierarchyContent" valign="top"><?php echo $view->subview;?></TD>
    </TR>
</table>
