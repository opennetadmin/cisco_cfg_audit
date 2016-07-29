<?php

//////////////////////////////////////////////////////////////////////////////
// Function: rpt_run()
//
// Description:
//   Returns the output for this report.
//   It will first get the DATA for the report by executing whatever code gathers
//   data used by the report.  This is handled by the rpt_get_data() function.
//   It will then pass that data to the appropriate output generator.
//
//   A rpt_output_XYZ() function should be written for each type of output format
//   you want to support.  The data from rpt_get_data will be used by this function.
//
//   IN GENERAL, YOU SHOULD NOT NEED TO EDIT THIS FUNCTION
//
//////////////////////////////////////////////////////////////////////////////
function rpt_run($form, $output_format='html') {

    $status=0;

    // See if the output function they requested even exists
    $func_name = "rpt_output_{$output_format}";
    if (!function_exists($func_name)) {
        $rptoutput = "ERROR => This report does not support an '{$form['format']}' output format.";
        return(array(1,$rptoutput));
    }

    // if we are looking for the usage, skip gathering data.  Otherwise, gather report data.
    if (!$form['rpt_usage']) list($status, $rptdata) = rpt_get_data($form);

    if ($status) {
        $rptoutput = "ERROR => There was a problem getting the data. <br> {$rptdata}";
    }
    // Pass the data to the output type
    else {
        // If the rpt_usage option was passed, add it to the gathered data
        if ($form['rpt_usage']) $rptdata['rpt_usage'] = $form['rpt_usage'];

        // Pass the data to the output generator
        list($status, $rptoutput) = $func_name($rptdata);
        if ($status)
            $rptoutput = "ERROR => There was a problem getting the output: {$rptoutput}";
    }

    return(array($status,$rptoutput));
}



//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////START EDITING BELOW////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////




//////////////////////////////////////////////////////////////////////////////
// Function: rpt_html_form()
//
// Description:
//   Returns the HTML form text for this report.
//   This is used by the display report code to present an html form to
//   the user.  This simply provides a gui to gather all the input variables.
//////////////////////////////////////////////////////////////////////////////
function rpt_html_form($report_name, $rptform='',$rptjs='') {
    global $images, $color, $style;
    $rpthtml = '';
    $rptjs = '';

    // Create your input form below
    $rpthtml .= <<<EOL

        <form id="{$report_name}_report_form" onsubmit="el('rpt_submit_button').onclick(); return false;">
            <input type="hidden" value="{$report_name}" name="report"/>
            Hostname: <input id="config" name="config" value="{$rptform['config']}" class="edit" type="text" size="35" />
            <input type="submit"
                   id="rpt_submit_button"
                   title="Search"
                   value="Run Report"
                   class="act"
                   onClick="el('report_content').innerHTML='<br><center><img src={$images}/loading.gif></center><br>';xajax_window_submit('display_report', xajax.getFormValues('{$report_name}_report_form'), 'run_report');"
            />
            <input class="act" type="button" name="reset" value="Clear" onClick="clearElements('{$report_name}_report_form');">
        </form>

EOL;


    // Return the html code for the form
    return(array(0,$rpthtml,$rptjs));
}














