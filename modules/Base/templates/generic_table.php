<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if (!empty($view->rows)): ?>

<script>

jQuery(document).ready(function() { 
    jQuery("#<?php echo $view->table_id;?>").tablesorter();
}); 
    
</script>

<table class="<?php echo $view->sort_table_class;?> <?php echo $view->table_class;?>" summary="" id="<?php echo $view->table_id;?>">
    <?php if (!empty($caption)): ?>
    <caption><?php echo $caption;?></caption>
    <?php endif;?>
    <thead>
        <TR>
            <?php if (!empty($view->labels)):?>
            <?php foreach ($view->labels as $label): ?>
            <TH scope="<?php echo $th_scope;?>"><?php echo $label;?></TH>
            <?php endforeach;?>
            <?php endif;?>
        </TR>
    </thead>
    <?php if (!empty($view->table_footer)): ?>
    <tfoot>
        <td colspan="<?php echo $view->col_count;?>"><?php echo $view->table_footer;?></td>
    </tfoot>
    <?php endif;?>
    <tbody>
        <?php foreach ($view->rows as $row):?>
        <TR>
            <?php if (!empty($view->table_row_template)): ?>
            <?php include($view->setTemplate($view->table_row_template));?>
            <?php else: ?>
            <?php foreach ($row as $item): ?>
            <TD><?php echo $item;?></TD>
            <?php endforeach;?>
            <?php endif;?>
        </TR>
        <?php endforeach;?>
    </tbody>
</table>

<?php else: ?>
    <?php if ($view->show_error):?>
    <div class="owa_status-msg">No data to display.</div>
    <?php endif;?>
<?php endif;?>