<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The funnel builder.
 *
 * The KIND was answered before this screen opened -- in New's "What do you
 * want to make?" -- because the kind decides what this form asks for. It is
 * posted, so an unchanged edit cannot fall back to a default, and it cannot be
 * changed: steps mean nothing to another kind.
 *
 * The header is the funnel's name, Shared and Cancel / Save, as on the report
 * editor. Under it, the steps and the filter at the left and a preview at the
 * right: the funnel counted as it stands, from the steps on the screen, over
 * the last 30 days (base.visualizationPreview).
 *
 * The form posts the steps as parallel arrays -- stepKind[], stepPath[],
 * stepGoalEventId[], stepName[] -- in row order, so the order on the screen is
 * the order saved. A step is a page path or a goal event; its name is not
 * asked for. A stored name rides along in stepName[] while the step it names is
 * unchanged, and a step with none is named for its page or goal event.
 */
$owa_v      = (array) $view->visualization;
$owa_type   = (string) ( $view->visualizationType ?: 'funnel' );
$owa_id     = (string) ( $owa_v['id'] ?? '' );
$owa_errors = (array) $view->validation_errors;
$owa_steps  = (array) $view->steps ?: array( array() );
$owa_goals  = (array) $view->goalEvents;

// The errors each step row shows; the rest are shown under the steps.
$owa_shownErrors = array( 'name' => true, 'constraints' => true );
?>

<?php
/*
 * An explicit action URL, not a hidden owa_action field: a report page does
 * not route the admin way, and posting so ran the save then rendered nothing.
 */
