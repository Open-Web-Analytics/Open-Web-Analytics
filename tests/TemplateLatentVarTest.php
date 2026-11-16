<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Regression guard for latent variables the $view migration surfaced.
 *
 * The migration to ViewScope (see ViewScopeCompatTest) rewrote OWA's own
 * templates to read view data as `$view->name`. An undefined bare variable is a
 * warning that yields null, whereas `$view->neverSet` throws, so these tests
 * render the ACTUAL template files with the data their views set.
 */
final class TemplateLatentVarTest extends TestCase
{
    /**
     * A Template for the admin add/edit forms. Stubs only the helpers that would
     * otherwise need a request/DB (nonce fields, links, the param namespace) --
     * everything the form template decides for itself is left real.
     */
    private function formSpy(): object
    {

        return new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return "<!--nonce:$action-->"; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

                return 'LINK';
            }
        };
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(object $t, string $file, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }
        $this->assertTrue($t->set_template($file), "could not locate template $file");
        return $t->fetch();
    }

    /**
     * The three admin add/edit forms render on the ADD path, where the record
     * does not exist yet.
     *
     * WHY THIS IS THE TEST THAT MATTERS. Each form serves both add and edit, and
     * on the add path the record vars are empty. They used to be read as bare
     * variables behind an @, which silently yielded null. They are now
     * $view->site / $view->user / $view->goal, and $view->neverSet THROWS -- so if a
     * view ever stops setting one of these unconditionally, this test fails
     * instead of an admin getting a 500. The hoisting that makes that safe lives
     * in Core/View.php (validation_errors) and View/SitesAdd.php +
     * View/SitesProfile.php (site, config).
     *
     * @dataProvider addPathForms
     * @param array<string, mixed> $vars
     * @param array<int, string>   $expected
     */
    public function testAdminFormsRenderOnTheAddPath(string $template, array $vars, array $expected): void
    {
        // The add path renders with an EMPTY record, so every read of a record
        // field has to be guarded. An unguarded one is only a PHP warning --
        // the form still renders and the assertions below still pass -- so the
        // diagnostics are captured explicitly. Without this the template can
        // warn on every render and nothing goes red: options_goal_entry.php did
        // exactly that for $goal['goal_name'] and $goal['url'], visible only in
        // a CI log nobody reads.
        $diagnostics = [];

        set_error_handler(static function ($no, $str, $file, $line) use (&$diagnostics) {
            $diagnostics[] = sprintf('%s (%s:%d)', $str, basename((string) $file), $line);
            return true;
        });

        try {
            $out = $this->render($this->formSpy(), $template, $vars);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, "$template raised PHP diagnostics on the add path");

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $out, "$template: missing $needle");
        }
        // An empty record must not leak a literal 'null' or an array-to-string
        // conversion into a form field.
        $this->assertStringNotContainsString('value="null"', $out);
        $this->assertStringNotContainsString('Array', $out);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, string>}>
     */
    public static function addPathForms(): array
    {
        $users = [['id' => '7', 'user_id' => 'admin', 'real_name' => 'Ada L', 'role' => 'admin']];

        return [
            'sites_addoredit (add)' => [
                'sites_addoredit.php',
                [
                    'headline' => 'Add Site', 'action' => 'base.sitesAdd', 'edit' => false,
                    'users' => $users, 'site_id' => '',
                    // what the hoisted views now always provide
                    'site' => [], 'config' => [], 'validation_errors' => [],
                ],
                /*
                 * The settings nonce moved with its form. sites_addoredit is the
                 * Profile's DETAILS now -- observation settings and access are
                 * their own screens under the hierarchy nav, so the details
                 * template carries only its own nonce.
                 */
                ['name="domain"', '<!--nonce:base.sitesAdd-->'],
            ],
            'users_addoredit (add)' => [
                'users_addoredit.php',
                [
                    'headline' => 'Add user', 'action' => 'base.usersAdd', 'edit' => false,
                    'isAdmin' => false, 'roles' => ['admin', 'viewer'], 'user' => [],
                ],
                ['name="user_id"', 'name="email_address"'],
            ],
            /*
             * A funnel is a VISUALIZATION now, built on the reporting side, so
             * this is the screen that owns its steps.
             */
            'visualization_edit (add)' => [
                'visualization_edit.php',
                [
                    /*
                     * Exactly what View\VisualizationEdit sets. The KIND is no
                     * longer a list to choose from -- it was answered in the
                     * modal on the roster and arrives here settled, so the
                     * screen is handed the one kind and its words rather than
                     * every kind.
                     */
                    'visualization' => [], 'visualizationId' => '', 'steps' => [],
                    'visualizationType' => 'funnel',
                    'visualizationTypeLabel' => 'Funnel',
                    'visualizationTypeHint' => 'How many people reached each step of a path.',
                    'visualizationTypeIcon' => 'fas fa-filter',
                    // The events a step may match, and the Property's own:
                    // EMPTY on purpose, the case a foreach over an unset var
                    // would not distinguish from a missing one.
                    'eventNames' => ['page_view', 'click'], 'customEventNames' => [],
                    'conditionVocabulary' => ['page_view' => [
                        ['name' => 'page_path', 'label' => 'Page path', 'description' => '', 'carried' => true]]],
                    // The step a new funnel opens with, and Add a step copies.
                    'blankStep' => [
                        'name' => '', 'picked' => 'page_view', 'custom_event' => '', 'match' => 'all',
                        'conditions' => [['property' => 'page_path', 'operator' => 'exact', 'value' => '']],
                        'properties' => [['name' => 'page_path', 'label' => 'Page path', 'description' => '', 'carried' => true]],
                    ],
                    'propertyId' => '7000000000000001',
                    'validation_errors' => [],
                    // The step cap, read from VisualizationSave so the form
                    // cannot offer a step the save then refuses.
                    'maxSteps' => 10,
                    // The funnel's filter, and what it may filter on.
                    'constraints' => '', 'dimensionChoices' => [],
                ],
                ['name="name"', 'name="stepEvent[]"', 'name="stepConditionValue[0][]"', 'id="owa_funnelVocabulary"',
                 'name="visualizationType"'],
            ],
            /*
             * The signed-in user's own account. Rendered for somebody who may
             * NOT change their address, because that branch renders a different
             * field and is the one a fixture is most likely to miss.
             */
            /*
             * The installer's environment report.
             *
             * Rendered nowhere else in the test suite: the web-wizard e2e walks
             * a HEALTHY environment, which routes straight past this screen to
             * the config form, so the only time it is drawn is the one nobody
             * exercises.
             */
            'install_check_env (failures present)' => [
                'install_check_env.php',
                [
                    'checks' => [
                        [ 'name' => 'PHP version', 'value' => '8.2.33',
                          'passed' => true, 'msg' => '' ],
                        [ 'name' => 'Database driver', 'value' => 'none found',
                          'passed' => false, 'msg' => 'Install pdo_mysql or mysqli.' ],
                    ],
                    'errors' => [
                        [ 'name' => 'Database driver', 'value' => 'none found',
                          'passed' => false, 'msg' => 'Install pdo_mysql or mysqli.' ],
                    ],
                ],
                ['owa_installCheck', 'PHP version', 'Database driver',
                 'Install pdo_mysql or mysqli.'],
            ],
            'my_profile (no email capability)' => [
                'my_profile.php',
                [
                    'user_id' => 'someone@example.test',
                    'real_name' => 'Someone',
                    'email_address' => 'someone@example.test',
                    'role' => 'viewer',
                    'may_edit_email' => false,
                    'my_profile_error' => '',
                    'my_profile_saved' => false,
                    'siteId' => 'owa-e2e',
                    'min_password_length' => 6,
                ],
                ['name="real_name"', 'name="current_password"', 'name="new_password"'],
            ],
            'goal_event_definition_edit (add)' => [
                'goal_event_definition_edit.php',
                [
                    /*
                     * Exactly what View\GoalEventDefinitionEdit sets on the ADD path --
                     * goalEventDefinition is an empty array there, not absent, and this
                     * test exists because a template reading a var nobody set
                     * used to render blank rather than fail.
                     */
                    'headline' => 'New Goal Event Definition', 'propertyId' => '7000000000000001',
                    'goalEventDefinition' => [], 'goalEventDefinitionId' => '',
                    'conditionProperties' => [ [ 'name' => 'page_path', 'label' => 'Page path',
                        'description' => 'The path of the page.', 'carried' => true ] ],
                    'conditionVocabulary' => [ 'page_view' => [ [ 'name' => 'page_path', 'label' => 'Page path',
                        'description' => 'The path of the page.', 'carried' => true ] ], 'click' => [] ],
                    'savedProperties' => [],
                    'triggerEvent' => 'page_view',
                    'triggerEvents' => [ 'page_view', 'click' ],
                    'customTriggerEvents' => [ 'newsletter_signup' ], 'customTriggerEvent' => '',
                    'funnelSteps' => [],
                    'conditions' => [], 'validation_errors' => [],
                ],
                ['name="name"', 'name="conditionValue[]"', 'id="owa_goalVocabulary"',
                 'data-description="The path of the page."', 'value="newsletter_signup"',
                 'name="customTriggerEvent"', 'value="once_per_event" selected'],
            ],
        ];
    }

    /**
     * Core/View sets validation_errors UNCONDITIONALLY, so a form template can
     * read it without knowing which controller branch it arrived through.
     *
     * It used to be set only when the key was already in $this->data, which is
     * what forced sites_addoredit.php to read it behind an @. An empty array
     * rather than null keeps if()/empty() behaving exactly as they did when the
     * key was simply absent. Asserted against the source because the alternative
     * -- standing up a full View with a body template -- would test the harness
     * more than the guarantee.
     */
    public function testValidationErrorsIsAlwaysSetOnTheBody(): void
    {
        $src = file_get_contents(__DIR__ . '/../Core/View.php');

        // Single-quoted on purpose. These were double-quoted, so "$this->data"
        // interpolated the TEST's own (undefined) $data property and collapsed
        // to the empty string: the negative assertion searched for
        // "if (array_key_exists('validation_errors', )) {" and passed no matter
        // what View.php contained, and the positive one matched only by
        // accident. The undefined-property warning was the only trace, and
        // nothing was configured to show it.
        $this->assertStringNotContainsString(
            'if (array_key_exists(\'validation_errors\', $this->data)) {',
            $src,
            'validation_errors is conditional again -- a form reading $view->validation_errors will throw'
        );
        $this->assertStringContainsString(
            '$this->data[\'validation_errors\'] ?? []',
            $src,
            'expected the unconditional set with an [] default'
        );
    }
}
