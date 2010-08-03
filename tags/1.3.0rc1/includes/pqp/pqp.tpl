<!-- JavaScript -->
{literal}
<script type="text/javascript">
	var PQP_DETAILS = true;
	var PQP_HEIGHT = "short";
	
	addEvent(window, 'load', loadCSS);

	function changeTab(tab) {
		var pQp = document.getElementById('pQp');
		hideAllTabs();
		addClassName(pQp, tab, true);
	}
	
	function hideAllTabs() {
		var pQp = document.getElementById('pQp');
		removeClassName(pQp, 'console');
		removeClassName(pQp, 'speed');
		removeClassName(pQp, 'queries');
		removeClassName(pQp, 'memory');
		removeClassName(pQp, 'files');
	}
	
	function toggleDetails(){
		var container = document.getElementById('pqp-container');
		
		if(PQP_DETAILS){
			addClassName(container, 'hideDetails', true);
			PQP_DETAILS = false;
		}
		else{
			removeClassName(container, 'hideDetails');
			PQP_DETAILS = true;
		}
	}
	function toggleHeight(){
		var container = document.getElementById('pqp-container');
		
		if(PQP_HEIGHT == "short"){
			addClassName(container, 'tallDetails', true);
			PQP_HEIGHT = "tall";
		}
		else{
			removeClassName(container, 'tallDetails');
			PQP_HEIGHT = "short";
		}
	}
	
	function loadCSS() {
		var sheet = document.createElement("link");
		sheet.setAttribute("rel", "stylesheet");
		sheet.setAttribute("type", "text/css");
		sheet.setAttribute("href", "/pqp/css/pQp.css");
		document.getElementsByTagName("head")[0].appendChild(sheet);
		setTimeout(function(){document.getElementById("pqp-container").style.display = "block"}, 10);
	}
	
	
	//http://www.bigbold.com/snippets/posts/show/2630
	function addClassName(objElement, strClass, blnMayAlreadyExist){
	   if ( objElement.className ){
	      var arrList = objElement.className.split(' ');
	      if ( blnMayAlreadyExist ){
	         var strClassUpper = strClass.toUpperCase();
	         for ( var i = 0; i < arrList.length; i++ ){
	            if ( arrList[i].toUpperCase() == strClassUpper ){
	               arrList.splice(i, 1);
	               i--;
	             }
	           }
	      }
	      arrList[arrList.length] = strClass;
	      objElement.className = arrList.join(' ');
	   }
	   else{  
	      objElement.className = strClass;
	      }
	}

	//http://www.bigbold.com/snippets/posts/show/2630
	function removeClassName(objElement, strClass){
	   if ( objElement.className ){
	      var arrList = objElement.className.split(' ');
	      var strClassUpper = strClass.toUpperCase();
	      for ( var i = 0; i < arrList.length; i++ ){
	         if ( arrList[i].toUpperCase() == strClassUpper ){
	            arrList.splice(i, 1);
	            i--;
	         }
	      }
	      objElement.className = arrList.join(' ');
	   }
	}

	//http://ejohn.org/projects/flexible-javascript-events/
	function addEvent( obj, type, fn ) {
	  if ( obj.attachEvent ) {
	    obj["e"+type+fn] = fn;
	    obj[type+fn] = function() { obj["e"+type+fn]( window.event ) };
	    obj.attachEvent( "on"+type, obj[type+fn] );
	  } 
	  else{
	    obj.addEventListener( type, fn, false );	
	  }
	}
</script>
{/literal}

