<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What a user agent string CLAIMS to be, for the spam rules.
 *
 * The browser family and version, the operating system and the device family
 * are the ua-parser readings ingest already makes (Classes\Browscap, through
 * Service::getBrowscap(), so one parse per agent serves both). The one reading
 * the parser does not give is the Chromium version: Opera and Samsung Internet
 * report their own release numbers (Opera 106 is Chromium 120), so
 * chromiumMajor() reads the Chrome/NN token every Chromium browser carries.
 *
 * A claim, never a finding. A forged agent parses exactly like the browser it
 * names, which is what the request_headers rules test it against.
 */
class Client {

    /** @var string */
    private $ua;

    /** @var object|false|null the parse, false for none */
    private $parsed = null;

    public function __construct( $ua ) {

        $this->ua = (string) $ua;
    }

    public function userAgent() {

        return $this->ua;
    }

    /** The parser's browser family, such as Chrome, Mobile Safari or Firefox. '' with no agent. */
    public function family() {

        return (string) ( $this->part( 'ua' )->family ?? '' );
    }

    /** @return int[] major, minor; 0 for a part the agent does not give */
    public function version() {

        $ua = $this->part( 'ua' );

        return array( (int) ( $ua->major ?? 0 ), (int) ( $ua->minor ?? 0 ) );
    }

    public function osFamily() {

        return (string) ( $this->part( 'os' )->family ?? '' );
    }

    /** @return int[] major, minor */
    public function osVersion() {

        $os = $this->part( 'os' );

        return array( (int) ( $os->major ?? 0 ), (int) ( $os->minor ?? 0 ) );
    }

    /** The parser's device family; Spider for a crawler it recognises. */
    public function deviceFamily() {

        return (string) ( $this->part( 'device' )->family ?? '' );
    }

    /** Every browser on iOS is WebKit with the system's version of it, whatever it calls itself. */
    public function isIos() {

        return $this->osFamily() === 'iOS';
    }

    /**
     * The Chromium major version from the Chrome/NN token, or null without one.
     *
     * Not HeadlessChrome/NN or CriOS/NN: the token has to stand on its own.
     */
    public function chromiumMajor() {

        if ( preg_match( '#(?<![A-Za-z])Chrome/(\d+)#', $this->ua, $m ) ) {

            return (int) $m[1];
        }

        return null;
    }

    /** The parser's Safari families: desktop, iOS, and an iOS app's web view. */
    const SAFARI_FAMILIES = array( 'Safari', 'Mobile Safari', 'Mobile Safari UI/WKWebView' );

    /**
     * Whether the agent claims to be a browser: Chromium (any browser carrying
     * the Chrome/NN token), Firefox, Safari, or any browser on iOS.
     *
     * Not a declared crawler, even one carrying a browser's tokens as Googlebot
     * does: UA_ROBOT_TOKEN and UA_PARSER_SPIDER score those, and a rule asking
     * "is this browser behaving like one" would only score them twice.
     */
    public function claimsBrowser() {

        if ( $this->ua === ''
             || Rules\UaRobotToken::matchingToken( $this->ua ) !== null
             || strtolower( $this->deviceFamily() ) === 'spider' ) {

            return false;
        }

        if ( $this->chromiumMajor() !== null ) {

            return true;
        }

        $family = $this->family();

        return strpos( $family, 'Firefox' ) === 0
            || in_array( $family, self::SAFARI_FAMILIES, true )
            || ( $this->isIos() && stripos( $this->ua, 'AppleWebKit/' ) !== false );
    }

    /** Whether the agent carries the Mobile token a phone's browser sends. */
    public function hasMobileToken() {

        return (bool) preg_match( '/\bMobile\b/', $this->ua );
    }

    /**
     * The operating system the agent names, as one of windows, mac, linux,
     * android, chromeos or ios; '' when it names none of them.
     *
     * The parser's OS family first; then, for an agent the parser does not
     * recognise -- a bare `android` -- the token it carries.
     */
    public function osClass() {

        $family = strtolower( $this->osFamily() );

        foreach ( array(
            'windows'  => '/^windows/',
            'mac'      => '/^(mac os|macos)/',
            'ios'      => '/^(ios|ipados)$/',
            'android'  => '/^android/',
            'chromeos' => '/^(chrome os|chromium os)$/',
            'linux'    => '/^(linux|ubuntu|debian|fedora|red hat|centos|suse|opensuse|arch linux|linux mint|gentoo|kubuntu|mageia|slackware)/',
        ) as $class => $pattern ) {

            if ( preg_match( $pattern, $family ) ) {

                return $class;
            }
        }

        // Tokens, most specific first: an Android agent also says Linux.
        foreach ( array(
            'android'  => '/android/i',
            'ios'      => '/\b(iphone|ipad|ipod)\b/i',
            'chromeos' => '/\bCrOS\b/',
            'windows'  => '/windows/i',
            'mac'      => '/macintosh|mac os x/i',
            'linux'    => '/linux/i',
        ) as $class => $pattern ) {

            if ( preg_match( $pattern, $this->ua ) ) {

                return $class;
            }
        }

        return '';
    }

    /** Whether a [major, minor] version is at least major.minor. */
    public static function atLeast( array $version, $major, $minor = 0 ) {

        list( $has_major, $has_minor ) = array_pad( array_values( $version ), 2, 0 );

        return $has_major > $major || ( $has_major === (int) $major && $has_minor >= $minor );
    }

    /** One part of the parse (ua, os, device) as an object; an empty one when there is none. */
    private function part( $name ) {

        if ( $this->parsed === null ) {

            $this->parsed = $this->ua === ''
                ? false
                : \OWA\Core\CoreAPI::serviceSingleton()->getBrowscap( $this->ua );
        }

        $browser = $this->parsed && isset( $this->parsed->browser ) ? $this->parsed->browser : null;

        if ( is_object( $browser ) && isset( $browser->$name ) && is_object( $browser->$name ) ) {

            return $browser->$name;
        }

        return new \stdClass();
    }
}

?>
