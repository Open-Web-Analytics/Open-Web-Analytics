<div class="owa_reportSectionContent">
	<div id="trend-chart" style="height:125px;width:auto;"></div>
	<div class="owa_reportHeadline" id="content-headline"></div>
	<div id="trend-metrics"></div>
</div>

<div class="clear"></div>
<BR>

<table style="width:100%;margin-top:;">
	<tr>
		
		
		<td valign="top" style="width:50%;">
			
			<div class="owa_reportSectionContent" style="min-width:350px;">
				<div class="owa_reportSectionHeader">Transaction Roster</div>
				<div id="transactions"></div>
				
			</div>
			
		</td>
	</tr>
</table>

<script>
//OWA.setSetting('debug', true);

var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
												'metrics' => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate', 
												'dimensions' => 'date', 
												'sort' => 'date',
												'format' => 'json',
												'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';
												  
OWA.items.rsh = new OWA.resultSetExplorer('trend-chart');
OWA.items.rsh.options.metricBoxes.width = '125px';
OWA.items.rsh.asyncQueue.push(['makeAreaChart', [{x:'date',y:'transactions'}]]);
OWA.items.rsh.asyncQueue.push(['makeMetricBoxes', 'trend-metrics']);
OWA.items.rsh.asyncQueue.push(['renderTemplate','#headline-template', {data: OWA.items.rsh}, 'replace', 'content-headline']);
OWA.items.rsh.load(aurl);

var transactionsurl = '<?php echo $this->makeApiLink(array(
												'do' => 'getResultSet', 
												'metrics' => 'transactionRevenue,shippingRevenue,taxRevenue', 
												'dimensions' => 'timestamp,transactionId', 
												'sort' => 'timestamp-',
												'format' => 'json',
												'resultsPerPage' => 25,
												'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';
												  
OWA.items.transactions = new OWA.resultSetExplorer('transactions');
OWA.items.transactions.addLinkToColumn('transactionId', '<?php echo $this->makeLink(array(
																		'do' => 'base.reportTransactionDetail', 
																		'transactionId' => '%s'
																	),true);?>', ['transactionId']);
OWA.items.transactions.options.grid.excludeColumns = ['timestamp'];
OWA.items.transactions.asyncQueue.push(['refreshGrid']);
OWA.items.transactions.load(transactionsurl);

					  
</script>

<?php require_once('js_report_templates.php');?>

<script type="text/x-jqote-template" id="headline-template">
<![CDATA[
	There were <*= this.data.resultSet.aggregates.transactions.formatted_value *> <* if (this.data.resultSet.aggregates.transactions.value > 1) {this.label = 'transactions';} else {this.label = 'transaction';} *> <*= this.label *> generating <*= this.data.resultSet.aggregates.transactionRevenue.formatted_value *>.
]]> 
</script>

