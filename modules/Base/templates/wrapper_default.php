<?php /** @var \OWA\Core\ViewScope $view */ ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<?php $cu = $view->getCurrentUser(); ?>
<html xmlns="http://www.w3.org/1999/xhtml">

    <head>
        <title>Open Web Analytics - <?php echo $view->page_title ?? '';?></title>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <?php include($view->getTemplatePath('base','head.php'));?>
    </head>

    <body class="<?php if ($cu->user->isOWAAdmin()) echo 'owaadmin'; ?>">
        <style>
            html {background-color: #F2F2F2;}
        </style>

        <div class="owa">
        <?php include($view->getTemplatePath('base', 'header.php'));?>

        <?php include($view->getTemplatePath('base', 'msgs.php'));?>

        <?php echo $view->body;?>

        <?php include($view->getTemplatePath('base', 'footer.php'));?>
        </div>
    </body>

</html>