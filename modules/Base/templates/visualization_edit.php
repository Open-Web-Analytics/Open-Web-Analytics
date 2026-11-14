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
 * Every step is an event and conditions on it (Classes\FunnelStep): a page is
 * a page_view with a page_path condition, which is what a new step starts as.
 * The form posts the steps as parallel arrays -- stepEvent[],
 * stepCustomEvent[], stepMatch[], stepName[] -- in row order, so the order on
 * the screen is the order saved, and each step's conditions as
 * stepConditionProperty[N][] and its siblings, N the step's position
 * (VisualizationSave::rowsFromRequest()). A step's name is not asked for. A
 * stored name rides along in stepName[] while the step it names is unchanged,
 * and a step with none is named for its page or its event.
 */
$owa_v      = (array) $view->visualization;
$owa_type   = (string) ( $view->visualizationType ?: 'funnel' );
$owa_id     = (string) ( $owa_v['id'] ?? '' );
$owa_errors = (array) $view->validation_errors;
$owa_steps  = (array) $view->steps ?: array( (array) $view->blankStep );

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
      action="<?php echo $view->makeLink( array( 'do' => 'base.visualizationSave' ) );?>"
      data-not-carried-label="<?php $view->out( \OWA\Core\CoreAPI::t( '%s -- not carried by %s' ) );?>"
      data-not-carried-help="<?php $view->out( \OWA\Core\CoreAPI::t( 'A %s event does not carry this property.' ) );?>">

    <?php echo $view->createNonceFormField( 'base.visualizationSave' );?>
    <input type="hidden" name="visualizationId" value="<?php $view->out( $view->visualizationId ?? '' );?>">
    <input type="hidden" name="visualizationType" value="<?php $view->out( $owa_type );?>">
    <?php // The saved funnel opens on this Property, as a saved report does. ?>
    <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
    <?php // The filter, written by the script from its pills. ?>
    <input type="hidden" name="constraints" id="owa_funnelConstraints"
           value="<?php $view->out( (string) $view->constraints );?>">

    <div class="owa_editorHead">
        <div class="owa_editorName">
            <div class="owa_builderFieldLabel">
                <label for="owa_funnelName"><?php $view->out( \OWA\Core\CoreAPI::t( 'Funnel name' ) ); ?></label>
                <span class="owa_builderRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span>
            </div>
            <input type="text" id="owa_funnelName" maxlength="255"
                   name="name"
                   placeholder="<?php $view->out( \OWA\Core\CoreAPI::t( 'Untitled funnel' ) ); ?>"
                   value="<?php $view->out( $owa_v['name'] ?? '' );?>">
            <?php if ( ! empty( $owa_errors['name'] ) ):?>
            <span class="validation_error"><?php $view->out( $owa_errors['name'] );?></span>
            <?php endif;?>
        </div>

        <?php // Listing, not access: anyone with the link can open it either way. ?>
        <label class="owa_switch" for="owa_funnelShared">
            <input type="checkbox" role="switch" id="owa_funnelShared" value="1"
                   name="isShared"
                   aria-describedby="owa_funnelSharedHint"
                   <?php echo ! empty( $owa_v['is_shared'] ) ? 'checked="checked"' : '';?>>
            <span class="owa_switchTrack" aria-hidden="true"></span>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'Shared in everyone\'s list' ) ); ?>
        </label>
        <span id="owa_funnelSharedHint" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Anyone you send the link to can open it either way.' ) ); ?></span>

        <div class="owa_editorActions">
            <a class="owa_button" id="owa_funnelCancel" href="<?php echo $owa_id
                ? $view->makeLink( array( 'do' => 'base.report',
                    'reportId' => \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX . $owa_id ), true )
                : $view->makeLink( array( 'do' => 'base.customReports' ), true );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Cancel' ) ); ?></a>
            <input class="owa_button is-primary" type="submit" id="owa_visualizationSubmit"
                   name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save funnel' ) ); ?>">
        </div>
    </div>

    <?php // Why Save will not go, from the same rules the save applies. ?>
    <p id="owa_visualizationBlocker" class="owa_builderBlocker" role="status" style="display:none;"></p>

    <div class="owa_funnelColumns">

        <section class="owa_funnelBuild" aria-labelledby="owa_funnelStepsHeading">

            <h2 id="owa_funnelStepsHeading"><?php $view->out( \OWA\Core\CoreAPI::t( 'Steps' ) ); ?> <span class="owa_builderRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span></h2>
            <p class="owa_builderHelp"><?php $view->out( \OWA\Core\CoreAPI::t( 'In order, first to last. Each step is an event, and optionally what it carries: a page is a page_view with its page path. A step with no conditions matches every event of that name.' ) );?></p>

            <ol class="owa_funnelStepList" id="owa_funnelSteps">
            <?php foreach ( array_values( $owa_steps ) as $owa_stepIndex => $owa_step ):?>
            <?php
                $owa_step       = (array) $owa_step;
                $owa_stepNo     = (int) $owa_stepIndex + 1;
                $owa_stepErrors = array();

                // The step's own messages: stepEvent<N>, and stepCondition<N>_<C> for each condition.
                foreach ( $owa_errors as $owa_field => $owa_message ) {
                    if ( $owa_field === 'stepEvent' . $owa_stepNo
                         || strpos( (string) $owa_field, 'stepCondition' . $owa_stepNo . '_' ) === 0 ) {
                        $owa_shownErrors[ $owa_field ] = true;
                        $owa_stepErrors[] = $owa_message;
                    }
                }

            ?>
                <?php include( 'visualization_edit_step.php' ); ?>
            <?php endforeach;?>
            </ol>

            <button type="button" class="owa_button owa_funnelAdd" id="owa_funnelAddStep">
                <i class="fas fa-plus" aria-hidden="true"></i> <?php $view->out( \OWA\Core\CoreAPI::t( 'Add a step' ) ); ?></button>

            <?php foreach ( array_diff_key( $owa_errors, $owa_shownErrors ) as $owa_otherError ):?>
            <p class="validation_error"><?php $view->out( $owa_otherError );?></p>
            <?php endforeach;?>

            <h2 id="owa_funnelFiltersLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Filters' ) ); ?></h2>
            <?php
                $owa_fp = array(
                    'pills' => 'owa_funnelFilters', 'add' => 'owa_funnelAddFilter', 'form' => 'owa_funnelFilterForm',
                    'dimension' => 'owa_funnelFilterDimension', 'operator' => 'owa_funnelFilterOperator',
                    'value' => 'owa_funnelFilterValue', 'addFilter' => 'owa_funnelFilterAdd',
                    'cancel' => 'owa_funnelFilterCancel', 'label' => 'owa_funnelFiltersLabel',
                );
                include( 'filter_pills.php' );
            ?>
            <p class="owa_builderHelp"><?php $view->out( \OWA\Core\CoreAPI::t( 'A filter applies to every step.' ) ); ?></p>
            <?php if ( ! empty( $owa_errors['constraints'] ) ):?>
            <p class="validation_error"><?php $view->out( $owa_errors['constraints'] );?></p>
            <?php endif;?>
        </section>

        <section class="owa_funnelPreview" aria-labelledby="owa_funnelPreviewHeading">
            <h2 id="owa_funnelPreviewHeading"><?php $view->out( \OWA\Core\CoreAPI::t( 'Preview' ) ); ?></h2>
            <p class="owa_funnelPreviewNote"><?php $view->out( \OWA\Core\CoreAPI::t( 'Sessions that reached each step, in order, last 30 days.' ) ); ?></p>
            <div id="owa_funnelPreview" aria-live="polite"></div>
        </section>
    </div>

    <?php // One blank step, for Add a step to copy. ?>
    <template id="owa_funnelStepTemplate">
        <?php
            $owa_step = (array) $view->blankStep; $owa_stepIndex = 0; $owa_stepNo = 0; $owa_stepErrors = array();
            include( 'visualization_edit_step.php' );
        ?>
    </template>
    <?php // Every event's condition properties, so a step's pickers follow its event (owa.goalbuilder.js). ?>
    <script type="application/json" id="owa_funnelVocabulary"><?php
        echo json_encode( (array) $view->conditionVocabulary,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    ?></script>
</form>

<?php if ( $owa_id ):?>
<div class="owa_editorFoot">
    <form method="post" action="<?php echo $view->makeLink( array( 'do' => 'base.customReportDelete' ) );?>">
        <?php echo $view->createNonceFormField( 'base.customReportDelete' );?>
        <input type="hidden" name="customReportId" value="<?php $view->out( $owa_id );?>">
        <?php // A roster with no Property has no navigation, so the Property goes along. ?>
        <input type="hidden" name="propertyId" value="<?php $view->out( $view->get( 'propertyId' ) );?>">
        <input class="owa_builderDelete" type="submit"
               name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this funnel' ) ); ?>"
               data-owa-confirm
               data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this funnel?' ) ); ?>"
               data-owa-confirm-body="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '“%s” disappears from the list. Nothing it counted is removed.' ), $owa_v['name'] ?? '' ) ); ?>"
               data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete funnel' ) ); ?>">
    </form>
</div>
<?php endif;?>

<script>
jQuery( function () {

    var MAX_STEPS   = <?php echo (int) ( $view->maxSteps ?: 10 );?>;
    var DIMENSIONS  = <?php echo json_encode( array_values( (array) $view->dimensionChoices ) );?>;
    // The words this script writes, translated; %s is the step's number, %1$s, %2$s... the values in order.
    var TEXT = <?php echo json_encode( array(
        'eventLabel' => \OWA\Core\CoreAPI::t( 'The event step %s matches' ),
        'needsEvent' => \OWA\Core\CoreAPI::t( 'Step %s needs an event.' ),
        'fullUrl'    => \OWA\Core\CoreAPI::t( 'Step %s needs a path, such as /basket, not a full web address.' ),
        'moveStep'   => \OWA\Core\CoreAPI::t( 'Move step %s' ),
        'removeStep' => \OWA\Core\CoreAPI::t( 'Remove step %s' ),
        'needsName'  => \OWA\Core\CoreAPI::t( 'Give the funnel a name.' ),
        'needsStep'  => \OWA\Core\CoreAPI::t( 'Add at least one step.' ),
        'noSteps'    => \OWA\Core\CoreAPI::t( 'Add a step to see how many sessions reach it.' ),
        'previewFailed' => \OWA\Core\CoreAPI::t( 'The preview could not be counted.' ),
        'stepsFailed'   => \OWA\Core\CoreAPI::t( 'These steps could not be counted.' ),
        'continued'  => \OWA\Core\CoreAPI::t( '%s continued' ),
        'summary'    => \OWA\Core\CoreAPI::t( '%1$s of sessions that reached %2$s went on to reach %3$s.' ),
        'moved'      => \OWA\Core\CoreAPI::t( 'Step moved to position %1$s of %2$s.' ),
    ), JSON_HEX_TAG | JSON_HEX_AMP );?>;

    function say( pattern, n ) {
        return String( pattern ).replace( '%s', n );
    }

    /** pattern with %1$s, %2$s... replaced by the values in order. */
    function sayEach( pattern, values ) {
        return String( pattern ).replace( /%(\d+)\$s/g, function ( all, i ) {
            return i - 1 < values.length ? String( values[ i - 1 ] ) : all;
        } );
    }

    var PREVIEW_URL = <?php echo json_encode( $view->makeLink( array(
        'do'          => 'base.visualizationPreview',
        'period'      => 'last_thirty_days',
        'funnelScope' => 'session',
    ), true ) );?>;

    var $list   = jQuery( '#owa_funnelSteps' );
    var builder = OWA.funnelBuilder;

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
        return builder.readStep( row );
    }

    /** A step the save keeps: not a row left as it was added. */
    function isStep( step ) {
        return ! builder.isBlankStep( step );
    }

    /** Numbers, labels and the condition fields' position, after any change to the list. */
    function syncRows() {

        rows().each( function ( i ) {

            var $row = jQuery( this );
            var n    = i + 1;

            builder.renumber( this, i );

            $row.find( '.owa_funnelStepNumber' ).text( n );
            $row.find( '.owa_funnelStepEvent' ).attr( 'aria-label', say( TEXT.eventLabel, n ) );
            $row.find( '.owa_builderMove' ).attr( 'aria-label', say( TEXT.moveStep, n ) );
            $row.find( '.owa_funnelStepRemove' ).attr( 'aria-label', say( TEXT.removeStep, n ) );
        } );

        jQuery( '#owa_funnelAddStep' ).prop( 'disabled', rows().length >= MAX_STEPS );
    }

    /** Why Save will not go, or ''. The same rules VisualizationSave::validate() applies. */
    function blocker() {

        if ( ! jQuery.trim( jQuery( '#owa_funnelName' ).val() || '' ) ) {
            return TEXT.needsName;
        }

        var kept    = 0;
        var problem = '';

        rows().each( function ( i ) {

            var step = readStep( this );

            if ( ! isStep( step ) ) {
                return;
            }

            kept++;

            if ( ! problem && step.event === '' ) {
                problem = say( TEXT.needsEvent, i + 1 );
            }

            step.conditions.forEach( function ( c ) {
                if ( ! problem && c.property === 'page_path' && /^[a-z][a-z0-9+.\-]*:\/\//i.test( c.value ) ) {
                    problem = say( TEXT.fullUrl, i + 1 );
                }
            } );
        } );

        if ( problem ) {
            return problem;
        }

        if ( ! kept ) {
            return TEXT.needsStep;
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

            if ( ! isStep( step ) ) {
                return;
            }

            if ( step.event === '' ) {
                bad = true;
            }

            step.conditions.forEach( function ( c ) {
                if ( c.property === 'page_path' && /^[a-z][a-z0-9+.\-]*:\/\//i.test( c.value ) ) {
                    bad = true;
                }
            } );

            steps.push( step );
        } );

        if ( ! steps.length ) {
            previewAsked = '';
            previewMessage( TEXT.noSteps );
            return;
        }

        if ( bad ) {
            return;
        }

        var url = PREVIEW_URL
            + '&' + 'steps' + '=' + encodeURIComponent( JSON.stringify( steps ) )
            + '&' + 'funnelFilter' + '=' + encodeURIComponent( filters.get() );

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
                    previewMessage( TEXT.previewFailed );
                }
            } )
            .always( function () {
                jQuery( '#owa_funnelPreview' ).removeClass( 'is-loading' );
            } );
    }

    // The install's locale (OWA.config.locale, else the one this page was
    // rendered in); undefined is the browser's own.
    function locale() {
        return ( window.OWA && OWA.config && OWA.config.locale )
            || <?php echo json_encode( \OWA\Core\Strings::locale() === \OWA\Core\Strings::SOURCE_LOCALE ? null : \OWA\Core\Strings::htmlLang() ); ?>
            || undefined;
    }

    function formatCount( n ) {
        try {
            return new Intl.NumberFormat( locale() ).format( n );
        } catch ( e ) {
            return n.toLocaleString();
        }
    }

    function percent( part, whole ) {
        var share = whole > 0 ? Math.round( part / whole * 1000 ) / 1000 : 0;

        try {
            return new Intl.NumberFormat( locale(), { style: 'percent', minimumFractionDigits: whole > 0 ? 1 : 0, maximumFractionDigits: 1 } ).format( share );
        } catch ( e ) {
            return whole > 0 ? ( share * 100 ).toFixed( 1 ) + '%' : '0%';
        }
    }

    function drawPreview( steps ) {

        var $box  = jQuery( '#owa_funnelPreview' ).empty();
        var first = steps.length ? steps[0].reached : 0;

        if ( ! steps.length ) {
            previewMessage( TEXT.stepsFailed );
            return;
        }

        var $list = jQuery( '<ol class="owa_funnelPreviewSteps">' );

        steps.forEach( function ( step, i ) {

            var $count = jQuery( '<span class="owa_funnelPreviewCount">' )
                .append( jQuery( '<b>' ).text( formatCount( step.reached ) ) );

            if ( i > 0 ) {
                $count.append( jQuery( '<span class="owa_funnelPreviewRate">' )
                    .text( ' · ' + say( TEXT.continued, percent( step.reached, steps[ i - 1 ].reached ) ) ) );
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
            // The sentence around the bold percentage: the parts before and after %1$s.
            var parts = String( TEXT.summary ).split( '%1$s' );
            var names = [ '', steps[0].name, last.name ];

            $box.append( jQuery( '<p class="owa_funnelPreviewSummary">' )
                .append( document.createTextNode( sayEach( parts[0], names ) ) )
                .append( jQuery( '<b>' ).text( percent( last.reached, first ) ) )
                .append( document.createTextNode( sayEach( parts.slice( 1 ).join( '%1$s' ), names ) ) ) );
        }
    }

    // ---- the steps --------------------------------------------------------

    function addStep() {

        if ( rows().length >= MAX_STEPS ) {
            return;
        }

        var $row = jQuery( jQuery( '#owa_funnelStepTemplate' ).html() ).filter( '.owa_funnelStep' );

        $list.append( $row );
        builder.bindStep( $row[0] );
        syncRows();
        refresh();

        $row.find( '.owa_funnelConditionValue' ).first().trigger( 'focus' );
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
        /*
         * Anything that changes what a step matches makes it a different step:
         * its stored name goes, and it is named for its page or event again.
         */
        .on( 'input change', '.owa_funnelStepEvent, .owa_funnelStepCustomEvent, .owa_funnelStepMatch, '
            + '.owa_funnelConditionProperty, .owa_funnelConditionOperator, .owa_funnelConditionValue', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).find( '.owa_funnelStepName' ).val( '' );
            refresh();
        } )
        // A condition added or removed by its + and X.
        .on( 'owa:rowadded', '.owa_funnelCondition', function () {
            syncRows();
            refresh();
        } )
        .on( 'click', '.owa_funnelConditions .constraintRemoveButton', function () {
            window.setTimeout( refresh, 0 );
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
                jQuery( '#owa_funnelMoved' ).text( sayEach( TEXT.moved, [ from + step + 1, rows().length ] ) );
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

    rows().each( function () {
        builder.bindStep( this );
    } );

    syncRows();
    refresh();
} );
</script>
<p id="owa_funnelMoved" class="owa_srOnly" aria-live="polite"></p>
