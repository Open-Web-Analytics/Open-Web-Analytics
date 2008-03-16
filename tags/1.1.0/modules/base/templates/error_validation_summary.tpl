<span class="inline_h2">The form that you completed had some errors:</span>
<UL>
<? foreach ($validation_errors as $k => $v): ?>
<LI><?=$v;?></LI>
<? endforeach;?>
</UL>