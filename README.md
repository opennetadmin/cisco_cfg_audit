cisco_cfg_audit
===============

OpenNetAdmin report plugin to compare the last config archive to data stored in the ONA database. It uses data normally collected by the `cfg_archive` plugin.

This report will compare the IP, Interface Name, and Description of the IOS devices interfaces to what is tracked for that device in ONA.  It will also give a listing of NAT address definitions compared to what is stored in ONA.

When you see the Orange links on data that is mis matched, you can click the link to update the database with the value that is defined on the IOS device.  The device is assumed authoritative for most information.

On the far right of the screen will be a status icon as well as a suggested action to fix the mis match.  Since there are multiple scenarios possible, you must choose the proper course of action.

At the top of the screen is a hostname field so you can quickly run a report on another host, just provide the FQDN. There is a magnifying glass icon next to the host name which will allow you to view the configuration stored in the archive that was used to generate the report with.

The reports generated will use the most recent archive copy of the `IOS_CONFIG` type for the selected host. 

CLI Report
----------

You can also execute the report from the CLI using the `report_run` DCM module. Here is the syntax:

    dcm.pl -r report_run name=cisco_cfg_audit config=<FQDN|FILE>

This allows you to pass in a local config file to use as an input instead of using an archive stored in the database by passing in a local filename path.

An example output would be:


             SOURCE CONFIG                       DATABASE   [Generated: Jul 3 15:55:33 PDT 2012]
    ------------------------------------------------------------------------------------------------------------
      IP:    172.25.1.2                          172.25.1.2                                                         [PASS]
    NAME:    Port-channel1                       Port-channel1                                                     
    DESC:    INSIDE-HSRP                         INSIDE-HSRP                                                       
    
      IP:    2.2.2.1                             2.2.2.1                                                            [PASS]
    NAME:    GigabitEthernet0/2                  GigabitEthernet0/2                                                
    DESC:    WAN Int to ISP                      WAN Int to ISP                                 
    
      IP:                                        172.25.1.4                                                         [FAIL]
    NAME:                                                                                                          
    DESC:                                                                                                          
    
    # DONE


Note that the last interface failed.  This indicates we had an IP in ONA that does not exist on the router itself.
