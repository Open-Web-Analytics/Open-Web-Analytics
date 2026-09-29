<?php
namespace OWA\Module\Base\Update;

/**
 * Saved custom reports into v2's names, and 1.x funnels into visualizations
 * (PLAN.html 2.21).
 *
 * REPORTS. Names resolve exactly and there is no alias layer, so a report saved
 * on 1.14 naming a renamed or removed metric or dimension fails whole on v2.
 * Each is rewritten once through Classes\Migration\CustomReportRewriter, and
 * every change is printed per report. The original definition is kept in
 * v1_definition. A rewrite that still does not validate is left as it was
 * and named, rather than stored half-fixed.
 *
 * FUNNELS. 1.13 and 1.14 funnels are already visualizations. Those from
 * before 1.13 are still steps inside the `goals` setting of each Profile,
 * which Update025 turned into goal events without their funnels. Each becomes
 * a funnel visualization: its steps as page steps, and its destination as a
 * step on the goal event Update025 made. is_required has no equivalent and
 * is reported where it was set. They are shared and owned by the first
 * administrator, since nobody else can be said to have made them.
 *
 * Idempotent: a rewritten report carries v1_definition and is not touched
 * again, and a converted funnel's id is derived from its site and goal.
 *
 * NOT CLI-ONLY: a handful of rows.
 */
class Update061 extends \OWA\Core\Update {

    var $schema_version = 61;

    var $is_cli_mode_required = false;

