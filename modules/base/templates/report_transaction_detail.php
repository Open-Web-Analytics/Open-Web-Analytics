<table class="management">

	<thead>
	
	</thead>
	
	<tbody>
		<?php 
			$fields = array(
				'order_id'		=> array(
						'label'		=> 'Order Id'
				),
				'order_source'		=> array(
						'label'		=> 'Order Source'
				),
				'gateway'		=> array(
						'label'		=> 'Transaction Processing Gateway'
				),
				'total_revenue'		=> array(
						'label'		=> 'Total Revenue',
						'data_type'	=> 'currency'
				),
				'tax_revenue'		=> array(
						'label'		=> 'Tax Revenue'
				),
				'shipping_revenue'		=> array(
						'label'		=> 'Shipping Revenue'
				),
				
			);
		?>
		
		<tr>
			<th>Order Id</th>
			<td><?php $this->out( $trans_detail['order_id'] );?></td>
		</tr>
		<tr>
			<th>Order Source</th>
			<td><?php $this->out( $trans_detail['order_source'] );?></td>
		</tr>
		<tr>	
			<th>Processing Gateway</th>
			<td><?php $this->out( $trans_detail['gateway'] );?></td>
		</tr>
		<tr>
			<th>Total Revenue</th>
			<td><?php $this->out( $this->formatCurrency( $trans_detail['total_revenue'] ) );?></td>
		</tr>
		<tr>
			<th>Tax Revenue</th>
			<td><?php $this->out( $this->formatCurrency( $trans_detail['tax_revenue'] ) );?></td>
		</tr>
		<tr>
			<th>Shipping Revenue</th>
			<td><?php $this->out( $this->formatCurrency( $trans_detail['shipping_revenue'] ) );?></td>
		</tr>
	</tbody>
</table>

<h3>Transaction Line Items</h3>
<?php if ( isset( $trans_detail['line_items'] ) ):?>
<table class="simpleTable">
	<tr>
		<th>Product Name</th>
		<th>SKU</th>
		<th>Unit Price</th>
		<th>Quantity</th>
		<th>Item Revenue</th>
	</tr>
	
	<?php foreach ($trans_detail['line_items'] as $li): ?>
	<tr>
		<td><?php $this->out( $li['product_name'] ); ?> (<?php $this->out( $li['category'] ); ?>)</td>
		<td><?php $this->out( $li['sku'] ); ?></td>
		<td><?php $this->out( $this->formatCurrency( $li['unit_price'] ) ); ?></td>
		<td><?php $this->out( $li['quantity'] ); ?></td>
		<td><?php $this->out( $this->formatCurrency( $li['item_revenue'] ) ); ?></td>
	</tr>
	<?php endforeach; ?>
</table>
<?php else: ?>
None.
<?php endif;?>

