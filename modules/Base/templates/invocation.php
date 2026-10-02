<?php /** @var \OWA\Core\ViewScope $view */ ?>
<fieldset>
    <legend>Javascript</legend>
    <div style="padding:10px;">
        <P>Paste this tag into the HTML of every page, once. It loads this Profile&rsquo;s tracking bundle,
        so a change to the settings below reaches your pages without pasting it again. To add a page-level command
        &mdash; a page title, a user id, a purchase &mdash; push it onto <code>owa_cmds</code> before the tag.
        Learn more about OWA's <a href="<?php echo $view->makeWikiLink('Javascript-Tracker');?>">Javascript tracking API</a>.</P>

        <textarea class="owa_trackingCode" rows="8" readonly>
<?php echo $view->bundle_tag; ?>
        </textarea>

        <details>
            <summary>Classic tag</summary>
            <P>For a page that cannot load the bundle. It carries this Profile&rsquo;s settings as they are now,
            and does not follow later changes: copy it again after changing them.</P>
            <textarea class="owa_trackingCode" rows="18" readonly>

<?php echo $view->tracking_code; ?>

            </textarea>
        </details>
    </div>
</fieldset>

<fieldset>
    <legend>PHP</legend>
    <div style="padding:10px;">

        <P>To track page views using PHP, download and use the <a href="https://github.com/Open-Web-Analytics/owa-php-sdk">OWA PHP SDK.</a></P>

    </div>
</fieldset>

