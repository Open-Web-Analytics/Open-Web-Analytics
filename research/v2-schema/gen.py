# -*- coding: utf-8 -*-
import io

# (property, persists_as, note)   persists_as: '' = not persisted
CLIENT = [
 ('~identity', None, None),
 ('event_type','event_type','Validated against the taxonomy at receipt.'),
 ('site_id','site_id','Existence checked where a store is reachable (&sect;7.2).'),
 ('visitor_id','visitor_id','The one value that cannot be content-derived (&sect;1.5).'),
 ('session_id','session_id','Embeds its creation timestamp; the midnight rule reads it back.'),
 ('user_id','user_id','Site-declared. Resolves at collection only &mdash; earlier events stay anonymous forever.'),
 ('fsts','visitor_fsts','<strong>Kept.</strong> The first-visit anchor on the client clock, sent raw so the server does day arithmetic without losing granularity. Recoverable from <code>visitor_id</code>&rsquo;s time prefix, and explicitly not taken that way &mdash; same reason as <code>sts</code>, &sect;7.8.'),
 ('nps','prior_sessions','Renamed &mdash; &ldquo;number of prior sessions&rdquo;.'),

 ('~control flags', None, None),
 ('is_new_session_start','','<strong>&ldquo;This request created the session&rdquo;</strong> &mdash; true for exactly one event. Materializes <code>session_start</code>. In v2 an <em>optimisation, not a correctness mechanism</em>: the marker&rsquo;s id is content-derived, so materializing from every event of the session would produce the same row and dedupe. See &sect;7.7.'),
 ('is_new_visitor_created','','<strong>&ldquo;This request minted the visitor&rdquo;</strong> &mdash; exactly one event. Materializes <code>first_visit</code>. Unconsumed in 1.x and kept deliberately for v2; same optimisation-not-correctness status. See &sect;7.7.'),

 ('~clocks', None, None),
 ('sts','','<strong>Kept.</strong> Session start on the <em>client</em> clock &mdash; which is the point: days-since is computed from client anchors on both sides, so skew cancels. Recoverable from <code>session_id</code>&rsquo;s embedded timestamp, but <strong>deliberately sent explicitly rather than parsed out of an id</strong> &mdash; &sect;7.8.'),

 ('~page', None, None),
 ('page_url','','Input. Becomes <code>page_uri</code> &mdash; URI, not URL &mdash; so the query string deliberately does not identify a page.'),
 ('page_title','page_title',''),
 ('HTTP_REFERER','referer_url','Renamed. The raw referer; attribution is what the server makes of it.'),
 ('session_referer','','Input to attribution.'),

 ('~acquisition &mdash; the visitor cookie', None, None),
 ('first_source','first_source','<strong>New.</strong> The visitor\u2019s FIRST session\u2019s attribution, written once at first session and never rewritten \u2014 the same permanence <code>visitor_id</code> and <code>fsts</code> already have in the <code>v</code> store. Stamped on every event, so &ldquo;revenue by acquisition source&rdquo; is a group-by rather than a join to each visitor\u2019s first row.'),
 ('first_medium','first_medium',''),
 ('first_campaign','first_campaign',''),

 ('~attribution claim &mdash; this touch', None, None),
 ('tagged_source','','<strong>&sect;7.5 rename.</strong> Today the tracker sends these as <code>source</code>/<code>medium</code>/<code>campaign</code>/<code>search_terms</code> &mdash; the same names the server writes &mdash; so nothing records which half produced a value. As <code>tagged_*</code> they are a claim, and the answer is a separate column. <strong>Held in the SESSION store</strong> beside <code>session_referer</code>, written once when the session is minted, so every event carries the session&rsquo;s originating tags and the resolver needs no state (&sect;7.3). A tag that differs mid-session starts a new session rather than overwriting them &mdash; &sect;7.10.'),
 ('tagged_medium','',''),
 ('tagged_campaign','',''),
 ('tagged_ad','',''),
 ('tagged_terms','',''),

 ('~engagement and geometry', None, None),
 ('engagement_msec','engagement_msec','<strong>A duration, not a clock.</strong> Milliseconds accrued on the current page since the last report &mdash; a difference between two readings of the SAME client clock, so skew cancels and only rate error survives. That is why it is trusted where a client <em>timestamp</em> is not. Additive, so session engagement is a SUM over anything.'),
 ('click_x','click_x',''),
 ('click_y','click_y',''),
 ('page_width','page_width',''),
 ('page_height','page_height',''),
 ('scroll_depth','scroll_depth','New in v2. 1.x&rsquo;s <code>dom.scroll</code> is a recording sample with no server handler.'),
 ('target_url','target_url','Click, download or outbound target.'),

 ('~element capture', None, None),
 ('element_path','element_path','New in v2, and the one that matters: a path identifies an element across renders.'),
 ('dom_element_id','element_id','Renamed.'),
 ('dom_element_tag','element_tag','Renamed.'),

 ('~commerce', None, None),
 ('ct_total','revenue','Renamed, and stored in cents. Negative on <code>refund</code>.'),
 ('ct_line_items','params.items','The nested array <code>params</code> exists for. Costs 1.x a separate fact table and a join.'),
 ('ct_gateway','params',''),
 ('ct_order_id','params',''),
 ('ct_order_source','params',''),
 ('ct_shipping','params',''),
 ('ct_tax','params',''),
 ('city / country / state','','<strong>Billing address</strong>, sent on <code>ecommerce.transaction</code>. Two of the three names collide with the IP-derived columns below &mdash; see &sect;7.6.'),

 ('~custom', None, None),
 ('feed_subscription_id','params','<code>feed_request</code> only.'),
]

