<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div style="width:340px; margin: 0px auto -1px auto;">
    <div class="inline_h1" style="text-align:left;">Login</div><BR>

    <div style="width:340px; margin: 0px auto -1px auto; text-align:center;">

        <!-- content goes here -->
        <DIV id="login_box" style="color:#ffffff; padding:45px; height:210px; text-align:left;" >

            <form method="POST">

            <div class="inline_h3"><B>User Name:</B></div>
            <INPUT class="owa_largeFormField" type="text" size="20" name="<?php echo $view->getNs();?>user_id" value="<?php $view->out( $view->user_id); ?>"><BR><BR>
            <div class="inline_h3"><B>Password:</B></div>
            <INPUT class="owa_largeFormField" type="password" size="20" name="<?php echo $view->getNs();?>password"><BR><BR>
            <input type="hidden" size="70" name="<?php echo $view->getNs();?>go" value="<?php echo $view->go?>">
            <input name="<?php echo $view->getNs();?>action" value="base.login" type="hidden">
            <div style="text-align:;">
            <INPUT class="owa_largeFormField" type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Login">
            </div>
            </form>

        </DIV>

    </div>


    <BR>
    <span class="info_text">
    <a href="<?php echo $view->makeLink(array('do' => 'base.passwordResetForm'))?>">Forgot your password?</a>
    </span>
</div>