function rpt_get_data($form) {

    if (!$form['config']) {
        $rptdata['nohost'] = "Please enter a hostname above to view most recent config archive comparison.";
        return(array(0,$rptdata));
    }

    // Determine if we were given a hostname or a full config text
    // If the size is less than 100 chars, assume it is a hostname and check it
    if (strlen($form['config']) < 100) {
        list($status, $rows, $host) = ona_find_host($form['config']);
        if ($host['id']) {
            // Now find the ID of the config type 'IOS_CONFIG'
            list($status, $rows, $config_type) = ona_get_config_type_record(array('name' => 'IOS_CONFIG'));
            if (!$config_type['id']) {
                $self['error'] = "ERROR => The config type specified, 'IOS_CONFIG', is invalid!";
                return(array(2, $self['error']."\n"));
            }
            // Select the first config record of the specified type and host
            list($status, $rows, $config) = ona_get_config_record(array('host_id' => $host['id'], 'configuration_type_id' => $config_type['id']));
            if (!$config['id']) {
                $self['error'] = "ERROR => Unable to find a config archive type of 'IOS_CONFIG' for host '{$host['fqdn']}'!";
                return(array(3, $self['error']."\n"));
            }
        } else {
            $self['error'] = "ERROR => Unable to find a database host or a local file named '{$form['config']}'.";
            return(array(4, $self['error']."\n"));
        }
    } else {
        $config['config_body'] = $form['config'];
    }



    // Turn the config into an array of lines
    $config_array = preg_split("[\n|\r]", $config['config_body']);

    // add the database device fqdn to the rptdata array
    $rptdata['dbhostname'] = $host['fqdn'];
    $rptdata['dbhostid'] = $host['id'];

    $rptdata['config_id'] = $config['id'];
    $rptdata['dcm_output'] = $form['dcm_output'];

    // Now that we have a config, lets start checking for things
    foreach ($config_array as $line) {

        // If this is a ! separator, lets reset some values so things dont get strange
        if (trim($line) == "!") {
            unset($intname);
            continue;
        }

        // Get the hostname
        if (preg_match("/^hostname \S+/i", $line)) {
            $rptdata['hostname'] = trim(preg_replace("/^hostname (\S+)/si","$1",$line));
            continue;
        }

        // Get the domainname
        if (preg_match("/^ip domain[ |-]name \S+/i", $line)) {
            $rptdata['domainname'] = trim(preg_replace("/^ip domain[ |-]name (\S+)/si","$1",$line));
            continue;
        }

        // Get the interface name we are "in"
        if (preg_match("/^interface /i", $line)) {
            $intname = preg_replace("/^interface (\S+)/si","$1",$line);
            $rptdata['ints'][$intname]['name'] = trim($intname);
            continue;
        }

        // Get the interface description
        if (preg_match("/^[ ]+description /i", $line)) {
            $rptdata['ints'][$intname]['description'] = trim(preg_replace("/^[ ]+description (.*)/si","$1",$line));
            continue;
        }

        // Get the interface ip info if it is a "service-module"
        // MP: needs more testing.. can I have a service-module ip AND an ip on the same interface?
        if (preg_match("/^[ ]+service-module ip address /i", $line)) {
            $rptdata['ints'][$intname]['ip_addr']  = preg_replace("/^[ ]+service-module ip address (\S+) \S+/si","$1",$line);
            $rptdata['ints'][$intname]['mask'] = preg_replace("/^[ ]+service-module ip address \S+ (\S+)/si","$1",$line);
            continue;
        }

        // Skip over funky things that use ip address
        if (preg_match("/^[ ]+ip address \S+ port/i", $line)) {
            $intname = 'misc';
            $rptdata['ints'][$intname]['ip_addr']  = ip_mangle(preg_replace("/^[ ]+ip address (\S+) port.*/si","$1",$line),'numeric');
            $rptdata['ints'][$intname]['name'] = 'Not an actual interface';
            continue;
        }

        // Get the interface ip info for secondary IPs
        if (preg_match("/^[ ]+ip address .* secondary/i", $line)) {
            $ip = preg_replace("/^[ ]+ip address (\S+) \S+ secondary/si","$1",$line);
            $intname2 = $intname.$ip;
            $rptdata['ints'][$intname2]['ip_addr']  = ip_mangle($ip,'numeric');
            $rptdata['ints'][$intname2]['mask'] = preg_replace("/^[ ]+ip address \S+ (\S+) secondary/si","$1",$line);
            $rptdata['ints'][$intname2]['name'] = $rptdata['ints'][$intname]['name'].' -- SECONDARY';
            $rptdata['ints'][$intname2]['description'] = $rptdata['ints'][$intname]['description'];
            continue;
        }

        // Get the interface ip info
        if (preg_match("/^[ ]+ip address /i", $line)) {
          // check if it has a cidr based mask
          if (preg_match("/^[ ]+ip address \S+\//i", $line)) {
            $rptdata['ints'][$intname]['ip_addr']  = ip_mangle(preg_replace("/^[ ]+ip address (\S+)\/\S+/si","$1",$line),'numeric');
            $rptdata['ints'][$intname]['mask'] = preg_replace("/^[ ]+ip address \S+\/(\S+)/si","$1",$line);
          } else {
            $rptdata['ints'][$intname]['ip_addr']  = ip_mangle(preg_replace("/^[ ]+ip address (\S+) \S+/si","$1",$line),'numeric');
            $rptdata['ints'][$intname]['mask'] = preg_replace("/^[ ]+ip address \S+ (\S+)/si","$1",$line);
          }
          continue;
        }

        // Get the interface standby ip for hsrp
        if (preg_match("/^[ ]+standby \d+ ip /i", $line)) {
            $rptdata['ints'][$intname]['hsrp']  = preg_replace("/^[ ]+standby \d+ ip (\S+)/si","$1",$line);
            continue;
        }

        // Get the interface standby ip for hsrp, for NXOS type systems
        if (preg_match("/^    ip \S+/i", $line)) {
            $rptdata['ints'][$intname]['hsrp']  = preg_replace("/^    ip (\S+)/si","$1",$line);
            continue;
        }

        // Lets check for some NAT
        if (preg_match("/^ip nat inside source static \d/i", $line)) {
            $in  = preg_replace("/^ip nat inside source static (\S+) \S+/si","$1",$line);
            $rptdata['nats'][$in]['outside'] = preg_replace("/^ip nat inside source static \S+ (\S+)/si","$1",$line);
            $rptdata['nats'][$in]['inside'] = $in;
            continue;
        }

        // Lets check for some PAT
        if (preg_match("/^ip nat inside source static tcp/i", $line)) {
            $patin = preg_replace("/^ip nat inside source static tcp (\S+) \S+ \S+ \S+ \S+/si","$1",$line);
            $rptdata['pats'][$patin]['outside'] = preg_replace("/^ip nat inside source static tcp \S+ \S+ (\S+) \S+ \S+/si","$1",$line);
            $rptdata['pats'][$patin]['inside'] = $patin;
            continue;
        }

        // TODO: MP: maybe look for server IPs that are in use.. tacacs, radius, ntp, dns etc all have IPs that should be in the database

    }


    return(array(0,$rptdata));
}






