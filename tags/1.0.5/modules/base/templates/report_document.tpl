<? include('report_header.tpl');?>

<?=$this->makeNavigation($nav);?>	  

<? include('report_document_summary_stats.tpl');?>

<? include('report_document_core_metrics.tpl');?>

<div class="section_header inline_h3">Top Referring Web Sites</div>

<? include('report_top_referers.tpl');?>