RECEIPT = [
 ('ts','ts','The server clock at edge receipt. Travels with the queue item, which is what keeps at-least-once redelivery from re-dating an event.'),
 ('ip_address','ip_address','The connection, read through <code>X-Forwarded-For</code> &mdash; whose leftmost value is attacker-controlled. <strong>Captured</strong> here because it is the request; <strong>anonymised</strong> at the drain, with geolocation, so the full value travels on the message (&sect;7.1).'),
 ('raw_ua','raw_ua','The <code>User-Agent</code> header, behind <code>store_raw_ua</code> (default on). It is what makes browser and OS deferrable and re-derivable.'),
 ('host','host','<code>Host</code> header.'),
 ('language','language','<code>Accept-Language</code> header.'),
 ('REMOTE_HOST','','1.x only. Reverse DNS on the connection; no v2 column.'),
]

DRAIN = [
 ('id','id','<span class="ph ph-ins">by INSERT</span> Content-derived for idempotent events, random otherwise. Either way the drain produces it; it just cannot be added after the row exists, being half the PK.'),
 ('yyyymmdd','yyyymmdd','<span class="ph ph-ins">by INSERT</span> From <code>ts</code>, or from <code>session_id</code> for idempotent events so a retry lands in the same partition. The partition key, so it must be right at insert &mdash; which is not the same as being request-time work.'),
 ('page_uri','page_uri','<code>hash(site_id + uri)</code> is page identity.'),
 ('source','source','The <em>answer</em>. Sticky: stamped on every event of the session, which is what makes &ldquo;sessions by landing page&rdquo; a group-by instead of a join. See &sect;7.3 for the ordering problem this creates at a drain.'),
 ('medium','medium',''),
 ('campaign','campaign',''),
 ('ad','ad',''),
 ('search_terms','search_terms','<strong>Referral</strong> terms. Collides with site-internal search &mdash; &sect;7.4.'),
 ('attribution_basis','attribution_basis','<strong>Proposed, &sect;7.5.</strong> Which rule produced the answer: <code>tagged</code>, <code>referer</code>, <code>direct</code>, or <code>carried</code>.'),
 ('browser','browser','Parsed from <code>raw_ua</code>, so re-derivable when the browscap data improves.'),
 ('browser_type','browser_type',''),
 ('os','os',''),
 ('country','country','Resolved at the drain from the full IP that travelled on the message, immediately before that IP is masked. Geo and anonymisation are one ordered step, and it completes before the insert (&sect;7.1).'),
 ('city','city',''),
 ('ip_address <span class="dash">(anonymised)</span>','ip_address','<span class="ph ph-ins">by INSERT</span> <strong>Anonymised after geolocation and before the insert</strong>, so the anonymised form is the only one ever written &mdash; the table never holds a full address, not even briefly. <code>Lib::anonymizeIp()</code> masks to <strong>/24</strong> for IPv4 and <strong>/64</strong> for IPv6: truncation, not a hash. Masking is idempotent, so a retry that re-masks an already-masked address is a no-op.'),
 ('is_key_event','is_key_event','<span class="ph ph-ins">by INSERT</span> Matched against the goal config. The constraint is the <em>reporting boundary</em>, not the request: resolved before the row is readable, because no reporting query may join the application store.'),
]