function rpt_output_html($form) {
    global $onadb, $style, $images;

    if (!$form['config_id']) {
        $text .= $form['nohost'];
        return(array(0,$text));
    }

    $timestamp = date('M j G:i:s T Y');
   // $refresh = "xajax_window_submit('display_report', xajax.getFormValues('cisco_cfg_audit_report_form'), 'run_report');";
    ///////////////// test that this name exists in the database//////////////////////
    // Build the FQDN if it has a domain defined
    if ($form['domainname']) $form['hostname'] = $form['hostname'].'.'.$form['domainname'];
    list($status, $rows, $host) = ona_find_host($form['hostname']);
    // Test what happens when there is no domain defined on the device.. how do I find the host?
    /////////////////////////////////////////////////////////////////////////////

    $text .= <<<EOL
    <table class="list-box" cellspacing="0" border="0" cellpadding="0" style="margin-bottom: 0;">
            <!-- Table Header -->
            <tr>
                <td class="list-header" align="left">&nbsp;</td>
                <td class="list-header" align="left">SOURCE CONFIG: {$form['hostname']}</td>
                <td class="list-header" align="right">DATABASE: {$form['dbhostname']} &nbsp;&nbsp;
                    <a title="View this config"
                       onClick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_config_text\', \'host_id=>{$form['dbhostid']},displayconf=>{$form['config_id']}\', \'display\')');"
                    ><img src="{$images}/silk/zoom.png" border="0"></a>&nbsp;</td>
                <td class="list-header" align="right">Generated on: {$timestamp}</td>
            </tr>
    </table>
    <div id="cisco_cfg_audit_res" style="overflow: auto; width: 100%; height: 89%;border-bottom: 1px solid;">
        <table class="list-box" cellspacing="0" border="0" cellpadding="0">
