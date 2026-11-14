<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\Strings;
use OWA\Module\Base\Classes\JobStatus;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Messages with a count say it in the form the count takes (CoreAPI::tn()),
 * in English and in a translation: the job lateness, the custom report
 * roster, the Property delete confirmation and System Health's queue wait.
 * None writes a plural as "(s)".
 */
final class CountedMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        Strings::useTranslations(null);
    }

    public function testHowLateCountsInWholeMinutesHoursAndDays(): void
    {
        Strings::useTranslations([]);

        $this->assertSame('1 minute', JobStatus::howLate(30));
        $this->assertSame('2 minutes', JobStatus::howLate(150));
        $this->assertSame('1 hour', JobStatus::howLate(3600));
        $this->assertSame('5 hours', JobStatus::howLate(5 * 3600 + 59));
        $this->assertSame('1 day', JobStatus::howLate(86400));
        $this->assertSame('3 days', JobStatus::howLate(3 * 86400));

        Strings::useTranslations(['%d day' => ['%d jour', '%d jours']], 'french');

        $this->assertSame('3 jours', JobStatus::howLate(3 * 86400));
    }

    public function testTheRosterCountsItsReports(): void
    {
        Strings::useTranslations([]);

        $this->assertSame('1 report', $this->rosterCount(1));
        $this->assertSame('0 reports', $this->rosterCount(0));
        $this->assertSame('12 reports', $this->rosterCount(12));

        Strings::useTranslations(['%d report' => ['%d rapport', '%d rapports']], 'french');

        $this->assertSame('0 rapport', $this->rosterCount(0));
        $this->assertSame('12 rapports', $this->rosterCount(12));
    }

    public function testDeletingAPropertyCountsTheProfilesItTakes(): void
    {
        Strings::useTranslations([]);

        $this->assertSame(
            '“Docs & Co” and its 1 Observation Profile stop recording and leave reporting. Everything collected is kept, and it can be restored.',
            $this->deleteConfirmation(1)
        );
        $this->assertStringStartsWith('“Docs & Co” and its 3 Observation Profiles stop recording', $this->deleteConfirmation(3));
    }

    public function testSystemHealthCountsTheOldestDueJobsWait(): void
    {
        Strings::useTranslations([]);

        $this->assertStringContainsString('The oldest due job has waited 1 minute.', $this->queueIntro(90));
        $this->assertStringContainsString('The oldest due job has waited 4 minutes.', $this->queueIntro(240));
        $this->assertStringNotContainsString('(s)', $this->queueIntro(240));
    }

    /**
     * No message writes a count's noun as "job(s)": the CLI commands and Core
     * say it in the form the count takes, and System Health through tn().
     *
     * @dataProvider sourcesWithCounts
     */
    public function testNoMessageWritesAPluralAsParenthesisedS(string $file): void
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/[a-z]\(s\)/i', str_replace('http(s)', '', $t[1]))) {
                $found[] = $t[2] . ': ' . $t[1];
            }
        }

        $this->assertSame([], $found);
    }

    public static function sourcesWithCounts(): array
    {
        $files = array_merge(
            glob(OWA_DIR . 'modules/*/Controller/*.php'),
            glob(OWA_DIR . 'Core/*.php'),
            glob(OWA_DIR . 'Core/*/*.php'),
            [OWA_DIR . 'modules/Base/Classes/SystemHealth.php']
        );

        $out = [];

        foreach ($files as $file) {
            $out[str_replace(OWA_DIR, '', $file)] = [$file];
        }

        return $out;
    }

    /** A Template whose links, nonces and user need no request or database. */
    private function template(): \OWA\Core\Template
    {
        return new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return ''; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) { return 'LINK'; }

            function getCurrentUser() {

                return new class { function isCapable($capability) { return true; } };
            }
        };
    }

    private function render(string $file, array $vars): string
    {
        $t = $this->template();

        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template($file));

        return (string) $t->fetch();
    }

    private function rosterCount(int $n): string
    {
        $html = $this->render('custom_reports.php', [
            'custom_reports' => [],
            'roster_count'   => $n,
            'new_kinds'      => ['report' => ['label' => 'Report', 'icon' => 'fas fa-th-large']],
        ]);

        $this->assertSame(1, preg_match('#<span class="owa_rosterCount">(.*?)</span>#', $html, $m));

        return $m[1];
    }

    private function deleteConfirmation(int $profiles): string
    {
        $html = $this->render('property_profile.php', [
            'headline'     => 'Property',
            'property'     => ['id' => 7, 'name' => 'Docs & Co', 'property_type' => 'web'],
            'profileCount' => $profiles,
        ]);

        $this->assertSame(1, preg_match('#data-owa-confirm-body="([^"]*)"#', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    private function queueIntro(int $oldestDueAge): string
    {
        $html = $this->render('system_health.php', ['sections' => ['queue' => [
            'title'    => 'Job queue',
            'level'    => 'green',
            'findings' => [],
            'stats'    => ['due' => 1, 'delayed' => 0, 'running' => 0, 'failed' => 0, 'done' => 3, 'oldest_due_age' => $oldestDueAge],
            'failed'   => [],
        ]]]);

        return (string) preg_replace('/\s+/', ' ', $html);
    }
}
