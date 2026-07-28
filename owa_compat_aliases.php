<?php

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
 * Backward-compatibility bridge for the PSR-4 namespace migration (Phase 6).
 * =========================================================================
 *
 * OWA's ~340 framework classes are being renamed from the global-namespace
 * `owa_` prefix convention (owa_coreAPI, owa_document, ...) into real PSR-4
 * namespaces (OWA\Core\CoreAPI, OWA\Module\Base\Entity\Document, ...). Every
 * legacy `owa_*` name must keep resolving for a full major-version deprecation
 * window — third-party modules, the WordPress plugin, and serialized state all
 * reference the old names, and OWA's own factories synthesize them at runtime
 * ('owa_' . $file, see owa_lib::factory / owa_coreAPI::moduleSpecificFactory).
 *
 * HOW THE BRIDGE WORKS — a LAZY forward-alias autoloader.
 * -------------------------------------------------------
 * When any code (a factory, a third-party `new owa_document`, a `class_exists`
 * with autoload enabled) asks for a legacy `owa_*` name that has already been
 * migrated, this autoloader looks the name up in the old->new map, ensures the
 * new namespaced class is loaded (via Composer's PSR-4/classmap loader, which
 * owa_env.php registered immediately before requiring this file), and declares
 * a `class_alias(<new>, <old>)`. From that point the old name resolves to the
 * new class: `new owa_document` works, `instanceof` works in BOTH directions,
 * `extends owa_entity` works. (Verified with a standalone probe before this
 * file was written.)
 *
 * WHY LAZY, not eager. The original migration scoping assumed "no autoloader
 * today, so aliases must be declared eagerly at file-load." Phase-6 stage 0
 * falsified that: Composer's autoloader IS registered on every boot
 * (owa_env.php requires vendor/autoload.php) and a classmap over the whole tree
 * makes every class discoverable. A lazy autoloader therefore needs NO change
 * to the factory synthesis seam (owa_lib.php / owa_coreAPI factory $class_ns
 * defaults stay 'owa_') and costs nothing for names that are never requested.
 *
 * REGISTRATION ORDER. This autoloader is registered AFTER Composer's, so a
 * request for a new namespaced name resolves via Composer first; this bridge
 * only ever fires for a LEGACY `owa_*` name, which Composer cannot resolve
 * once the class has been renamed. The `owa_` prefix short-circuit keeps it a
 * no-op for every non-legacy class name.
 *
 * THE MAP. owa_compat_class_map() below is the single source of truth of
 * old->new renames. It is EMPTY at stage 1 (nothing renamed yet — the bridge
 * is inert and the safety-net suites stay green). Each later migration stage
 * appends its entries here as it renames a directory. A `class_exists(<old>,
 * false)` guard in the alias step prevents redefining an old name that some
 * code still declares directly during the transition.
 *
 * RESIDUAL BREAK (documented, not worked around): a module doing string
 * equality on a class name — `get_class($x) === 'owa_foo'` or
 * `$x::class === 'owa_foo'` — sees the NEW name and breaks. That is rare, a
 * code smell versus `instanceof` (which is unaffected), and a one-line author
 * fix. It is covered by the migration wiki page, not by code here.
 */

/**
 * The authoritative legacy-name -> new-namespaced-name map.
 *
 * EMPTY until renames begin (stage 2+). Add one entry per renamed class:
 *   'owa_document' => 'OWA\\Module\\Base\\Entity\\Document',
 *
 * @return array<string, string>
 */
