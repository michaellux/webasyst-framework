<?php

return array(
    'name'         => 'Webasyst',
    'prefix'       => 'webasyst',
    'version'      => '2.7.1',
    'critical'     => '2.7.1',
    'vendor'       => 'webasyst',
    'csrf'         => true,
    'header_items' => array(
        'settings' => array(
            'icon'   => 'img/wa-settings/settings.svg',
            'name'   => 'Settings',  // _w('Settings')
            'link'   => 'settings',
            'rights' => 'backend'
        ),
    ),
    'ui'           => '1.3,2.0'
);
