<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Open Web Analytics - Domstream</title>
</head>
<body style="text-align: center">
<iframe src="<?php $view->safeHref($view->url); ?>" width="<?php $view->out(($view->domstream['page_width'] > 0 ? $view->domstream['page_width'] . 'px' : '100%')); ?>" height="<?php $view->out(($view->domstream['page_height'] > 0 ? $view->domstream['page_height'] . 'px' : '100%')); ?>"></iframe>
</body>
</html>