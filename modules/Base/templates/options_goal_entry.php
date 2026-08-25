<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php echo $view->headline?></div>

<div class="subview_content">

<h3>Goal <?php $view->out($view->goal_number);?> Settings</h3>

<form name="goal-entry" method="POST">

    <table class="management" width="100%">
        <thead>

        </thead>

        <tbody>

            <tr>

                <th valign="top">Name:</th>
                <td>
                    <input name="<?php echo $view->getNs();?>goal[goal_name]" type="text" size="40" value="<?php $view->out($view->goal['goal_name'] ?? '');?>">
                </td>
            </tr>
            <tr>
                <th valign="top">Group:
                    <p class="formInstructions">
                        The group that you want to assign this goal to. Goal groups are presented as a tab view on most reports.
                    </p>
                </th>
                <td>

                    <select name="<?php echo $view->getNs();?>goal[goal_group]">
                        <?php foreach ($view->goal_groups as $k => $group): ?>
                        <option value="<?php $view->out($k, false);?>" <?php if ( isset( $view->goal['goal_group'] ) && $view->goal['goal_group'] == $k ) { echo 'SELECTED';}?>><?php
                        if ( !empty( $group ) ) {
                            $view->out($k." - $group");
                        } else {
                            $view->out($k);
                        }
                        ?></option>
                        <?php endforeach;?>
                    </select>
                    <BR><BR>Edit the group label:

                    <input name="<?php echo $view->getNs();?>new_goal_group_name" type="text" size="20" value="<?php $view->out($view->goal_groups[ $view->goal['goal_group'] ?? '' ] ?? '');?>">
                </td>
            </tr>
            <tr>
                <th valign="top">Status:</th>
                <td>
                    <select name="<?php echo $view->getNs();?>goal[goal_status]">
                        <option value="active" <?php if (isset($view->goal['goal_status']) && $view->goal['goal_status'] != 'disabled'){echo 'SELECTED';}?>>
                            Active
                        </option>
                        <option value="disabled" <?php if (isset($view->goal['goal_status']) && $view->goal['goal_status'] === 'disabled'){echo 'SELECTED';}?>>
                            Disabled
                        </option>
                    </select>


                </td>
            </tr>

            <tr>
                <th valign="top">
                    Value:
                    <p class="formInstructions">
                        The value associated with achieving this goal.
                    </p>
                </th>
                <td>
                    <input name="<?php echo $view->getNs();?>goal[goal_value]" type="text" size="20" value="<?php $view->out($view->goal['goal_value'] ?? '');?>">
                    <span class="optional">Optional</span>
                </td>
            </tr>

            <tr>
                <th valign="top">
                    Type:
                    <p class="formInstructions">
                        The type of goal.
                    </p>
                </th>
                <td>
                    <input type="radio" name="<?php echo $view->getNs();?>goal[goal_type]" value="url_destination" <?php if ( ! isset($view->goal['goal_type']) || $view->goal['goal_type'] === 'url_destination'){echo 'CHECKED';}?> > URL Destination<BR>
                </td>
            </tr>

        </tbody>

    </table>

    <!-- URL destination specific options -->
    <div id="url_destination_details" class="goal-detail">

        <h3>Goal Details</h3>
        <table class="management">
            <tr>
                <th>Match Type:</th>
                <td>
                    <select name="<?php echo $view->getNs();?>goal[details][match_type]">
                        <option value="begins" <?php if (isset($view->goal['details']['match_type']) && $view->goal['details']['match_type'] === 'begins'){echo 'SELECTED';}?>>
                            Begins With
                        </option>
                        <option value="exact" <?php if (isset($view->goal['details']['match_type']) && $view->goal['details']['match_type'] === 'exact'){echo 'SELECTED';}?>>
                            Exact Match
                        </option>
                        <option value="regex" <?php if (isset($view->goal['details']['match_type']) && $view->goal['details']['match_type'] === 'regex'){echo 'SELECTED';}?>>
                            Regular Expression
                        </option>
                    </select>

                </td>
            </tr>
            <tr>
                <th>
                Goal URL:
                <p class="formInstructions">
                    Example: /register.html
                </p>
                </th>
                <td>
                    <input name="<?php echo $view->getNs();?>goal[details][goal_url]" value="<?php $view->out($view->goal['details']['goal_url'] ?? '');?>" type="text" size="60">
                </td>
            </tr>
        </table>

        <h3>Funnel</h3>

        <table class="management" id="funnel-steps">
            <TR>
                <th></th>
                <th>Step Path</th>
                <th>Name</th>
                <th>Is Required?</th>
                <th></th>
            </TR>
        </table>
        <BR>
        <a name="steps-end" href="#    steps-end" id="addStep">Add New Funnel Step</a>
    </div>

    <input type="hidden" name="<?php echo $view->getNs();?>goal[goal_number]" value="<?php $view->out($view->goal_number, false);?>">
    <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out($view->siteId, false);?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.optionsGoalEdit">
    <?php echo $view->createNonceFormField('base.optionsGoalEdit');?>
    <BR>
    <input type="submit" value="Submit">
    </form>

