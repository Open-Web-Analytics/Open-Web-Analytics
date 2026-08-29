# -*- coding: utf-8 -*-
"""Builds the property table AND refuses to emit an inconsistent one."""
import io,re,sys
SP='/tmp/claude-1000/-var-www-html-test-openwebanalytics-com-owa/f0e0aba2-a520-43bd-8737-bebeb3f42b17/scratchpad/'
ns={}; src=io.open(SP+'gen.py',encoding='utf-8').read()
exec(compile(src[:src.index('def rows(')],'gen','exec'),ns)
C,R,D,X=ns['CLIENT'],ns['RECEIPT'],ns['DRAIN'],ns['DROPPED']

V1={'event_type':'y','site_id':'y','visitor_id':'y','session_id':'y','user_id':'user_name / user_email','fsts':'y','nps':'y','sts':'y','is_new_session':'y','is_new_session_start':'y','is_new_visitor':'y','is_new_visitor_created':'y','timestamp':'y','last_req':'y','page_url':'y','page_title':'y','HTTP_REFERER':'y','session_referer':'y','first_source':'n','first_medium':'n','first_campaign':'n','tagged_source':'source','tagged_medium':'medium','tagged_campaign':'campaign','tagged_ad':'n','tagged_terms':'search_terms','engagement_msec':'n','click_x':'y','click_y':'y','page_width':'y','page_height':'y','scroll_depth':'n','target_url':'y','element_path':'n','dom_element_id':'y','dom_element_tag':'y','dom_element_class':'y','dom_element_name':'y','dom_element_text':'y','dom_element_value':'y','dom_element_x':'y','dom_element_y':'y','ct_total':'y','ct_line_items':'y','ct_gateway':'y','ct_order_id':'y','ct_order_source':'y','ct_shipping':'y','ct_tax':'y','city / country / state':'y','action_name':'y','action_group':'y','action_label':'y','numeric_value':'y','attribs':'y','cv1 &ndash; cv3':'y','feed_subscription_id':'y','ts':'timestamp','ip_address':'y','raw_ua':'header only','host':'y','language':'y','country':'y','city':'y','REMOTE_HOST':'y','id':'y','yyyymmdd':'y','page_uri':'y','source':'y','medium':'y','campaign':'y','ad':'y','search_terms':'y','attribution_basis':'n','browser':'y','browser_type':'y','os':'y','is_key_event':'n','session_start / first_visit':'n','ip_address <span class="dash">(anonymised)</span>':'y'}

# --- the guard: a v2 row may not describe itself as gone -----------------
GONE = re.compile(r'\b(dropped|removed|goes away|not carried|replaced by|no v2 stage)\b', re.I)
KEPT = re.compile(r'\b(kept|proposed|recoverable|deliberately not)\b', re.I)
bad=[]
for lst,stage in ((C,'1'),(R,'2'),(D,'3')):
    for n,per,note in lst:
        if n.startswith('~') or not note: continue
        plain=re.sub(r'<[^>]+>','',note)
        if GONE.search(plain) and not KEPT.search(plain):
            bad.append('stage %s: %s -- "%s"' % (stage, n, plain[:80]))
if bad:
    print('REFUSING TO BUILD -- rows in a v2 stage whose note says they are gone:')
    for b in bad: print('   '+b)
    sys.exit(1)

def vcell(n,v2):
    v=V1.get(n)
    if v2: one='off' if v=='n' else 'on'; two='on'; was='' if v in ('y','n',None) else ' <code>%s</code>'%v
    else: one,two,was='on','off',''
    return '<span class="vsq v-%s">1.x</span><span class="vsq v-%s">v2</span>%s'%(one,two,was)
def rows(items,tag,v2=True):
    o=[]
    for n,per,note in items:
        if n.startswith('~'): o.append('<tr class="grp"><td colspan="5">%s</td></tr>'%n[1:]); continue
        p='<span class="no">%s</span>'%('dropped' if not v2 else 'not stored') if per=='' else ('<span class="dash">%s</span>'%per if per.startswith('(') else '<code>%s</code>'%per)
        o.append('<tr%s><td class="col">%s</td><td>%s</td><td class="ver">%s</td><td>%s</td><td>%s</td></tr>'%('' if v2 else ' class="gone"',n,tag,vcell(n,v2),p,note or ''))
    return "\n".join(o)
CT='<span class="ph ph-c">CLIENT</span>';AT='<span class="ph ph-a">A</span>';BT='<span class="ph ph-b">B</span>';XT='<span class="ph ph-x">&mdash;</span>'
io.open(SP+'alltable.html','w',encoding='utf-8').write("\n".join(['<div class="scroll"><table class="allprops">','<thead><tr><th>Property</th><th>Stage</th><th>1.x&nbsp;/&nbsp;v2</th><th>Persists as</th><th>Note</th></tr></thead><tbody>','<tr class="stage"><td colspan="5">Stage 1 &mdash; the tracker sends it</td></tr>',rows(C,CT),'<tr class="stage"><td colspan="5">Stage 2 &mdash; the server stamps it at receipt</td></tr>',rows(R,AT),'<tr class="stage"><td colspan="5">Stage 3 &mdash; the server resolves it at the drain</td></tr>',rows(D,BT),'<tr class="stage stage-x"><td colspan="5">Stage 0 &mdash; in 1.x, not carried forward</td></tr>',rows(X,XT,False),'</tbody></table></div>']))
a=[x for x in C+R+D if not x[0].startswith('~')]; d=[x for x in X if not x[0].startswith('~')]
print('OK  v2 pipeline %d (stored %d, sent-not-stored %d) | stage0 %d | total %d'
      % (len(a), sum(1 for x in a if x[1]), sum(1 for x in a if not x[1]), len(d), len(a)+len(d)))
