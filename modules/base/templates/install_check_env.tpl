<!-- <div class="panel_headline"><?php //echo $headline;?></div> -->

<h2>Uh-oh. We found a few issues.</h2>

<p>We found a few problems with your server environment. Please resolve these issues and start the installation again.</p>

<style>
.form-row {border-bottom:1px solid #efefef;padding:10px;}
.form-label {position: inherit; min-width: 300px;}
.form-field {position: absolute; left: 620px;}
.form-error {background-color: red; border:1px solid red; color:#ffffff; padding:3px;}
.form-instructions {position: absolute; left: 850px; font-size:12px; color: #9f9f9f;}
</style>    

<h3>Problems</h3>
<?php foreach ($errors as $error): ?>
<p class="form-row">
    <span class="form-label"><?php echo $error['name'];?></span>
    <span class="form-field form-error"><?php echo $error['value'];?></span>
    <span class="form-instructions"><?php echo $error['msg'];?></span>
</p>
<?php endforeach; ?>
