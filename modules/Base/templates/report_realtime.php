<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The realtime screen's frame. OWA.realtime (owa.realtime.js) fills every
 * card from v1/realtime; nothing here reads data itself.
 */
$owa_realtimeApi = $view->makeApiLink( array(
    'do'         => 'realtime',
    'module'     => 'base',
    'version'    => 'v1',
    'propertyId' => $view->get( 'realtime_property_id' ),
) );
?>
<div id="owa_realtime" class="owa_realtime">

    <p class="owa_realtimeLead">
        <?php $view->out( \OWA\Core\CoreAPI::t( 'The last 30 minutes, as events arrive. Refreshes every 15 seconds while this tab is open. Sources are shown as collected; channels are classified when reports are built.' ) );?>
    </p>

    <p class="owa_realtimeStatus" role="status" hidden></p>

    <p class="owa_realtimeQueued" hidden>
        <?php $view->out( \OWA\Core\CoreAPI::t( 'This install queues incoming events, so they appear here when the queue is processed.' ) );?>
    </p>

    <div class="owa_realtimeSummary">

        <div class="owa_realtimeHead">
            <div class="owa_realtimeKpi">
                <div class="owa_realtimeKpiLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Active users, last 30 minutes' ) );?></div>
                <div class="owa_realtimeKpiValue owa_realtimeUsers30">&hellip;</div>
            </div>
            <div class="owa_realtimeKpi">
                <div class="owa_realtimeKpiLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Last 5 minutes' ) );?></div>
                <div class="owa_realtimeKpiValue owa_realtimeUsers5">&hellip;</div>
            </div>
            <div class="owa_realtimePerMinute">
                <div class="owa_realtimeKpiLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Active users per minute' ) );?></div>
                <div class="owa_realtimeBars" aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Active users per minute, oldest first' ) );?>"></div>
            </div>
        </div>

        <div class="owa_realtimeMapCard">
            <div class="owa_realtimeMap" data-card="map"></div>
            <p class="owa_realtimeMapNote"></p>
        </div>

        <div class="owa_realtimeGrid">

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'First user source' ) );?></h3>
                <table class="owa_realtimeTable" data-card="sources">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Source' ) );?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Medium' ) );?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Campaign' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Users' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Pages' ) );?></h3>
                <table class="owa_realtimeTable" data-card="pages">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Page' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Views' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Users' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Event count' ) );?></h3>
                <table class="owa_realtimeTable" data-card="events">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Event' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Count' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Goal completions' ) );?> <span class="owa_realtimeGoalTotal">&hellip;</span></h3>
                <table class="owa_realtimeTable" data-card="goals">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Goal' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Completions' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Countries' ) );?></h3>
                <table class="owa_realtimeTable" data-card="countries">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Country' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Users' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>

            <section class="owa_realtimeCard">
                <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Devices' ) );?></h3>
                <table class="owa_realtimeTable" data-card="devices">
                    <thead><tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Device' ) );?></th><th class="n"><?php $view->out( \OWA\Core\CoreAPI::t( 'Users' ) );?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </section>
        </div>

        <section class="owa_realtimeCard owa_realtimeRecentCard">
            <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Recent events' ) );?></h3>
            <p class="owa_realtimeHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'Select an event to see that visitor’s last 30 minutes.' ) );?></p>
            <ol class="owa_realtimeEvents owa_realtimeRecent"></ol>
        </section>
    </div>

    <div class="owa_realtimeVisitor" hidden>
        <button type="button" class="owa_realtimeBack"><?php $view->out( \OWA\Core\CoreAPI::t( '← Back to the overview' ) );?></button>
        <h3 class="owa_realtimeVisitorId"></h3>
        <ol class="owa_realtimeEvents owa_realtimeVisitorEvents"></ol>
    </div>
</div>

<script>
jQuery( function () {
    new OWA.realtime( document.getElementById( 'owa_realtime' ), {
        apiUrl:   <?php echo json_encode( $owa_realtimeApi ); ?>,
        mapUrl:   <?php echo json_encode( $view->makeImageLink( 'base/i/world-110m.svg' ) ); ?>,
        timezone: <?php echo json_encode( (string) $view->get( 'realtime_timezone' ) ); ?>
    } ).start();
} );
</script>
