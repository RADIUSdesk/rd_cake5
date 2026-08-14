<?php

#Improved reporting for meshes (also modify the Nginx config file accordingly)
#Every five minutes, process any reports from the temp_reports table (also check the syslog file there will be a report from this script saying how long it took ... should be under 5 min!!)
#*/5 * * * * www-data php /var/www/html/cake4/rd_cake/setup/scripts/reporting/process_report.php >> /dev/null 2>&1


//Some global variables
$servername = "localhost";
$username   = "rd";
$password   = "rd";
$conn       = false;
$conn2      = false;

$rebootFlag = false;
$logFlag    = false;

//Some defaults
$MeshMacLookup    = [];
$counter    = 0;
$ap_counter = 0;

$start_time = microtime(true);

//Statements
//Uptime Histories

//MAC Cache
$__mac_cache = [];

doConnection();

//Node Uptimes
$stmt_uth_C    = $conn->prepare("INSERT into node_uptm_histories (node_id,node_state,state_datetime,report_datetime,created,modified) VALUES(:node_id,'1',NOW(),NOW(),NOW(),NOW())");
$stmt_uth_R    = $conn->prepare("SELECT * FROM node_uptm_histories WHERE node_id = :node_id ORDER BY report_datetime DESC");
$stmt_uth_U    = $conn->prepare("UPDATE node_uptm_histories SET modified = NOW(),report_datetime = NOW() WHERE id = :id");
//Node Alerts (only read and update needed)
$stmt_alert_R  = $conn->prepare("SELECT * FROM alerts WHERE node_id = :node_id AND resolved IS NULL ORDER BY modified DESC");
$stmt_alert_U  = $conn->prepare("UPDATE alerts SET resolved = NOW() WHERE id = :id");


//AP Uptimes
$stmt_ap_uth_C    = $conn->prepare("INSERT into ap_uptm_histories (ap_id,ap_state,state_datetime,report_datetime,created,modified) VALUES(:ap_id,'1',NOW(),NOW(),NOW(),NOW())");

$stmt_ap_uth_R = $conn->prepare("
    SELECT id, ap_state 
    FROM ap_uptm_histories 
    WHERE ap_id = :ap_id 
    ORDER BY report_datetime DESC
    LIMIT 1
");

$stmt_ap_uth_U = $conn->prepare("
    UPDATE ap_uptm_histories 
    SET modified = NOW(), report_datetime = NOW() 
    WHERE id = :id
");


//AP Alerts (only read and update needed)
$stmt_ap_alert_R  = $conn->prepare("SELECT * FROM alerts WHERE ap_id = :ap_id AND resolved IS NULL ORDER BY modified DESC");
$stmt_ap_alert_U  = $conn->prepare("UPDATE alerts SET resolved = NOW() WHERE id = :id"); //Acutuallly the same for AP and Nodes but leave like this for now to keep pattern

main();

// End clock time in seconds 
$end_time = microtime(true);   
// Calculate script execution time 
$execution_time = ($end_time - $start_time);

$log_message = " $counter Node and $ap_counter AP reports processed in: ".$execution_time." sec";
echo $log_message."\n";
openlog('radiusdesk', LOG_CONS | LOG_NDELAY | LOG_PID, LOG_USER | LOG_PERROR);
syslog(LOG_INFO, $log_message);
closelog();  


function main(){
    global $conn,$rebootFlag,$repSettings;
    _doReports();  
}

//==== FOR Postgresql =====
/*
function doConnection(){
    global $servername,$username,$password,$conn;
    try {
        $conn = new PDO("pgsql:host=$servername;dbname=rd", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch(PDOException $e){
        echo "Connection failed: " . $e->getMessage();
    }
}
*/


//==== For Mysql / MariaDB =====
function doConnection(){

    global $servername,$username,$password,$conn;
    try {
        $conn = new PDO("mysql:host=$servername;dbname=rd", $username, $password,[PDO::ATTR_PERSISTENT => true]);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);   
        $conn->setAttribute( PDO::ATTR_EMULATE_PREPARES, false );
    }
    catch(PDOException $e){
        echo "Connection failed: " . $e->getMessage();
    } 
}


function _doReports(){       
    //--nlbwmon stats--
    
    $report = [];
    
    $json_string = '{"wg_stats":{"wg02":{"public_key":"8bpoIozwWXYCzpLy85ZJpAdtuDf3vhFLfqA7jEJopXg=","name":"wg02","fwmark":"off","listen_port":"43097","peers":[{"endpoint":"164.160.89.129:51821","public_key":"wftdvdAJLKLlwmbunHqRxGMrKNOSsY0BN6B6AK4kyXU=","name":"id_2_name_Slow Banjo","latest_handshake":"1763015943","persistent_keepalive":"25","allowed_ips":["0.0.0.0/0","::/0"],"transfer_tx":"3900","transfer_rx":"928"}]},"wg01":{"public_key":"yPvGALbAN8NyXu4ziqjx9LUcNjIzm2CmGk4Qi2cfHxU=","name":"wg01","fwmark":"off","listen_port":"51106","peers":[{"endpoint":"164.160.89.129:51820","public_key":"hxwdhRA4JqtmF1Jz1tc8C6cFUh8aUzRHBkJ1tuQQEmU=","name":"id_1_name_Fast Banjo","latest_handshake":"1763015962","persistent_keepalive":"25","allowed_ips":["0.0.0.0/0","::/0"],"transfer_tx":"15848","transfer_rx":"19199"}]}}}';
    
    $json_string = '{"ovpn_stats": [
        {
          "rx_bytes": 1744,
          "tx_bytes": 8446,
          "id": 3,
          "timestamp": 1763799274,
          "ip": "10.8.0.2"
        }
      ]}';

    $wg_stats = json_decode($json_string, true);       
    $report   = $wg_stats; 
 
    if (!empty($report['wg_stats'])) {
        _do_wg_stats($report['wg_stats']);
    }
    if (!empty($report['ovpn_stats'])) {
        _do_ovpn_stats($report['ovpn_stats']);
    }
    
    print("AP Reports Done!\n");
}


