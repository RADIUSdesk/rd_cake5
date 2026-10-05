<?php

$config        = [];
$config['Cnr'] = [
    'special_uam_url'   => 'https://cloud.radiusdesk.com/cake4/rd_cake/dynamic-details/connect-and-redirect',
    'session_time'      => 600, //Time in seconds the special user will connect
    'bw_up'             => 2, //Bandwidth in mbps
    'bw_down'           => 2, //Bandwidth in mbps
    'start_with'        => 'CNR',
    'password'          => 'conn_and_redir' //Headsup MAX 15 Characters for password
];


return $config;

?>