?>
<form method="post" name="owa_visualization" class="owa_editor owa_funnelEditor" id="owa_funnelForm"
      action="<?php echo $view->makeLink( array( 'do' => 'base.visualizationSave' ) );?>">

    <?php echo $view->createNonceFormField( 'base.visualizationSave' );?>
    <input type="hidden" name="<?php echo $view->getNs();?>visualizationId" value="<?php $view->out( $view->visualizationId ?? '' );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>visualizationType" value="<?php $view->out( $owa_type );?>">
    <?php // The saved funnel opens on this Property, as a saved report does. ?>
    <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
    <?php // The filter, written by the script from its pills. ?>
    <input type="hidden" name="<?php echo $view->getNs();?>constraints" id="owa_funnelConstraints"
           value="<?php $view->out( (string) $view->constraints );?>">

    <div class="owa_editorHead">
        <div class="owa_editorName">
            <div class="owa_builderFieldLabel">
                <label for="owa_funnelName">Funnel name</label>
                <span class="owa_builderRequired">Required</span>
            </div>
            <input type="text" id="owa_funnelName" maxlength="255"
                   name="<?php echo $view->getNs();?>name"
                   placeholder="Untitled funnel"
                   value="<?php $view->out( $owa_v['name'] ?? '' );?>">
            <?php if ( ! empty( $owa_errors['name'] ) ):?>
            <span class="validation_error"><?php $view->out( $owa_errors['name'] );?></span>
            <?php endif;?>
        </div>

        <?php // Listing, not access: anyone with the link can open it either way. ?>
        <label class="owa_switch" for="owa_funnelShared">
            <input type="checkbox" role="switch" id="owa_funnelShared" value="1"
                   name="<?php echo $view->getNs();?>isShared"
                   aria-describedby="owa_funnelSharedHint"
                   <?php echo ! empty( $owa_v['is_shared'] ) ? 'checked="checked"' : '';?>>
            <span class="owa_switchTrack" aria-hidden="true"></span>
            Shared in everyone's list
        </label>
        <span id="owa_funnelSharedHint" class="owa_srOnly">Anyone you send the link to can open it either way.</span>

        <div class="owa_editorActions">
            <a class="owa_button" id="owa_funnelCancel" href="<?php echo $owa_id
                ? $view->makeLink( array( 'do' => 'base.report',
                    'reportId' => \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX . $owa_id ), true )
                : $view->makeLink( array( 'do' => 'base.customReports' ), true );?>">Cancel</a>
            <input class="owa_button is-primary" type="submit" id="owa_visualizationSubmit"
                   name="<?php echo $view->getNs();?>submit_btn" value="Save funnel">
        </div>
    </div>

    <?php // Why Save will not go, from the same rules the save applies. ?>
    <p id="owa_visualizationBlocker" class="owa_builderBlocker" role="status" style="display:none;"></p>

    <div class="owa_funnelColumns">

        <section class="owa_funnelBuild" aria-labelledby="owa_funnelStepsHeading">

            <h2 id="owa_funnelStepsHeading">Steps <span class="owa_builderRequired">Required</span></h2>
            <p class="owa_builderHelp">In order, first to last. A page is matched on its path; an event is
                one of this Property's goal events, counted wherever it would count a conversion.</p>

            <ol class="owa_funnelStepList" id="owa_goalEventFunnel">
            <?php foreach ( $owa_steps as $owa_stepIndex => $owa_step ):?>
            <?php
                $owa_step     = (array) $owa_step;
                $owa_stepNo   = (int) $owa_stepIndex + 1;
                $owa_stepGoal = (string) ( $owa_step['goal_event_id'] ?? '' );
                $owa_stepErrors = array();

                foreach ( array( 'stepName', 'stepPath', 'stepGoalEvent' ) as $owa_key ) {
                    $owa_field = $owa_key . $owa_stepNo;
                    $owa_shownErrors[ $owa_field ] = true;
                    if ( ! empty( $owa_errors[ $owa_field ] ) ) {
                        $owa_stepErrors[] = $owa_errors[ $owa_field ];
                    }
                }

            ?>
                <?php include( 'visualization_edit_step.php' ); ?>
            <?php endforeach;?>
            </ol>

            <button type="button" class="owa_button owa_funnelAdd" id="owa_funnelAddStep">
                <i class="fas fa-plus" aria-hidden="true"></i> Add a step</button>

            <?php foreach ( array_diff_key( $owa_errors, $owa_shownErrors ) as $owa_otherError ):?>
            <p class="validation_error"><?php $view->out( $owa_otherError );?></p>
            <?php endforeach;?>

            <h2 id="owa_funnelFiltersLabel">Filters</h2>
            <?php
                $owa_fp = array(
                    'pills' => 'owa_funnelFilters', 'add' => 'owa_funnelAddFilter', 'form' => 'owa_funnelFilterForm',
                    'dimension' => 'owa_funnelFilterDimension', 'operator' => 'owa_funnelFilterOperator',
                    'value' => 'owa_funnelFilterValue', 'addFilter' => 'owa_funnelFilterAdd',
                    'cancel' => 'owa_funnelFilterCancel', 'label' => 'owa_funnelFiltersLabel',
                );
                include( 'filter_pills.php' );
            ?>
            <p class="owa_builderHelp">A filter applies to every step.</p>
            <?php if ( ! empty( $owa_errors['constraints'] ) ):?>
            <p class="validation_error"><?php $view->out( $owa_errors['constraints'] );?></p>
            <?php endif;?>
        </section>

        <section class="owa_funnelPreview" aria-labelledby="owa_funnelPreviewHeading">
            <h2 id="owa_funnelPreviewHeading">Preview</h2>
            <p class="owa_funnelPreviewNote">Sessions that reached each step, in order, last 30 days.</p>
            <div id="owa_funnelPreview" aria-live="polite"></div>
        </section>
    </div>

    <?php // One blank step, for Add a step to copy. ?>
    <template id="owa_funnelStepTemplate">
        <?php
            $owa_step = array(); $owa_stepNo = 0; $owa_stepGoal = ''; $owa_stepErrors = array();
            include( 'visualization_edit_step.php' );
        ?>
    </template>
</form>

<?php if ( $owa_id ):?>
<div class="owa_editorFoot">
    <form method="post" action="<?php echo $view->makeLink( array( 'do' => 'base.customReportDelete' ) );?>">
        <?php echo $view->createNonceFormField( 'base.customReportDelete' );?>
        <input type="hidden" name="<?php echo $view->getNs();?>customReportId" value="<?php $view->out( $owa_id );?>">
        <?php // A roster with no Property has no navigation, so the Property goes along. ?>
        <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
        <input class="owa_builderDelete" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Delete this funnel"
               data-owa-confirm
               data-owa-confirm-title="Delete this funnel?"
               data-owa-confirm-body="&ldquo;<?php $view->out( $owa_v['name'] ?? '' );?>&rdquo; disappears from the list. Nothing it counted is removed."
               data-owa-confirm-proceed="Delete funnel">
    </form>