function _do_sqm_stats($sqm_stats, $type = 'ap'){

    global $conn;
    $not_these = ['id','type','device','sqm'];  
    if($type == 'ap'){
	    foreach($sqm_stats as $stat){ 
	        print_r($stat);	    
            foreach($not_these as $remove){
                    unset($stat[$remove]);
	        }
	        if(isset($stat['memory_used'])){ 

		        // Turn on exceptions so you see exactly what's missing
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $required = [
                    'ap_id','ap_profile_exit_id','bytes','packets','drops','overlimits',
                    'backlog','qlen','memory_used','peak_delay_us','avg_delay_us',
                    'base_delay_us','way_misses','way_indirect_hits'
                ];

                // $row is your input array from print_r(...)
                $params = [];
                foreach ($required as $k) {
                    // default to 0 if missing to avoid "missing parameter" error
                    $params[$k] = isset($row[$k]) ? (int)$row[$k] : 0;
                }

                $stmt = $conn->prepare("
                    INSERT INTO ap_sqm_stats (
                        ap_id, ap_profile_exit_id, bytes, packets, drops, overlimits,
                        backlog, qlen, memory_used, peak_delay_us, avg_delay_us,
                        base_delay_us, way_misses, way_indirect_hits, created, modified
                    ) VALUES (
                        :ap_id, :ap_profile_exit_id, :bytes, :packets, :drops, :overlimits,
                        :backlog, :qlen, :memory_used, :peak_delay_us, :avg_delay_us,
                        :base_delay_us, :way_misses, :way_indirect_hits, NOW(), NOW()
                    )
                ");

                $stmt->execute($params);
      	       print_r($stat);	    
//               $stmt   = $conn->prepare("INSERT into ap_sqm_stats (ap_id,ap_profile_exit_id,bytes,packets,drops,overlimits,backlog,qlen,memory_used,peak_delay_us,avg_delay_us,base_delay_us,way_misses,way_indirect_hits,created,modified)VALUES(:ap_id,:ap_profile_exit_id,:bytes,:packets,:drops,:overlimits,:backlog,:qlen,:memory_used,:peak_delay_us,:avg_delay_us,:base_delay_us,:way_misses,:way_indirect_hits,NOW(),NOW())");
//               $stmt->execute($stat);
	        }       
        }      
    }
}

function _do_ovpn_stats(array $data) {
    global $conn;

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Last session by starttime (most recent)
    $sql_last_session = $conn->prepare("
        SELECT *
        FROM ap_vpn_sessions
        WHERE ap_vpn_connection_id = :cid
        ORDER BY starttime DESC, id DESC
        LIMIT 1
    ");

    // Insert a brand new session (open: stoptime IS NULL)
    $sql_insert_session = $conn->prepare("
        INSERT INTO ap_vpn_sessions (
            ap_vpn_connection_id,
            starttime,
            stoptime,
            sessiontime,
            rx_bytes,
            tx_bytes
        ) VALUES (
            :cid,
            :starttime,
            NULL,
            0,
            :rx_bytes,
            :tx_bytes
        )
    ");

    // Update an existing active session (no stoptime change, only sessiontime + bytes)
    $sql_update_active = $conn->prepare("
        UPDATE ap_vpn_sessions
        SET
            sessiontime = TIMESTAMPDIFF(SECOND, starttime, :nowtime),
            rx_bytes    = :rx_bytes,
            tx_bytes    = :tx_bytes
        WHERE id = :id
    ");

    // Close a session (set stoptime & sessiontime)
    $sql_close_session = $conn->prepare("
        UPDATE ap_vpn_sessions
        SET
            stoptime    = :stoptime,
            sessiontime = TIMESTAMPDIFF(SECOND, starttime, :stoptime_calc)
        WHERE id = :id
    ");

      foreach($data as $ovpn_stat){

        $tx_bytes   = (int)$ovpn_stat['tx_bytes'];
        $rx_bytes   = (int)$ovpn_stat['rx_bytes'];
        $timestamp  = (int)$ovpn_stat['timestamp'];        
        $cid        = (int)$ovpn_stat['id'];    
        
        if ($timestamp > 0) {
            $sample_time = date('Y-m-d H:i:s', $timestamp);
        } else {
            //We skip entries where timestamp is zero (meaning the gateway could not be reached)
            echo "No VPN conteact to Gateway Skip This Insert\n";
            continue; 
        }

        // 2) Get last session for this connection
        $sql_last_session->execute([':cid' => $cid]);
        $last = $sql_last_session->fetch(PDO::FETCH_ASSOC);

        // No previous session at all → create first (open) session
        if (!$last) {
            echo "No previous session, creating first session for connection $cid\n";

            $sql_insert_session->execute([
                ':cid'       => $cid,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);
            continue;
        }

        $last_id       = (int)$last['id'];
        $last_tx_bytes = (int)$last['tx_bytes'];
        $last_stoptime = $last['stoptime']; // may be NULL

        // If last session is already closed (stoptime NOT NULL),
        // we ALWAYS start a new session with this sample.
        if ($last_stoptime !== null) {
            echo "Last session is closed, starting new session for connection $cid\n";

            $sql_insert_session->execute([
                ':cid'       => $cid,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);
            
            continue;
        }

        // At this point, last session is ACTIVE (stoptime IS NULL)

        // 3) Decide if same session or new one
        if ($tx_bytes >= $last_tx_bytes) {
            // Same session continues → update sessiontime + bytes
            echo "Updating active session ID $last_id for connection $cid\n";

            $sql_update_active->execute([
                ':nowtime'  => $sample_time,
                ':rx_bytes' => $rx_bytes,
                ':tx_bytes' => $tx_bytes,
                ':id'       => $last_id,
            ]);

        } else {
            // TX counter reset → close old and start new

            echo "TX reset detected, closing session ID $last_id and creating new session for connection $cid\n";

            // 3a) Close old session; its stoptime should be the new session's starttime
            $sql_close_session->execute([
                ':stoptime'         => $sample_time,
                ':stoptime_calc'    => $sample_time,   // same value, different param name
                ':id'               => $last_id,
            ]);

            // 3b) Insert new OPEN session (stoptime NULL)
            $sql_insert_session->execute([
                ':cid'       => $cid,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);
        }
    }
}


function _do_wg_stats(array $data) {
    global $conn;

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Prepare statements once
    $sql_find_conn = $conn->prepare("
        SELECT id
        FROM ap_vpn_connections
        WHERE wg_public_key = :wg_public_key
        LIMIT 1
    ");

    // Last session by starttime (most recent)
    $sql_last_session = $conn->prepare("
        SELECT *
        FROM ap_vpn_sessions
        WHERE ap_vpn_connection_id = :cid
        ORDER BY starttime DESC, id DESC
        LIMIT 1
    ");

    // Insert a brand new session (open: stoptime IS NULL)
    $sql_insert_session = $conn->prepare("
        INSERT INTO ap_vpn_sessions (
            ap_vpn_connection_id,
            starttime,
            stoptime,
            sessiontime,
            rx_bytes,
            tx_bytes
        ) VALUES (
            :cid,
            :starttime,
            NULL,
            0,
            :rx_bytes,
            :tx_bytes
        )
    ");

    // Update an existing active session (no stoptime change, only sessiontime + bytes)
    $sql_update_active = $conn->prepare("
        UPDATE ap_vpn_sessions
        SET
            sessiontime = TIMESTAMPDIFF(SECOND, starttime, :nowtime),
            rx_bytes    = :rx_bytes,
            tx_bytes    = :tx_bytes
        WHERE id = :id
    ");

    // Close a session (set stoptime & sessiontime)
    $sql_close_session = $conn->prepare("
        UPDATE ap_vpn_sessions
        SET
            stoptime    = :stoptime,
            sessiontime = TIMESTAMPDIFF(SECOND, starttime, :stoptime_calc)
        WHERE id = :id
    ");

      foreach($data as $wg_stat){

        $peer       = $wg_stat['peers'][0];
        
        // ---- Map your peer structure ----
        $public_key = $peer['public_key'];

        // "164.160.89.129:51820"
        $endpoint = $peer['endpoint'];
        [$ip, $port] = explode(':', $endpoint, 2);

        $tx_bytes = (int)$peer['transfer_tx'];
        $rx_bytes = (int)$peer['transfer_rx'];

        // Use latest_handshake as the sample time if > 0, else now
        $latest_handshake = (int)$peer['latest_handshake'];
        if ($latest_handshake > 0) {
            $sample_time = date('Y-m-d H:i:s', $latest_handshake);
        } else {
            $sample_time = date('Y-m-d H:i:s');
        }

        echo "===================\n";
        print_r($peer);
        echo "+++++++++++++++++\n";

        // 1) Find connection by wg_public_key
        $sql_find_conn->execute([':wg_public_key' => $public_key]);
        $conn_row = $sql_find_conn->fetch(PDO::FETCH_ASSOC);

        if (!$conn_row) {
            echo "No ap_vpn_connections row for public_key: $public_key\n";
            continue;
        }

        $connection_id = (int)$conn_row['id'];

        // 2) Get last session for this connection
        $sql_last_session->execute([':cid' => $connection_id]);
        $last = $sql_last_session->fetch(PDO::FETCH_ASSOC);

        // No previous session at all → create first (open) session
        if (!$last) {
            echo "No previous session, creating first session for connection $connection_id\n";

            $sql_insert_session->execute([
                ':cid'       => $connection_id,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);

            continue;
        }

        $last_id       = (int)$last['id'];
        $last_tx_bytes = (int)$last['tx_bytes'];
        $last_stoptime = $last['stoptime']; // may be NULL

        // If last session is already closed (stoptime NOT NULL),
        // we ALWAYS start a new session with this sample.
        if ($last_stoptime !== null) {
            echo "Last session is closed, starting new session for connection $connection_id\n";

            $sql_insert_session->execute([
                ':cid'       => $connection_id,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);

            continue;
        }

        // At this point, last session is ACTIVE (stoptime IS NULL)

        // 3) Decide if same session or new one
        if ($tx_bytes >= $last_tx_bytes) {
            // Same session continues → update sessiontime + bytes
            echo "Updating active session ID $last_id for connection $connection_id\n";

            $sql_update_active->execute([
                ':nowtime'  => $sample_time,
                ':rx_bytes' => $rx_bytes,
                ':tx_bytes' => $tx_bytes,
                ':id'       => $last_id,
            ]);

        } else {
            // TX counter reset → close old and start new

            echo "TX reset detected, closing session ID $last_id and creating new session for connection $connection_id\n";

            // 3a) Close old session; its stoptime should be the new session's starttime
            $sql_close_session->execute([
                ':stoptime'         => $sample_time,
                ':stoptime_calc'    => $sample_time,   // same value, different param name
                ':id'               => $last_id,
            ]);

            // 3b) Insert new OPEN session (stoptime NULL)
            $sql_insert_session->execute([
                ':cid'       => $connection_id,
                ':starttime' => $sample_time,
                ':rx_bytes'  => $rx_bytes,
                ':tx_bytes'  => $tx_bytes,
            ]);
        }
    }
}

function logger($message){
    global $logFlag;
    if($logFlag===true){
        print($message);
    }
}

?>
