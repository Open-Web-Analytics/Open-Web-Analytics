<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="owa_header">

    <span class="owa_logo"><img src="<?php echo $view->makeImageLink( \OWA\Core\CoreAPI::getSetting( 'base', 'logo_image_path' ) ); ?>" alt="Open Web Analytics"></span>
     &nbsp
    <span class="owa_navigation">
        <UL>
            <?php if ($view->getCurrentUser()->isCapable('view_site_list')): ?>
                <LI><a href="<?php echo $view->makeLink(array('do' => 'base.reportingHome'));?>">Reporting</a></LI>
            <?php endif; ?>
            <?php if ($view->getCurrentUser()->isCapable('edit_settings')): ?>
                <LI><a href="<?php echo $view->makeLink(array('do' => 'base.optionsGeneral'));?>">Settings</a></LI>
            <?php endif; ?>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki">Documentation</a></LI>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/issues">Report a Bug</a></LI>
            <LI><a href="https://github.com/sponsors/padams">Donate</a>

        </UL>
    </span>
    <?php $cu = $view->getCurrentUser(); ?>
<?php if ( \OWA\Core\CoreAPI::isCurrentUserAuthenticated() ): ?>
<?php
    /*
     * The badge count is NOT rendered here and is not a call of its own.
     *
     * One fetch returns the notifications, and the badge is the length of what
     * came back -- so the number and the list cannot disagree, which is the
     * failure mode of a separately-computed count. The count is owned by the
     * same code that draws the panel.
     *
     * makeApiLink() builds the endpoint AND mints the nonce, so this does not
     * hard-code where the API lives or how a nonce is derived. Both routes are
     * named `notifications` and differ only by HTTP method, so one URL serves
     * the read and the dismiss.
     */
    $owa_apiUrl = $view->makeApiLink( array(
        'do'      => 'notifications',
        'module'  => 'base',
        'version' => 'v1',
    ) );
