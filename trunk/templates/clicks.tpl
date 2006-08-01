<script type="text/javascript" src="<?=$this->config['public_url'].'/js/dynifs.js';?>"></script>
<script type="text/javascript" src="<?=$this->config['public_url'].'/js/wz_jsgraphics.js';?>"></script>

<span class="inline_h2"><?=$headline;?> 

<fieldset class="options">
	<legend>Document Info</legend>
	

<?=$detail['page_title']?> (<?=$detail['page_type']?>)</span><BR>
<span class="info_text"><?=$detail['url']?></span>

</fieldset>

<DIV id="clickspage" style="width:100%;height:100%;position:relative;margin:0px;padding:0px;border:1px solid red;" >
	
	<iframe
		 id="iframe" name="iframe"
		 src="<?=$detail['url']?>" 
		 frameborder="0" 
		 scrolling="No" 
		 style="width:100%;height:500px;border:0px dotted #BEBEBE;margin:0px;padding:0px;"
		 onload="DYNIFS.resize('iframe')"
		 marginheight="0"
		 marginwidth="0" >
	</iframe>
	
</DIV>

<script>

	var jg_doc = new jsGraphics("clickspage");

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

</script>