DROPPED = [
 ('~derived date parts &mdash; eleven, replaced by one', None, None),
 ('year','','Replaced by <code>yyyymmdd</code> alone &mdash; the partition key &mdash; with the rest derived from <code>ts</code> at query time. Eleven of these sat on every 1.x fact row.'),
 ('month','',''),('day','',''),('dayofweek','',''),('dayofyear','',''),('weekofyear','',''),
 ('hour','',''),('minute','',''),('second','',''),('sec','',''),
 ('msec','','Sub-second precision, kept nowhere in v2: <code>ts</code> holds what is needed.'),

 ('~dimension foreign keys &mdash; the star itself', None, None),
 ('document_id','','One of ten dimension foreign keys. They <em>are</em> the star schema, and the double-hash bug this release cycle was spent fixing was a bug in maintaining them. The values they point at are denormalised onto the event instead.'),
 ('ua_id','','Which is why 1.x does not store the raw user agent &mdash; it stores this instead. v2 keeps <code>raw_ua</code> and re-derives.'),
 ('location_id','',''),('host_id','',''),('os_id','',''),('campaign_id','',''),
 ('ad_id','',''),('source_id','',''),('referer_id','',''),('referring_search_term_id','',''),

 ('~flags replaced by derivations', None, None),
 ('is_repeat_visitor','','Derives from <code>visitor_fsts</code>, on every row. A flag that can disagree with its own derivation is a bug nothing detects &mdash; and this one did: NULL from 2015 until this year, and a column holding 1/0/NULL made a pie draw two slices with the same label.'),
 ('is_entry_page','','Entry and exit flags are deliberately absent (&sect;1.10). Exit is served by an <code>is_exit</code> flag set by a closer job, for the one report that consumes it.'),
 ('is_robot','','Bots are refused at ingest and never become rows, so there is nothing to flag &mdash; and &sect;1.9 forbids reclassifying stored rows later, which is what a flag invites.'),
 ('is_browser','','Implied by the absence of a bot.'),
 ('full_host','','<code>host</code> covers it.'),

 ('~geo detail beyond country and city', None, None),
 ('latitude','','Coordinates are precision this product does not report on and a privacy surface it need not hold.'),
 ('longitude','',''),
 ('country_code','','Redundant with <code>country</code>.'),
 ('state','','No column. Note the collision in &sect;7.6 &mdash; the tracker also sends <code>state</code> as part of a billing address.'),

 ('~session offsets &mdash; anchors travel instead', None, None),
 ('psts','','Prior session start. v2 ships <em>anchors</em> and derives offsets the way GA does: <code>fsts</code>, <code>sts</code> and <code>prior_sessions</code> go on the wire and days-since is arithmetic at query time.'),
 ('days_since_first_session','',''),
 ('days_since_prior_session','',''),
 ('time_since_last_session','',''),

 ('~identity and page classification', None, None),
 ('user_name','','Replaced by the single <code>user_id</code>. &sect;1.5 makes a site-supplied id the only identity field, and two free-text fields are two chances to store PII by accident.'),
 ('user_email','',''),
 ('page_type','','A site-declared classification with no consumer in the v2 reporting surface. If one is wanted it belongs in <code>params</code>, where site-defined keys live.'),
 ('prior_page','','Derivable from the visitor&rsquo;s own events, which is the single table&rsquo;s whole argument.'),

 ('~fixed-slot custom data, replaced by event_type + params', None, None),
 ('action_name','','<strong>Becomes the event&rsquo;s own name.</strong> In v2 a custom event <em>is</em> its name: <code>event_type</code> carries it, so there is no separate field to send. &sect;7.9.'),
 ('action_group','','UA-era category / label / value slots, three fixed names for whatever a site wanted to say. In v2 they are ordinary <code>params</code> keys the site chooses &mdash; the fixed names go away, the values do not. &sect;7.9.'),
 ('action_label','',''),
 ('numeric_value','',''),
 ('cv1','','<strong>Numbered slots.</strong> 1.x spends ten session columns (<code>cv1_name</code>&hellip;<code>cv5_value</code>) implementing exactly five custom variables &mdash; not a preference but what a fixed-width session row forces. On a param map the limit disappears and the slot numbers with it. &sect;7.9.'),
 ('cv2','',''),
 ('cv3','',''),

 ('~client clocks v2 does not need', None, None),
 ('last_req','','<strong>The domain crossing.</strong> A client instant the server differenced against its own clock, with nothing in the schema recording that the two came from different clocks. Every other client time value is differenced only against other client values. Dropped &mdash; if &ldquo;time since last request&rdquo; is wanted, the client sends the delta and it becomes an <code>engagement_msec</code>-shaped duration. &sect;7.8.'),
 ('timestamp','','The client clock. Its only job was standing in for <code>sts</code> when that was absent (<code>sessionDateOf()</code>); with <code>sts</code> kept and guaranteed it has none, and <code>ts</code> is the authority for <em>when</em>. Dropped &mdash; &sect;7.8.'),

 ('~new-ness flags replaced by a derived dimension', None, None),
 ('is_new_visitor','','<strong>&ldquo;This session was the visitor&rsquo;s first&rdquo;</strong>, on every event of it &mdash; but sent only on the <code>page_request</code> family, so it cannot answer &ldquo;was this purchase from a new visitor?&rdquo; at all. Replaced by <code>newVsReturning</code>, derived from <code>prior_sessions</code>, which rides every event type. &sect;7.7.'),
 ('is_new_session','','&ldquo;This event is on the page the session started on&rdquo;. Served a per-event entry dimension; entry and exit flags are deliberately absent in v2. &sect;7.7.'),

 ('~element attributes the path replaces', None, None),
 ('dom_element_class','','Six attributes describing one element. v2 keeps <code>element_path</code>, <code>element_tag</code> and <code>element_id</code>: a path identifies an element across renders, which is what the others were approximating.'),
 ('dom_element_name','',''),('dom_element_text','',''),('dom_element_value','',''),
 ('dom_element_x','',''),('dom_element_y','',''),

 ('~the campaign cookie', None, None),
 ('attribs','','The campaign HISTORY array from the <code>c</code> cookie &mdash; not custom variables. Stored today as <code>latest_attributions</code> on the session row, behind the <code>latestAttributions</code> dimension. Goes with the cookie: every v2 event carries its own session&rsquo;s attribution, so a visitor&rsquo;s campaign sequence is a group-by over their own events. &sect;7.5c.'),

 ('~listed for completeness &mdash; this one IS carried', None, None),
 ('num_prior_sessions','prior_sessions','The registered name for what the wire calls <code>nps</code>. Carried, and already in Stage 1 under that name.'),
]

