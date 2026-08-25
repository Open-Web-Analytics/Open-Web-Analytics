<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="owa_header">

    <span class="owa_logo"><img src="<?php echo $view->makeImageLink( \OWA\Core\CoreAPI::getSetting( 'base', 'logo_image_path' ) ); ?>" alt="Open Web Analytics"></span>
     &nbsp
    <span class="owa_navigation">
        <UL>
            <?php if ($view->getCurrentUser()->isCapable('view_site_list')): ?>
                <LI><a href="<?php echo $view->makeLink(array('do' => 'base.sites'));?>">Reporting</a></LI>
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
            <span id="owa_notificationBadge" class="owa_notificationBadge" hidden>0</span>
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

        // The badge IS the count of what we hold. Never computed anywhere else.
        function paintBadge(n) {
            badge.textContent = n;
            badge.hidden = (n === 0);
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
                    paintBadge(items.querySelectorAll('.owa_notification').length);
                    if (!items.querySelector('.owa_notification')) { renderEmpty(); }
                })
                .catch(function () { /* leave it on screen; the next load re-reads */ });
        }

        function renderEmpty() {
            items.innerHTML = '';
            var p = document.createElement('p');
            p.className = 'info_text';
            p.textContent = 'Nothing new.';
            items.appendChild(p);
        }

        function render(list) {
            items.innerHTML = '';

            if (!list.length) { renderEmpty(); return; }

            list.forEach(function (n) {
                var row = document.createElement('div');
                row.className = 'owa_notification';

                var meta = document.createElement('div');
                meta.className = 'owa_notificationMeta';
                if (n.published_at) {
                    var when = document.createElement('span');
                    when.className = 'info_text';
                    when.textContent = new Date(n.published_at * 1000)
                        .toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
                    meta.appendChild(when);
                }
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'owa_notificationDismiss';
                x.textContent = 'Dismiss';
                x.addEventListener('click', function () { dismiss(n.id, row); });
                meta.appendChild(x);
                row.appendChild(meta);

                var title = document.createElement('div');
                title.className = 'owa_notificationTitle';
                if (n.url) {
                    var a = document.createElement('a');
                    a.href = n.url;
                    // textContent throughout: release notes are other people's
                    // text and none of it is markup here.
                    a.textContent = n.title;
                    title.appendChild(a);
                } else {
                    title.textContent = n.title;
                }
                row.appendChild(title);

                if (n.body) {
                    var body = document.createElement('div');
                    body.className = 'owa_notificationBody';
                    body.textContent = n.body;
                    row.appendChild(body);
                }

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
                paintBadge(list.length);
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