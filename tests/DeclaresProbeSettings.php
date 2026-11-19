<?php

/**
 * Settings a test declares for itself, and forgets again.
 *
 * Only a declared setting may be stored at a scope, so a test that writes a
 * probe key declares it. The declaration lives in the settings singleton for
 * the rest of the process, where SettingsDeclarationContractTest, run later in
 * the same suite, finds a level no screen offers.
 */
trait DeclaresProbeSettings
{
    /** @var list<string> module|key of each probe declared */
    private array $declaredProbes = [];

    /** @param array<string, mixed> $args */
    private function declareProbe( string $module, string $key, array $args ): void
    {
        \OWA\Core\CoreAPI::configSingleton()->registerField( $module, $key, $args );

        $this->declaredProbes[] = $module . '|' . $key;
    }

    private function forgetProbes(): void
    {
        if ( ! $this->declaredProbes ) {
            return;
        }

        $settings = \OWA\Core\CoreAPI::configSingleton();
        $registry = new \ReflectionProperty( $settings, 'registry' );
        $declared = $registry->getValue( $settings );

        foreach ( $this->declaredProbes as $id ) {
            unset( $declared[ $id ] );
        }

        $registry->setValue( $settings, $declared );
        $this->declaredProbes = [];
    }
}
