<?php

namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * A short-lived, scoped credential for the heatmap overlay and domstream
 * player.
 *
 * Those two run on the *tracked* site and fetch from the OWA origin, so they
 * cannot use a session cookie -- it would be a third-party cookie, which
 * browsers increasingly refuse -- and they cannot send an Authorization header
 * without turning a simple cross-origin GET into a preflighted one. The
 * credential therefore has to travel in the URL, and the question is only what
 * it is worth to whoever reads it there.
 *
 * Previously it was the signed-in user's **apiKey**, which is long-lived and
 * carries that user's whole account. This carries one user, one endpoint, one
 * resource, and expires in minutes.
 *
 * Stateless by design: verification recomputes the signature rather than
 * looking the token up, so there is no table, no schema version to bump and no
 * cleanup job. The usual objection to stateless tokens -- that they cannot be
 * revoked before they expire -- is answered by the expiry being minutes.
 */
class OverlayToken {

    /**
     * How long a freshly minted token is good for, in seconds.
     *
     * Long enough to open a page and let the overlay fetch, short enough that a
     * leaked token is worth little. Overridable per install, but not upward
     * without thought: the token's safety is its brevity.
     */
    const DEFAULT_TTL = 300;

    /**
     * Mints a token authorising exactly one action on one resource.
     *
     * The resource is named by the request parameter that carries it, so the
     * token is self-describing and verification needs no table mapping actions
     * to parameter names -- one that would have to be remembered the next time
     * an overlay is added.
     *
     * @param    string    $userId        the user whose privileges this carries
     * @param    string    $action        the REST 'do' this permits, e.g. 'reports'
     * @param    string    $resourceKey    the request param naming the resource
     * @param    string    $resource    the one value of that param this permits
     * @param    int        $ttl        lifetime in seconds
     * @return    string    a URL-safe token
     */
    public static function mint( $userId, $action, $resourceKey, $resource, $ttl = self::DEFAULT_TTL ) {

        $claims = array(
            'user_id'      => (string) $userId,
            'action'       => (string) $action,
            'resource_key' => (string) $resourceKey,
            'resource'     => (string) $resource,
            'exp'          => time() + (int) $ttl,
        );

        $payload = self::encode( $claims );

        return $payload . '.' . self::sign( $payload );
    }

    /**
     * Returns the token's claims, or null if it is not a token this
     * installation minted, or has expired.
     *
     * @param    string    $token
     * @return    array|null
     */
    public static function verify( $token ) {

        if ( ! is_string( $token ) || $token === '' || strlen( $token ) > 4096 ) {

            return null;
        }

        $parts = explode( '.', $token );

        if ( count( $parts ) !== 2 ) {

            return null;
        }

        list( $payload, $signature ) = $parts;

        if ( $payload === '' || $signature === '' ) {

            return null;
        }

        // Constant-time: a timing-variable compare here leaks the signature a
        // byte at a time to anyone willing to make enough requests.
        if ( ! hash_equals( self::sign( $payload ), $signature ) ) {

            return null;
        }

        $claims = json_decode( self::decode( $payload ), true );

        if ( ! is_array( $claims ) ) {

            return null;
        }

        foreach ( array( 'user_id', 'action', 'resource_key', 'resource', 'exp' ) as $required ) {

            if ( ! array_key_exists( $required, $claims ) ) {

                return null;
            }
        }

        if ( (int) $claims['exp'] <= time() ) {

            return null;
        }

        return $claims;
    }

    /**
     * Whether this token permits this exact action on this exact resource.
     *
     * The scope check is the security-critical half of the design: a token that
     * authenticates a user without constraining what they may reach is no
     * better than the apiKey it replaces.
     *
     * @param    string    $token
     * @param    string    $action        the 'do' the request is asking for
     * @param    callable    $paramReader    given a param name, returns its request value
     * @return    boolean
     */
    public static function permits( $token, $action, $paramReader ) {

        $claims = self::verify( $token );

        if ( ! $claims ) {

            return false;
        }

        if ( ! hash_equals( (string) $claims['action'], (string) $action ) ) {

            return false;
        }

        $submitted = (string) call_user_func( $paramReader, $claims['resource_key'] );

        // An empty resource on the request must never satisfy a token, or a
        // request that simply omits the parameter would pass unchecked.
        if ( $submitted === '' ) {

            return false;
        }

        return hash_equals( (string) $claims['resource'], $submitted );
    }

    /**
     * The signature covers the whole payload, so every claim in it -- the user,
     * the action, the resource and the expiry alike -- is uneditable in a URL.
     */
    private static function sign( $payload ) {

        return rtrim( strtr( base64_encode(
            hash_hmac( 'sha256', 'owa_overlay_token' . $payload, self::key(), true )
        ), '+/', '-_' ), '=' );
    }

    private static function key() {

        // The install's nonce secret, reused rather than adding another
        // constant to owa-config.php. Rotating it invalidates outstanding
        // tokens, which at a five-minute lifetime costs nothing.
        return (string) \OWA\Core\CoreAPI::getSalt( 'nonce' );
    }

    private static function encode( array $claims ) {

        return rtrim( strtr( base64_encode( json_encode( $claims ) ), '+/', '-_' ), '=' );
    }

    private static function decode( $payload ) {

        return (string) base64_decode( strtr( $payload, '-_', '+/' ), true );
    }
}