</div>

<script>
OWA.setSetting('debug', true);
jQuery(document).ready(function() {

    showGoalDetails();

    // show hide the right goal type details
    jQuery("input[name='<?php echo $view->getNs();?>goal[goal_type]']").change(function(e) {
        showGoalDetails();
    });

    jQuery('#addStep').click(function() {
        addNewStep();
    });

    if (OWA.util.countObjectProperties(steps) > 0) {
        populateGoalSteps();
    }
});

function showGoalDetails() {
    var val = jQuery("input[name='<?php echo $view->getNs();?>goal[goal_type]']:checked").val();
    OWA.debug(val);
    jQuery('.goal-detail').hide();
    var selector = '#'+val+'_details';
    OWA.debug(selector);
    jQuery(selector).show();
}

function populateGoalSteps() {
    OWA.debug('pop');
    for (step in steps) {
        renderStep(steps[step]);
    }
}

function addNewStep() {
    var count = OWA.util.countObjectProperties(steps);
    OWA.debug('count: '+count);
    var num;
    if (count === 0) {
        num = 1;
    } else {
        num = count + 1;
    }

    if (num < 11) {

        OWA.debug('num: '+num);
        var empty_step = {step_number: num, is_required: '', name: '', url: ''};
        renderStep(empty_step);
        steps[num] = empty_step;
    } else {
        alert("Sorry but funnels can only have 10 steps.");
    }
}

function renderStep(step) {
    jQuery('#funnel-steps tr:last').after(jQuery('#funnel-step').jqote(step, '*'));
}

</script>

<script type="text/x-jqote-template" id="funnel-step">
<![CDATA[
<tr>
<th class="">Step <*= this.step_number *></th>
<td class=""><input type="text" size="20" name="<?php echo $view->getNs();?>goal[details][funnel_steps][<*= this.step_number *>][path]" value="<*= this.path *>"></td>
<td class=""><input type="text" size="20" name="<?php echo $view->getNs();?>goal[details][funnel_steps][<*= this.step_number *>][name]" value="<*= this.name *>"></td>
<td class="">


<input type="checkbox" size="20" name="<?php echo $view->getNs();?>goal[details][funnel_steps][<*= this.step_number *>][is_required]" value="true"
<* if ( this.is_required ) { *>
CHECKED
<* } *>
>

</td>

</tr>
]]>
</script>

<script>
var steps = [];
<?php // !empty() guard first: $view->goal['details'] is null for a goal with no details,
      // and array_key_exists() with a null second argument is a TypeError on PHP 8
      // (pre-8 it was a warning returning false). ?>
<?php if (!empty($view->goal['details']) && array_key_exists('funnel_steps', $view->goal['details'])):?>
<?php $view->out(sprintf("steps = %s;", json_encode($view->goal['details']['funnel_steps'])), false); ?>
<?php endif;?>
</script>