def rows(items, stage_tag):
    out=[]
    for name, persists, note in items:
        if name.startswith('~'):
            out.append('<tr class="grp"><td colspan="4">%s</td></tr>' % name[1:])
            continue
        if persists == '':
            p = '<span class="no">not stored</span>'
        elif persists.startswith('params'):
            p = '<code>%s</code>' % persists
        elif persists.startswith('('):
            p = '<span class="dash">%s</span>' % persists
        else:
            p = '<code>%s</code>' % persists
        out.append('<tr><td class="col">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>'
                   % (name, stage_tag, p, note or ''))
    return "\n".join(out)

CT = '<span class="ph ph-c">CLIENT</span>'
AT = '<span class="ph ph-a">A</span>'
BT = '<span class="ph ph-b">B</span>'

html = []
html.append('<div class="scroll"><table class="allprops">')
html.append('<thead><tr><th>Property</th><th>Stage</th><th>Persists as</th><th>Note</th></tr></thead><tbody>')
html.append('<tr class="stage"><td colspan="4">Stage 1 &mdash; the tracker sends it</td></tr>')
html.append(rows(CLIENT, CT))
html.append('<tr class="stage"><td colspan="4">Stage 2 &mdash; the server stamps it at receipt</td></tr>')
html.append(rows(RECEIPT, AT))
html.append('<tr class="stage"><td colspan="4">Stage 3 &mdash; the server resolves it at the drain</td></tr>')
html.append(rows(DRAIN, BT))
html.append('</tbody></table></div>')
io.open('/tmp/claude-1000/-var-www-html-test-openwebanalytics-com-owa/f0e0aba2-a520-43bd-8737-bebeb3f42b17/scratchpad/alltable.html','w',encoding='utf-8').write("\n".join(html))
n=sum(1 for x in CLIENT+RECEIPT+DRAIN if not x[0].startswith('~'))
stored=sum(1 for x in CLIENT+RECEIPT+DRAIN if not x[0].startswith('~') and x[1])
print('rows:', n, '| stored:', stored, '| not stored:', n-stored)
