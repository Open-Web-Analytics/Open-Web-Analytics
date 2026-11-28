<?php
namespace OWA\Module\Base\Controller;

/**
 * The goal event definitions of one Property.
 *
 * This lists what exists.
 *
 * "Goal event definition" rather than "goal": each one names the goal events,
 * rows added when a behaviour pattern matches.
 *
 * Other tools call the same idea a key event. Keeping "goal" is deliberate:
 * it is the word 1.x users already have.
 */
class GoalEventDefinitions extends \OWA\Core\AdminController {

    // edit_settings, and access to the Property the request names, or the goal's.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $propertyId = $this->resolveCurrentPropertyId( $this->getParam( 'propertyId' ), $this->getParam( 'siteId' ) );

        $this->set( 'propertyId', $propertyId );
        $this->set( 'goalEventDefinitions', self::listForProperty( $propertyId ) );

        $this->settingsChrome( [ 'propertyId' => $propertyId ], '', $propertyId );
        $this->setSubview( 'base.goalEventDefinitions', [
            'template' => 'goal_event_definitions.php',
            'vars'     => [ 'headline' => \OWA\Core\CoreAPI::t( 'Goal Event Definitions' ),
                            'goalEventDefinitions', 'propertyId' ] ] );
    }

    /**
     * A Property's goal event definitions.
     *
     * Guarded, because Db::where() drops an empty value rather than matching
     * nothing -- no Property would list every definition on the installation.
     *
     * @param  string $propertyId
     * @return array<int, array<string, mixed>> the definitions' rows, each with its step count
     */
    public static function listForProperty( $propertyId ) {

        if ( ! $propertyId ) {

            return [];
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $propertyId );
        // ASC explicitly: orderBy() with no direction is DESC.
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $rows = (array) $db->getAllRows();

        /*
         * How many funnel steps each one has, so the list can offer the funnel
         * report only where there is a funnel to look at. Counted here rather than
         * in the template, which should not be running queries.
         */
        foreach ( $rows as $i => $row ) {

            $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
            $definition->setProperties( $row );

            /*
             * The conditions, summarised for the list. Conditions are rows now,
             * so the definition row itself carries none -- reading one off it
             * showed an empty cell for every definition.
             */
            $summary = [];

            foreach ( $definition->loadConditions() as $condition ) {

                $summary[] = [
                    'property' => $condition->get( 'condition_property' ),
                    'operator' => $condition->get( 'condition_operator' ),
                    'value'    => $condition->get( 'condition_value' ),
                ];
            }

            $rows[ $i ]['conditions']       = $summary;
            $rows[ $i ]['condition_match']  = $definition->conditionMatch();
        }

        return $rows;
    }
}
