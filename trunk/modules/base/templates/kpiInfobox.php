<div id="<?php $this->out( str_replace(' ', '', $this->get( 'label' ) ) );?>_kpibox" class="owa_metricInfobox kpiInfobox">
	<p class="owa_metricInfoboxLabel"><?php $this->out( $this->get( 'label' ) ); ?></p>
	<p class="owa_metricInfoboxLargeNumber"><?php $this->out( $this->get( 'number' ) ); ?></p>
</div>