    /** Visualization steps, as VisualizationSave allows. */
    const MAX_STEPS = 10;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
        $table  = $entity->getTableName();
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $this->addColumnIfMissing( $entity, 'v1_definition', OWA_DTD_BLOB ) ) {

            $this->e->notice( sprintf( 'Adding %s.v1_definition failed.', $table ) );

            return false;
        }

        return $this->rewriteReports( $table ) && $this->convertFunnels();
    }

    /** Every rewritten report as it was; the converted funnels removed; the column gone. */
    function down() {

        $table = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' )->getTableName();
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( $this->funnelPlans() as $plan ) {

            $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( $plan['id'] ) );
        }

        if ( ! $this->hasBackupColumn( $table ) ) {

            return true;
        }

        if ( $db->query( sprintf( 'UPDATE %s SET definition = v1_definition WHERE v1_definition IS NOT NULL',
                $table ) ) === false ) {

            return false;
        }

        return $db->dropColumn( $table, 'v1_definition' ) !== false;
    }

    private function hasBackupColumn( $table ) {

        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf( "SHOW COLUMNS FROM %s LIKE 'v1_definition'", $table ) );
    }

    private function rewriteReports( $table ) {

        $db       = \OWA\Core\CoreAPI::dbSingleton();
        $rewriter = new \OWA\Module\Base\Classes\Migration\CustomReportRewriter();

        $rows = (array) $db->get_results( sprintf(
            'SELECT id, name, definition FROM %s WHERE v1_definition IS NULL'
            . ' AND ( report_type IS NULL OR report_type = ? OR report_type = ? )',
            $table ), array( '', \OWA\Module\Base\Entity\CustomReport::TYPE_REPORT ) );

        foreach ( $rows as $row ) {

            $row        = (array) $row;
            $raw        = (string) $row['definition'];
            $definition = json_decode( html_entity_decode( $raw, ENT_QUOTES ), true );

            if ( ! is_array( $definition ) ) {

                $this->e->notice( sprintf( 'Custom report "%s" (%s): its definition cannot be read; left as it is.',
                    $row['name'], $row['id'] ) );

                continue;
            }

            $result = $rewriter->rewrite( $definition );

            if ( ! $result['changes'] ) {

                continue;
            }

            $error = \OWA\Module\Base\Classes\CustomReports::validate( $result['definition'] );

            if ( $error !== '' ) {

                $this->e->notice( sprintf( 'Custom report "%s" (%s): left as it is, because the rewrite'
                    . ' still would not render (%s). Changes it needed: %s',
                    $row['name'], $row['id'], $error, implode( '; ', $result['changes'] ) ) );

                continue;
            }

            $ok = $db->query( sprintf( 'UPDATE %s SET definition = ?, v1_definition = ? WHERE id = ?', $table ),
                array( json_encode( $result['definition'] ), $raw, $row['id'] ) );

            if ( $ok === false ) {

                $this->e->notice( sprintf( 'Rewriting custom report %s failed.', $row['id'] ) );

                return false;
            }

            $this->e->notice( sprintf( 'Custom report "%s": %s', $row['name'], implode( '; ', $result['changes'] ) ) );
        }

        return true;
    }

    private function convertFunnels() {

        $plans = $this->funnelPlans();

        if ( ! $plans ) {

            return true;
        }

        $owner = $this->firstAdmin();

        foreach ( $plans as $plan ) {

            $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );
            $report->load( $plan['id'] );

            if ( $report->wasPersisted() ) {

                continue;
            }

            $report->setProperties( array(
                'id'                     => $plan['id'],
                'name'                   => $plan['name'],
                'user_id'                => $owner,
                'report_type'            => \OWA\Module\Base\Entity\CustomReport::TYPE_VISUALIZATION,
                'visualization_type'     => 'funnel',
                'is_shared'              => 1,
                'definition'             => json_encode( array( 'steps' => $plan['steps'] ) ),
                'creation_timestamp'     => time(),
                'last_updated_timestamp' => time(),
            ) );

            if ( $report->create() !== true ) {

                $this->e->notice( sprintf( 'Converting the funnel of goal "%s" failed.', $plan['goal'] ) );

                return false;
            }

            $this->e->notice( sprintf( 'Funnel "%s" converted: %d steps%s.', $plan['name'], count( $plan['steps'] ),
                $plan['notes'] ? '; ' . implode( '; ', $plan['notes'] ) : '' ) );
        }

        return true;
    }

    /**
     * Each pre-1.13 funnel, planned: id, name, steps and what did not carry.
     *
     * @return array[]
     */
    public function funnelPlans() {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $db      = \OWA\Core\CoreAPI::dbSingleton();

        $rows = (array) $db->get_results( sprintf(
            "SELECT scope_id, value FROM %s WHERE name = 'goals' AND scope_type = 'profile'",
            $setting->getTableName() ) );

        $plans = array();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            foreach ( self::planFunnels( $row ) as $plan ) {

                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * One Profile's funnels. Pure, so the rules are tested without a database.
     *
     * @param  array $row scope_id and the serialized goals map
     * @return array[]
     */
    public static function planFunnels( array $row ) {

        $goals = @unserialize( (string) ( $row['value'] ?? '' ), array( 'allowed_classes' => false ) );

        if ( ! is_array( $goals ) ) {

            return array();
        }

        $site_id  = (string) $row['scope_id'];
        $property = Update025::propertyFor( $site_id );
        $plans    = array();

        foreach ( $goals as $number => $goal ) {

            if ( ! is_array( $goal ) || trim( (string) ( $goal['goal_name'] ?? '' ) ) === '' ) {

                continue;
            }

            $details = isset( $goal['details'] ) && is_array( $goal['details'] ) ? $goal['details'] : array();
            $funnel  = isset( $details['funnel_steps'] ) && is_array( $details['funnel_steps'] )
                ? $details['funnel_steps'] : array();

            $funnel = array_values( array_filter( $funnel, function ( $s ) {

                return is_array( $s ) && trim( (string) ( $s['path'] ?? ( $s['url'] ?? '' ) ) ) !== '';
            } ) );

            if ( ! $funnel ) {

                continue;
            }

            usort( $funnel, function ( $a, $b ) {

                return (int) ( $a['step_number'] ?? 0 ) <=> (int) ( $b['step_number'] ?? 0 );
            } );

            $goal_number = (int) ( ( $goal['goal_number'] ?? '' ) ?: $number );
            $steps       = array();
            $notes       = array();

            foreach ( $funnel as $step ) {

                $steps[] = array(
                    'name'        => trim( (string) ( $step['name'] ?? '' ) ),
                    'path'        => trim( (string) ( $step['path'] ?? $step['url'] ) ),
                    'step_number' => count( $steps ) + 1,
                );

                if ( ! empty( $step['is_required'] ) ) {

                    $notes[] = sprintf( 'step "%s" was marked required, which v2 has no equivalent for',
                        $step['name'] ?? $step['path'] ?? '' );
                }
            }

            // The destination: the goal event Update025 made of this goal.
            $goal_event = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

            $steps[] = array(
                'name'          => (string) $goal['goal_name'],
                'goal_event_id' => $goal_event->generateId( 'goal_event:' . $property . ':' . $goal_number ),
                'step_number'   => count( $steps ) + 1,
            );

            if ( count( $steps ) > self::MAX_STEPS ) {

                $notes[] = sprintf( 'kept the first %d of its %d steps and the destination',
                    self::MAX_STEPS - 1, count( $steps ) - 1 );

                $last  = array_pop( $steps );
                $steps = array_slice( $steps, 0, self::MAX_STEPS - 1 );
                $last['step_number'] = self::MAX_STEPS;
                $steps[] = $last;
            }

            $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

            $plans[] = array(
                'id'    => $report->generateId( 'visualization:v1-funnel:' . $site_id . ':' . $goal_number ),
                'name'  => sprintf( '%s funnel (%s, from 1.x)', $goal['goal_name'], self::siteName( $site_id ) ),
                'goal'  => (string) $goal['goal_name'],
                'steps' => $steps,
                'notes' => $notes,
            );
        }

        return $plans;
    }

    private static function siteName( $site_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $site_id ) );

        return $site->wasPersisted() ? (string) ( $site->get( 'name' ) ?: $site->get( 'domain' ) ) : $site_id;
    }

    /** @return string the user_id of the first administrator */
    private function firstAdmin() {

        $user = \OWA\Core\CoreAPI::entityFactory( 'base.user' );

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            "SELECT user_id FROM %s WHERE role = 'admin' ORDER BY id LIMIT 1", $user->getTableName() ) );

        return (string) ( $row['user_id'] ?? '' );
    }
}

?>
