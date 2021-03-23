<?php if (!empty($rows)): ?>

<script>

jQuery(document).ready(function() { 
    jQuery("#<?php echo $table_id;?>").tablesorter();
}); 
    
</script>

<table class="<?php echo $sort_table_class;?> <?php echo $table_class;?>" summary="" id="<?php echo $table_id;?>">
    <?php if (!empty($caption)): ?>
    <caption><?php echo $caption;?></caption>
    <?php endif;?>
    <thead>
        <TR>
            <?php if (!empty($labels)):?>
            <?php foreach ($labels as $label): ?>
            <TH scope="<?php echo $th_scope;?>"><?php echo $label;?></TH>
            <?php endforeach;?>
            <?php endif;?>
        </TR>
    </thead>
    <?php if (!empty($table_footer)): ?>
    <tfoot>
        <td colspan="<?php echo $col_count;?>"><?php echo $table_footer;?></td>
    </tfoot>
    <?php endif;?>
    <tbody>
        <?php foreach ($rows as $row):?>
        <TR>
            <?php if (!empty($table_row_template)): ?>
            <?php include($this->setTemplate($table_row_template));?>
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
    <?php if ($show_error):?>
    <div class="owa_status-msg">No data to display.</div>
    <?php endif;?>
<?php endif;?>