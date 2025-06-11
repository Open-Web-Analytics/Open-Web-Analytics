<div style="width:550px;margin: 0px auto -1px auto;">
    <div class="inline_h1" style="text-align:left;">Password Reset</div><BR>
    <div class="inline_h2" style="text-align:left;">Enter the e-mail address associated with your account.</div><BR>
    <div style="width:550px; margin: 0px auto -1px auto; ">
      <b class="spiffy">
      <b class="spiffy1"><b></b></b>
      <b class="spiffy2"><b></b></b>
      <b class="spiffy3"></b>
      <b class="spiffy4"></b>
      <b class="spiffy5"></b></b>

      <div class="spiffyfg">
        <!-- content goes here -->
        <div id="" style="color:#ffffff; padding:30px; height:100px; text-align:left;" >
            <form method="POST">
                <div class="inline_h3">E-mail address:</div>
                <INPUT class="owa_largeFormField" type="text" size="30" name="<?php echo $this->getNs();?>email_address" value=""></TD>
                </TR>

                <TR>
                    <TH scope="row"></TH>
                    <TD>

                        <input name="<?php echo $this->getNs();?>action" value="base.passwordResetRequest" type="hidden"><BR><BR>
                        <INPUT class="owa_largeFormField" type="submit" size="30" name="<?php echo $this->getNs();?>submit" value="Request New Password">
                    </TD>
                </TR>

                </TABLE>

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


    <BR>
    <span class="info_text">
    <!--<a href="<?php echo $this->makeLink(array('do' => 'base.passwordResetForm'))?>">Forgot your password?</a> -->
    </span>
</div>

