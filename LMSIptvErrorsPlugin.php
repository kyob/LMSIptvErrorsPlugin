<?php

/**
 * LMSIptvErrorsPlugin
 * Monitoring błędów IPTV (dekodery, podsieci, grupy multicast) z API portal.sgt.net.pl
 * (device-errors-api.sgt.net.pl, format OpenMetrics).
 *
 * @author Łukasz Kopiszka <lukasz@alfa-system.pl>
 */
class LMSIptvErrorsPlugin extends LMSPlugin
{
    const PLUGIN_NAME = 'LMS IPTV Errors plugin';
    const PLUGIN_DESCRIPTION = 'Błędy IPTV z monitoringu SGT: dekodery, podsieci, grupy multicast, trendy i diagnostyka.';
    const PLUGIN_AUTHOR = 'Łukasz Kopiszka &lt;lukasz@alfa-system.pl&gt;';
    const PLUGIN_DIRECTORY_NAME = 'LMSIptvErrorsPlugin';
    const PLUGIN_VERSION = '1.0.0';

    public function registerHandlers()
    {
        $this->handlers = array(
            'menu_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'menuIptvErrors',
            ),
            'smarty_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'smartyIptvErrors',
            ),
            'modules_dir_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'modulesDirIptvErrors',
            ),
            'access_table_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'accessTableInit',
            ),
        );
    }
}
