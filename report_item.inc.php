<?php
global $base;

// Provide a short (less than 50 characters) description
$report_description="Compare IOS configuration to data stored in ONA.";

// Display the ios router config audit report if this device has an ios config archive.

if ($extravars['window_name'] == 'display_host') {
    list($status, $rows, $rpttype) = ona_get_config_type_record(array('name' => 'IOS_CONFIG'));
    list($status, $rows, $rptconf) = ona_get_config_record(array('host_id' => $record['id'], 'configuration_type_id' => $rpttype['id']));
    if ($rows) {
    $row_html .= <<<EOL
            <tr title="{$report_description}">
                <td class="padding" align="right" nowrap="true">IOS Config Audit:
                <a onClick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_report\', \'report=>cisco_cfg_audit,config=>{$record['fqdn']}\', \'display\')');"
                >View Report</a>
            </tr>
EOL;
    }
}


?>