EOL;


    // check that the hostnames match
    if ($form['hostname'] != $form['dbhostname']) {
        $text .= <<<EOL
                <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                    <td class="list-row" align="right">
                        HOSTNAMES:
                    </td>
                    <td class="list-row" align="left" style="{$style['borderR']};">
                        {$form['hostname']}
                    </td>
                    <td class="list-row" align="left">{$form['dbhostname']}</td>
                    <td class="list-row" align="left"><img src="{$images}/silk/stop.png" border="0" /></td>
                    <td class="list-row" align="left">Update router hostname or database hostname</td>
                </tr>
EOL;
    }


    ////////////////// Process the config file interfaces against the database ////////////////////////////
    foreach ($form['ints'] as $int) {
        $subnetaction = '';
        // If the interface has an IP, lets check it.
#echo "<pre>";
#print_r($int);
#echo "</pre>";
        if ($int['ip_addr']) {
            list($status, $rows, $interface) = ona_get_interface_record(array('host_id' => $form['dbhostid'],'ip_addr' => $int['ip_addr']));
            if ($rows == 0) {
                    // Lets find if there is a subnet to put this IP into
                    list($status, $rows, $subnet) = ona_find_subnet($int['ip_addr']);
                    if ($rows == 0) {
                        // find the subnet base from the mask and ip
                        $subnetbase = ip_mangle(str_pad(substr(ip_mangle($int['ip_addr'], 'binary'), 0, ip_mangle($int['mask'], 'cidr')), 32, '0'), 'dotted');
                        $subnetaction = <<<EOL
                            <a title="Add subnet."
                               class="act"
                               onClick="xajax_window_submit('edit_subnet', 'ip_addr=>{$subnetbase},ip_mask=>{$int['mask']},name=>{$int['description']}', 'editor');"
                            >Add the subnet</a> then 
EOL;

                    }


                    $int['ip_addr'] = ip_mangle($int['ip_addr'],'dotted');

                    $text .= <<<EOL
                    <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                        <td class="list-row" align="right">
                            IP:<br>
                            NAME:<br>
                            DESC:<br>
                            HSRP:
                        </td>
                        <td class="list-row" align="left" style="{$style['borderR']};">
                            {$int['ip_addr']}<br>
                            {$int['name']}<br>
                            {$int['description']}<br>
                            {$int['hsrp']}
                        </td>
                        <td class="list-row" align="left">&nbsp;</td>
                        <td class="list-row" align="left"><img src="{$images}/silk/stop.png" border="0"></td>
                        <td class="list-row" align="left">
                           {$subnetaction}
                           <a title="Add interface."
                               class="act"
                               onClick="xajax_window_submit('edit_interface', 'host_id=>{$form['dbhostid']},ip_addr=>{$int['ip_addr']},name=>{$int['name']},description=>{$int['description']}', 'editor');"
                            >Add the interface</a>&nbsp;
                        </td>
                    </tr>
EOL;
            } else {
                     // Set up an array of all the IPs that did match the database,
                    $dbints[] = $int['ip_addr'];
                    $int['ip_addr'] = ip_mangle($int['ip_addr'],'dotted');
                    $interface['ip_addr'] = ip_mangle($interface['ip_addr'],'dotted');

                    // check our HSRP info
                    $dbhsrp = '';
                    if ($int['hsrp']) {
                      $hsrp_addr = ip_mangle($int['hsrp'], 'numeric');
                      list($status, $rows, $hsrp_interface) = ona_get_interface_record(array('ip_addr' => $hsrp_addr));
                        if ($rows == 1) {
                          $dbhsrp = ip_mangle($hsrp_interface['ip_addr'],'dotted');
                    
                        }
                    }

                    // Set up update text for empty DB fields
                    if ($int['name'] and !$interface['name']) $interface['name'] = "Update name";
                    if ($int['description'] and !$interface['description']) $interface['description'] = "Update description";

                    // provide quick updates for name and description
                    $dbintname = $interface['name'];
                    $dbintdesc = $interface['description'];
                    if ($int['name'] and $int['name'] != $interface['name']) $dbintname = <<<EOL
                        <a title="Update interface name in database to '{$int['name']}'."
                           class="act"
                           onClick="xajax_window_submit('edit_interface', 'interface_id=>{$interface['id']},set_ip=>{$interface['ip_addr']},set_name=>{$int['name']}', 'save');xajax_window_submit('display_report', xajax.getFormValues('cisco_cfg_audit_report_form'), 'run_report');"
                        >{$interface['name']}</a>
EOL;
                    if ($int['description'] and $int['description'] != $interface['description']) $dbintdesc = <<<EOL
                        <a title="Update interface description in database to '{$int['description']}'."
                           class="act"
                           onClick="xajax_window_submit('edit_interface', 'interface_id=>{$interface['id']},set_ip=>{$interface['ip_addr']},set_description=>{$int['description']}', 'save');xajax_window_submit('display_report', xajax.getFormValues('cisco_cfg_audit_report_form'), 'run_report');"
                        >{$interface['description']}</a>
EOL;

                    $text .= <<<EOL
                    <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                        <td class="list-row" align="right">
                            IP:<br>
                            NAME:<br>
                            DESC:<br>
                            HSRP:
                        </td>
                        <td class="list-row" align="left" style="{$style['borderR']};">
                            {$int['ip_addr']}<br>
                            {$int['name']}<br>
                            {$int['description']}<br>
                            {$int['hsrp']}
                        </td>
                        <td id="" class="list-row" align="left">
                            {$interface['ip_addr']}<br>
                            {$dbintname}<br>
                            {$dbintdesc}<br>
                            {$dbhsrp}
                        </td>
                        <td class="list-row" align="left"><img src="{$images}/silk/accept.png" border="0" /></td>
                        <td class="list-row" align="left">OK</td>
                    </tr>
EOL;

            }
        }
    }
    ////////////////////////////////////////////////////////////////////////////

    ////////////////// Process the database interfaces that didnt pass above ////////////////////////////
  //  if (!isset($options['dcm_output'])) {
        // get a list of interfaces for this host.. dont include NAT IPs though
        list($status, $int_rows, $interfaces) = db_get_records($onadb, 'interfaces', "host_id = {$form['dbhostid']} and id not in (select nat_interface_id from interfaces where host_id = {$form['dbhostid']}) and id not in (select interface_id from interface_clusters where interface_id = {$int['ip_addr']}");
        foreach ($interfaces as $record) {
            if(in_array($record['ip_addr'],$dbints) === FALSE) {
                $record['ip_addr'] = ip_mangle($record['ip_addr'],'dotted');
                $text .= <<<EOL
                <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                    <td class="list-row" align="right">
                        IP:<br>
                        NAME:<br>
                        DESC:
                    </td>
                    <td class="list-row" align="left" style="{$style['borderR']};">&nbsp;</td>
                    <td class="list-row" align="left">
                        {$record['ip_addr']}<br>
                        {$record['name']}<br>
                        {$record['description']}
                    </td>
                    <td class="list-row" align="left"><img src="{$images}/silk/stop.png" border="0" /></td>
                    <td class="list-row" align="left">Update router config or
                        <a title="Delete '{$record['ip_addr']}'."
                           class="act"
                           onClick="xajax_window_submit('edit_interface', 'interface_id=>{$record['id']}', 'delete');"
                        >delete interface</a>
                    </td>
                </tr>
EOL;
            }
        }
   // }
    ////////////////////////////////////////////////////////////////////////////


    ////////////////// Process the config nat entries with the database ////////////////////////////
    foreach ((array)$form['nats'] as $nat) {
        list($status, $rows, $natint) = ona_find_interface($nat['inside']);
        if ($rows == 0) {
            // See if the outside interface is in the DB
            list($status, $rows, $natintout) = ona_find_interface($nat['outside']);
            if ($rows == 0) {
                $text .= <<<EOL
                <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                    <td class="list-row" align="right">
                        NAT:
                    </td>
                    <td class="list-row" align="left" style="{$style['borderR']};">
                        {$nat['inside']}=>{$nat['outside']}
                    </td>
                    <td class="list-row" align="left">&nbsp;</td>
                    <td class="list-row" align="left"><img src="{$images}/silk/stop.png" border="0" /></td>
                    <td class="list-row" align="left">
                        <a title="Add host."
                            class="act"
                            onClick="xajax_window_submit('edit_host', 'ip_addr=>{$nat['inside']},js=>null', 'editor');"
                        >Add as host</a> or 
                        <a title="Add interface."
                            class="act"
                            onClick="xajax_window_submit('edit_interface', 'ip_addr=>{$nat['inside']},js=>null', 'editor');"
                        >Add as interface</a>, then add NAT.</td>
                </tr>
EOL;
            } else {
                $natintout['ip_addr'] = ip_mangle($natintout['ip_addr'],'dotted');
                $text .= <<<EOL
                <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                    <td class="list-row" align="right">
                        NAT:
                    </td>
                    <td class="list-row" align="left" style="{$style['borderR']};">
                        {$nat['inside']}=>{$nat['outside']}
                    </td>
                    <td class="list-row" align="left">???=>{$natintout['ip_addr']}</td>
                    <td class="list-row" align="left"><img src="{$images}/silk/error.png" border="0" /></td>
                    <td class="list-row" align="left">Add the inside IP to the database.</td>
                </tr>
EOL;
            }
        } else {
            list($status, $rows, $nathost) = ona_find_host($natint['host_id']);
            list($status, $rows, $natinterface) = ona_find_interface($natint['nat_interface_id']);
            if (!$natinterface['id']) {
                $dbnatinfo = "{$natint['ip_addr_text']}=>NOT NATTED";
                $stat = "<img src=\"{$images}/silk/error.png\" border=\"0\" />";
                $statmsg = <<<EOL
                    <a title="Add NAT entry in database."
                        class="act"
                        onClick="wwTT(this, event,
                                        'id', 'tt_quick_interface_nat_{$natint['host_id']}',
                                        'type', 'static',
                                        'delay', 0,
                                        'styleClass', 'wwTT_qf',
                                        'direction', 'southwest',
                                        'javascript', 'xajax_window_submit(\'tooltips\', \'tooltip=>quick_interface_nat,id=>tt_quick_interface_nat_{$natint['host_id']},interface_id=>{$natint['id']},ip_addr=>{$natint['ip_addr_text']},natip=>{$nat['outside']}\');'
                                        );"
                    >Add outside NAT IP.</a>
EOL;
            } else {
                $dbnatinfo = "{$natint['ip_addr_text']}=>{$natinterface['ip_addr_text']}";
                $stat = "<img src=\"{$images}/silk/accept.png\" border=\"0\" />";
                $statmsg = "OK";
            }

            $text .= <<<EOL
            <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                <td class="list-row" align="right">
                    NAT:
                </td>
                <td class="list-row" align="left" style="{$style['borderR']};">
                    {$nat['inside']}=>{$nat['outside']}
                </td>
                <td class="list-row" align="left">{$dbnatinfo} ({$nathost['fqdn']})</td>
                <td class="list-row" align="left">{$stat}</td>
                <td class="list-row" align="left">{$statmsg}</td>
            </tr>
EOL;

        }
    }

