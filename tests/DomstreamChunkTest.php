<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Domstream\Classes\Chunk;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A `domstream` event is accepted only as the exact tuples the recorder sends,
 * because what is stored is replayed in an operator's browser.
 */
final class DomstreamChunkTest extends TestCase
{
    private function event(array $props): object
    {
        $event = new \OWA\Module\Base\Classes\Event();
        $event->setEventType('domstream');
        $event->setProperties($props + [
            'site_id'      => 'chunk-site',
            'visitor_id'   => '1790000000123456789',
            'session_id'   => '1790000000223456789',
            'recording_id' => '1790000000323456789',
            'seq'          => 2,
            'ts'           => mktime(12, 0, 0, 9, 28, 2026) * 1000000,
            'page_location'=> 'https://chunk.test/pricing?plan=pro',
            'offset_ms'    => 3000,
            'duration_ms'  => 2500,
            'viewport_w'   => 1280,
            'viewport_h'   => 800,
            'page_view_seq'=> 4,
        ]);

        return $event;
    }

    private function samples(array $tuples): string
    {
        return json_encode($tuples);
    }

    public function testTheRecordersTuplesAreStored(): void
    {
        $rows = Chunk::fromEvent($this->event(['samples' => $this->samples([
            [120, 'm', 100, 50],
            [150, 'm', -10, 5],
            [200, 's', 400],
            [30, 'c', 12, 34, 'a', 'buy', ''],
            [10, 'k', 'input', 'email', 'email'],
        ])]));

        $this->assertNotNull($rows, Chunk::refusal());

        $chunk = $rows['chunk'];
        $this->assertSame(5, $chunk['sample_count']);
        $this->assertSame(1, $chunk['click_count']);
        $this->assertSame(1, $chunk['keypress_count']);
        $this->assertSame(2, $chunk['seq']);
        $this->assertSame(4, $chunk['page_view_seq']);
        $this->assertSame(20260928, $chunk['yyyymmdd']);
        $this->assertSame('/pricing', $chunk['page_path']);
        $this->assertSame(strlen($rows['payload']['payload']), $chunk['bytes']);

        $this->assertSame([
            [120, 'm', 100, 50], [150, 'm', -10, 5], [200, 's', 400],
            [30, 'c', 12, 34, 'a', 'buy', ''], [10, 'k', 'input', 'email', 'email'],
        ], json_decode(gzdecode($rows['payload']['payload']), true));
    }

    public function testTheIdIsTheChunksOwnContent(): void
    {
        $a = Chunk::fromEvent($this->event(['samples' => $this->samples([[0, 's', 1]])]));
        $b = Chunk::fromEvent($this->event(['samples' => $this->samples([[5, 's', 2]])]));
        $c = Chunk::fromEvent($this->event(['seq' => 3, 'samples' => $this->samples([[0, 's', 1]])]));

        $this->assertSame($a['chunk']['id'], $b['chunk']['id'], 'a redelivered chunk is the same chunk');
        $this->assertNotSame($a['chunk']['id'], $c['chunk']['id'], 'the next seq is another chunk');
        $this->assertSame($a['chunk']['id'], $a['payload']['id']);
    }

    /** The endpoint's input filter entity-encodes what it passes through. */
    public function testEntityEncodedSamplesAreRead(): void
    {
        $encoded = htmlspecialchars($this->samples([[0, 'k', 'input', 'q', 'q']]), ENT_QUOTES);

        $this->assertNotNull(Chunk::fromEvent($this->event(['samples' => $encoded])), Chunk::refusal());
    }

    /**
     * @dataProvider refused
     */
    public function testAnythingElseIsRefusedWhole($samples, string $because): void
    {
        $this->assertNull(Chunk::fromEvent($this->event(['samples' => $samples])));
        $this->assertStringContainsString($because, Chunk::refusal());
    }

    public static function refused(): array
    {
        return [
            'not JSON'              => ['{nope', 'not a list'],
            'empty'                 => ['[]', 'not a list'],
            'an object'             => ['{"a":1}', 'not a list'],
            'unknown type'          => ['[[0,"x",1]]', 'sample 0'],
            'a move missing an axis'=> ['[[0,"m",1]]', 'sample 0'],
            'a key WITH a key value'=> ['[[0,"k","input","q","q","a"]]', 'sample 0'],
            'a negative pause'      => ['[[-1,"s",1]]', 'sample 0'],
            'a pause over an hour'  => ['[[3600001,"s",1]]', 'sample 0'],
            'markup where a number goes' => ['[[0,"s","<b>"]]', 'sample 0'],
            'a text field too long' => ['[[0,"k","' . str_repeat('x', 256) . '","",""]]', 'sample 0'],
            'no samples'            => ['', 'no samples'],
            'too many samples'      => [json_encode(array_fill(0, 1001, [0, 's', 1])), 'too many'],
        ];
    }

    public function testAChunkWithoutItsIdentityIsRefused(): void
    {
        $samples = $this->samples([[0, 's', 1]]);

        foreach (['recording_id', 'visitor_id', 'session_id'] as $key) {
            $this->assertNull(Chunk::fromEvent($this->event([$key => 'abc', 'samples' => $samples])), $key);
            $this->assertStringContainsString($key, Chunk::refusal());
        }

        $this->assertNull(Chunk::fromEvent($this->event(['seq' => 0, 'samples' => $samples])));
        $this->assertStringContainsString('seq', Chunk::refusal());
    }
}