function owa_compat_class_map(): array
{
    return [
        // --- populated as classes are migrated, one entry per rename ---

        // root framework classes -> OWA\Core\ (Phase 6 stage 2, roots batch)
        'owa_base' => 'OWA\\Core\\Base',

        // modules/base/entities (Phase 6 stage 2)
        'owa_document' => 'OWA\\Module\\Base\\Entity\\Document',
        'owa_action_fact' => 'OWA\\Module\\Base\\Entity\\ActionFact',
        'owa_ad_dim' => 'OWA\\Module\\Base\\Entity\\AdDim',
        'owa_campaign_dim' => 'OWA\\Module\\Base\\Entity\\CampaignDim',
        'owa_click' => 'OWA\\Module\\Base\\Entity\\Click',
        'owa_commerce_line_item_fact' => 'OWA\\Module\\Base\\Entity\\CommerceLineItemFact',
        'owa_commerce_transaction_fact' => 'OWA\\Module\\Base\\Entity\\CommerceTransactionFact',
        'owa_configuration' => 'OWA\\Module\\Base\\Entity\\Configuration',
        'owa_domstream' => 'OWA\\Module\\Base\\Entity\\Domstream',
        'owa_exit' => 'OWA\\Module\\Base\\Entity\\ExitPage',
        'owa_feed_request' => 'OWA\\Module\\Base\\Entity\\FeedRequest',
        'owa_host' => 'OWA\\Module\\Base\\Entity\\Host',
        'owa_impression' => 'OWA\\Module\\Base\\Entity\\Impression',
        'owa_location_dim' => 'OWA\\Module\\Base\\Entity\\LocationDim',
        'owa_os' => 'OWA\\Module\\Base\\Entity\\Os',
        'owa_queue_item' => 'OWA\\Module\\Base\\Entity\\QueueItem',
        'owa_referer' => 'OWA\\Module\\Base\\Entity\\Referer',
        'owa_request' => 'OWA\\Module\\Base\\Entity\\Request',
        'owa_search_term_dim' => 'OWA\\Module\\Base\\Entity\\SearchTermDim',
        'owa_session' => 'OWA\\Module\\Base\\Entity\\Session',
        'owa_site' => 'OWA\\Module\\Base\\Entity\\Site',
        'owa_site_user' => 'OWA\\Module\\Base\\Entity\\SiteUser',
        'owa_source_dim' => 'OWA\\Module\\Base\\Entity\\SourceDim',
        'owa_ua' => 'OWA\\Module\\Base\\Entity\\Ua',
        'owa_user' => 'OWA\\Module\\Base\\Entity\\User',
        'owa_visitor' => 'OWA\\Module\\Base\\Entity\\Visitor',

        // modules/base/metrics (Phase 6 stage 2)
        'owa_actions' => 'OWA\\Module\\Base\\Metric\\Actions',
        'owa_actionsPerVisit' => 'OWA\\Module\\Base\\Metric\\ActionsPerVisit',
        'owa_actionsValue' => 'OWA\\Module\\Base\\Metric\\ActionsValue',
        'owa_bounceRate' => 'OWA\\Module\\Base\\Metric\\BounceRate',
        'owa_bounces' => 'OWA\\Module\\Base\\Metric\\Bounces',
        'owa_clickBrowserTypes' => 'OWA\\Module\\Base\\Metric\\ClickBrowserTypes',
        'owa_configurableMetric' => 'OWA\\Module\\Base\\Metric\\ConfigurableMetric',
        'owa_domClicks' => 'OWA\\Module\\Base\\Metric\\DomClicks',
        'owa_ecommerceConversionRate' => 'OWA\\Module\\Base\\Metric\\EcommerceConversionRate',
        'owa_feedReaders' => 'OWA\\Module\\Base\\Metric\\FeedReaders',
        'owa_feedRequests' => 'OWA\\Module\\Base\\Metric\\FeedRequests',
        'owa_feedSubscriptions' => 'OWA\\Module\\Base\\Metric\\FeedSubscriptions',
        'owa_goalAbandonRateAll' => 'OWA\\Module\\Base\\Metric\\GoalAbandonRateAll',
        'owa_goalCompletionsAll' => 'OWA\\Module\\Base\\Metric\\GoalCompletionsAll',
        'owa_goalConversionRateAll' => 'OWA\\Module\\Base\\Metric\\GoalConversionRateAll',
        'owa_goalNCompletions' => 'OWA\\Module\\Base\\Metric\\GoalNCompletions',
        'owa_goalNStarts' => 'OWA\\Module\\Base\\Metric\\GoalNStarts',
        'owa_goalNValue' => 'OWA\\Module\\Base\\Metric\\GoalNValue',
        'owa_goalStartsAll' => 'OWA\\Module\\Base\\Metric\\GoalStartsAll',
        'owa_goalValueAll' => 'OWA\\Module\\Base\\Metric\\GoalValueAll',
        'owa_latestDomstreams' => 'OWA\\Module\\Base\\Metric\\LatestDomstreams',
        'owa_lineItemQuantity' => 'OWA\\Module\\Base\\Metric\\LineItemQuantity',
        'owa_lineItemQuantityFromSessionFact' => 'OWA\\Module\\Base\\Metric\\LineItemQuantityFromSessionFact',
        'owa_lineItemRevenue' => 'OWA\\Module\\Base\\Metric\\LineItemRevenue',
        'owa_lineItemRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\LineItemRevenueFromSessionFact',
        'owa_newVisitors' => 'OWA\\Module\\Base\\Metric\\NewVisitors',
        'owa_pagesPerVisit' => 'OWA\\Module\\Base\\Metric\\PagesPerVisit',
        'owa_repeatVisitors' => 'OWA\\Module\\Base\\Metric\\RepeatVisitors',
        'owa_revenuePerTransaction' => 'OWA\\Module\\Base\\Metric\\RevenuePerTransaction',
        'owa_revenuePerVisit' => 'OWA\\Module\\Base\\Metric\\RevenuePerVisit',
        'owa_sessionBrowserTypes' => 'OWA\\Module\\Base\\Metric\\SessionBrowserTypes',
        'owa_shippingRevenue' => 'OWA\\Module\\Base\\Metric\\ShippingRevenue',
        'owa_shippingRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\ShippingRevenueFromSessionFact',
        'owa_taxRevenue' => 'OWA\\Module\\Base\\Metric\\TaxRevenue',
        'owa_taxRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TaxRevenueFromSessionFact',
        'owa_topReferers' => 'OWA\\Module\\Base\\Metric\\TopReferers',
        'owa_topVisitors' => 'OWA\\Module\\Base\\Metric\\TopVisitors',
        'owa_transactionRevenue' => 'OWA\\Module\\Base\\Metric\\TransactionRevenue',
        'owa_transactionRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TransactionRevenueFromSessionFact',
        'owa_transactions' => 'OWA\\Module\\Base\\Metric\\Transactions',
        'owa_transactionsFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TransactionsFromSessionFact',
        'owa_uniqueActions' => 'OWA\\Module\\Base\\Metric\\UniqueActions',
        'owa_uniqueLineItems' => 'OWA\\Module\\Base\\Metric\\UniqueLineItems',
        'owa_uniqueLineItemsFromSessionFact' => 'OWA\\Module\\Base\\Metric\\UniqueLineItemsFromSessionFact',
        'owa_uniquePageViews' => 'OWA\\Module\\Base\\Metric\\UniquePageViews',
        'owa_visitDuration' => 'OWA\\Module\\Base\\Metric\\VisitDuration',
        'owa_visitors' => 'OWA\\Module\\Base\\Metric\\Visitors',
    ];
}

spl_autoload_register(function (string $class): void {

    // Only ever act on legacy global-namespace owa_* names. A namespaced name
    // (OWA\...) contains a backslash and is Composer's job, not ours.
    if (strncmp($class, 'owa_', 4) !== 0) {
        return;
    }

    $map = owa_compat_class_map();
    if (!isset($map[$class])) {
        return; // not a migrated class — leave it to the require_once loaders
    }

    $new = $map[$class];

    // Make sure the new class is actually loaded (Composer PSR-4/classmap).
    // Guard the alias so we never redefine an old name that is still declared
    // directly somewhere during the transition.
    if (class_exists($new) && !class_exists($class, false)) {
        class_alias($new, $class);
    }
});
