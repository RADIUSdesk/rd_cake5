<?php

$config        = [];
$config['Cnr'] = [
    'special_uam_url'   => 'http://192.168.8.1/connect_and_redirect.php',
    'session_time'      => 600, //Time in seconds the special user will connect
    'bw_up'             => 2, //Bandwidth in mbps
    'bw_down'           => 2, //Bandwidth in mbps
    'start_with'        => 'CNR'
];


return $config;

?>
