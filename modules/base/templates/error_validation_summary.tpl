<span class="inline_h2">The form that you completed had some errors:</span>
<UL>
<?php foreach ($validation_errors as $k => $v): ?>
<LI><?php echo $v;?></LI>
<?php endforeach;?>
</UL>