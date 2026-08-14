<?php

//as www-data
//cd /var/www/html/cake4/rd_cake && bin/cake fup

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

use Cake\I18n\DateTime;
use Cake\I18n\Time;
use Cake\Http\Client;

class FupCommand extends Command
{
    protected ?object $Profiles     = null;
    protected ?object $Radaccts     = null;
    protected ?object $Radchecks    = null;
    protected ?object $Vouchers     = null;
    protected ?object $Clouds       = null;
    protected ?object $Users        = null;
    protected ?object $PermanentUsers = null;
    protected ?object $AppliedFupComponents = null;
    
    protected array $fupProfiles;
    
    public function initialize():void{
        parent::initialize();      
        $this->Profiles     = $this->fetchTable('Profiles');
        $this->Radaccts     = $this->fetchTable('Radaccts');
        $this->Radchecks    = $this->fetchTable('Radchecks');
        $this->Vouchers     = $this->fetchTable('Vouchers');
        $this->Clouds       = $this->fetchTable('Clouds');
        $this->Users        = $this->fetchTable('Users');
        $this->PermanentUsers = $this->fetchTable('PermanentUsers');
        $this->AppliedFupComponents = $this->fetchTable('AppliedFupComponents');
    }
    
    //ConsoleIo $io
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $this->findFupProfiles($io);
        if(!empty($this->fupProfiles)){
        	//There is FUP Profiles lets see if some are with the current active users
        	$this->testActiveConnections($io);
        }else{
        	$io->success("No FUP Profiles - Exit");
        }
        return static::CODE_SUCCESS;
    }
    
    private function testActiveConnections($io){    
    	$e_ra = $this->{'Radaccts'}->find()->where(['Radaccts.acctstoptime IS NULL'])->all();
    	foreach($e_ra as $ra){ 
    		$this->processUsername($ra,$io);   			
    	}      
    }  
    
    private function processUsername($ra,ConsoleIo $io){
    	$type = $this->find_type($ra->username);
    	$io->info($ra->username." is of type $type");
    	if($type == 'user'){  	
    		$e_pu = $this->{'PermanentUsers'}->find()->where(['PermanentUsers.username' => $ra->username])->first();
    		if($e_pu){
    			if(isset($this->fupProfiles[$e_pu->profile_id])){
    				$io->info("Found FUP Profile for user");
    				$billing_cycle = false;	
    				if(($e_pu->extra_name == 'BCStartDay')&&(intval($e_pu->extra_value) > 0)){
    					$billing_cycle = $e_pu->extra_value;	
    				}
    				$timezone 	= $this->getTimezone($ra->nasidentifier);
    				$profile_id = $e_pu->profile_id;
    				$profile_name = $e_pu->profile;
    				$username	= $ra->username;
    				$this->fup($username,$profile_id,$timezone,$e_pu->cloud_id,$io,$billing_cycle,$profile_name);
    			}
    		}
    	}
    	
    	if($type == 'voucher'){  	
    		$e_v = $this->{'Vouchers'}->find()->where(['Vouchers.name' => $ra->username])->first();
    		if($e_v){
    			if(isset($this->fupProfiles[$e_v->profile_id])){
    				$io->info("Found FUP Profile for voucher");
    				$timezone 	= $this->getTimezone($ra->nasidentifier);
    				$profile_id = $e_v->profile_id;
    				$username	= $ra->username;
    				$this->fup($username,$profile_id,$timezone,$e_v->cloud_id,$io);
    			}
    		}
    	}
    	
    	if($type == 'device'){  	
    		$e_d = $this->{'Devices'}->find()->where(['Devices.name' => $ra->username])->first();
    		if($e_d){
    			if(isset($this->fupProfiles[$e_d->profile_id])){
    				$io->info("Found FUP Profile for device");
    				$timezone 	= $this->getTimezone($ra->nasidentifier);
    				$profile_id = $e_v->profile_id;
    				$username	= $ra->username;
    				//Need to find the Permanet User
    				$e_pu_d = $this->{'PermanentUsers'}->find()->where(['PermanentUsers.id' => $e_d->permanent_user_id])->first();
    				if($e_pu_d){
    					$this->fup($username,$profile_id,$timezone,$e_pu_d->cloud_id,$io);
    				}
    			}
    		}
    	}    	    	     
    }
    
    private function findFupProfiles(){    
    	$e_p = $this->{'Profiles'}->find()->contain(['ProfileFupComponents'])->all();
    	foreach($e_p as $p){
    		if(count($p->profile_fup_components)> 0){
    			$this->fupProfiles[$p->id] = $p->profile_fup_components;
    		}    	
    	}    
    }
        
    private function find_type($username){
        $type = 'unknown';
        $q_r = $this->Radchecks->find()->where(['Radchecks.username' => $username,'Radchecks.attribute' => 'Rd-User-Type'])->first();
        if($q_r){
            $type = $q_r->value;
        }
        return $type;
    }
    
    private function getTimezone($nasid){
    
    	$timezone = $this->timezone;
   		$sql_dynamic = "SELECT IFNULL((SELECT tz.name FROM dynamic_clients c LEFT JOIN timezones tz ON tz.id = c.timezone where c.nasidentifier=:nasid),'timezone_not_found') as timezone";
   		$sql_system  = "SELECT IFNULL((SELECT tz.name FROM user_settings us LEFT JOIN timezones tz ON tz.id = us.value where us.name='timezone' AND us.user_id=-1 LIMIT 1),'timezone_not_found') as timezone" ; 
   		
   		$connection = ConnectionManager::get('default');          
      	$results = $connection
    		->execute($sql_dynamic , ['nasid' => $nasid])
    		->fetchAll('assoc');
    	if($results[0]['timezone']== 'timezone_not_found'){ 
    		$results_system = $connection
    			->execute($sql_system)
    			->fetchAll('assoc');    			
    		if($results_system[0]['timezone'] !== 'timezone_not_found'){ 
    			$timezone = $results_system[0]['timezone'];
    		}    	
    	}else{
       		$timezone = $results[0]['timezone'];
    	}    	
    	return $timezone;    
    }
    
    private function fup($username,$profile_id,$timezone,$cloud_id,ConsoleIo $io,$billing_cycle = false,$profile_name = ''){
    
    	#Get the current active applied_fup_component for the user (Then compare it with the one which SHOULD apply)
    	#If different you then issue a disconnect request
    	$current_applied = 0; //Default is none applied (even if it might not be recorded)
    	$e_applied = $this->{'AppliedFupComponents'}->find()->where(['AppliedFupComponents.username' => $username])->first();
    	if($e_applied){
    		$current_applied = $e_applied->profile_fup_component_id;
    	}

		$should_apply 	= 0;
		$apply_record	= null;
		$limits			= [];
		$most_decrease 	= null;
		$least_increase = null;
  
    	foreach($this->fupProfiles[$profile_id] as $c){   	
    		if($c->if_condition == 'time_of_day'){
				$return_action = $this->check_time_of_day($c,$timezone,$io);
				if($return_action == 'block'){
					$should_apply = $c->id;
					$apply_record = $c;
					break;				
				}
				if($return_action == 'limit'){				
					$limits[$c->id] = $c;
				}   		
    		}else{    		
    			#These are day_usage week_usage or month_usage limits
    			$return_usage = $this->check_usage($username,$c,$timezone,$io,$billing_cycle);
    			if($return_usage == 'block'){
					$should_apply = $c->id;
					$apply_record = $c;
					break;				
				}
				if($return_usage == 'limit'){				
					$limits[$c->id] = $c;
				}     				    		
    		}   	
    	}
    	
    	if($should_apply !== 0){ //Should apply is set on a 'block' if it is set we dont have to worry about throttle calculations
    	
    		$io->info("Block Active");
    		
    	}else{	
			
			//Work out the biggest throttle value (if we are not blocking)  	
			foreach($limits as $val){				
				#Decrease Speed
		        if($most_decrease){
		            if($val->{'action'} == 'decrease_speed'){
		                if($val->{'action_amount'} >$most_decrease->{'action_amount'}){
		                    $most_decrease = $val;
		                } 
		            }
		        }else{
		            if($val->{'action'} == 'decrease_speed'){
		                $most_decrease = $val;
		            }
		        }

		        #Increase Speed
		        if($least_increase){
		            if($val->{'action'} == 'increase_speed'){
		                if($val->{'action_amount'} <$least_increase->{'action_amount'}){
		                    $least_increase = $val;
		                } 
		            }
		        }else{
		            if($val->{'action'} == 'increase_speed'){
		                $least_increase = $val;
		            }
		        }  	
			}		
		}
    	
    	if($most_decrease){
        	$should_apply = $most_decrease->{'id'};
        	$apply_record = $most_decrease;
		}else{
		    if($least_increase){
		    	$should_apply = $least_decrease->{'id'};
		    	$apply_record = $least_decrease;    
		    }
		}

		$io->info("Current Applied $current_applied");
    	$io->info("Should Apply $should_apply");
    	if($current_applied !== $should_apply){
    		$e_cloud = $this->{'Clouds'}->find()->where(['Clouds.id' => $cloud_id])->first();
    		if($e_cloud){
    			$user_id = $e_cloud->user_id;
    			$e_user = $this->{'Users'}->find()->where(['Users.id' => $user_id])->first();
    			if($e_user){
    			
    				$token 	 = $e_user->token;
    				$url 	 = 'http://127.0.0.1/cake4/rd_cake/radaccts/kick-active-username.json';
    				$request = ['cloud_id' => $cloud_id,'username' => $username,'token' => $token];
    					
    				//===================================================================	
    				//-------------------------------------------------------------------
    				//--- USE THIS BLOCK FOR CUSTOM SETUPS e.g. where you have ----------
    				//--- Two different URLs to call when blocking vs decreasing speed --
    				//--- Also when you need another URL to call when you restore things-
    				//--- e.g. remove a limit -------------------------------------------
    				//-------------------------------------------------------------------		
    			   			
    				if($should_apply !== 0){
    				
    					$ip_pool = $apply_record->ip_pool;
    				
						if($apply_record->action == 'block'){
							$io->info("Action is to BLOCK");
						}
						
						if($apply_record->action == 'increase_speed'){
							$io->info("Action is to INCREASE SPEED $ip_pool");
						}
						
						if($apply_record->action == 'decrease_speed'){
							$io->info("Action is to DECREASE SPEED $ip_pool");
						}
						
					}else{
						$io->info("NO LIMIT TO APPLY $profile_name");
									
					}
					    			
    				//---------------------------------------------------------------------
    				//=====================================================================
    			   			 				
    				$http  = new Client();
					$response = $http->get(
					  $url,
					  $request,
					  ['type' => 'json']
					);
					$reply          = $response->getStringBody();
					print($reply);
					    			
    			}    		
    		}
    	}  	   
    }
    
    private function check_time_of_day($row,$timezone, ConsoleIo $io) {
    
    	$dt  		= new DateTime();
    	$dt			= $dt->setTimezone($timezone);
    	
    	$dt_start  	= new DateTime();
    	$dt_start  	= $dt_start->setTimezone($timezone);
    	
    	$dt_end  	= new DateTime();
    	$dt_end  	= $dt_end->setTimezone($timezone);
    	
    	$time_start = explode(':', $row->time_start);
    	$dt_start   = $dt_start->hour($time_start[0])->minute($time_start[1])->second(00);

		$time_end 	= explode(':', $row->time_end);
		$dt_end     = $dt_end->hour($time_end[0])->minute($time_end[1])->second(00);

		//$io->info("<info>".$dt_start->nice()."</info>");
		//$io->info("<info>".$dt->nice()."</info>");
		//$io->info("<info>".$dt_end->nice()."</info>");
		if(($dt >= $dt_start)&&($dt <= $dt_end)){
			if($row->{'action'} == 'block'){
				$io->info("BLOCK");
				return 'block';
			}else{
				$io->info("LIMIT");			
				return 'limit';
			}
		}
		return 'noop';
	}
	
	private function check_usage($username,$row,$timezone, ConsoleIo $io,$billing_cycle=false){

    	$time_start = $this->get_start_of($row->{'if_condition'},$timezone,$io,$billing_cycle);
    	$nice = $time_start->toIso8601String();
    	$io->info("TIME START $nice");
    	$sql_usage 	= "SELECT IFNULL(SUM(acctinputoctets)+SUM(acctoutputoctets),0) AS data_used FROM user_stats WHERE username=:username AND timestamp >=:timestamp";
    	$connection = ConnectionManager::get('default');          
      	$results = $connection
    		->execute($sql_usage , ['username' => $username,'timestamp' => $nice])
    		->fetchAll('assoc');
    	$data_used 	= $results[0]['data_used'];
    	$io->info("DATA USED $data_used");
   		$trigger  	= $row->{'data_amount'};
		if($row->{'data_unit'} == 'mb'){
		    $trigger = $trigger * 1024 * 1024;
		}
		if($row->{'data_unit'} == 'gb'){
		    $trigger = $trigger * 1024 * 1024 * 1024;
		}

		if($data_used > $trigger){
		    if($row->{'action'} == 'block'){
		        return 'block';
		    }
		    return 'limit';
		}
    	return 'noop';  
	}

	private function get_start_of($when,$timezone,ConsoleIo $io, $billing_cycle=false){
		#'day_usage' is default of current day
		$dt = new DateTime();
    	$dt = $dt->setTimezone($timezone);
    	$dt = $dt->hour(00)->minute(00)->second(00);
		if($when == 'week_usage'){
		    $dt = $dt->startOfWeek();
		}

		if($when == 'month_usage'){
			if($billing_cycle){
				$dt = $this->findBillingCycleStart($timezone,$billing_cycle,$io);	
			}else{
		    	$dt = $dt->startOfMonth();
		   	}
		}		
		return $dt;
	}
	
	private function findBillingCycleStart($timezone,$bc_day,ConsoleIo $io){

    	$dt 		= new DateTime();
    	$dt 		= $dt->setTimezone($timezone);
    	$day_now 	= $dt->day;
    	
    	if(($bc_day > $day_now)&&($day_now < 28)){
    		$bc_start = $dt->subMonth(1);
			$bc_start = $bc_start->setDateTime($bc_start->year,$bc_start->month,$bc_day,0,0,0);   		
    	}else{
    		if($day_now > 28){ //Roll over to next month after 28th (29,30,31 will use this)
    			$bc_start = $dt->setDateTime($dt->year,$dt->month,29,0,0,0);	
    		}else{
    			$bc_start = $dt->setDateTime($dt->year,$dt->month,$bc_day,0,0,0);
    		}
    	}
    	$io->info("Billing Cycle Start Time IS $bc_start");   	
    	return $bc_start;  
    }
}