?>
    <span class="owa_notificationBell">
        <button type="button" id="owa_notificationToggle" class="owa_notificationToggle"
                aria-expanded="false" aria-controls="owa_notificationPanel"
                aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span id="owa_notificationBadge" class="owa_notificationBadge">0</span>
        </button>
        <div id="owa_notificationPanel" class="owa_notificationPanel" hidden>
            <div class="owa_notificationPanelHeader">Notifications</div>
            <div id="owa_notificationItems" class="owa_notificationItems">
                <p class="info_text">Loading...</p>
            </div>
        </div>
    </span>
    <script>
    (function () {
        var toggle = document.getElementById('owa_notificationToggle');
        var panel  = document.getElementById('owa_notificationPanel');
        var badge  = document.getElementById('owa_notificationBadge');
        var items  = document.getElementById('owa_notificationItems');

        if (!toggle) { return; }

        // Route, nonce and endpoint all come from makeApiLink(): this install
        // addresses the API as api/index.php?do=...&module=...&version=...,
        // and assembling path segments here would only work on an install
        // whose URLs are rewritten.
        var apiUrl = <?php echo json_encode( $owa_apiUrl ); ?>;

        // The badge counts the UNREAD rows we hold -- not how many rows there
        // are, because a read notification stays on screen without counting.
        // Still derived from the content and nowhere else.
        function paintBadge() {
            var unread = items.querySelectorAll('.owa_notification.is-unread').length;

            badge.textContent = unread;
            // Never hidden: an empty badge is still the control people look
            // for, and a bell that only sometimes has one moves under the
            // cursor.
            badge.classList.toggle('is-zero', unread === 0);
        }

        function markRead(id, el) {
            if (!el.classList.contains('is-unread')) { return; }

            // Unbold immediately; the row stays either way, and a click that
            // looks like nothing happened invites a second one.
            el.classList.remove('is-unread');
            paintBadge();

            fetch(apiUrl + '&notificationId=' + encodeURIComponent(id),
                  { method: 'POST', credentials: 'same-origin' })
                .catch(function () { /* the next load re-reads the truth */ });
        }

        function dismiss(id, el) {
            fetch(apiUrl + '&notificationId=' + encodeURIComponent(id),
                  { method: 'DELETE', credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { return; }
                    // Remove locally rather than refetching: the server has
                    // just been told, and a round trip to learn what we did
                    // would make the click feel slower than it is.
                    el.remove();
                    paintBadge();
                    if (!items.querySelector('.owa_notification')) { renderEmpty(); }
                })
                .catch(function () { /* leave it on screen; the next load re-reads */ });
        }

        function renderEmpty() {
            items.innerHTML = '';
            paintBadge();
            var p = document.createElement('p');
            p.className = 'info_text';
            p.textContent = 'Nothing new.';
            items.appendChild(p);
        }

        // An icon per TYPE. Unknown types get a neutral bell rather than
        // nothing, so a row never renders with an empty hole where the icon is.
        var ICONS = { release: 'fa-box', general: 'fa-bell' };

        // "3d" rather than a date, the way a notification list reads. Falls
        // back to a real date past a week, where "37d" stops being useful.
        function relativeTime(seconds) {
            var diff = Math.floor(Date.now() / 1000) - seconds;

            if (diff < 60)     { return 'just now'; }
            if (diff < 3600)   { return Math.floor(diff / 60) + 'm'; }
            if (diff < 86400)  { return Math.floor(diff / 3600) + 'h'; }
            if (diff < 604800) { return Math.floor(diff / 86400) + 'd'; }

            return new Date(seconds * 1000)
                .toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }

        function render(list) {
            items.innerHTML = '';

            if (!list.length) { renderEmpty(); return; }

            list.forEach(function (n) {
                var row = document.createElement('div');
                // is-unread is what the styling keys on, and what the badge
                // counts. One class, one source of truth.
                row.className = 'owa_notification' + (n.read ? '' : ' is-unread');

                var icon = document.createElement('span');
                icon.className = 'owa_notificationIcon';
                var glyph = document.createElement('i');
                glyph.className = 'fas ' + (ICONS[n.type] || ICONS.general);
                glyph.setAttribute('aria-hidden', 'true');
                icon.appendChild(glyph);
                row.appendChild(icon);

                var main = document.createElement('div');
                main.className = 'owa_notificationMain';

                var title = document.createElement('div');
                title.className = 'owa_notificationTitle';
                if (n.url) {
                    var a = document.createElement('a');
                    a.href = n.url;
                    // A new tab, deliberately: these point at github.com, and
                    // marking read is a request of our own -- letting the same
                    // click navigate away races it.
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    // textContent throughout: these are other people's words
                    // and none of them are markup here.
                    a.textContent = n.title;
                    title.appendChild(a);
                } else {
                    title.textContent = n.title;
                }
                main.appendChild(title);

                if (n.excerpt) {
                    var excerpt = document.createElement('div');
                    excerpt.className = 'owa_notificationExcerpt';
                    excerpt.textContent = n.excerpt;
                    main.appendChild(excerpt);
                }

                if (n.published_at) {
                    var when = document.createElement('div');
                    when.className = 'owa_notificationWhen';
                    when.textContent = relativeTime(n.published_at);
                    main.appendChild(when);
                }

                row.appendChild(main);

                // An x, not the word "Dismiss": the row is already busy, and
                // this is the control people look for in the corner.
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'owa_notificationDismiss';
                x.setAttribute('aria-label', 'Dismiss notification');
                x.textContent = '\u00d7';
                x.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dismiss(n.id, row);
                });
                row.appendChild(x);

                // Anywhere on the row, the way a notification list behaves --
                // not only the headline.
                row.addEventListener('click', function () { markRead(n.id, row); });

                items.appendChild(row);
            });
        }

        fetch(apiUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                // The REST view wraps everything in an envelope --
                // {requestId, httpResponse, error, data} -- so the payload is
                // one level down. Reading data.notifications silently yields
                // undefined and an empty badge, which looks exactly like
                // "nothing to show".
                var list = (data && data.data && data.data.notifications) || [];
                render(list);
                paintBadge();
            })
            .catch(function () { renderEmpty(); });

        toggle.addEventListener('click', function () {
            var open = !panel.hidden;
            panel.hidden = open;
            toggle.setAttribute('aria-expanded', String(!open));
        });

        document.addEventListener('click', function (e) {
            if (!panel.hidden && !e.target.closest('.owa_notificationBell')) {
                panel.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();
    </script>
<?php endif; ?>
    <span class="user-greating" style="">
        Hi, <?php $view->out( $cu->getUserData('user_id') );?> ! &bull;
        <?php if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'is_embedded' ) ):?>

                <?php if ( \OWA\Core\CoreAPI::isCurrentUserAuthenticated() ):?>
                <a class="login" href="<?php echo $view->makeLink(array('do' => 'base.logout'), false);?>">Logout</a>
                <?php else:?>
                <a class="login" href="<?php echo $view->makeLink(array('do' => 'base.loginForm'), false);?>">Login</a>
                <?php endif;?>

            <?php endif;?>
    </span>
    <div class="post-nav"></div>
    <?php if (!empty($service_msg)): ?>
    <div class="owa_headerServiceMsg"><?php echo $service_msg; ?></div>
    <?php endif;?>

    <?php $view->headerActions(); ?>

</div>