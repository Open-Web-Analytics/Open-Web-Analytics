<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
	</head>
	
	<body>
	
		<style>
					
			th {text-align:left;}
			td {padding:2px;}
			.inline_h2 {font-size:18px;}
			.visitor_info_box {
				  width:40px;
				  height:40px;
				  text-align:center;
			  }
			  
			  .comments_info_box {
					padding:4px 4px 4px 4px;
				border:solid 0px #999999;
				margin:0px 2px 2px 2px;
				width:40px;
				height:40px;
				background-image: url('<?=$this->config['images_url'];?>/comment_background.jpg');
				background-repeat: no-repeat;
				text-align:center;
			  }
			  
			  .date_box {
			  
			  	padding:4px;
				border:solid 1px #999999;
				margin:2px;
			  }
			  
			   .pages_box {
			  
			  	padding:4px 4px 4px 4px;
				border:solid 2px #999999;
				margin:0px 2px 2px 2px;
				background-color:;
				color:;
			  }
			  
			  .large_number {
			  	font-size:24px;
			  
			  }
			  
			  .info_text {
			  
			  color:#999999;
			  font-size:12px;
			 /* font-family:Arial, Helvetica, sans-serif; */
			  
			  }
			  
			 .h_label {
			  
			  color:;
			  font-size:14px;
			  font-weight:bold;
			 /* font-family:Arial, Helvetica, sans-serif; */
			  
			  }
			
			.centered_buttons {margin-left:auto;margin-right:auto;}

		</style>
	
	   <div class="wrap">
	        
			<?=$content;?>
					
	   </div>
	
	</body>
</html>