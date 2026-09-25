<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The stages of ingest, and the filter points at each one.
 *
 * WHY THESE EXIST. Ingest was four procedural stages with no seams, and the
 * only way to influence it was to edit the stage. That is how the beacon compat
 * layer ended up unable to do its job: it renames names an older tracker sends,
 * apply() runs in the PROPERTY stage, and the gate that had to admit those
 * names first is in the REQUEST stage -- so the layer needed to participate in a
 * stage it had no access to, and the bridge went unreachable the moment that
 * gate became an allowlist. No test caught it, because no test sent an old name.
 *
 * TWO POINTS PER STAGE, and the pairing is the contract:
 *
 *   PRE   runs before the stage does anything, on what the stage was handed.
 *         A compat layer normalises here: the stage then sees only the current
 *         shape and needs no knowledge of any older one.
 *
 *   POST  runs after the stage's own work and immediately before handoff, on
 *         what the next stage will receive. A layer that needs the stage's
 *         output -- a derived value, an assembled row -- works here.
 *
 * Which one a thing belongs at is decided by what it needs to see, and the two
 * names make that a choice rather than an accident.
 *
 * THE VALUE IS CHAINED, THE CONTEXT IS NOT. Every point passes the thing the
 * stage is working on as the filtered value, plus whatever else the stage can
 * offer as unchained context -- so a listener can read the event while filtering
 * a row without being able to swap the event.
 *
 * A POINT WITH NO LISTENERS COSTS ONE ARRAY LOOKUP. EventDispatch returns the
 * value untouched when nothing is attached, which is the normal case: these are
 * seams, not work.
 */
class Ingest {

    /**
     * STAGE 1 -- REQUEST. log.php: the wire becomes an event.
     *
     * pre:  the decoded request parameters, before the allowlist. A name an
     *       older tracker sends is admitted here or not at all.
     * post: the event, built and carrying the admitted parameters, before
     *       logEvent() is called.
     */
    const REQUEST_PRE  = 'ingest.request.pre';
    const REQUEST_POST = 'ingest.request.post';

    /**
     * STAGE 2 -- EDGE. CoreAPI::logEvent(): what the server observed, and the
     * gates.
     *
     * pre:  the event as it arrived, before the environmental stamp. This is
     *       the last point before anything is decided about the request.
     * post: the event with the edge stamp on it and every gate passed, before
     *       it is queued or dispatched.
     *
     * THE STAMP IS THE PERISHABLE PART. ip_address, the user agent, the host,
     * the language and the receipt time come from $_SERVER, which is gone by the
     * time a queued event is drained -- possibly on another machine. A listener
     * at post is the last thing that can see the request at all.
     */
    const EDGE_PRE  = 'ingest.edge.pre';
    const EDGE_POST = 'ingest.edge.post';

    /**
     * STAGE 3 -- PROPERTY. ProcessEvent: the maps are applied.
     *
     * pre:  the event before any callback has run. The beacon compat layer
     *       normalises here, which is where Compat::apply() already sat.
     * post: the event with every regular and derived property resolved, before
     *       it is dispatched to the handlers.
     */
    const PROPERTY_PRE  = 'ingest.property.pre';
    const PROPERTY_POST = 'ingest.property.post';

    /**
     * STAGE 4 -- STORE. EventRawHandlers: the event becomes rows.
     *
     * pre:  the event before it is expanded, so a listener sees one beacon
     *       rather than the several rows it becomes.
     * post: each assembled row, immediately before INSERT. The row is complete
     *       here -- deviceColumns and taggedColumns are merged, which they are
     *       not while the row literal is being built -- so this is where a
     *       decision about the row belongs. Goal marking is the case.
     *
     * post RUNS PER ROW, not once per beacon, and receives the event as context.
     * A page_view expands to three rows and each is marked on its own facts,
     * which is what lets a goal target session_start.
     */
    const STORE_PRE  = 'ingest.store.pre';
    const STORE_POST = 'ingest.store.post';

    /**
     * Every point, in the order a beacon meets them.
     *
     * Enumerated so a test can assert the set rather than trusting a list
     * written by hand somewhere else.
     *
     * @return string[]
     */
    public static function points() {

        return array(
            self::REQUEST_PRE,  self::REQUEST_POST,
            self::EDGE_PRE,     self::EDGE_POST,
            self::PROPERTY_PRE, self::PROPERTY_POST,
            self::STORE_PRE,    self::STORE_POST,
        );
    }

    /**
     * Run one point.
     *
     * A thin wrapper so a stage reads as `Ingest::at( Ingest::STORE_POST, $row,
     * $event )` rather than naming the filter mechanism, and so the points stay
     * greppable as constants instead of scattered strings.
     *
     * @param  string $point    one of the constants above
     * @param  mixed  $value    chained through the listeners
     * @param  mixed  ...$context  passed unchanged to every listener
     * @return mixed
     */
    public static function at( $point, $value, ...$context ) {

        return \OWA\Core\CoreAPI::filter( $point, $value, ...$context );
    }

}

?>
