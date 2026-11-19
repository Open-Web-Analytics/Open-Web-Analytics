<?php

use PHPUnit\Framework\TestCase;

/**
 * The getMsg() substitution contract.
 *
 * WHY THIS EXISTS
 * ---------------
 * conf/messages.php entries are two independent sprintf templates -- 'headline'
 * and 'message' -- and a caller normally fills in only one of them. getMsg()
 * substitutes the parts the CALLER supplied, not the parts the TEMPLATE has:
 * filling in 'message' alone must not read $substitutions['headline'], which
 * is an undefined-key warning and then vsprintf(null), a TypeError (a 500 on
 * the password-reset flow, messages 2000/2001).
 *
 * The second half of the contract is the value shape. vsprintf() takes an
 * ARGUMENT LIST, so a substitution value is an array. The call sites pass
 * arrays, and getMsg() casts anyway so that a scalar from a third-party module
 * cannot 500 an admin screen.
 *
 * A message with no substitutions at all must come back verbatim -- that is
 * how all the other ~20 call sites use it, and running an unsubstituted
 * template through vsprintf() would fatal on any literal '%' in the text.
 */
final class MessageSubstitutionTest extends TestCase
{
    private object $base;

    protected function setUp(): void
    {
        // getMsg() needs only the autoloader (Env locates conf/messages.php); the
        // constructor's error/config singletons are irrelevant to it, so skip
        // them rather than boot the whole framework.
        require_once dirname(__DIR__) . '/owa_env.php';

        $this->base = (new ReflectionClass(\OWA\Core\Base::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * Message 2000 substituted, headline left alone.
     * failOnWarning="true" in phpunit.xml makes the undefined-key warning a
     * failure on its own, before the TypeError would be reached.
     */
    public function testSubstitutingOnlyTheMessageLeavesTheHeadlineIntact(): void
    {
        $msg = $this->base->getMsg(2000, ['message' => ['user@example.com']]);

        $this->assertSame('Check your email', $msg['headline']);
        $this->assertStringContainsString('user@example.com', $msg['message']);
        $this->assertStringNotContainsString('%s', $msg['message']);
    }

    /** A bare scalar is one argument, not a TypeError. */
    public function testAScalarSubstitutionIsTreatedAsASingleArgument(): void
    {
        $msg = $this->base->getMsg(2000, ['message' => 'user@example.com']);

        $this->assertStringContainsString('user@example.com', $msg['message']);
    }

    /** Substituting only the headline must not touch the message. */
    public function testSubstitutingOnlyTheHeadlineLeavesTheMessageIntact(): void
    {
        $raw = $this->base->getMsg(3009);
        $msg = $this->base->getMsg(3009, ['headline' => ['ignored']]);

        $this->assertSame($raw['message'], $msg['message']);
    }

    /** The common case: no substitutions, template returned verbatim. */
    public function testAMessageWithoutSubstitutionsIsReturnedVerbatim(): void
    {
        $msg = $this->base->getMsg(3009);

        $this->assertSame('Error', $msg['headline']);
        $this->assertSame(
            'A user with that email address already exists.',
            $msg['message']
        );
    }

    /** An unknown code is an empty array, not a fatal. */
    public function testAnUnknownCodeReturnsAnEmptyArray(): void
    {
        $this->assertSame([], $this->base->getMsg(99999, ['message' => ['x']]));
    }
}
