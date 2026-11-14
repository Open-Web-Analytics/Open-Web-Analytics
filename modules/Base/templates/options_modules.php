<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>
<div id="panel">

<?php if (!empty($view->modules)): ?>

<table width="100%" id="module_roster" class="management">
    <thead>
    <TR>
        <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Module' ) ); ?></TH>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Current Schema' ) ); ?></th>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Required Schema' ) ); ?></th>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Schema Up to Date?' ) ); ?></th>
        <TH></TH>
    </TR>
    </thead>
    <tbody>
    <?php foreach ($view->modules as $k => $v): ?>

    <TR>
        <TD>
        <B><?php echo $v['display_name'];?></B><BR>
        <?php echo $v['description'];?>
        </TD>
        <TD><?php echo $v['current_schema_version'];?></TD>
        <TD><?php echo $v['required_schema_version'];?></TD>
        <TD><?php echo $v['schema_uptodate'];?></TD>
        <TD class="">
        <?php if ($v['name'] != 'base'): ?>
        <?php if (! empty($v['activated_by'])): ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Active while %s is set' ), '<code>' . htmlspecialchars( (string) $v['activated_by'], ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?>
        <?php elseif (isset($v['status']) && $v['status'] == 'active'): ?>
            <a href="<?php echo $view->makeLink( array('do' => 'base.moduleDeactivate', 'module' => $v['name']), false, false, false, true );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Deactivate' ) ); ?></a>
        <?php else: ?>
            <a href="<?php echo $view->makeLink( array('do' => 'base.moduleActivate', 'module' => $v['name']), false, false, false, true );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Activate' ) ); ?></a>
        <?php endif; ?>
        <?php endif;?>

        </TD>
    </TR>

    <?php endforeach; ?>
    </tbody>
</table>

<?php else: ?>

<?php $view->out( \OWA\Core\CoreAPI::t( 'There are no additional modules installed.' ) ); ?>

<?php endif;?>

</div>