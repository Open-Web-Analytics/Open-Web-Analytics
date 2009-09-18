<style>
.wrap {margin:0px;padding:0px}
.special_wrap{margin:5px;padding:5px}
</style>


<div class="special_wrap">

<?php include('report_document_summary_stats.tpl');?>

<P>
<form>
	<input id="points_radio" type="radio" name="overlay" onclick="reveal('clickspage2', 'heatmap')"> Points
	
	<input type="radio" name="overlay" onclick="javascript: heatmap()"> Heatmap &nbsp
	&nbsp;View Clicks by Browser Type:
	<select name="uas" size="" onchange="OnChange(this.form.uas, 'ua_id')">
		<option value="" <?php if (empty($params['ua_id'])): echo 'selected'; endif; ?>>All Browser Types</option>
  		<?php foreach ($uas as $k => $v):?>
    	<option value="<?php echo $v['id'];?>" <?php if ($params['ua_id'] == $v['id']): echo 'selected'; endif; ?>><?php echo $v['browser_type'];?></option>
    	<?php endforeach; ?>
	</select>
 <form>
</P>
</div>

<DIV id="heatmap" style="position:absolute;margin:0px;padding:0px;z-index:3;"></div>
<DIV id="clickspage2" style="position:absolute;margin:0px;padding:0px;z-index:3;"></div>
<DIV id="clickspage" style="width:100%;height:100%;position:relative;margin:0px;padding:0px;border:1px solid red;z-index:2;" >
	
	<iframe
		 id="iframe" name="iframe"
		 src="<?php echo $detail['url'];?><?php if (strpos($detail['url'], '?') == false): echo '?'; endif;?>&<?php echo $this->getNs;?>preview=1" 
		 frameborder="0" 
		 scrolling="No" 
		 style="position:relative;width:100%;height:500px;border:0px dotted #BEBEBE;margin:0px;padding:0px; z-index:1;"
		 onload="DYNIFS.resize('iframe')"
		 marginheight="0"
		 marginwidth="0" >
	</iframe>
	
</DIV>



<script>

	var jg_doc = new jsGraphics("clickspage2");

	drawClicks();	
	
	function drawClicks() {
	 
	  jg_doc.setColor("red"); // green
	  
	  var windowWidth = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
	  var divWidth = document.getElementById("clickspage").offsetWidth;
	  var relWidth = '';
	  <?php if (!empty($clicks)):?>
	  	<?php foreach ($clicks as $click => $value):?>
	  
	  <?php if ($this->config['click_drawing_mode'] == 'center_on_page'): ?>
	  relWidth = <?php echo $value['click_x'];?> / <? if($value['page_width']):?><?php echo $value['page_width'];?><?else:?><?echo '0';?><?endif;?>;
	  clickX = divWidth * relWidth;
	  //alert(divWidth +',' + relWidth);
	  <?php else:?>
	  clickX = <?php echo $value['click_x'];?>
	  <?php endif;?>
	  
	  jg_doc.fillEllipse(clickX, <?php echo $value['click_y'];?>,  <?php echo $value['count'] * 4.1;?>,  <?php echo $value['count'] * 4.1;?>); // co-ordinates related to the document
	  <?php endforeach;?>
	  <?php else:?>
	  
	  jg_doc.setFont("arial","15px",Font.ITALIC_BOLD);
	  jg_doc.drawString("There are no clicks for this time period.",20,50);
	  
	  <?php endif;?>
	  
	  jg_doc.setColor("maroon");
	  jg_doc.paint(); // draws, in this case, directly into the document
	  
	  document.getElementById('points_radio').checked = true;
	  return true;
	
	}
	
	function heatmap() {
		//alert('hello');
		
		if (document.getElementById('heatmap').style.visibility == 'hidden') {
			document.getElementById('heatmap').style.visibility = 'visible';
		}
		
		
		var windowWidth = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
		var divWidth = document.getElementById("clickspage").offsetWidth;
		var divHeight = document.getElementById("clickspage").offsetHeight;
		var relWidth = '';
		
		var url = '<img src="<?php echo $this->graphLink(array('do' => 'base.heatmapClicks', 'document_id' => $document_id), true, $this->config['action_url']);?>&owa_width=' + divWidth + '&owa_height=' + divHeight + '">';
		document.getElementById('heatmap').innerHTML = url;
		document.getElementById('clickspage2').style.visibility = 'hidden';
		//alert('<!-- ' + url + ' -->');
		return;
		
	}
	
	function reveal(id, hide) {
		document.getElementById(hide).style.visibility = 'hidden';
		document.getElementById(id).style.visibility = 'visible';
		return;
		
	}

</script>



