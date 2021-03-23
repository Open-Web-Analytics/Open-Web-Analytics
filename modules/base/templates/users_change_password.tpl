<div style="width:550px;margin: 0px auto -1px auto;">
    <div class="inline_h1" style="text-align:left;">Password Setup</div><BR>
    <div class="inline_h2" style="text-align:left;">Enter your new password below.</div><BR>
    <div style="width:550px; margin: 0px auto -1px auto; ">
      <b class="spiffy">
      <b class="spiffy1"><b></b></b>
      <b class="spiffy2"><b></b></b>
      <b class="spiffy3"></b>
      <b class="spiffy4"></b>
      <b class="spiffy5"></b></b>

      <div class="spiffyfg">
        <!-- content goes here -->
        <div id="" style="color:#ffffff; padding:30px; height:200px; text-align:left;" >
            <form method="POST">
                <div class="inline_h2">New Password</div>
                <INPUT class="owa_largeFormField" type="password" size="20" name="<?php echo $this->getNs();?>password"><BR><BR>
                <div class="inline_h2">Re-type your Password</div>
                <INPUT class="owa_largeFormField" type="password" size="20" name="<?php echo $this->getNs();?>password2"><BR><BR>
                <?php if ( $is_embedded ) {?>
		        <input type="hidden" name="<?php echo $this->getNs();?>is_embedded" value="<?php echo $is_embedded;?>">                
                <?php } ?>
                <input type="hidden" name="<?php echo $this->getNs();?>k" value="<?php echo $key;?>">
                <input name="<?php echo $this->getNs();?>action" value="base.usersChangePassword" type="hidden">
                <INPUT class="owa_largeFormField" type="submit" size="" name="<?php echo $this->getNs();?>submit_btn" value="Save Your New Password">
            </form>
        </div>
    </div>

      <b class="spiffy">
      <b class="spiffy5"></b>
      <b class="spiffy4"></b>
      <b class="spiffy3"></b>
      <b class="spiffy2"><b></b></b>
      <b class="spiffy1"><b></b></b></b>
    </div>

</div>
