<script type="text/javascript" src="<?=$this->config['public_url'].'/js/dynifs.js';?>"></script>
<script type="text/javascript" src="<?=$this->config['public_url'].'/js/wz_jsgraphics.js';?>"></script>

<H2><?=$headline;?> for <?=$period_label;?><?=$date_label;?></h2> 

<fieldset>
	<legend>Document Details</legend>
	<? include('report_document_detail.tpl');?>
</fieldset>

<P>
	<input type="radio" name="overlay" onclick="javascript: heatmap()">Heatmap &nbsp
	<input type="radio" name="overlay" onclick="reveal('clickspage2', 'heatmap')">Points
</P>

<DIV id="heatmap" style="position:absolute;margin:0px;padding:0px;z-index:3;"></div>
<DIV id="clickspage2" style="position:absolute;margin:0px;padding:0px;z-index:3;"></div>
<DIV id="clickspage" style="width:100%;height:100%;position:relative;margin:0px;padding:0px;border:1px solid red;z-index:2;" >
	
	<iframe
		 id="iframe" name="iframe"
		 src="<?=$detail['url']?>" 
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
	  <? if (!empty($clicks)):?>
	  <?php foreach ($clicks as $click => $value):?>
	  
	  <? if ($this->config['click_drawing_mode'] == 'center_on_page'): ?>
	  relWidth = <?=$value['click_x'];?> / <? if($value['page_width']):?><?=$value['page_width'];?><?else:?><?echo '0';?><?endif;?>;
	  clickX = divWidth * relWidth;
	  //alert(divWidth +',' + relWidth);
	  <? else:?>
	  clickX = <?=$value['click_x'];?>
	  <?endif;?>
	  
	  jg_doc.fillEllipse(clickX, <?=$value['click_y'];?>,  <?=$value['count'] * 4.1;?>,  <?=$value['count'] * 4.1;?>); // co-ordinates related to the document
	  <? endforeach;?>
	  <? else:?>
	  
	  jg_doc.setFont("arial","15px",Font.ITALIC_BOLD);
	  jg_doc.drawString("There are no clicks for this time period.",20,50);
	  
	  <?endif;?>
	  
	  jg_doc.setColor("maroon");
	  jg_doc.paint(); // draws, in this case, directly into the document
	  
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
		
		var url = '<img src="<?=$this->graphLink(array('do' => 'base.heatmapClicks', 'document_id' => $document_id), true, $this->config['action_url']);?>&owa_width=' + divWidth + '&owa_height=' + divHeight + '">';
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



