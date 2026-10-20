<?php

/**
 * Copy to config.php and adjust. config.php is git-ignored.
 */
return [
    // Require clients to send this in the X-API-Key header (or ?api_key=). Empty = open API.
    'api_key' => '',

    // Value for Access-Control-Allow-Origin.
    'cors_origin' => '*',

    'http' => [
        'timeout'    => 20,
        'verify_ssl' => true,
        // XAMPP often ships without a CA bundle; point this at cacert.pem if you get SSL errors.
        // e.g. 'C:/xampp/php/extras/ssl/cacert.pem'
        'ca_bundle'  => null,
    ],
];
