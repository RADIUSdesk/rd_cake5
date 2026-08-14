<?php

//as www-data
//cd /var/www/html/cake4/rd_cake && bin/cake update_nas_data_usage

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

use Cake\I18n\DateTime;

class UpdateNasDataUsageCommand extends Command
{

    protected ?object $DynamicClients   = null;
    protected ?object $Radaccts         = null;
    protected ?object $Timezones        = null;    
    protected  $default_timezone        = 'UTC'; //Default for timezone
    protected  $timezone_lookup         = [];

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax             
        $this->DynamicClients   = $this->fetchTable('DynamicClients');
        $this->Radaccts         = $this->fetchTable('Radaccts');
        $this->Timezones        = $this->fetchTable('Timezones');                
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->out('==================================');
        $io->out('----- Update Nas Data Usage ------');
        $io->out('----- RADIUSdesk 2012-2026 -------');
        $io->out('__________________________________');

        $this->_prime_timezones($io);
        $this->_update_usage($io);
        $this->_update_daily_usage($io);	
			
        return static::CODE_SUCCESS;
    }
    
    private function _prime_timezones(ConsoleIo $io){ 
        $results = $this->{'Timezones'}->find()->all();   
        foreach($results as $ent){       
            $this->timezone_lookup[$ent->id] = $ent->name;        
        }
    } 
    
    private function _update_daily_usage(ConsoleIo $io){
        $ent_list_dc = $this->{'DynamicClients'}->find()->where(['DynamicClients.daily_data_limit_active' => true])->all();
        foreach($ent_list_dc as $ent){

            $r_hour     = $ent->daily_data_limit_reset_hour;
            $r_min      = $ent->daily_data_limit_reset_minute;         
            $tz_id      = $ent->timezone;
            $timezone   = $this->default_timezone;
            if($tz_id !== ''){
                if(isset($this->timezone_lookup[$tz_id])){
                    $timezone = $this->timezone_lookup[$tz_id];
                }
            }
            
            $start_of_day = $this->_start_of_day($r_hour,$r_min,$timezone);
            $nas_id = $ent->nasidentifier;
            $this->out("Name ".$ent->name." with nasid ".$nas_id." resets on ".$r_hour." on timezone $timezone");
             
            $query_string = "SELECT IFNULL(SUM(acctinputoctets)+ ".
                            "SUM(acctoutputoctets),0) as used ".
                            "FROM radacct WHERE nasidentifier='$nas_id' ".
                            "AND UNIX_TIMESTAMP(acctstarttime) + acctsessiontime > UNIX_TIMESTAMP(CONVERT_TZ(FROM_UNIXTIME('$start_of_day'),'$timezone','+00:00'))";
                            
            $connection = ConnectionManager::get('default');
            $results = $connection
                ->execute($query_string)
                ->fetch('assoc');
            $used = $results['used'];
            
            $this->{'DynamicClients'}->patchEntity($ent,['daily_data_used' =>$used]);
            $this->{'DynamicClients'}->save($ent);
            
        } 
    }
    
    private function _update_usage(ConsoleIo $io){ 
        $ent_list_dc = $this->{'DynamicClients'}->find()->where(['DynamicClients.data_limit_active' => true])->all(); 
        foreach($ent_list_dc as $ent){
            $r_day      = $ent->data_limit_reset_on;
            $r_hour     = $ent->data_limit_reset_hour;
            $r_min      = $ent->data_limit_reset_minute;        
            $tz_id      = $ent->timezone;
            $timezone   = $this->default_timezone;
            if($tz_id !== ''){
                if(isset($this->timezone_lookup[$tz_id])){
                    $timezone = $this->timezone_lookup[$tz_id];
                }
            }
            
            $start_of_month = $this->_start_of_month($r_day,$r_hour,$r_min,$timezone);
            $nas_id = $ent->nasidentifier;
            $this->out("Name ".$ent->name." with nasid ".$nas_id." resets on ".$r_day." on timezone $timezone");
             
            $query_string = "SELECT IFNULL(SUM(acctinputoctets)+ ".
                            "SUM(acctoutputoctets),0) as used ".
                            "FROM radacct WHERE nasidentifier='$nas_id' ".
                            "AND UNIX_TIMESTAMP(acctstarttime) + acctsessiontime > UNIX_TIMESTAMP(CONVERT_TZ(FROM_UNIXTIME('$start_of_month'),'$timezone','+00:00'))";    
                            
            $connection = ConnectionManager::get('default');
            $results = $connection
                ->execute($query_string)
                ->fetch('assoc');
            $used = $results['used'];
            
            $this->{'DynamicClients'}->patchEntity($ent,['data_used' =>$used]);
            $this->{'DynamicClients'}->save($ent);
            
        }
    }
       
    private function _start_of_day($r_hour,$r_min,$timezone) {
        // Get the current time.
        $dt_now     = DateTime::now();
        $dt_now->setTimezone($timezone);
            
        $dt_reset  = DateTime::now()
            ->year($dt_now->year)
            ->month($dt_now->month)
            ->day($dt_now->day)
            ->hour($r_hour)
            ->minute($r_min);              
        $dt_reset->setTimezone($timezone);
              
        #IF we are BEFORE the reset date move one day back
        if($dt_now->timestamp < $dt_reset->timestamp){
            $dt_reset->subDay(1);
        }         
        $dt_reset->setTimezone('UTC');
        return $dt_reset->timestamp;
    }
    
    private function _start_of_month($r_day,$r_hour,$r_min,$timezone) {
   
        // Get the current time.
        $dt_now     = DateTime::now();
        $dt_now->setTimezone($timezone);
        
        $timestamp  = $dt_now->timestamp;
        
        #Get the day of the month at this moment in time
        $day_now    = $dt_now->day;
        
        //We do the follwoing if reset day is for instance 31 but the month only has 30 days (or 28 for that matter)
        $month_end = DateTime::now();
        $month_end->setTimezone($timezone);
        
        $last_day_of_month = $month_end->endOfMonth()->day;
        if($r_day > $last_day_of_month){
            $r_day = $last_day_of_month;
        }
          
        $dt_reset  = DateTime::now()
            ->year($dt_now->year)
            ->month($dt_now->month)
            ->day($r_day)
            ->hour($r_hour)
            ->minute($r_min);
                    
        $dt_reset->setTimezone($timezone);
        
        if($dt_now->timestamp < $dt_reset->timestamp){  
            #We use the previous month 
            //When adding or subtracting months, if the resulting time is a date that does not exist, 
            //the result of this operation will always be the last day of the intended month.
            $dt_reset->subMonth();
        }
        $dt_reset->setTimezone('UTC');
        return $dt_reset->timestamp;  
    }   
    
}
