
		
<div class="owa_reportSectionContent">	
	
	<div id="past-visits"></div>	
	<script>
		var pvurl = '<?php echo $this->makeApiLink(
						array(
					
							'do' => 'getResultSet', 
							'metrics' => 'visits', 
							'dimensions' => 'date', 
							'sort' => 'visits-',
							'resultsPerPage' => 10,
							'format' => 'json',
							'constraints'	=> 'visitorId=='.$visitor_id
						),
						true);
					?>';
																				  
						OWA.items.pastvisits = new OWA.resultSetExplorer('past-visits');
						OWA.items.pastvisits.addLinkToColumn('visits', '<?php echo $this->makeLink(array('do' => 'base.reportVisits', 'visitorId' => $visitor_id, 'date' => '%s')); ?>', ['date']);
						OWA.items.pastvisits.asyncQueue.push(['refreshGrid']);
						OWA.items.pastvisits.load(pvurl);
	</script>
</div>	
				

