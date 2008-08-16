<div style="border: 2px solid red;">

<div id="flash-graph" style="visiblity:;">

<?=$this->ofc($params['width'], $params['height'], $this->makeAbsoluteLink(array('do' => $widget, 'period' => 'last_thirty_days', 'site_id' => $params['site_id'], 'format' => $format), false , $this->config['action_url'])); ?>

</div>

<div id="data-table"></div>
<div id="data-export"></div>

</div>

<script>

</script>