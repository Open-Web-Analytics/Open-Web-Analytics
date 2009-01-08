<div style="width:340px; margin: 0px auto -1px auto;">
	<div class="inline_h1" style="text-align:left;">Login</div><BR>
	
	<div style="width:340px; margin: 0px auto -1px auto; text-align:center;">
	  <b class="spiffy">
	  <b class="spiffy1"><b></b></b>
	  <b class="spiffy2"><b></b></b>
	  <b class="spiffy3"></b>
	  <b class="spiffy4"></b>
	  <b class="spiffy5"></b></b>
	
	  <div class="spiffyfg">
	    <!-- content goes here -->
		<DIV id="login_box" style="color:#ffffff; padding:45px; height:210px; text-align:left;" >
			
			<form method="POST">
		    
			<div class="inline_h3"><B>User Name:</B></div>
			<INPUT class="owa_largeFormField" type="text" size="20" name="<?=$this->getNs();?>user_id" value="<?=$user_id;?>"><BR><BR>
			<div class="inline_h3"><B>Password:</B></div>
			<INPUT class="owa_largeFormField" type="password" size="20" name="<?=$this->getNs();?>password"><BR><BR>
			<input type="hidden" size="70" name="<?=$this->getNs();?>go" value="<?=$go?>">
			<input name="<?=$this->getNs();?>action" value="base.login" type="hidden">
			<div style="text-align:;">
			<INPUT class="owa_largeFormField" type="submit" name="<?=$this->getNs();?>submit_btn" value="Login">
			</div>
			</form>
		 
		</DIV>
	    
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
	<a href="<?=$this->makeLink(array('do' => 'base.passwordResetForm'))?>">Forgot your password?</a>
	</span>	
</div>



