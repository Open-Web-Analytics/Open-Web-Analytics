<style>

.widget-container {border:1px solid #cccccc; width:100%;}
.widget-header {color: white;background-color:orange; padding:10px;text-align:left; font-weight:bold; font-size:18px;}
.widget-title {font-size:20px;text-align:right;}
.widget-title a {color: white;}
.widget-title-controls {font-size:14px;text-align:right;}
.widget-controls {text-align:right;padding:10px}
.widget-content {padding:10px;}

</style>


<script>
var state = new Object;
state['<?=$widget;?>'] = new Object;
state['<?=$widget;?>'].url = "http://wp25-php5-test.openwebanalytics.com/index.php?owa_specialAction&owa_do=base.dashboardTrendWidget&owa_period=last_thirty_days&owa_format=";

</script>

<script> 
	//var widgeturl = "http://wp25-php5-test.openwebanalytics.com/index.php?owa_specialAction&owa_do=base.dashboardTrendWidget&owa_period=last_thirty_days&owa_format=";
	var widgeturl = state['<?=$widget;?>'].url;
	
  // When the document loads do everything inside here ... 
     jQuery(document).ready(function(){ 
     //$('#content').load('boo.php'); //by default initally load text from boo.php 
		jQuery('.widget-control').click(function() { //start function when any link is clicked
			var parentname = jQuery("div").parent(".widget-container").attr("id");
			var widgetname2 = parentname.split("_");
			var widgetname = widgetname2[0];
			var widgetcontentid = "#"+widgetname+"_widget-content";
			var format = jQuery(this).attr("name");
			//alert(widgetcontentid);
			jQuery(widgetcontentid).slideUp("slow"); 
			 //var content_show = $(this).attr("title"); //retrieve title of link so we can compare with php file 
			jQuery.ajax({ 
				method: "get",url: widgeturl+format, 
				beforeSend: function(){ jQuery(".widget-status").show("fast");}, //show loading just when link is clicked 
				complete: function(){ jQuery(".widget-status").hide("fast");}, //stop showing loading when the process is complete 
				success: function(html){ //so, if data is retrieved, store it in html 
					jQuery(widgetcontentid).show("slow"); //animation 
					jQuery(widgetcontentid).html(html); //show the html inside .content div 
		 		} 
	 		}); //close $.ajax( 
         }); //close click( 
     }); //close $( 
</script> 


<div id="<?=$widget;?>_widget-container" class="widget-container">

	<div id="<?=$widget;?>_widget-header"class="widget-header">
		<span class="widget-title"><?=$title;?></span>

		<div id="<?=$widget;?>_widget-title-controls" style="float:right;">
			<span class="widget-title-controls"><a href="">Close</a></span>
		</div>
		
	</div>

	<div id="<?=$widget;?>_widget-status" class="widget-status">LOADING</div> 

	<div id="<?=$widget;?>_widget-content" class="widget-content"><?=$subview;?></div>
	
	<div id="<?=$widget;?>_widget-controls" class="widget-controls">
		<a class="widget-control" href="#base-dashboardTrendWidget_widget-header" name="graph">Graph</a> | 
		<a class="widget-control" href="#base-dashboardTrendWidget_widget-header" name="table">Table</a> | 
		<a class="widget-control" href="#base-dashboardTrendWidget_widget-header" name="sparkline">Sparkline</a>
	</div>

	
</div>
