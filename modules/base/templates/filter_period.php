<div class="timePeriodControlContainer">

    <table id="owa_reportPeriodLabelContainer" cellpadding="0" cellspacing="0">
        <TR>
            <TD class="owa_reportPeriodLabelText">

                <span>
                    <*=this.datelabel *>
                </span>
            </TD>

            <TD class="owa_reportRevealControl"></TD>
        </TR>
    </table>

    <table id="owa_reportPeriodFiltersContainer" style="display:none;" cellpadding="0" cellspacing="0">
        <TR>
            <TH colspan="3">
                Enter a Date Range:
            </TH>
        </TR>
        <TR>
            <TD class="picker" valign="top">
                <div>Start: <input type="text" id="owa_report-datepicker-start-display" size="10"></div>
                <div id="owa_report-datepicker-start"></div>
            <TD class="picker" valign="top">
                <div>End: <input type="text" id="owa_report-datepicker-end-display"  size="10"></div>
                <div id="owa_report-datepicker-end"></div>
            </TD>
            <TD>

            </TD>

            <TD valign="top">
                Predefined Periods:<BR>

                <SELECT id="owa_reportPeriodFilter" name="owa_reportPeriodFilter">
                    <OPTION>Select...</OPTION>
                    <* for (period in this.periods) { *>
                    <OPTION VALUE="<*= period *>" <* if ( period === this.selectedPeriod ) { *>selected<* } *> >
                        <*= this.periods[period] *>
                    </OPTION>
                    <* } *>
                </SELECT>
                <P><INPUT class="submit-button" type="submit" id="owa_reportPeriodFilterSubmit" name="" value="Change Date Range"></P>
            </TD>
        </TR>
        <TR>
            <TD colspan="3"></TD>
        </TR>
    </table>
</div>