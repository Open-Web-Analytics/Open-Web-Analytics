<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;
use OWA\Module\Base\Classes\ResultSetManager;
use OWA\Module\Base\Classes\StoredLabels;
use OWA\Module\Base\Classes\V2Event;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A constraint is applied as its dimension's type and stored values need:
 *
 *   - the empty test of a number column is NULL alone ('' compares as 0), and
 *     a Yes/No dimension refuses it (false is NULL);
 *   - "(unknown)" filters on the sentinel a build could not resolve, alone or
 *     as one part of a joined dimension;
 *   - a stored word (a channel, New or Returning) is accepted in the words
 *     the locale shows;
 *   - a date typed into a date-and-time dimension is the day's span of epoch
 *     seconds.
 *
 * What the constraint becomes is read off a Db that records it; which rows it
 * selects is TypedConstraintRowsTest's business.
 */
final class TypedConstraintTest extends TestCase
{
    protected function tearDown(): void
    {
        Strings::useTranslations(null);
        Strings::useLocale(null);
    }

    /** A Db that records the conditions it is given. */
    private static function recorder(): object
    {
        return new class extends \OWA\Core\Db {

            public $conditions = [];

            public function __construct()
            {
            }

            public function where($name, $value, $operator = '=', $key = null)
            {
                $this->conditions[] = [ 'name' => $name, 'value' => $value, 'operator' => $operator ];
            }

            public function whereSql($key, $sql, array $bindings = [])
            {
                $this->conditions[] = [ 'name' => $sql, 'value' => $bindings, 'operator' => 'sql' ];
            }
        };
    }

    /** [ conditions, errors ] for one constraint. */
    private function applied(string $name, string $operator, string $value): array
    {
        $rsm = new ResultSetManager();
        $db  = self::recorder();

        $rsm->applyConstraint([ 'name' => $name, 'operator' => $operator, 'value' => $value ], $db,
            \OWA\Core\CoreAPI::entityFactory('base.event'));

        $errors = new \ReflectionProperty($rsm, 'errors');

        return [ $db->conditions, (array) $errors->getValue($rsm) ];
    }

    private function clause(string $operator): string
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $m  = new \ReflectionMethod($db, '_makeConstraintClause');

