<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Open Web Analytics - Domstream</title>
</head>
<body style="text-align: center">
<iframe src="<?php $this->out($url); ?>" width="<?php $this->out(($domstream['page_width'] > 0 ? $domstream['page_width'] . 'px' : '100%')); ?>" height="<?php $this->out(($domstream['page_height'] > 0 ? $domstream['page_height'] . 'px' : '100%')); ?>"></iframe>
</body>
</html>