<div id="pqp-container" class="pQp" style="display:none">
<div id="pQp" class="console">
	<table id="pqp-metrics" cellspacing="0">
		<tr>
			<td class="green" onclick="changeTab('console');">
				<var>{$logs.console|@count}</var>
				<h4>Console</h4>
			</td>
			<td class="blue" onclick="changeTab('speed');">
				<var>{$speedTotals.total}</var>
				<h4>Load Time</h4>
			</td>
			<td class="purple" onclick="changeTab('queries');">
				<var>{$queryTotals.count} Queries</var>
				<h4>Database</h4>
			</td>
			<td class="orange" onclick="changeTab('memory');">
				<var>{$memoryTotals.used}</var>
				<h4>Memory Used</h4>
			</td>
			<td class="red" onclick="changeTab('files');">
				<var>{$files|@count} Files</var>
				<h4>Included</h4>
			</td>
		</tr>
	</table>
	
	<div id='pqp-console' class='pqp-box'>
		{if $logs.console|@count == 0}
			<h3>This panel has no log items.</h3>
		{else}
			<table class='side' cellspacing='0'>
			<tr>
				<td class='alt1'><var>{$logs.logCount}</var><h4>Logs</h4></td>
				<td class='alt2'><var>{$logs.errorCount}</var> <h4>Errors</h4></td>
			</tr>
			<tr>
				<td class='alt3'><var>{$logs.memoryCount}</var> <h4>Memory</h4></td>
				<td class='alt4'><var>{$logs.speedCount}</var> <h4>Speed</h4></td>
			</tr>
			</table>
			<table class='main' cellspacing='0'>
				{foreach from=$logs.console item=log}
					<tr class='log-{$log.type}'>
						<td class='type'>{$log.type}</td>
						<td class="{cycle values="alt,"}">
							{if $log.type == 'log'} 
								<div><pre>{$log.data}</pre></div>
							{elseif $log.type == 'memory'}
								<div><pre>{$log.data}</pre> <em>{$log.dataType}</em>: {$log.name} </div>
							{elseif $log.type == 'speed'}
								<div><pre>{$log.data}</pre> <em>{$log.name}</em></div>
							{elseif $log.type == 'error'}
								<div><em>Line {$log.line}</em> : {$log.data} <pre>{$log.file}</pre></div>
							{/if}
						</td>
						</tr>
				{/foreach}
			</table>
		{/if}
	</div>
	
	<div id="pqp-speed" class="pqp-box">
		{if $logs.speedCount == 0}
			<h3>This panel has no log items.</h3>
		{else}
			<table class='side' cellspacing='0'>
				<tr><td><var>{$speedTotals.total}</var><h4>Load Time</h4></td></tr>
				<tr><td class='alt'><var>{$speedTotals.allowed} s</var> <h4>Max Execution Time</h4></td></tr>
			</table>
		
			<table class='main' cellspacing='0'>
			{foreach from=$logs.console item=log}
				{if $log.type == 'speed'}
					<tr class='log-{$log.type}'>
						<td class="{cycle values="alt,"}"><b>{$log.data}</b> {$log.name}</td>
					</tr>
				{/if}
			{/foreach}
			</table>
		{/if}
	</div>
	
	<div id='pqp-queries' class='pqp-box'>
		{if $queryTotals.count == 0}
			<h3>This panel has no log items.</h3>
		{else}
			<table class='side' cellspacing='0'>
			<tr><td><var>{$queryTotals.count}</var><h4>Total Queries</h4></td></tr>
			<tr><td class='alt'><var>{$queryTotals.time}</var> <h4>Total Time</h4></td></tr>
			<tr><td><var>0</var> <h4>Duplicates</h4></td></tr>
			</table>
			
				<table class='main' cellspacing='0'>
				{foreach from=$queries item=query}
						<tr>
							<td class="{cycle values="alt,"}">
								{$query.sql}
								{if $query.explain}
								<em>
									Possible keys: <b>{$query.explain.possible_keys}</b> &middot; 
									Key Used: <b>{$query.explain.key}</b> &middot; 
									Type: <b>{$query.explain.type}</b> &middot; 
									Rows: <b>{$query.explain.rows}</b> &middot; 
									Speed: <b>{$query.time}</b>
								</em>
								{/if}
							</td>
						</tr>
				{/foreach}
				</table>
		{/if}
	</div>

	<div id="pqp-memory" class="pqp-box">
		{if $logs.memoryCount == 0}
			<h3>This panel has no log items.</h3>
		{else}
			<table class='side' cellspacing='0'>
				<tr><td><var>{$memoryTotals.used}</var><h4>Used Memory</h4></td></tr>
				<tr><td class='alt'><var>{$memoryTotals.total}</var> <h4>Total Available</h4></td></tr>
			</table>
		
			<table class='main' cellspacing='0'>
			{foreach from=$logs.console item=log}
				{if $log.type == 'memory'}
					<tr class='log-{$log.type}'>
						<td class="{cycle values="alt,"}"><b>{$log.data}</b> <em>{$log.dataType}</em>: {$log.name}</td>
					</tr>
				{/if}
			{/foreach}
			</table>
		{/if}
	</div>

	<div id='pqp-files' class='pqp-box'>
			<table class='side' cellspacing='0'>
				<tr><td><var>{$fileTotals.count}</var><h4>Total Files</h4></td></tr>
				<tr><td class='alt'><var>{$fileTotals.size}</var> <h4>Total Size</h4></td></tr>
				<tr><td><var>{$fileTotals.largest}</var> <h4>Largest</h4></td></tr>
			</table>
			<table class='main' cellspacing='0'>
				{foreach from=$files item=file}
					<tr><td class="{cycle values="alt,"}"><b>{$file.size}</b> {$file.name}</td></tr>
				{/foreach}
			</table>
	</div>
	
	<table id="pqp-footer" cellspacing="0">
		<tr>
			<td class="credit">
				<a href="http://particletree.com/features/php-quick-profiler/" target="_blank">
				<strong>PHP</strong> 
				<b class="green">Q</b><b class="blue">u</b><b class="purple">i</b><b class="orange">c</b><b class="red">k</b>
				Profiler</a></td>
			<td class="actions">
				<a href="#" onclick="toggleDetails();return false">Details</a>
				<a class="heightToggle" href="#" onclick="toggleHeight();return false">Height</a>
			</td>
		</tr>
	</table>
</div>
</div>