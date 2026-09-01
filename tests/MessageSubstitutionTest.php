<?php

use PHPUnit\Framework\TestCase;

/**
 * The getMsg() substitution contract.
 *
 * WHY THIS EXISTS
 * ---------------
 * conf/messages.php entries are two independent sprintf templates -- 'headline'
 * and 'message' -- and a caller normally fills in only one of them. getMsg()
 * used to decide whether to substitute by looking at which parts the TEMPLATE
 * has, not which parts the CALLER supplied, so filling in 'message' alone read
 * $substitutions['headline'] anyway: an undefined-key warning on PHP 8 followed
 * by vsprintf(null) -- a TypeError, i.e. a 500, on the password-reset flow
 * (messages 2000/2001, the only substituting call sites in the tree).
 *
 * The second half of the contract is the value shape. vsprintf() takes an
 * ARGUMENT LIST, so a substitution value is an array. Every caller that has
 * ever passed substitutions passed a bare string instead, which on PHP 5/7
 * degraded to a warning and a blank message and on PHP 8 is a hard TypeError.
 * The call sites now pass arrays, and getMsg() casts anyway so that a scalar
 * from a third-party module cannot 500 an admin screen.
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
        // getMsg() needs only OWA_DIR (to locate conf/messages.php); the
        // constructor's error/config singletons are irrelevant to it, so skip
        // them rather than boot the whole framework.
        require_once dirname(__DIR__) . '/owa_env.php';

        $this->base = (new ReflectionClass(\OWA\Core\Base::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * The regression: message 2000 substituted, headline left alone.
     * failOnWarning="true" in phpunit.xml makes the undefined-key warning a
     * failure on its own, before the TypeError would have been reached.
     */
    public function testSubstitutingOnlyTheMessageLeavesTheHeadlineIntact(): void
    {
        $msg = $this->base->getMsg(2000, ['message' => ['user@example.com']]);

        $this->assertSame('Check your e-mail', $msg['headline']);
        $this->assertStringContainsString('user@example.com', $msg['message']);
        $this->assertStringNotContainsString('%s', $msg['message']);
    }

    /**
     * An error-headlined message substitutes the same way.
     *
     * This used 2001, the reset form's error. That message no longer takes a
     * substitution at all -- it deliberately says nothing about the address it
     * was given, because saying anything about it reported whether an account
     * exists. 3008 is the same shape and still has a %s.
     */
    public function testTheErrorMessageSubstitutesTheSameWay(): void
    {
        $msg = $this->base->getMsg(3008, ['message' => ['12']]);

        $this->assertSame('Error', $msg['headline']);
        $this->assertStringContainsString('12', $msg['message']);
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
        $raw = $this->base->getMsg(3010);
        $msg = $this->base->getMsg(3010, ['headline' => ['ignored']]);

        $this->assertSame($raw['message'], $msg['message']);
    }

    /** The common case: no substitutions, template returned verbatim. */
    public function testAMessageWithoutSubstitutionsIsReturnedVerbatim(): void
    {
        $msg = $this->base->getMsg(3010);

        $this->assertSame('Error', $msg['headline']);
        $this->assertSame(
            'A user with that email address does not exist.',
            $msg['message']
        );
    }

    /** An unknown code is an empty array, not a fatal. */
    public function testAnUnknownCodeReturnsAnEmptyArray(): void
    {
        $this->assertSame([], $this->base->getMsg(99999, ['message' => ['x']]));
    }

    /**
     * The call sites the framework actually has: no getMsg() caller may hand a
     * substitution value that is neither an array nor a scalar (an object or
     * null would still be a TypeError inside vsprintf()). Cheap source scan --
     * these two are the only substituting call sites in the tree, and this
     * keeps a third one from being added in the broken shape.
     */
    public function testThePasswordResetCallSitesPassArgumentLists(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/modules/Base/Controller/PasswordResetRequest.php'
        );

        $this->assertSame(
            2,
            preg_match_all("/getMsg\(200[01], \['message' => \[\\\$email_address\]\]\)/", $source),
            'PasswordResetRequest must pass vsprintf() argument lists, not bare strings.'
        );
    }
}