</div>
<?php endif;?>

<script>
jQuery( function () {

    var MAX_STEPS   = <?php echo (int) ( $view->maxSteps ?: 10 );?>;
    var DIMENSIONS  = <?php echo json_encode( array_values( (array) $view->dimensionChoices ) );?>;
    var PREVIEW_URL = <?php echo json_encode( $view->makeLink( array(
        'do'          => 'base.visualizationPreview',
        'period'      => 'last_thirty_days',
        'funnelScope' => 'session',
    ), true ) );?>;

    var $list = jQuery( '#owa_goalEventFunnel' );

    var filters = new OWA.filterPills( {
        pills: 'owa_funnelFilters', add: 'owa_funnelAddFilter', form: 'owa_funnelFilterForm',
        dimension: 'owa_funnelFilterDimension', operator: 'owa_funnelFilterOperator',
        value: 'owa_funnelFilterValue', addFilter: 'owa_funnelFilterAdd', cancel: 'owa_funnelFilterCancel'
    }, DIMENSIONS, function () {
        jQuery( '#owa_funnelConstraints' ).val( filters.get() );
        refresh();
    } );

    filters.set( jQuery( '#owa_funnelConstraints' ).val() );

    function rows() {
        return $list.children( '.owa_funnelStep' );
    }

    function readStep( row ) {

        var $row = jQuery( row );

        return {
            kind:          $row.find( '.owa_funnelStepKind' ).val() || 'path',
            path:          jQuery.trim( $row.find( '.owa_funnelStepPath' ).val() || '' ),
            goal_event_id: $row.find( '.owa_funnelStepGoal' ).val() || '',
            name:          $row.find( '.owa_funnelStepName' ).val() || ''
        };
    }

    function valueOf( step ) {
        return step.kind === 'goal_event' ? step.goal_event_id : step.path;
    }

    /** Numbers, labels and the page/event field each row shows, after any change to the list. */
    function syncRows() {

        rows().each( function ( i ) {

            var $row = jQuery( this );
            var n    = i + 1;
            var goal = $row.find( '.owa_funnelStepKind' ).val() === 'goal_event';

            $row.find( '.owa_funnelStepNumber' ).text( n );
            $row.find( '.owa_funnelStepPath' ).toggle( ! goal ).attr( 'aria-label', 'Page path of step ' + n );
            $row.find( '.owa_funnelStepGoal' ).toggle( goal ).attr( 'aria-label', 'Goal event of step ' + n );
            $row.find( '.owa_funnelStepKind' ).attr( 'aria-label', 'What step ' + n + ' matches' );
            $row.find( '.owa_builderMove' ).attr( 'aria-label', 'Move step ' + n );
            $row.find( '.owa_funnelStepRemove' ).attr( 'aria-label', 'Remove step ' + n );
        } );

        jQuery( '#owa_funnelAddStep' ).prop( 'disabled', rows().length >= MAX_STEPS );
    }

    /** Why Save will not go, or ''. The same rules VisualizationSave::validate() applies. */
    function blocker() {

        if ( ! jQuery.trim( jQuery( '#owa_funnelName' ).val() || '' ) ) {
            return 'Give the funnel a name.';
        }

        var kept    = 0;
        var problem = '';

        rows().each( function ( i ) {

            var step = readStep( this );

            if ( ! valueOf( step ) ) {
                return;
            }

            kept++;

            if ( ! problem && step.kind !== 'goal_event' && /^[a-z][a-z0-9+.\-]*:\/\//i.test( step.path ) ) {
                problem = 'Step ' + ( i + 1 ) + ' needs a path, such as /basket, not a full web address.';
            }
        } );

        if ( problem ) {
            return problem;
        }

        if ( ! kept ) {
            return 'Add at least one step.';
        }

        return filters.problem();
    }

    function refresh() {

        var problem = blocker();

        jQuery( '#owa_visualizationSubmit' ).prop( 'disabled', !! problem ).attr( 'title', problem );
        jQuery( '#owa_visualizationBlocker' ).text( problem ).toggle( !! problem );

        schedulePreview();
    }

    // ---- the preview ------------------------------------------------------

    var previewTimer   = null;
    var previewRequest = null;
    var previewAsked   = '';

    function schedulePreview() {

        window.clearTimeout( previewTimer );
        previewTimer = window.setTimeout( preview, 400 );
    }

    function previewMessage( text ) {
        jQuery( '#owa_funnelPreview' ).empty()
            .append( jQuery( '<p class="owa_funnelPreviewEmpty">' ).text( text ) );
    }

    function preview() {

        var steps = [];
        var bad   = false;

        rows().each( function () {

            var step = readStep( this );

            if ( ! valueOf( step ) ) {
                return;
            }

            if ( step.kind !== 'goal_event' && /^[a-z][a-z0-9+.\-]*:\/\//i.test( step.path ) ) {
                bad = true;
            }

            steps.push( step );
        } );

        if ( ! steps.length ) {
            previewAsked = '';
            previewMessage( 'Add a step to see how many sessions reach it.' );
            return;
        }

        if ( bad ) {
            return;
        }

        var url = PREVIEW_URL
            + '&' + OWA.util.appNs( 'steps' ) + '=' + encodeURIComponent( JSON.stringify( steps ) )
            + '&' + OWA.util.appNs( 'funnelFilter' ) + '=' + encodeURIComponent( filters.get() );

        // Nothing it counts has changed.
        if ( url === previewAsked ) {
            return;
        }

        previewAsked = url;

        if ( previewRequest ) {
            previewRequest.abort();
        }

        jQuery( '#owa_funnelPreview' ).addClass( 'is-loading' );

        previewRequest = jQuery.getJSON( url )
            .done( function ( answer ) {

                if ( answer && answer.error ) {
                    previewMessage( answer.error );
                    return;
                }

                drawPreview( ( ( answer && answer.data ) || {} ).steps || [] );
            } )
            .fail( function ( xhr, status ) {

                if ( status !== 'abort' ) {
                    previewAsked = '';
                    previewMessage( 'The preview could not be counted.' );
                }
            } )
            .always( function () {
                jQuery( '#owa_funnelPreview' ).removeClass( 'is-loading' );
            } );
    }

    function percent( part, whole ) {
        return whole > 0 ? ( Math.round( part / whole * 1000 ) / 10 ).toFixed( 1 ) + '%' : '0%';
    }

    function drawPreview( steps ) {

        var $box  = jQuery( '#owa_funnelPreview' ).empty();
        var first = steps.length ? steps[0].reached : 0;

        if ( ! steps.length ) {
            previewMessage( 'These steps could not be counted.' );
            return;
        }

        var $list = jQuery( '<ol class="owa_funnelPreviewSteps">' );

        steps.forEach( function ( step, i ) {

            var $count = jQuery( '<span class="owa_funnelPreviewCount">' )
                .append( jQuery( '<b>' ).text( step.reached.toLocaleString() ) );

            if ( i > 0 ) {
                $count.append( jQuery( '<span class="owa_funnelPreviewRate">' )
                    .text( ' · ' + percent( step.reached, steps[ i - 1 ].reached ) + ' continued' ) );
            }

            $list.append( jQuery( '<li class="owa_funnelPreviewStep">' )
                .append( jQuery( '<span class="owa_funnelPreviewHead">' )
                    .append( jQuery( '<span class="owa_funnelPreviewName">' )
                        .append( jQuery( '<span class="owa_funnelPreviewNumber">' ).text( i + 1 ) )
                        .append( document.createTextNode( ' ' + step.name ) ) )
                    .append( $count ) )
                .append( jQuery( '<span class="owa_funnelPreviewBar" aria-hidden="true">' )
                    .append( jQuery( '<span class="owa_funnelPreviewFill">' )
                        .css( 'width', ( first > 0 ? step.reached / first * 100 : 0 ).toFixed( 1 ) + '%' ) ) ) );
        } );

        $box.append( $list );

        if ( steps.length > 1 ) {

            var last = steps[ steps.length - 1 ];

            $box.append( jQuery( '<p class="owa_funnelPreviewSummary">' )
                .append( jQuery( '<b>' ).text( percent( last.reached, first ) ) )
                .append( document.createTextNode( ' of sessions that reached ' + steps[0].name
                    + ' went on to reach ' + last.name + '.' ) ) );
        }
    }

    // ---- the steps --------------------------------------------------------

    function addStep() {

        if ( rows().length >= MAX_STEPS ) {
            return;
        }

        var $row = jQuery( jQuery( '#owa_funnelStepTemplate' ).html() );

        $list.append( $row );
        syncRows();
        refresh();

        $row.find( '.owa_funnelStepPath' ).trigger( 'focus' );
    }

    function moveStep( from, to ) {

        var $rows = rows();

        if ( from === to || to < 0 || to >= $rows.length ) {
            return false;
        }

        var $moved = $rows.eq( from ).detach();

        if ( to >= rows().length ) {
            $list.append( $moved );
        } else {
            rows().eq( to ).before( $moved );
        }

        syncRows();
        refresh();

        return true;
    }

    jQuery( '#owa_funnelAddStep' ).on( 'click', addStep );

    $list
        .on( 'change', '.owa_funnelStepKind', function () {

            // A different kind of step is a different step: its old name goes.
            jQuery( this ).closest( '.owa_funnelStep' ).find( '.owa_funnelStepName' ).val( '' );
            syncRows();
            refresh();
        } )
        .on( 'input change', '.owa_funnelStepPath, .owa_funnelStepGoal', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).find( '.owa_funnelStepName' ).val( '' );
            refresh();
        } )
        .on( 'click', '.owa_funnelStepRemove', function () {

            var at = jQuery( this ).closest( '.owa_funnelStep' ).index();

            jQuery( this ).closest( '.owa_funnelStep' ).remove();

            if ( ! rows().length ) {
                addStep();
                return;
            }

            syncRows();
            refresh();

            rows().eq( Math.min( at, rows().length - 1 ) ).find( '.owa_funnelStepRemove' ).trigger( 'focus' );
        } )

        // Moving a step: its handle and the arrow keys...
        .on( 'keydown', '.owa_builderMove', function ( e ) {

            var step = { 37: -1, 38: -1, 39: 1, 40: 1 }[ e.which ];

            if ( ! step ) {
                return;
            }

            e.preventDefault();

            var from = jQuery( this ).closest( '.owa_funnelStep' ).index();

            if ( moveStep( from, from + step ) ) {
                rows().eq( from + step ).find( '.owa_builderMove' ).trigger( 'focus' );
                jQuery( '#owa_funnelMoved' ).text( 'Step moved to position ' + ( from + step + 1 ) + ' of ' + rows().length + '.' );
            }
        } )

        /*
         * ...or dragging a step onto another: it takes that step's place.
         * Draggable only while its handle is held, so the text in its field
         * can still be selected.
         */
        .on( 'mousedown', '.owa_builderMove', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).attr( 'draggable', 'true' );
        } )
        .on( 'mouseup', '.owa_builderMove', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).removeAttr( 'draggable' );
        } )
        .on( 'dragstart', '.owa_funnelStep', function ( e ) {

            dragFrom = jQuery( this ).index();

            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData( 'text/plain', String( dragFrom ) );

            jQuery( this ).addClass( 'is-dragging' );
        } )
        .on( 'dragover', '.owa_funnelStep', function ( e ) {

            if ( dragFrom === null ) {
                return;
            }

            e.preventDefault();
            rows().removeClass( 'is-dropTarget' );
            jQuery( this ).not( '.is-dragging' ).addClass( 'is-dropTarget' );
        } )
        .on( 'drop', '.owa_funnelStep', function ( e ) {

            e.preventDefault();

            if ( dragFrom !== null ) {
                moveStep( dragFrom, jQuery( this ).index() );
            }

            dragFrom = null;
        } )
        .on( 'dragend', '.owa_funnelStep', function () {
            dragFrom = null;
            rows().removeClass( 'is-dragging is-dropTarget' ).removeAttr( 'draggable' );
        } );

    var dragFrom = null;

    jQuery( '#owa_funnelName' ).on( 'input', refresh );

    jQuery( '#owa_funnelForm' ).on( 'submit', function ( e ) {

        jQuery( '#owa_funnelConstraints' ).val( filters.get() );

        if ( blocker() ) {
            e.preventDefault();
            refresh();
            return false;
        }
    } );

    syncRows();
    refresh();
} );
</script>
<p id="owa_funnelMoved" class="owa_srOnly" aria-live="polite"></p>
