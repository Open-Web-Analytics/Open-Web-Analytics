<?php

/*
   Solace PHP profiler v1.0
   (c) Zilav <solace@ezmail.ru>
   Use freely and enjoy.
   
   Based on PHP's 'ticks' directive and debug_backtrace() function.
   Compatible with both PHP4 and PHP5, doesn't require any extensions.
   Supports inludes, evals, asserts.

   Generates profile reports from script source code, adding number
   of php interpreter passes and overall time in percents
   for each line. Can be useful to find slow or unused code, compare
   speed of different codes, track loops count and conditions, etc.
   Reports are in txt or html format.
   Please note, that due to the way the tick works, there will be
   some issues:
   - return statement is not profiled directly, it is counted as the
     function call
   - all kinds if multiline loops, 'if', 'else' will usually report
     their time at the enclosing '}'
   - summary of all time percents is not 100% (php tags are ignored)
   - and some others not serious...
   
   To enable profiling just place the following code at the beginning
   of the main script:
     include_once('class.solace.profiler.php');
     declare(ticks=1);

   You can also profile only parts of the code. Put
     declare(ticks=1);
   before code block, and
     declare(ticks=0);
   after it.
*/


    // processing large scripts with profiling can take some time
    // (usually 3-5 times longer)
    // uncomment the line below if your scripts are large

    set_time_limit(0);

class solace_profiler {

    var $options = array (

        // report format ('html' or 'txt')
        'REPORT_FORMAT' => 'html',

        // combine reports of all included files in one full report (1 or 0)
        'REPORT_COMBINE' => 1,

        // save report(s) to file(s) (1 or 0)
        // if REPORT_COMBINE is set, then save full report to file
        // with the name of main script (script were profile class is included)
        // if REPORT_COMBINE is not set, then save reports in
        // separate files for each script
        'REPORT_SAVE' => 1,

        // extension for report files
        'REPORT_EXTENSION' => '.html',

        // path for report files (with trailing slash)
        // if empty, then save in the same dir with profiling scripts
        'REPORT_SAVEPATH' => '',

        // echo report upon exit (1 or 0)
        'REPORT_ECHO' => 1,

        'null' => ''
    );

    // Do not modify these vars manually
    var $self_name = '';
    var $profile = array();
    var $mt = 0;
    var $_script = '';
    var $_line = 0;
    var $_php5 = 0;
    
    //===========================================================================
    function solace_profiler () {
        $this->self_name = __FILE__;
        if (version_compare(phpversion(), '5') >= 0) $this->_php5 = 1;
        register_tick_function(array(&$this, 'tick_function')); 
        register_shutdown_function(array(&$this, 'create_report'));
        $this->mt = array_sum(explode(' ', microtime()));
    }

    //===========================================================================
    function reportfile_for_script($script) {
        $fn = substr($script, 0, strrpos($script, '.')).$this->options['REPORT_EXTENSION'];
        if (strlen($this->options['REPORT_SAVEPATH']) <> 0)
            $fn = $this->options['REPORT_SAVEPATH'].basename($fn);
        return $fn;
    }

    //===========================================================================
    function elapsed() {
        $e = array_sum(explode(' ', microtime())) - $this->mt;
        $this->mt += $e;
        return $e;
    }

    //===========================================================================
    function tick_function () {
        $dbg = debug_backtrace();
        list(, $info) = each($dbg);
        if (!$this->_php5) list(, $info) = each($dbg);
        if ($info['file'] == $this->self_name) return;
        if ($this->_php5) list(, $info) = each($dbg);

        $script = $info['file'];
        $br = strpos($script, '(');
        if ($br === false) {
            $line = $info['line'];
        } else {
            $line = substr($script, $br + 1, strpos($script, ')', $br) - $br - 1);
            $script = substr($script, 0, $br);
        }
        if ($this->_script)
            $this->profile[$this->_script][$this->_line][0] += $this->elapsed();
        if (!isset($this->profile[$script][$line][1])) {
            $this->profile[$script][$line][0] = 0;
            $this->profile[$script][$line][1] = 1;
        } else
            $this->profile[$script][$line][1] += 1;
        $this->_script = $script;
        $this->_line = $line;
    }

    //===========================================================================
    function create_report() {
        if (!is_array($this->profile)) return;
        $o = &$this->options;
        $full_report = '';
        foreach ($this->profile as $script => $lines) {
            if (!isset($main_script)) $main_script = $script;
            $overall_time = 0;
            foreach ($lines as $n => $info) $overall_time += $info[0];
            $script_src = @file($script);
            if (!is_array($script_src)) {
                echo "PROFILE ERROR: unable to open file $script<br>\n";
                continue;
            }
            $report = "<?php\n";
            $report .= "/* PROFILE for script ".basename($script)." */\n";
            $report .= "/* created ".strftime('%c')." */\n";
            $report .= "?>\n";
            for ($n = 0; $n < count($script_src); $n++) {
                $code = $script_src[$n];
                if (!isset($lines[$n + 1]))
                    $info = array(0, 0);
                else
                    $info = $lines[$n + 1];
                if ($info[1]) {
                    $tag = strtolower(trim($code));
                    if ($tag == '?>')
                        $report .= $code;
                    else
                        $report .= sprintf('/* %4d %02.2f%% */ %s',
                            $info[1], $info[0]*100/$overall_time, $code);
                } else {
                    $tag = strtolower(trim($code));
                    if (($tag == '<?') or ($tag == '<?php'))
                        $report .= $code;
                    else
                        $report .= sprintf('   %4s %6s    %s', ' ', ' ', $code);
                }
            }
            if ($o['REPORT_FORMAT'] == 'html')
                $report = highlight_string($report, true);
            if ($o['REPORT_SAVE'] and !$o['REPORT_COMBINE']) {
                $fn = $this->reportfile_for_script($script);
                if (($f = @fopen($fn, 'w+b')) !== false) {
                    fwrite($f, $report);
                    fclose($f);
                } else
                    echo "PROFILE ERROR: unable to create file $fn<br>\n";
            }
            if ($o['REPORT_COMBINE']) $full_report .= $report;
                else if ($o['REPORT_ECHO']) echo $report;
        }
        if ($o['REPORT_SAVE'] and $o['REPORT_COMBINE']) {
            $fn = $this->reportfile_for_script($main_script);
            if (($f = @fopen($fn, 'w+b')) !== false) {
                fwrite($f, $full_report);
                fclose($f);
            } else
                echo "PROFILE ERROR: unable to create file $fn<br>\n";
        }
        if ($o['REPORT_ECHO'] and $o['REPORT_COMBINE']) echo $full_report;
    }
    
}

    new solace_profiler();

?>
