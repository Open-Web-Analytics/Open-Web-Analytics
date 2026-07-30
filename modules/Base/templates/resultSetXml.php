<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php echo ("<?xml version='1.0' encoding='UTF-8'?>");?>
<resultSet>
    <timePeriod>
<?php foreach ($view->rs->timePeriod as $k => $v):?>
        <?php echo sprintf("<%s>%s</%s>\n", $k, $view->escapeForXml($v), $k);?>
<?php endforeach;?>
    </timePeriod>

    <aggregates>
<?php foreach ($view->rs->aggregates as $item):?>
        <?php echo sprintf("<%s name='%s' value='%s' label='%s'/>\n", $item['result_type'], $view->escapeForXml($item['name']), $view->escapeForXml($item['value']), $view->escapeForXml($item['label']));?>
<?php endforeach;?>
    </aggregates>

    <resultsTotal><?php echo $view->rs->resultsTotal;?></resultsTotal>

    <resultsReturned><?php echo $view->rs->resultsReturned;?></resultsReturned>

    <resultsPerPage><?php echo $view->rs->resultsPerPage;?></resultsPerPage>

    <resultsRows>
<?php foreach ($view->rs->resultsRows as $row):?>
        <row>
<?php foreach ($row as $item):?>
            <?php echo sprintf("<%s name='%s' value='%s' label='%s'/>\n", $item['result_type'], $view->escapeForXml($item['name']), $view->escapeForXml($item['value']), $view->escapeForXml($item['label']));?>
<?php endforeach;?>
        </row>
<?php endforeach;?>
    </resultsRows>

</resultSet>