//     print_r($pats);
//     ////////////////// Process the config pat entries with the database ////////////////////////////
//     foreach ($form['pats'] as $pat) {
//         list($status, $rows, $patint) = ona_find_interface($pat['inside']);
//         if ($rows == 0) {
//             $text .= sprintf("%-12s %-35s %-27s %-15s %s\n",' PAT:',$pat['inside'].'=>'.$pat['outside'], '', '', '[FAIL]');
//         } else {
//             list($status, $rows, $pathost) = ona_find_host($patint['host_id']);
//             list($status, $rows, $patinterface) = ona_find_interface($patint['pat_interface_id']);
//             if (!$patinterface['id']) {
//                 $dbpatinfo = ip_mangle($patint['ip_addr'],'dotted').'=>NOT NATTED';
//                 $stat = '[PARTIAL]';
//             } else {
//                 $dbpatinfo = ip_mangle($patint['ip_addr'],'dotted').'=>'.ip_mangle($patinterface['ip_addr'],'dotted');
//                 $stat = '[PASS]';
//             }
//             $text .= sprintf("%-12s %-35s %-27s %-15s %s\n",' PAT:',$pat['inside'].'=>'.$pat['outside'],$dbpatinfo,"(".$pathost['fqdn'].")", $stat);
//         }
//     }

    $text .= "</table><br><center>END OF REPORT</center></div>";

    return(array(0,$text));
}














