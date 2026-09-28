<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Open Web Analytics - Overlay</title>
</head>
<body style="text-align: center">
<iframe src="<?php $view->safeHref($view->url); ?>" width="100%" height="100%"></iframe>
</body>
</html>
