<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$summary_stats['page_views'];?> page views for this web site.</span></p> 

<? include('report_dashboard_summary_stats.tpl');?>

<? include('report_top_pages.tpl');?>

<img src="<?=$this->graphLink(array('view' => 'base.graphPageTypes'), true); ?>">
			