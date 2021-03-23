<?php require('report_trend_section.php');?>

<div class="owa_reportSectionContent">
    <table style="width:100%;">
        <TR>

            <TD width="50%" valign="top">

                <div class="owa_reportSectionContent">
                    <div class="section_header">Dom IDs</div>
                    <div style="min-width:300px;" id="topDomIds"></div>
                    <script>
                    var url = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                  'metrics' => 'domClicks',
                                                                  'dimensions' => 'domElementId',
                                                                  'constraints' => $constraints,
                                                                  'sort' => 'domClicks-',
                                                                  'resultsPerPage' => 5,
                                                                  'format' => 'json'), true);?>';

                    rshre = new OWA.resultSetExplorer('topDomIds');
                    rshre.asyncQueue.push(['refreshGrid']);
                    rshre.load(url);
                    </script>
                </div>

                <div class="owa_reportSectionContent">
                    <div class="section_header">Name Attributes</div>
                    <div style="min-width:300px;" id="topDomNames"></div>
                    <script>
                    var url = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                  'metrics' => 'domClicks',
                                                                  'dimensions' => 'domElementName',
                                                                  'constraints' => $constraints,
                                                                  'sort' => 'domClicks-',
                                                                  'resultsPerPage' => 5,
                                                                  'format' => 'json'), true);?>';

                    rshre = new OWA.resultSetExplorer('topDomNames');
                    rshre.asyncQueue.push(['refreshGrid']);
                    rshre.load(url);
                    </script>
                </div>

            </TD>

            <TD width="" valign="top">

                <div class="owa_reportSectionContent">
                    <div class="section_header">HTML Tags</div>
                    <div style="min-width:300px;" id="topHtmlTags"></div>
                    <script>
                    var url = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                  'metrics' => 'domClicks',
                                                                  'dimensions' => 'domElementTag',
                                                                  'constraints' => $constraints,
                                                                  'sort' => 'domClicks-',
                                                                  'resultsPerPage' => 5,
                                                                  'format' => 'json'), true);?>';

                    rshre = new OWA.resultSetExplorer('topHtmlTags');
                    rshre.asyncQueue.push(['refreshGrid']);
                    rshre.load(url);
                    </script>
                </div>

                <div class="owa_reportSectionContent">
                    <div class="section_header">Dom Classes</div>
                    <div style="min-width:300px;" id="topDomClasses"></div>
                    <script>
                    var url = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                  'metrics' => 'domClicks',
                                                                  'dimensions' => 'domElementClass',
                                                                  'constraints' => $constraints,
                                                                  'sort' => 'domClicks-',
                                                                  'resultsPerPage' => 5,
                                                                  'format' => 'json'), true);?>';

                    rshre = new OWA.resultSetExplorer('topDomClasses');
                    rshre.asyncQueue.push(['refreshGrid']);
                    rshre.load(url);
                    </script>
                </div>

            </TD>
        </TR>
    </table>
</div>

<?php require_once('js_report_templates.php');?>