        return preg_replace('/\s+/', ' ', trim($m->invoke($db, 'WHERE',
            [ [ 'name' => 'prior_sessions', 'operator' => $operator, 'value' => '(not set)' ] ])));
    }

    // ---- the empty test, by type -----------------------------------------

    public function testTheNullTestsAreNullAlone(): void
    {
        $this->assertSame('WHERE prior_sessions IS NULL', $this->clause('null'));
        $this->assertSame('WHERE prior_sessions IS NOT NULL', $this->clause('notnull'));
    }

    public function testANumberDimensionsNotSetIsNull(): void
    {
        foreach ([ 'priorSessionCount', 'hour', 'minute', 'anchorX' ] as $name) {

            [ $conditions, $errors ] = $this->applied($name, '==', '(not set)');

            $this->assertSame([], $errors, $name);
            $this->assertSame('null', $conditions[0]['operator'] ?? null, $name);

            [ $conditions ] = $this->applied($name, '!=', '(not set)');

            $this->assertSame('notnull', $conditions[0]['operator'] ?? null, $name);
        }
    }

    /** A text dimension keeps the test of both states, NULL and ''. */
    public function testATextDimensionsNotSetIsStillNullOrEmpty(): void
    {
        [ $conditions ] = $this->applied('sessionCampaign', '==', '(not set)');

        $this->assertSame('empty', $conditions[0]['operator'] ?? null);
    }

    public function testAYesNoDimensionRefusesNotSet(): void
    {
        foreach ([ 'isOutbound', 'isGoalEvent', 'isEngagedSession' ] as $name) {

            foreach ([ '==', '!=' ] as $operator) {

                [ $conditions, $errors ] = $this->applied($name, $operator, '(not set)');

                $this->assertSame([], $conditions, "$name$operator(not set) was applied");
                $this->assertStringContainsString('is Yes or No, so it cannot be (not set)', implode(' ', $errors), $name);
            }
        }
    }

    /** From a request it is a 400, like any other malformed constraint. */
    public function testAYesNoDimensionsNotSetIsARequestError(): void
    {
        $rsm = new ResultSetManager();
        $rsm->setConstraints($rsm->parseConstraintsString('isOutbound==(not set)'));

        $this->assertTrue($rsm->hasRequestErrors());
        $this->assertNotContains('isOutbound', array_keys((array) $rsm->getConstraints()));
    }

    // ---- (unknown) ---------------------------------------------------------

    public function testUnknownFiltersOnTheSentinel(): void
    {
        foreach ([ 'firstSource', 'firstMedium', 'firstCampaign', 'firstChannel', 'newVsReturning' ] as $name) {

            [ $conditions, $errors ] = $this->applied($name, '==', '(unknown)');

            $this->assertSame([], $errors, $name);
            $this->assertSame(V2Event::UNRESOLVED, $conditions[0]['value'] ?? null, $name);
        }

        [ $conditions ] = $this->applied('firstSource', '!=', '(unknown)');

        $this->assertSame(V2Event::UNRESOLVED, $conditions[0]['value'] ?? null);
    }

    /** A joined dimension carries the sentinel in one part. */
    public function testUnknownIsOnePartOfAJoinedDimension(): void
    {
        [ $conditions ] = $this->applied('firstSourceMedium', '==', '(unknown) / referral');

        $this->assertSame(V2Event::UNRESOLVED . ' / referral', $conditions[0]['value'] ?? null);
    }

    public function testARegexIsNotRewritten(): void
    {
        [ $conditions ] = $this->applied('firstSource', '=~', '(unknown)');

        $this->assertSame('(unknown)', $conditions[0]['value'] ?? null);
    }

    // ---- stored words, in the locale ---------------------------------------

    public function testAStoredWordIsShownInTheLocale(): void
    {
        Strings::useLocale('fr_FR');
        Strings::useTranslations([ 'New' => 'Nouveau', 'Organic Search' => 'Recherche naturelle' ]);

        $this->assertSame('Nouveau', StoredLabels::shown('newVsReturning', 'New'));
        $this->assertSame('Returning', StoredLabels::shown('newVsReturning', 'Returning'), 'no translation: as stored');
        $this->assertSame('Recherche naturelle', StoredLabels::shown('channel', 'Organic Search'));
        $this->assertSame('Our Partners', StoredLabels::shown('channel', 'Our Partners'), 'a site\'s own channel');
    }

    public function testAFilterTakesTheTranslatedWord(): void
    {
        Strings::useLocale('fr_FR');
        Strings::useTranslations([ 'New' => 'Nouveau', 'Organic Search' => 'Recherche naturelle' ]);

        [ $conditions ] = $this->applied('newVsReturning', '==', 'nouveau');
        $this->assertSame('New', $conditions[0]['value'] ?? null);

        [ $conditions ] = $this->applied('firstChannel', '!=', 'Recherche naturelle');
        $this->assertSame('Organic Search', $conditions[0]['value'] ?? null);

        [ $conditions ] = $this->applied('attributedChannel', '==', 'Organic Search');
        $this->assertSame('Organic Search', $conditions[0]['value'] ?? null, 'the English still works');

        [ $conditions ] = $this->applied('sessionChannel', '==', 'Somewhere');
        $this->assertSame('Somewhere', $conditions[0]['value'] ?? null);
    }

    /** The dimensions holding stored words say which. */
    public function testTheChannelAndNewVsReturningDimensionsNameTheirSet(): void
    {
        $all = (array) \OWA\Core\CoreAPI::getAllDimensions();

        foreach ([ 'sessionChannel' => 'channel', 'attributedChannel' => 'channel', 'firstChannel' => 'channel',
                   'newVsReturning' => 'newVsReturning' ] as $name => $set) {

            $this->assertSame($set, $all[ $name ]['label_set'] ?? null, $name);
        }
    }

    /** The catalogued lists are the build's. */
    public function testTheListedLabelsAreTheBuildsOwn(): void
    {
        $this->assertSame(StoredLabels::CHANNELS,
            \OWA\Module\Base\Classes\Cube\ChannelStep::channels(
                require \OWA\Core\Env::root() . '/conf/channels.php'));

        $this->assertSame([ \OWA\Module\Base\Classes\Cube\NewVsReturningStep::IS_NEW,
                            \OWA\Module\Base\Classes\Cube\NewVsReturningStep::IS_RETURNING ],
            StoredLabels::NEW_VS_RETURNING);
    }

    // ---- a typed date on a date-and-time dimension -------------------------

    public function testADayIsItsSpanOfSecondsInTheConfiguredZone(): void
    {
        $first = (new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone(date_default_timezone_get())))->getTimestamp();

        $next  = (new \DateTimeImmutable('2026-11-02 00:00:00', new \DateTimeZone(date_default_timezone_get())))->getTimestamp();

        $this->assertSame([ $first, $next - 1 ], ResultSetManager::daySpan('20261101'));

        $this->assertNull(ResultSetManager::daySpan('20261341'), 'not a date');
        $this->assertNull(ResultSetManager::daySpan('1793404800'), 'epoch seconds');
    }

    public function testADateFiltersSessionStartTimeByTheDay(): void
    {
        [ $first, $last ] = ResultSetManager::daySpan('20261101');

        [ $conditions ] = $this->applied('sessionStartTime', '<', '20261101');
        $this->assertSame([ '<', $first ], [ $conditions[0]['operator'], $conditions[0]['value'] ]);

        [ $conditions ] = $this->applied('sessionStartTime', '>', '20261101');
        $this->assertSame([ '>', $last ], [ $conditions[0]['operator'], $conditions[0]['value'] ]);

        [ $conditions ] = $this->applied('sessionStartTime', '==', '20261101');
        $this->assertSame([ 'BETWEEN', [ 'start' => $first, 'end' => $last ] ], [ $conditions[0]['operator'], $conditions[0]['value'] ]);

        [ $conditions ] = $this->applied('sessionStartTime', '!=', '20261101');
        $this->assertSame([ 'sql', [ $first, $last ] ], [ $conditions[0]['operator'], $conditions[0]['value'] ]);

        // Epoch seconds are compared as given.
        [ $conditions ] = $this->applied('sessionStartTime', '<', '1793404800');
        $this->assertSame([ '<', '1793404800' ], [ $conditions[0]['operator'], $conditions[0]['value'] ]);
    }
}
