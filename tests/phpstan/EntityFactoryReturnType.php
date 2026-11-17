<?php
namespace OWA\PHPStan;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * CoreAPI::entityFactory('base.site') returns an OWA\Module\Base\Entity\Site.
 *
 * The class is found by the factory's own convention
 * (CoreAPI::namespacedEntityClass()). A name that is not a literal leaves the
 * declared type, Entity.
 */
final class EntityFactoryReturnType implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return \OWA\Core\CoreAPI::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'entityFactory';
    }

    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $args = $methodCall->getArgs();

        if (!$args) {
            return null;
        }

        $names = $scope->getType($args[0]->value)->getConstantStrings();

        if (count($names) !== 1) {
            return null;
        }

        $class = \OWA\Core\CoreAPI::namespacedEntityClass($names[0]->getValue());

        return $class !== null ? new ObjectType($class) : null;
    }
}
