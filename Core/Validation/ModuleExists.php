<?php
namespace OWA\Core\Validation;

/**
 * Module Exists Validation
 *
 * The name of a module, one with a Module class: maxmind_geoip, or its
 * directory's name, MaxmindGeoip. The message lists the modules directory's.
 */
class ModuleExists extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $value = $this->getValues();
        $names = self::names();

        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'There is no module named %1$s. Modules: %2$s.' ),
                is_scalar( $value ) ? (string) $value : '', implode( ', ', $names ) ) );
        }

        if ( ! self::exists( $value ) ) {

            $this->hasError();
        }
    }

    /**
     * Whether $name names a module: a Module class for it, as
     * CoreAPI::moduleClassFactory() builds one.
     *
     * @param  mixed $name
     * @return bool
     */
    public static function exists( $name ) {

        if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {

            return false;
        }

        $class = \OWA\Core\Env::moduleNamespace( $name ) . '\\Module';

        return class_exists( $class ) && is_subclass_of( $class, \OWA\Core\Module::class );
    }

    /**
     * The modules present, by the name cmd=activate takes: base, maxmind_geoip.
     *
     * @return list<string>
     */
    public static function names() {

        $names = [];

        foreach ( \OWA\Core\CliWithoutBoot::moduleDirs() as $dir ) {

            if ( class_exists( \OWA\Core\Env::moduleNamespace( $dir ) . '\\Module' ) ) {

                $names[] = strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $dir ) );
            }
        }

        return $names;
    }
}
