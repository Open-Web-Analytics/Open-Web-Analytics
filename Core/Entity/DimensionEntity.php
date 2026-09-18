<?php

namespace OWA\Core\Entity;

/**
 * A dimension table, and the one place its identity is derived.
 *
 * A dimension row's primary key is a hash of its own content, so two events
 * carrying the same content resolve to the same row without a lookup. That
 * derivation used to live in three places at once -- the tracking-property
 * config (`generateDimensionId`), the dimension handlers, and inline
 * `Lib::setStringGuid()` calls in SessionHandlers -- and they did not agree.
 * LocationHandlers keyed on country.city while the fact table's callback keyed
 * on country.state.city, so for any location carrying a state the handler wrote
 * a row no fact row ever pointed at, and the row the facts did point at was
 * created by nothing. Every geo report depended on the two hashes coinciding.
 *
 * So: one derivation, owned by the dimension, because the dimension is the only
 * thing that knows what makes two of its rows the same. Facts declare WHICH
 * dimension a foreign key points at (Entity::setProperty populates
 * `_tableProperties['foreign_keys']` from setForeignKey); they do not need to
 * know HOW, and there are four fact tables per dimension, so declaring it on the
 * fact side would restore the duplication this removes.
 *
 * ## Why deriveId() takes an array and not an event
 *
 * Partly because the call site has no event -- `Entity::setProperties()` is
 * handed `$event->getProperties()`, a flat array -- but mainly because an event
 * would re-open the defect this class exists to close. Given an event, a caller
 * can read `$event->get('location_id')` and hash an ALREADY-DERIVED id, which is
 * the over-hashing bug fixed once in registerCallbacks/attachFilter and present
 * again in LocationHandlers today. An array of content properties has no object
 * to reach through, and because the single implementation below reads only
 * `static::CONTENT_KEY`, a subclass cannot reach for anything else either.
 *
 * Derivation is a pure function of the content: array in, id or null out.
 *
 * NOTE it is not *quite* pure. `Lib::setStringGuid()` consults
 * `Lib::useNarrowGuid()`, which reads the install's schema version to choose
 * between 32- and 64-bit ids. That is deliberate and has to stay, but it means a
 * test assuring a literal id has to pin that state or it will pass on one
 * install shape and fail on another.
 */
abstract class DimensionEntity extends \OWA\Core\Entity {

    /**
     * Absence of content means the thing exists but could not be resolved.
     *
     * Wants a row, so the fact still joins and the report groups it under a
     * label applied at render time. An IP that MaxMind cannot place still has a
     * country; we just do not know it. Dimension joins are INNER
     * (ResultSetManager::addDimension), so a fact pointing at no row is not
     * rendered as "(not set)" -- it leaves the report entirely.
     */
    const ABSENCE_UNKNOWN = 'unknown';

    /**
     * Absence of content means the thing does not exist for this event.
     *
     * Wants NO row. Direct traffic has no referring site, and a referring-sites
     * report should exclude it rather than invent a bucket for it. Measured on
     * demo: every session whose medium implies a referrer (referral,
     * organic-search, social-network) has a referer_id; only direct, email,
     * feed and display lack one, which is correct.
     */
    const ABSENCE_NOT_APPLICABLE = 'not_applicable';

    /**
     * The key part that stands in for "nothing resolved", repeated once per
     * CONTENT_KEY entry.
     *
     * Spelled this way because it must be. Until the sentinel was removed, an
     * unresolvable property took the literal '(not set)' as its default and was
     * then hashed, so every existing install already stores rows at
     * setStringGuid( '(not set)' x count( CONTENT_KEY ) ). Deriving a tidier key
     * would give new events a different id from old ones and split one bucket
     * into two rows that render identically. Verified against demo: the id this
     * produces is already present in owa_source_dim, owa_host, owa_ua,
     * owa_document and owa_location_dim.
     *
     * It is a hash input. It never reaches a column.
     */
    const UNRESOLVED_KEY_PART = '(not set)';

    /**
     * Ordered EVENT PROPERTY names whose values form this dimension's identity.
     *
     * Event property names, not column names: they differ for eight of the ten
     * dimensions (source -> source_domain, page_url -> url, HTTP_USER_AGENT ->
     * ua, os -> name, campaign -> name, ad -> name, search_terms -> terms,
     * session_referer -> url). Ordered, because the hash is a concatenation.
     */
    const CONTENT_KEY = array();

    /** What absence means here. One of the ABSENCE_* constants above. */
    const ABSENCE = self::ABSENCE_UNKNOWN;

    /**
     * This dimension's id for the content carried by $props.
     *
     * @param  array $props Event properties, as Event::getProperties() returns.
     * @return string|null  The id, or null when absence means "not applicable".
     */
    static function deriveId( array $props ) {

        if ( ! static::CONTENT_KEY ) {

            throw new \LogicException(  static::class . ' declares no CONTENT_KEY.' );
        }

        $parts = array();

        foreach ( static::CONTENT_KEY as $name ) {

            $parts[] = self::normalize( isset( $props[ $name ] ) ? $props[ $name ] : null );
        }

        /*
         * Absence is decided HERE, by comparing the normalised key to the empty
         * string, and nowhere else.
         *
         * Specifically not by truthiness. setStringGuid()'s own guard is a
         * truthiness test, so the legitimate value '0' -- a campaign named "0",
         * a search for "0" -- read as absent and returned null, which reaches
         * the foreign key as 0 and points at no row. For a dimension declared
         * UNKNOWN that also broke this method's contract: it must always answer
         * with an id. Hence $allow_falsy.
         *
         * A path of '/' is unaffected and always was: trim() leaves it alone and
         * '/' is a perfectly good hash input. What still resolves to absence is
         * the empty string and strings that are entirely whitespace, which are
         * not values.
         */
        $key = implode( '', $parts );

        if ( $key === '' ) {

            if ( static::ABSENCE === self::ABSENCE_NOT_APPLICABLE ) {

                return null;
            }

            $key = str_repeat( self::UNRESOLVED_KEY_PART, count( static::CONTENT_KEY ) );
        }

        return \OWA\Core\Lib::setStringGuid( $key, true );
    }

    /**
     * The one normalisation, applied before hashing.
     *
     * Consolidating four handlers that disagreed: Source, Campaign and Ad
     * trimmed and lowercased before hashing; Document, Referer, Ua and Os did
     * not. Case never actually mattered -- Lib::wideStringGuid() lowercases
     * internally -- but whitespace did, so ' Google ' and 'Google' hashed to
     * different rows depending on which code path derived the id.
     */
    static function normalize( $value ) {

        return trim( strtolower( (string) $value ) );
    }

    /**
     * Whether this dimension derives its id from event content at all.
     *
     * A fact's foreign keys are not all content-derived: site_id is a minted
     * identifier and visitor_id arrives from the tracker. Those entities declare
     * no CONTENT_KEY, so the fact write path leaves their columns alone.
     */
    static function isContentDerived() {

        return (bool) static::CONTENT_KEY;
    }
}
