<?php
namespace OWA\Module\Base\Controller;

/**
 * Remove one goal event definition.
 *
 * A real delete, not an archive. Unlike a Profile, a definition owns no
 * collected data: the events it counted are ordinary rows that stay exactly as
 * they are, and what is removed is only the instruction to count them. There is
 * nothing to restore that deleting it destroys.
 */
class GoalEventDefinitionDelete extends \OWA\Core\AdminController {

    // edit_settings, and access to the Property the request names, or the goal's.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    /**
     * The Property of the goal event definition the request names.
     *
     * @return string|null
     */
    protected function requestObjectPropertyId() {

        return \OWA\Module\Base\Entity\GoalEventDefinition::propertyIdOf( (string) $this->getParam( 'goalEventDefinitionId' ) );
    }

    public function validate() {

        $this->addValidation( 'goalEventDefinitionId', $this->getParam( 'goalEventDefinitionId' ), 'required' );
    }

    public function action() {

        $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
        $definition->delete( $this->getParam( 'goalEventDefinitionId' ) );

        $this->setRedirectAction( 'base.goalEventDefinitions' );
        $this->setStatusCode( 3220 );
    }
}
