<?php if ( $this->get( 'link' ) ): ?>
<a class="kpiInfoboxLink" href="<?php $this->out($this->get('link'));?>">
<?php endif;?>

<div id="<?php $this->out( str_replace(' ', '', $this->get( 'label' ) ) );?>_kpibox" class="owa_metricInfobox kpiInfobox <?php $this->out($this->get('class'));?>">

	<p class="owa_metricInfoboxLabel"><?php $this->out( $this->get( 'label' ) ); ?></p>
	<p class="owa_metricInfoboxLargeNumber"><?php $this->out( $this->get( 'number' ), false ); ?></p>
</div>

<?php if ( $this->get( 'link' ) ): ?>
</a>
<?php endif;?>