function rpt_output_text($form) {
    global $onadb;

    // Provide a usage message here
    $usagemsg = <<<EOL
Report: cisco_cfg_audit
  Processes the latest archived IOS_CONFIG for a cisco device and compare it to the database.

  Required:
    config=FQDN|FILE    The FQDN of the device to compare. If you give it a
                        local file path, that file will be uploaded.

  Optional:
    dcm_output          Output DCM formated commands to fix problems, use with text output

  Output Formats:
    html
    text

EOL;

    // Provide a usage message
    if ($form['rpt_usage'] or !$form['hostname']) {
        return(array(0,$usagemsg));
    }
    if (!isset($form['dcm_output'])) {
        $text .= sprintf("%-8s %-35s %-10s [Generated: %s]\n",'','SOURCE CONFIG','DATABASE', date('M j G:i:s T Y'));
        $text .= "------------------------------------------------------------------------------------------------------------\n";
    } else {
        $text .= "# CAUTION: These dcm.pl commands are suggestions and may not necessarily reflect your true environment.\n";
        $text .= "#          It is recommended that you examine each line before executing it.\n";
    }

    ///////////////// test that this name exists in the database//////////////////////
    // Build the FQDN if it has a domain defined
    if ($form['domainname']) $form['hostname'] = $form['hostname'].'.'.$form['domainname'];
    list($status, $rows, $host) = ona_find_host($form['hostname']);
    if (!$rows and !isset($form['dcm_output'])) $text .= sprintf("%-8s %-35s %s\n\n",'FQDN:',$form['hostname'] ,$form['dbhostname']);
    // Test what happens when there is no domain defined on the device.. how do I find the host?
    /////////////////////////////////////////////////////////////////////////////

    ////////////////// Process the config file interfaces against the database ////////////////////////////
    foreach ($form['ints'] as $int) {
        // If the interface has an IP, lets check it.
        if ($int['ip_addr']) {
            list($status, $rows, $interface) = ona_get_interface_record(array('host_id' => $form['dbhostid'],'ip_addr' => $int['ip_addr']));
            if ($rows == 0) {
                if (isset($form['dcm_output'])) {
                    $text .= "dcm.pl -l admin -r interface_add    host={$host['fqdn']} ip=".ip_mangle($int['ip_addr'],'dotted')." name=\"{$int['name']}\" description=\"{$int['description']}\"\n";
                } else {
                  $text .= sprintf("%-8s %-35s %-66s %s\n",'  IP:',ip_mangle($int['ip_addr'],'dotted'),'', '[FAIL]');
                  $text .= sprintf("%-8s %-35s %-66s\n",'NAME:',$int['name'],'');
                  $text .= sprintf("%-8s %-35s %-66s\n\n",'DESC:',$int['description'],'');
                  $exit_status++;
                }
            } else {
                if (isset($form['dcm_output'])) {
                    if ($int['name'] != $interface['name']) $setname = "set_name=\"{$int['name']}\"";
                    if ($int['description'] != $interface['description']) $setdesc = "set_description=\"{$int['description']}\"";

                    if ($setname or $setdesc) $text .= "dcm.pl -l admin -r interface_modify interface=".ip_mangle($int['ip_addr'],'dotted')." {$setname} {$setdesc}\n";
                } else {
                   $text .= sprintf("%-8s %-35s %-66s %s\n",'  IP:',ip_mangle($int['ip_addr'],'dotted'),ip_mangle($interface['ip_addr'],'dotted'), '[PASS]');
                   $text .= sprintf("%-8s %-35s %-66s\n",'NAME:',$int['name'],$interface['name']);
                   $text .= sprintf("%-8s %-35s %-66s\n\n",'DESC:',$int['description'],$interface['description']);
                   // Set up an array of all the IPs that did match the database,
                   $dbints[] = $int['ip_addr'];
                }
            }
        }
    }
    ////////////////////////////////////////////////////////////////////////////

    ////////////////// Process the database interfaces that didnt pass above ////////////////////////////
    if (!isset($form['dcm_output'])) {
        list($status, $int_rows, $interfaces) = db_get_records($onadb, 'interfaces', array('host_id' => $form['dbhostid']));
        foreach ($interfaces as $record) {
            if(in_array($record['ip_addr'],$dbints) === FALSE) {
               $text .= sprintf("%-8s %-35s %-66s %s\n",'  IP:','',ip_mangle($record['ip_addr'],'dotted'), '[FAIL]');
               $text .= sprintf("%-8s %-35s %-66s\n",'NAME:','',$record['name']);
               $text .= sprintf("%-8s %-35s %-66s\n\n",'DESC:','',$record['description']);
               $exit_status++;
            }
        }
    }
    ////////////////////////////////////////////////////////////////////////////

    ////////////////// Process the config nat entries with the database ////////////////////////////
    foreach ((array)$form['nats'] as $nat) {
        list($status, $rows, $natint) = ona_find_interface($nat['inside']);
        if ($rows == 0) {
            if (!isset($form['dcm_output'])) {
                // See if the outside interface is in the DB
                list($status, $rows, $natintout) = ona_find_interface($nat['outside']);
                if ($rows == 0) {
                    $text .= sprintf("%-8s %-35s %-35s %-30s %s\n",' NAT:',$nat['inside'].'=>'.$nat['outside'], '', '', '[FAIL]');
                    $exit_status++;
                } else {
                    $text .= sprintf("%-8s %-35s %-35s %-30s %s\n",' NAT:',$nat['inside'].'=>'.$nat['outside'],'???<=>'.ip_mangle($natintout['ip_addr'],'dotted'), '', '[PARTIAL]');
                    $exit_status++;
                }
            }
        } else {
            list($status, $rows, $nathost) = ona_find_host($natint['host_id']);
            list($status, $rows, $natinterface) = ona_find_interface($natint['nat_interface_id']);
            if (!$natinterface['id']) {
                $dbnatinfo = ip_mangle($natint['ip_addr'],'dotted').'=>NOT NATTED';
                $stat = '[PARTIAL]';
                //$exit_status++;
                if (isset($form['dcm_output'])) {
                    $text .= "dcm.pl -l admin -r nat_add          ip=".ip_mangle($natint['ip_addr'],'dotted')." natip={$nat['outside']} \n";
                }
            } else {
                $dbnatinfo = ip_mangle($natint['ip_addr'],'dotted').'=>'.ip_mangle($natinterface['ip_addr'],'dotted');
                $stat = '[PASS]';
            }

            if (!isset($form['dcm_output'])) {
                $text .= sprintf("%-8s %-35s %-35s %-30s %s\n",' NAT:',$nat['inside'].'=>'.$nat['outside'],$dbnatinfo,"(".$nathost['fqdn'].")", $stat);
            }
        }
    }

//     print_r($pats);
//     ////////////////// Process the config pat entries with the database ////////////////////////////
//     foreach ($form['pats'] as $pat) {
//         list($status, $rows, $patint) = ona_find_interface($pat['inside']);
//         if ($rows == 0) {
//             $text .= sprintf("%-12s %-35s %-27s %-15s %s\n",' PAT:',$pat['inside'].'=>'.$pat['outside'], '', '', '[FAIL]');
//         } else {
//             list($status, $rows, $pathost) = ona_find_host($patint['host_id']);
//             list($status, $rows, $patinterface) = ona_find_interface($patint['pat_interface_id']);
//             if (!$patinterface['id']) {
//                 $dbpatinfo = ip_mangle($patint['ip_addr'],'dotted').'=>NOT NATTED';
//                 $stat = '[PARTIAL]';
//             } else {
//                 $dbpatinfo = ip_mangle($patint['ip_addr'],'dotted').'=>'.ip_mangle($patinterface['ip_addr'],'dotted');
//                 $stat = '[PASS]';
//             }
//             $text .= sprintf("%-12s %-35s %-27s %-15s %s\n",' PAT:',$pat['inside'].'=>'.$pat['outside'],$dbpatinfo,"(".$pathost['fqdn'].")", $stat);
//         }
//     }

    $text .= "# DONE";
    //$rptoutput = "<pre style=\"border: 1px solid {$color['border']}; margin: 10px 20px; padding: 10px 10px;\">{$form}</pre>";

    return(array(0,$text));
}















?>
