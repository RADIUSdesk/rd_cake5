<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

class UsageCommand extends Command
{
    protected ?object $Radchecks = null;

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax
        $this->Radchecks = $this->fetchTable('Radchecks');
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // This method satisfies the Command interface requirement if run directly
        $io->out('Usage utility command. Meant to be called inside other commands.');
        return static::CODE_SUCCESS;
    }
       
    // --- Kept public helper methods below for VoucherCommand to call ---
    
    public function find_no_reset_time_usage(string $username) {
        //We only find the totals reset = never
        $usage = false;
        $this->_show_header('no reset time',$username); 
        $conn   = ConnectionManager::get('default');
        $stmt   = $conn->execute("SELECT IFNULL(SUM(GREATEST(UNIX_TIMESTAMP(timestamp) - UNIX_TIMESTAMP(created), 0)), 0) AS used FROM user_stats WHERE username='$username'");
        $row    = $stmt->fetch('assoc');
        $accounting_used = $row['used'];
        return $accounting_used;
    }
    
    public function find_no_reset_data_usage(string $username) {
    //We only find the totals reset = never  
        $usage = false;
        $conn   = ConnectionManager::get('default');
        $stmt   = $conn->execute("SELECT IFNULL(SUM(acctinputoctets)+SUM(acctoutputoctets),0) as used FROM user_stats WHERE username='$username'");
        $row    = $stmt->fetch('assoc');
        $accounting_used = $row['used'];
        return $accounting_used;
    }
    
    
    public function time_left_from_login(string $username): array {
        $time_left  = false;
		$time_avail = false;
         //See if it has an exprire from first login value
        $q_r        = $this->{'Radchecks'}->find()->where(['Radchecks.username' => $username,'Radchecks.attribute' => 'Rd-Voucher'])->first();
        if($q_r){
            $expire     = $q_r->value;
            $pieces     = explode("-", $expire);
            $time_avail = ($pieces[0] * 86400)+($pieces[1] * 3600)+($pieces[2] * 60)+($pieces[3]);
            $conn   = ConnectionManager::get('default');           
            $stmt   = $conn->execute("SELECT (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(created)) AS time_since_login FROM user_stats WHERE username = '$username' ORDER BY created ASC LIMIT 1");
            $row    = $stmt->fetch('assoc');
     
            if($row){
                $time_since_logon = $row['time_since_login'];
            }else{
                $time_since_logon = 0;
            }

            $time_left  = $time_avail - $time_since_logon;

            if($time_left <= 0){
                $time_left = 'depleted';  
            }
        }
        return array($time_left,$time_avail);
    }
    
    public function perc_used_from_login($username){
        $perc_used_from_login =false;
         //See if it has an exprire from first login value
        $q_r = $this->{'Radchecks'}->find()->where(['Radchecks.username' => $username,'Radchecks.attribute' => 'Rd-Voucher'])->first();
        if($q_r){
            $expire     = $q_r->value;
            $pieces     = explode("-", $expire);
            $time_avail = ($pieces[0] * 86400)+($pieces[1] * 3600)+($pieces[2] * 60)+($pieces[3]);
            $conn       = ConnectionManager::get('default');
            $stmt       = $conn->execute("SELECT (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(created)) AS time_since_login FROM user_stats WHERE username = '$username' ORDER BY created ASC LIMIT 1");
            $row        = $stmt->fetch('assoc');
            
            $perc_used_from_login  = intval(($row['time_since_login'] / $time_avail)* 100);

            if($perc_used_from_login >= 100){
                $perc_used_from_login = 'depleted';  
            }
        }
        return $perc_used_from_login;
    }
    
    public function time_left_from_expire(string $username){
        $time_left =false;
        //See if there is an expiry date check attribute for this voucher
        $q_r = $this->{'Radchecks'}->find()->where(['Radchecks.username' => $username,'Radchecks.attribute' => 'Expiration'])->first();
        if($q_r){
            $exp            = $q_r->value;
            //The format should be 6 Mar 2013 - Get the unix timestamp of that
            $exp_in_unix    = $this->_find_unix_timestamp_for_exp($exp);
            $time_left      = $exp_in_unix - time();
            if($time_left <= 0){
                $time_left = 'expired';
            }
        }
        return $time_left;
    }

    public function time_usage($counter_data, $username, $field)
    {
        $conn = ConnectionManager::get('default');
        $query_string = false;

        if ($counter_data['reset'] == 'never') {
            $query_string = "SELECT IFNULL(SUM(GREATEST(UNIX_TIMESTAMP(timestamp) - UNIX_TIMESTAMP(created), 0)), 0) AS used FROM user_stats WHERE $field=:username";
        } else {
            $start_time = $this->_find_start_time($counter_data);
            if ($start_time) {
                $query_string = "SELECT IFNULL(SUM(GREATEST(UNIX_TIMESTAMP(timestamp) - GREATEST(UNIX_TIMESTAMP(created), :start_time), 0)), 0) AS used " .
                                "FROM user_stats " .
                                "WHERE $field=:username AND UNIX_TIMESTAMP(timestamp) > :start_time";
            }
        }

        if ($query_string) {
            // Using modern parameterized queries to prevent SQL Injection
            $stmt = $conn->execute($query_string, ['username' => $username, 'start_time' => $start_time ?? null]);
            $row = $stmt->fetch('assoc');
            return $row['used'] ?? 0;
        }
        return false;
    }

    public function time_usage_for_mac($counter_data, $username, $mac)
    {
        $conn = ConnectionManager::get('default');
        $query_string = false;

        if ($counter_data['reset'] == 'never') {
            $query_string = "SELECT IFNULL(SUM(GREATEST(UNIX_TIMESTAMP(timestamp) - UNIX_TIMESTAMP(created), 0)), 0) AS used FROM user_stats WHERE username=:username AND callingstationid=:mac"; 
        } else {
            $start_time = $this->_find_start_time($counter_data);
            if ($start_time) {
                $query_string = "SELECT IFNULL(SUM(GREATEST(UNIX_TIMESTAMP(timestamp) - GREATEST(UNIX_TIMESTAMP(created), :start_time), 0)), 0) AS used " .
                                "FROM user_stats " .
                                "WHERE username=:username AND callingstationid=:mac AND UNIX_TIMESTAMP(timestamp) > :start_time";
            }
        }

        if ($query_string) {
            $stmt = $conn->execute($query_string, ['username' => $username, 'mac' => $mac, 'start_time' => $start_time ?? null]);
            $row = $stmt->fetch('assoc');
            return $row['used'] ?? 0;
        }
        return false;
    }


    public function data_usage(array $counter_data, string $username, string $field): int|float{

        // 1.) A Whitelist of field names to prevent SQL injection
        $allowed_fields = ['username', 'callingstationid','realm','nasipaddress','nasidentifier','callingstationid']; 
        if (!in_array($field, $allowed_fields)) {
            throw new \InvalidArgumentException('Non valid field specified');
        }

        $conn = ConnectionManager::get('default');
        
        // 2. Start with CakePHP's Query Buider
        $query = $conn->selectQuery()
            ->select([
                'used' => 'IFNULL(SUM(acctinputoctets) + SUM(acctoutputoctets), 0)'
            ])
            ->from('user_stats')
            ->where([$field => $username]); // Base filter

        // 3. Add time condition only if needed
        if ($counter_data['reset'] !== 'never') {
            $start_time = $this->_find_start_time($counter_data);
            if ($start_time) {
                // Change Unix timestamp to MySQL DATETIME
                $formatted_time = date('Y-m-d H:i:s', $start_time);
                $query->andWhere(['created >' => $formatted_time]);
            } else {
                return 0; // If there are not start time return early
            }
        }

        // 4. Do the query and get the result
        $row = $query->execute()->fetch('assoc');
        
        return $row ? (int)$row['used'] : 0;
    }
 

    public function data_usage_for_mac($counter_data, $username, $mac)
    {
        $conn = ConnectionManager::get('default');
        $query_string = false;

        if ($counter_data['reset'] == 'never') {
            $query_string = "SELECT IFNULL(SUM(acctinputoctets)+SUM(acctoutputoctets),0) as used FROM user_stats WHERE username=:username AND callingstationid=:mac"; 
        } else {
            $start_time = $this->_find_start_time($counter_data);
            if ($start_time) {
                $query_string = "SELECT IFNULL(SUM(acctinputoctets) + SUM(acctoutputoctets), 0) AS used " .
                                "FROM user_stats " .
                                "WHERE username=:username AND callingstationid=:mac " .
                                "AND created > FROM_UNIXTIME(:start_time)";
            }
        }

        if ($query_string) {
            $stmt = $conn->execute($query_string, ['username' => $username, 'mac' => $mac, 'start_time' => $start_time ?? null]);
            $row = $stmt->fetch('assoc');
            return $row['used'] ?? 0;
        }
        return false;
    }

    private function _find_start_time($counter_data){
        $start_time = false;
        if($counter_data['reset'] == 'daily'){
            print("Start at midnight");
            $start_time = mktime(0, 0, 0, date('m'), date('d'), date('Y')); 
        }
        if($counter_data['reset'] == 'weekly'){
            print("Start at monday");
            $start_time = mktime(0, 0, 0, date('n'), date('j'), date('Y')) - ((date('N')-1)*3600*24); 
        }
        if($counter_data['reset'] == 'monthly'){
            print("Start at 1st of month");
            $start_time = mktime(0, 0, 0, date('m'), 1, date('Y'));
        }

		//This is an enhancement and expects a value for reset_interval to determine the start time :-)
		if($counter_data['reset'] == 'dynamic'){
			$interval = 0;
			if($counter_data['reset_interval']){
				$interval = $counter_data['reset_interval'];
			}
            print("Start dynamic interval: $interval seconds back from now\n");
            $start_time = time() - $interval;
        }
        return $start_time;
    }
    
    private function _find_unix_timestamp_for_exp($exp){

        $pieces     = explode(" ",$exp);
        $day        = $pieces[0];
        $m_string   = $pieces[1];
        $year       = $pieces[2];

        $m_arr      = array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
        $month      = 1;

        foreach($m_arr as $m){
            if($m_string == $m){
                break;
            }
            $month++;
        }
        $exp_in_unix = mktime(0,0, 0, $month,$day, $year);
        return $exp_in_unix;
    } 
}

