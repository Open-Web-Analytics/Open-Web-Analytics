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
.post-nav {clear: both; margin:0px; padding:0px 0px 20px 0px;}
.active_nav_link {background-color:#cccccc;}

/*globalnav*/

#globalnav {
	position:relative;
	float:left;
	width:100%;
	padding:0 0 0px 0px;
	margin:0;
	list-style:none;
	line-height:auto;
}

#globalnav LI {
	float:left;
	margin:0;
	padding:0;
}

#globalnav A {
	display:block;
	color:#444;
	text-decoration:none;
	font-weight:bold;
	background:#ddd;
	margin:0;
	padding:0.25em 10px;
	border-left:1px solid #fff;
	border-top:1px solid #fff;
	border-right:1px solid #aaa;
}

#globalnav A:hover,
#globalnav A:active,
#globalnav A.here:link,
#globalnav A.here:visited {
	background:#bbb;
}

#globalnav A.here:link,
#globalnav A.here:visited {
	position:relative;
	z-index:102;
}

/*subnav*/

#globalnav UL {
	position:absolute;
	left:0;
	top:1.5em;
	float:left;
	background:#bbb;
	width:100%;
	margin:0;
	padding:4px 0 4px 0;
	list-style:none;
	border-top:1px solid #fff;
}

#globalnav UL LI {
	float:left;
	display:block;
	margin-top:1px;
}

#globalnav UL A {
	background:#bbb;
	color:#fff;
	display:inline;
	margin:0;
	padding:0 15px;
	border:0
}

#globalnav UL A:hover,
#globalnav UL A:active,
#globalnav UL A.here:link,
#globalnav UL A.here:visited {
	color:#444;
}





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
.data {white-space:nowrap; width:auto;}
.form {width:;}
.sub-row {padding-left:20px; font-weight:normal;}
#summary_stats {font-size:16px; font-weight: normal;}

/* FORMATING */

.active_wizard_step {background-color:#1874CD; color:#ffffff;border:1px solid; padding:5px; font-weight:bold; font-size:16px;}
.wizard_step {font-weight:bold; font-size:16px;}
.visitor_info_box {width:40px; height:40px; text-align:center;}
.visit_icon {width:40px;}
.graph {padding:10px; text-align:center;}
.comments_info_box {
	padding:4px 4px 4px 4px;
	border:solid 0px #999999; 
	margin:0px 2px 2px 2px;
	width:40px;
	height:40px;
	background-image: url('<?=$this->makeImageLink('comment_background.jpg');?>
	background-repeat: no-repeat;
	text-align:center;
}
.visit_summary {width:100%;}
.date_box {padding:4px;	border:solid 1px #999999;margin:2px;}
.pages_box {padding:2px; border:solid 2px #999999; margin:0px 0px 0px 0px; text-align:center;}
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

/* REPORTS */
#report_header {width:100%;margin: 0 0 20px 0;}
.report_headline {font-size:26px; padding:5px; font-weight:bold;}
.data_table {border-collapse: collapse;margin:0;width:100%;}
.report_period {border: 1px solid #cccccc;font-size:22px; color:#999999; text-align:right; padding:5px; min-width:100px;}
.data_table td {border:2px solid #CCCCCC;  min-width:80px;padding:10px;}
.col_item_label {background-color:#CCCCCC; font-weight:bold; border-bottom: 2px solid #999999;}
.col_label {background-color:#CCCCCC; font-weight:bold; border-bottom: 2px solid #999999; text-align:center;}
.data_cell {text-align:center; vertical-align:center;}
.item_cell {}
.section_header {width:98%;background-color:#cccccc; padding:12px; margin: 15px 0 15px 0;}


/* LAYOUT */
#summary_stats {border-collapse: collapse; width:100%;}
#summary_stats td {border:2px solid #CCCCCC; padding:10px; height:65px; width:px; vertical-align:center; margin:0px; min-width:150px;}
#trend_graph {text-align:center;}
#admin_nav{font-size:12px;}	
#keywords{width:400px;}
#login_box {width:390px;}
#header {background-color: #B0C4DE; padding:4px; font-weight:bold; clear: both;}
#report_top_level_nav {margin: 5px 0 0 0;}
#side_bar {width:auto; color: ; border-right: 0px solid #000000; padding: 5px; background-color: ; font-size: 12px;}
#report_filters {margin:0 0 30px 0;}
</style>