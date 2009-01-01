<style>

/* HTML ENTITIES*/
body {border-color:#cccccc; background-color:; font-family:Helvetica,'Arial'; padding:0; margin: 0;}
th {padding:6px 6px 6px 6px; text-align:left;}
td {padding: 2px 6px 2px 6px;}
legend {font-size:16px;font-weight:bold;}
fieldset{margin: 7px; border:1px solid #cccccc;}
div {margin:0;}

/* COLORS */
.red {background-color:red;}
.yellow {background-color:yellow;}
.green {background-color:green; color:#ffffff;}

/* NAVIGATION */
#sub_nav {padding:5px; background-color:#cccccc; width=100%; }
.top_level_nav{font-size:20px;}
.nav_links {list-style:none; margin:0px; padding:0px; }
.nav_links li {float: left; padding:4px 20px 4px 20px;}
.nav_links li a {text-decoration: none; }
.nav_links ul {clear: both;}
.post-nav {clear: both; margin:0px; padding:0px 0px 5px 0px;}
.active_nav_link {background-color:#cccccc;}
.host_app_nav {background-color:; vertical-align:middle;font-size:18px;padding:4px;}
#owa_header {border-bottom: 3px solid orange;}
.owa_navigation {float:left; overflow: hidden;}
.owa_navigation ul {list-style: none; padding: 0; margin: 0;}
.owa_navigation li {text-decoration: none; float:left; margin: 2px;}
.owa_navigation li a {
	background: url(background.gif) #fff bottom left repeat-x;
	height: 2em;
	line-height: 2em;
	float: left;
	width: 9em;
	display: block;
	border: 0.1em solid #efefef;
	color: ;
	text-decoration: none;
	text-align: center;
}

.owa_pagination {float:left; overflow: hidden;}
.owa_pagination ul {list-style: none; padding: 0; margin: 0;}
.owa_pagination li {text-decoration: none; float:left; margin: 2px;}
.owa_pagination li {
	background: url(background.gif) #fff bottom left repeat-x;
	height:2em;
	line-height:2em;
	float: left;
	width: 15px;
	display: block;
	border: 0.1em solid #efefef;
	color: ;
	text-decoration: none;
	text-align: center;
}

.owa_headerServiceMsg {border: 1px solid #efefef;border-left: 8px solid yellow; height: 25px; width: auto; padding:10px}


/* HEADLINES */

.inline_h1 {font-size:24px; font-weight:bold;}
.inline_h2 {font-size:20px;}
.inline_h2_grey {font-size:20px; color:#cccccc;}
.inline_h3 {font-size:16px;}
.inline_h4 {font-size:14px;}
.headline {font-size:20px; background-color:#E0EEEE;color:;border-color:#000000;padding:6px; font-weight:bold;margin: 0px 0px 0px 0px;}
.panel_headline {font-size:18px; background-color:#FFF8DC;padding:10px;font-weight:bold;margin: 0px 0px 20px 0px;border-bottom:solid 1px}
.sub-legend {font-size:16px;font-weight:bold; }

/* DATA TABLES */

.h_label {font-size:14px; font-weight:bold;}
.indented_header_row {padding:0px 0px 0px 20px;}
#layout_panels {border:1px solid;border-collapse: collapse; width:100%; vertical-align:top;}
#layout_panels td {border:1px solid;border-collapse: collapse; vertical-align:top;}
#panel {border-collapse: collapse; width:;border:0px;padding:10px; vertical-align:top;}
#panel td {margin: 0px; padding-top:0px;width:;border-collapse: collapse;border:0px;}
.layout_subview {margin: 0px; padding:0px;border-collapse: collapse;}
.subview_content{padding:10px;}
.subview_content td {padding:20ps;}
#nav_left {width:240px;}
.data {white-space:; width:auto;}
.form {width:;}
.sub-row {padding-left:20px; font-weight:normal;}
#summary_stats {font-size:16px; font-weight: normal;}

/* FORMATING */

.active_wizard_step {background-color:#1874CD; color:#ffffff;border:1px solid; padding:5px; font-weight:bold; font-size:16px;}
.wizard_step {font-weight:bold; font-size:16px;}
.visitor_info_box {width:40px; height:40px; text-align:center; padding:7px;}
.owa_visitSummaryLeftCol {width:auto;}
.owa_visitSummaryRightCol {padding-left:15px;width:auto; vertical-align: top;}
.visit_icon {width:40px;}
.graph {padding:10px; text-align:center;}
.comments_info_box {
	padding:4px 4px 4px 4px;
	border:solid 0px #999999; 
	margin:0px 2px 2px 2px;
	width:40px;
	height:40px;
	background-image: url('<?=$this->makeImageLink('comment_background.jpg');?>');
	background-repeat: no-repeat;
	text-align:center;
}
.visit_summary {width:100%;}
.date_box {padding:4px;	border:solid 1px #999999;margin:2px;}
.pages_box {padding:5px; border:solid 2px #999999; margin:0px 0px 0px 0px; text-align:center;}
.large_number {font-size:24px; font-weight:bold;}
.info_text {color:#999999;font-size:12px;}
.legend_link {color:#999999;font-size:12px;font-weight:normal;}
.legend_link a {text-decoration:underline;}
.centered_buttons {margin-left:auto;margin-right:auto;}
.snippet_text {color:;font-size:12px;}
.snippet_text a {color:#999999;}
.snippet_anchor {font-size:14px;font-weight:bold;}
.visit_box_stat {width:42px;}
.nav_bar{text-decoration:none;}		
.id_box{background-color:green;color:#ffffff;font-style:bold;font-size:18px;padding:6px;}
.code {padding:7px;margin:0px 30px 0px 30px;background-color:; border: 1px dashed blue; font-size:10px;}
.top_level_nav_link{padding:0px 5px 0px 5px; font-size:22px;}
.visible {display:;}
.invisible {display:none;}
.status {color: #ffffff; border: 2px solid #000000; margin:20px 40px 20px 40px; padding: 20px 10px 20px 10px; background-color: green; font-size: 14px; font-weight:bold;}
.error{color: #ffffff; border: 2px solid #000000; margin:20px 40px 20px 40px; padding: 20px 10px 20px 10px; background-color: red; font-size: 14px; font-weight:bold;}
.tiny_icon{width:10px;padding-left:0px;}
.wrap {margin:0px;padding:10px;}
.validation_error {color:red;}

/* Admin Settings */
.setting {padding:5px;border:1px solid #cccccc; margin:1px;}
.setting .description {border:0px solid #cccccc; font-size:10px; padding: 2px 0 2px 0;}
.setting .title {font-weight:bold; font-size:16px; padding: 2px 0 2px 0;}
.setting .field {padding: 2px 0 2px 0;}





/* LAYOUT */
#summary_stats {border-collapse: collapse; width:100%;}
#summary_stats td {border:2px solid #CCCCCC; padding:10px; height:65px; width:px; vertical-align:center; margin:0px; min-width:150px;}
#trend_graph {text-align:center;}
#admin_nav{font-size:12px;}	
#keywords{width:400px;}
#login_box {width:390px;}
#owa_header {background-color:#FFFFFF; padding:4px; font-weight:bold; clear: both;}
#report_top_level_nav {margin: 5px 0 0 0;}
#side_bar {width:auto; color: ; border-right: 0px solid #000000; padding: 5px; background-color: ; font-size: 12px;}
#report_filters {margin:0 0 30px 0;}
</style>