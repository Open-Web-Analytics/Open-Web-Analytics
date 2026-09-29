<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * new_vs_returning: whether the session this event belongs to was the
 * visitor's first.
 *
 * IT STORES THE LABEL, and that is the point of it. The reporting engine
 * GROUPs BY a column and nothing else -- ResultSetManager::applyDimensions()
 * emits `groupBy($dim['column'])`, never an expression -- and the dimension
 * registry has no slot for value labels: registerDimension() takes a name, a
 * column, a family and a data_type. So a stored code cannot be grouped into
 * two buckets and cannot be named. It can only be formatted, by data_type,
 * globally, and the one formatter that fits a two-state column answers Yes and
 * No.
 *
 * WHICH IS HOW THE PIE BUG HAPPENED. v1 spelled this as two boolean
 * dimensions over a nullable tinyint: is_new_visitor and is_repeat_visitor,
 * holding 1, 0 and NULL. Three values, three GROUP BY buckets, and a
 * valueLabels map in dashboard.json folding them back onto two names -- so the
 * chart drew two slices both labelled New. Stamping the label makes the
 * failure unreachable rather than fixed: the column holds one of three known
 * strings, the group-by is a plain indexable column read, and there is nothing
 * left to fold.
 *
 * A REWORDING IS A REBUILD, which is why storing a display string here does not
 * contradict 2.11's rule about sentinels. The cube is derived; changing what
 * this step writes and rebuilding the partition changes every row. The rule
 * exists to stop an OBSERVATION being overwritten by a reading, and there is no
 * observation here to lose -- prior_sessions is still on the row, and
 * priorVisitCount still reads it.
 *
 * NULL IS NOT NEW. prior_sessions is nullable: ingest writes NULL when the
 * tracker sent nothing numeric for it (Handler\EventRawHandlers::number()), and
 * that is an unknown rather than a first visit. Calling it New would turn a
 * gap in the beacon into a claim about the visitor, so it gets the same
 * sentinel the acquisition columns use.
 */
class NewVsReturningStep extends Step {

    /** The visitor's first session. */
    const IS_NEW = 'New';

    /** Any session after it. */
    const IS_RETURNING = 'Returning';

    /** The raw column the reading is taken from. */
    const SOURCE = 'prior_sessions';

    public function execute( Context $context ) {

        // No requires(): prior_sessions is on the candidate row itself, so this
        // costs no join.
        return sprintf(
            'CASE WHEN %1$s.%2$s IS NULL THEN %3$s'
          . ' WHEN %1$s.%2$s > 0 THEN %4$s ELSE %5$s END',
            Context::RAW,
            self::SOURCE,
            $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ),
            $context->literal( self::IS_RETURNING ),
            $context->literal( self::IS_NEW ) );
    }
}

?>
