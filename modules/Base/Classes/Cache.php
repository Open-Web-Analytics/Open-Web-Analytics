<?php
namespace OWA\Module\Base\Classes;

/**
 * The object cache: values kept for the rest of one request, by collection
 * and key. Nothing outlives the request.
 */
class Cache {

    /** @var array<string, array<string, mixed>> values by collection, then key */
    private $items = [];

    /**
     * @param  string $collection
     * @param  string $key
     * @param  mixed  $value whatever the caller keeps
     * @return void
     */
    public function set( $collection, $key, $value ) {

        $this->items[ $collection ][ $key ] = $value;
    }

    /**
     * @param  string $collection
     * @param  string $key
     * @return mixed what set() was given; null when nothing was
     */
    public function get( $collection, $key ) {

        return $this->items[ $collection ][ $key ] ?? null;
    }

    /**
     * @param  string $collection
     * @param  string $key
     * @return bool whether there was such a value
     */
    public function remove( $collection, $key ) {

        if ( ! isset( $this->items[ $collection ] ) || ! array_key_exists( $key, $this->items[ $collection ] ) ) {

            return false;
        }

        unset( $this->items[ $collection ][ $key ] );

        return true;
    }

    /**
     * @return true
     */
    public function flush() {

        $this->items = [];

        return true;
    }
}
