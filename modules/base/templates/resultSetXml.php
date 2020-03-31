<?php echo ("<?xml version='1.0' encoding='UTF-8'?>");?>
<resultSet>
    <timePeriod>
<?php foreach ($rs->timePeriod as $k => $v):?>
        <?php echo sprintf("<%s>%s</%s>\n", $k, $this->escapeForXml($v), $k);?>
<?php endforeach;?>
    </timePeriod>

    <aggregates>
<?php foreach ($rs->aggregates as $item):?>
        <?php echo sprintf("<%s name='%s' value='%s' label='%s'/>\n", $item['result_type'], $this->escapeForXml($item['name']), $this->escapeForXml($item['value']), $this->escapeForXml($item['label']));?>
<?php endforeach;?>
    </aggregates>

    <resultsTotal><?php echo $rs->resultsTotal;?></resultsTotal>

    <resultsReturned><?php echo $rs->resultsReturned;?></resultsReturned>

    <resultsPerPage><?php echo $rs->resultsPerPage;?></resultsPerPage>

    <resultsRows>
<?php foreach ($rs->resultsRows as $row):?>
        <row>
<?php foreach ($row as $item):?>
            <?php echo sprintf("<%s name='%s' value='%s' label='%s'/>\n", $item['result_type'], $this->escapeForXml($item['name']), $this->escapeForXml($item['value']), $this->escapeForXml($item['label']));?>
<?php endforeach;?>
        </row>
<?php endforeach;?>
    </resultsRows>

</resultSet>