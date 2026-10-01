/**
 * The beacon's wire names: each property the tracker sends, under the short key
 * it travels as.
 *
 * ONLY THE WIRE. The tracker works in the server's property names -- page_title,
 * visitor_id -- and translates once, as the request is assembled
 * (prepareRequestData). The server renames each short key back onto its property
 * from the `wire` entries in conf/beacon_compat.php, and BeaconWireNamesTest holds
 * the two lists to each other.
 *
 * THE CONVENTION is scope_field, lowercase, underscore-separated (PHP rewrites a
 * dot in a query key):
 *
 *   _       control: about the beacon, not the data
 *   e_      differs from event to event: type, sequence, engagement
 *   site v_ u_ s_   identity: site, visitor, the site's user, session
 *   p_      the same on every event of the page
 *   d_      the device
 *   el_     the element interacted with, and the link it was clicked through
 *   c_ sc_ f_ fm_ o_   parameters one kind of event sends: click, scroll,
 *                      file download, form, order
 *
 * A timestamp's code ends in `ts`, a millisecond duration's in `ms`.
 *
 * Custom properties carry their scope and type in the prefix: eps_ and epn_
 * (event, text and number), vps_ and vpn_ (visitor).
 *
 * Reading the beacon in a browser's network tab is this table in reverse.
 */
export const WIRE_NAMES = {

    // control
    beacon_version:         '_v',

    // the event
    event_type:             'e_t',
    event_seq:              'e_sq',
    engagement_msec:        'e_ems',

    // identity
    site_id:                'site',
    visitor_id:             'v_id',
    fsts:                   'v_fts',
    nps:                    'v_nps',
    is_new_visitor_created: 'v_new',
    consent_state:          'v_cs',
    user_id:                'u_id',
    session_id:             's_id',
    sts:                    's_sts',
    psts:                   's_pts',
    is_new_session_start:   's_new',

    // the page
    page_location:          'p_l',
    page_title:             'p_t',
    HTTP_REFERER:           'p_r',
    content_group:          'p_cg',
    page_width:             'p_w',
    page_height:            'p_h',
    search_term:            'p_q',

    // the device
    screen_resolution:      'd_sr',

    // the element
    dom_element_tag:        'el_tg',
    dom_element_id:         'el_id',
    dom_element_class:      'el_cl',
    dom_element_name:       'el_nm',
    dom_element_text:       'el_tx',
    target_url:             'el_lu',
    is_outbound:            'el_lo',

    // one kind of event
    click_x:                'c_x',
    click_y:                'c_y',
    scroll_depth:           'sc_d',
    file_name:              'f_nm',
    file_extension:         'f_ext',
    form_id:                'fm_id',
    form_name:              'fm_nm',
    form_length:            'fm_len',
    form_destination:       'fm_dst',
    form_submit_text:       'fm_stx',
    first_field_id:         'fm_ffid',
    first_field_name:       'fm_ffnm',
    first_field_type:       'fm_fft',
    first_field_position:   'fm_ffp',
    ct_order_id:            'o_id',
    ct_total:               'o_tot',
    ct_tax:                 'o_tax',
    ct_shipping:            'o_shp',
    ct_value:               'o_val',
    currency:               'o_cur',
    coupon:                 'o_cpn',
    ct_gateway:             'o_gw',
    ct_order_source:        'o_src',
    ct_line_items:          'o_items'
};

/** Flags travel as 1 and 0, not as the strings true and false. */
const FLAGS = [ 'is_new_visitor_created', 'is_new_session_start', 'is_outbound' ];

/**
 * A copy of an event's properties under their wire names.
 *
 * A property the table does not name -- a custom property, already under its
 * own prefix, or a site's own key -- travels as it is.
 *
 * @param  {Object} properties  by property name
 * @return {Object}             by wire name
 */
export function toWire( properties ) {

    var out = {};

    for ( var name in properties ) {

        if ( ! properties.hasOwnProperty( name ) ) {
            continue;
        }

        var value = properties[ name ];

        if ( FLAGS.indexOf( name ) > -1 ) {
            if ( value === true || value === 'true' ) {
                value = 1;
            } else if ( value === false || value === 'false' ) {
                value = 0;
            }
        }

        out[ WIRE_NAMES.hasOwnProperty( name ) ? WIRE_NAMES[ name ] : name ] = value;
    }

    return out;
}
