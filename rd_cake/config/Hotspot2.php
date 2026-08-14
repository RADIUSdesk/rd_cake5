<?php

/*

0 0 Unspecified
1 0 Unspecified Assembly
1 1 Arena
1 2 Stadium
1 3 Passenger Terminal (e.g., airport, bus, ferry, train station)
1 4 Amphitheater
1 5 Amusement Park
1 6 Place of Worship
1 7 Convention Center
1 8 Library
1 9 Museum
1 10 Restaurant
1 11 Theater
1 12 Bar
1 13 Coffee Shop
1 14 Zoo or Aquarium
1 15 Emergency Coordination Center

    option iw_enabled '1'
    option iw_interworking '1'
    option iw_access_network_type '3'
    option iw_internet '1'
    option iw_disable_dgaf '1'
    option iw_asra '0'
    option iw_esr '0'
    option iw_uesa '0'
    option iw_venue_group '2'
    option iw_venue_type '8'
    option iw_hessid '00:00:00:01:02:03'
    list iw_roaming_consortium 'xxyyzz0000'
    list iw_nai_realm '0,example.com,13[5:6],21[2:4][5:7]'
    list iw_nai_realm '0,example.org,13[5:6],21[2:4][5:7]'
    list iw_venue_name 'eng:somePublicSpace'
    list iw_venue_url '1:http://www.example.com/info-eng'
    option iw_network_auth_type '00'
    option iw_ipaddr_type_availability '0c'
    list iw_domain_name 'example.com'
    option hs20 '1'
    option hs20_oper_friendly_name 'eng:YourFriendPasspoint'
    option hs20_operating_class '517C'

*/

/*
    option iw_enabled '1'
    option iw_interworking '1'
    option iw_access_network_type '3'
    option iw_internet '1'
    option iw_disable_dgaf '1'
    option iw_asra '0'
    option iw_esr '0'
    option iw_uesa '0'
    option iw_venue_group '2'
    option iw_venue_type '8'
    option iw_hessid '00:00:00:01:02:03'
    list iw_roaming_consortium 'AA146B0000'
    list iw_roaming_consortium 'BAA2D00000'
    list iw_roaming_consortium '5A03BA0000'
    list iw_nai_realm '0,ironwifi,13[5:6],21[2:4][5:7]'
    list iw_venue_name 'eng:somePublicSpace'
    list iw_venue_url '1:http://www.example.com/info-eng'
    option iw_network_auth_type '00'
    option iw_ipaddr_type_availability '0c'
    list iw_domain_name 'ironwifi.net'
    list iw_domain_name 'openroaming.org'
    list iw_domain_name 'apple.openroaming.net'
    list iw_domain_name 'google.openroaming.net'
    list iw_domain_name 'ciscooneid.openroaming.net'
    option hs20 '1'
    option hs20_oper_friendly_name 'eng:IronWiFiPasspoint'
    option hs20_operating_class '517C'
*/


$config = [];

$config['Hotspot2']['utils'] = [];



$config['Hotspot2']['utils']['eap_methods'] = [
    ['name' => 'EAP-TLS',           'id' => 'eap_tls',          'hostapd_string' => '13[5:6]',      'active' => true ],
    ['name' => 'EAP-TTLS/PAP',      'id' => 'eap_ttls_pap',     'hostapd_string' => '21[2:1][5:7]', 'active' => true ],
    ['name' => 'EAP-TTLS/MSCHAP2',  'id' => 'eap_ttls_mschap2', 'hostapd_string' => '21[2:4][5:7]', 'active' => true ],
    ['name' => 'PEAP',              'id' => 'peap',             'hostapd_string' => '25[2:4][5:7]', 'active' => true ],
    ['name' => 'EAP-SIM',           'id' => 'eap_sim',          'hostapd_string' => '18[5:1]',      'active' => true ],
    ['name' => 'EAP-USIM',          'id' => 'eap_usim',         'hostapd_string' => '18[5:2]',      'active' => true ],
];

$config['Hotspot2']['utils']['access_network_type'] = [
    ['id' => 0, 'name' => 'Private network',                    'active' => true ],
    ['id' => 1, 'name' => 'Private network with guest access',  'active' => true ],
    ['id' => 2, 'name' => 'Chargeable public network',          'active' => true ],
    ['id' => 3, 'name' => 'Free public network',                'active' => true ],
    ['id' => 4, 'name' => 'Personal device network',            'active' => true ],
    ['id' => 5, 'name' => 'Emergency services only network',    'active' => true ],
    ['id' => 14,'name' => 'Test or experimental',               'active' => true ],
    ['id' => 15,'name' => 'Wildcard',                           'active' => true ],
];


$config['Hotspot2']['options']	= [
	'iw_enabled'	=> 1,
    'iw_interworking' => 1,
  	'iw_access_network_type' => 3,
    'iw_internet' => 1,
    'iw_disable_dgaf' => 1,
    'iw_asra' => 0,
    'iw_esr' => 0,
    'iw_uesa' => 0,
    'iw_venue_group' => 2,
    'iw_venue_type' => 8,
    'iw_hessid' => '00:00:00:01:02:03',
    'iw_network_auth_type' => '00',
    'iw_ipaddr_type_availability' => '0c',
    'hs20' => 1,
    'hs20_oper_friendly_name' => 'eng:YourFriendPasspoint',
    'hs20_operating_class' => '517C',
];
      	
$config['Hotspot2']['lists']	= [
    [ 'name' =>  'iw_roaming_consortium', 'value' => 'AA146B0000'],
    [ 'name' =>  'iw_roaming_consortium', 'value' => 'BAA2D00000'],
    [ 'name' =>  'iw_roaming_consortium', 'value' => '5A03BA0000'],
    [ 'name' =>  'iw_nai_realm',        'value' => '0,ironwifi,13[5:6],21[2:4][5:7]'],
    [ 'name' =>  'iw_nai_realm',        'value' => '0,example.org,13[5:6],21[2:4][5:7]'],
    [ 'name' =>  'iw_venue_name',       'value' => 'eng:somePublicSpace'],
    [ 'name' =>  'iw_venue_url',        'value' => '1:http://www.example.com/info-eng'],
    [ 'name' =>  'iw_domain_name',      'value' => 'ironwifi.net'],
    [ 'name' =>  'iw_domain_name',      'value' => 'openroaming.org'],
    [ 'name' =>  'iw_domain_name',      'value' => 'apple.openroaming.net'],
    [ 'name' =>  'iw_domain_name',      'value' => 'google.openroaming.net'],
    [ 'name' =>  'iw_domain_name',      'value' => 'ciscooneid.openroaming.net'],  
];

return $config;

?>
