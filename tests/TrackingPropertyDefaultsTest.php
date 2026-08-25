<?php

use PHPUnit\Framework\TestCase;

/**
 * A required tracking property resolves to a value, never to null.
 *
 * Three separate defects in setTrackerProperties() let a null reach the
 * database, and each one hid the next:
 *
 *  1. THE TYPE WAS APPLIED TOO EARLY. setDataType() ran on the value as it
 *     arrived, BEFORE the callback. So it only ever sanitised input, and
 *     whatever a callback returned went onward untouched -- which is how a
 *     derivation that falls off the end wrote NULL into a column whose declared
 *     type is boolean. setRepeatVisitorFlag did exactly that from 2015 until
 *     8d24fc65.
 *
 *  2. THE DEFAULT COULD NEVER BE FALSY. The guard was
 *     `isset($property['default_value']) && $property['default_value']`, so
 *     `false` and `0` -- the only defaults a boolean or a counter would ever
 *     want -- failed the truthy test. The one case a default exists for was the
 *     one case it was skipped.
 *
 *  3. is_new_visitor's DEFAULT KEY HAD A LEADING SPACE (' default_value'), so
 *     isset() never matched it at all. Dead for the whole life of the file, and
 *     invisible because defect 2 would have discarded it anyway.
 *
 * Why it matters now: until #1028 a null was interpolated as '' and MySQL
 * coerced it to 0, so none of this was observable. PDO binds the null, so it
 * reaches the column intact.
 */
final class TrackingPropertyDefaultsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function helpers()
    {
        return \OWA\Module\Base\Classes\TrackingEventHelpers::getInstance();
    }

    private function event()
    {
        $e = owa_coreAPI::supportClassFactory('base', 'event');
        $e->setEventType('base.page_request');

        return $e;
    }

    /**
     * Defect 3, as a rule rather than a single fix: no option key in any
     * tracking-property map may carry surrounding whitespace.
     *
     * A typo'd key is not a syntax error and not a warning -- it is simply a
     * setting that never applies, and nothing in the suite would have noticed.
     */
    public function testNoPropertyOptionKeyHasStrayWhitespace(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $bad     = [];
        $seen    = 0;

        foreach (['environmental', 'regular', 'derived'] as $type) {

            $map = $service->getMap('tracking_properties_' . $type);

            if (! is_array($map)) {
                continue;
            }

            foreach ($map as $property => $options) {

                if (! is_array($options)) {
                    continue;
                }

                foreach (array_keys($options) as $key) {

                    $seen++;

                    if (! is_string($key) || $key !== trim($key)) {
                        $bad[] = sprintf('%s.%s -> "%s"', $type, $property, $key);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'no tracking property options were read at all');
        $this->assertSame([], $bad,
            'these option keys have surrounding whitespace, so they silently do nothing: '
            . implode(', ', $bad));
    }

    /** is_new_visitor specifically -- the one that carried the typo. */
    public function testIsNewVisitorDeclaresAUsableDefault(): void
    {
        $map = \OWA\Core\CoreAPI::serviceSingleton()->getMap('tracking_properties_regular');

        $this->assertIsArray($map);
        $this->assertArrayHasKey('is_new_visitor', $map);
        $this->assertArrayHasKey('default_value', $map['is_new_visitor'],
            "the key was ' default_value' with a leading space, so it never applied");
    }

    /**
     * Defect 2: a falsy default is applied.
     *
     * No callbacks, no incoming value -- so the default is the only thing that
     * can supply one.
     */
    public function testAFalsyDefaultIsApplied(): void
    {
        $event = $this->event();

        // NO data_type on purpose. With one, the post-filter type resolution
        // supplies false by itself and this passes whether or not the default
        // works -- which is what the first version of this test did.
        $this->helpers()->setTrackerProperties($event, [
            'probe_falsy_default' => [
                'required'      => true,
                'default_value' => false,
            ],
        ]);

        $this->assertNotNull($event->get('probe_falsy_default'),
            'a falsy default was discarded, leaving the property null');
        $this->assertFalse($event->get('probe_falsy_default'));
    }

    public function testAZeroDefaultIsApplied(): void
    {
        $event = $this->event();

        // Likewise untyped: 'integer' would coerce null to 0 on its own.
        $this->helpers()->setTrackerProperties($event, [
            'probe_zero_default' => [
                'required'      => true,
                'default_value' => 0,
            ],
        ]);

        $this->assertSame(0, $event->get('probe_zero_default'));
    }

    /**
     * Defect 1: a callback that returns nothing must not leave the property
     * null when the property declares a type.
     *
     * This is the exact shape of the 2015 bug, reproduced with a callback that
     * still has it.
     */
    public function testACallbackReturningNullIsResolvedByTheDeclaredType(): void
    {
        $event = $this->event();

        $this->helpers()->setTrackerProperties($event, [
            'probe_null_callback' => [
                'required'  => true,
                'data_type' => 'boolean',
                'callbacks' => ['OwaProbeNullCallback::fallsOffTheEnd'],
            ],
        ]);

        $value = $event->get('probe_null_callback');

        $this->assertNotNull($value,
            'a derivation that falls off the end still reaches the column as NULL');
        $this->assertFalse($value, 'a boolean property should resolve to false, not null');
    }

    /**
     * The resolution must be narrow: a callback that deliberately returns a
     * value keeps it. Otherwise this would flatten every real derivation.
     */
    public function testACallbackThatReturnsAValueKeepsIt(): void
    {
        $event = $this->event();

        $this->helpers()->setTrackerProperties($event, [
            'probe_real_callback' => [
                'required'  => true,
                'data_type' => 'boolean',
                'callbacks' => ['OwaProbeNullCallback::returnsTrue'],
            ],
        ]);

        $this->assertTrue($event->get('probe_real_callback'));
    }
}

/** Callbacks are resolved by name, so these need to be a real class. */
class OwaProbeNullCallback
{
    /** Returns true for one branch and nothing for the other -- the 2015 shape. */
    public static function fallsOffTheEnd($value, $event)
    {
        if (false) {
            return true;
        }
    }

    public static function returnsTrue($value, $event)
    {
        return true;
    }
}
