--TEST--
Log: Levels
--FILE--
<?php

require_once 'Log.php';

function verify($exp, $msg)
{
    echo $msg . ': ';
    echo ($exp) ? 'pass' : 'fail';
    echo "\n";
}

function testLevels($mask)
{
    echo "Mask: $mask\n";

    for ($priority = PEAR_LOG_EMERG; $priority <= PEAR_LOG_DEBUG; $priority++) {
        $masked = (Log::MASK($priority) & $mask);
        echo "Priority $priority: ";
        echo($masked) ? "masked\n" : "unmasked\n";
    }
}

testLevels(Log::MIN(PEAR_LOG_WARNING));
echo "\n";
testLevels(Log::MAX(PEAR_LOG_WARNING));

--EXPECT--
Mask: 240
Priority 0: unmasked
Priority 1: unmasked
Priority 2: unmasked
Priority 3: unmasked
Priority 4: masked
Priority 5: masked
Priority 6: masked
Priority 7: masked

Mask: 31
Priority 0: masked
Priority 1: masked
Priority 2: masked
Priority 3: masked
Priority 4: masked
Priority 5: unmasked
Priority 6: unmasked
Priority 7: unmasked
