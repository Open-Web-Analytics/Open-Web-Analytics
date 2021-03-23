<h2>Default Site & User Information</h2>
<div id="configSettings">
    <form method="POST">
        
        <p class="form-row">
            <span class="form-label">Site Domain</span>
            <span class="form-field">
                <select name="<?php echo $this->getNs();?>protocol">
                    <option value="http://">http://</option>
                    <option value="https://">https://</option>
                </select> 
                <input type="text"size="30" name="<?php echo $this->getNs();?>domain" value="<?php $this->out( $defaults['domain'] );?>">
            </span>
            <span class="form-instructions">This is the domain of the site to track.</span>
        </p>

        <p class="form-row">
            <span class="form-label">Your Admin Name</span>
            <span class="form-field">
                <input type="text"size="30" name="<?php echo $this->getNs();?>user_id" value="<?php $this->out( $defaults['user_id'] );?>">
            </span>
            <span class="form-instructions">This is name of the admin user.</span>
        </p>

        <p class="form-row">
            <span class="form-label">Your E-mail Address</span>
            <span class="form-field">
                <input type="text"size="30" name="<?php echo $this->getNs();?>email_address" value="<?php $this->out( $defaults['email_address'] );?>">
            </span>
            <span class="form-instructions">This is the e-mail address of the admin user.</span>
        </p>
        
        <p class="form-row">
            <span class="form-label">Your Password</span>
            <span class="form-field">
                <input type="password"size="30" name="<?php echo $this->getNs();?>password" value="">
            </span>
            <span class="form-instructions">This will be the password of the admin user.</span>
        </p>
                
        <p>
            <?php echo $this->createNonceFormField('base.installBase');?>
            <input type="hidden" value="base.installBase" name="<?php echo $this->getNs();?>action">
            <input class="owa-button" type="submit" value="Continue..." name="<?php echo $this->getNs();?>save_button">
        </p>
        
    </form>
    
</div>
    