<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

use App\Model\Table\RadusergroupsTable;
use App\Model\Table\RadgroupchecksTable;
use App\Model\Table\RadchecksTable;

class CountersCommand extends Command
{   
    protected RadusergroupsTable $Radusergroups;
    protected RadgroupchecksTable $Radgroupchecks;
    protected RadchecksTable $Radchecks;

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax
        $this->Radusergroups = $this->fetchTable('Radusergroups');
        $this->Radgroupchecks = $this->fetchTable('Radgroupchecks');
        $this->Radchecks = $this->fetchTable('Radchecks');
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // This method satisfies the Command interface requirement if run directly
        $io->info('==================================');
        $io->info('------- Counters Command ---------');
        $io->info('----- RADIUSdesk 2012-2026 -------');
        $io->info('__________________________________');
        $io->out('Usage utility command. Meant to be called inside other commands.');
        return static::CODE_SUCCESS;
    }
       
    // --- Kept public helper methods below for VoucherCommand to call ---
    
    public function return_counter_data($profile_name,$type,$username,ConsoleIo $io) {
        $counters = array();
        $this->_show_header($profile_name,$io);
        if(($type == 'voucher')||($type == 'user')||($type == 'device')){ //nothing fancy here initially
            $counters = $this->_find_counters($profile_name,$username);
        }
        return $counters;    
    }

    private function _show_header($profile_name,ConsoleIo $io){
        $io->comment('=============================');
        $io->comment('--Find potential counters for--');
        $io->comment("-------$profile_name---------");
        $io->comment('______________________________');
    }

    private function _find_counters($profile_name,$username){

        $counters = [];
        //First we need to find all the goupnames associated with this profile
        $q_r = $this->{'Radusergroups'}->find()
            ->where(['Radusergroups.username' => $profile_name])
            ->orderBy(['Radusergroups.priority ASC']) //So smallese numbers first. then bigger numbers can override the smaller numbers
            ->all();
        
        $counters_info = [];

        foreach($q_r as $i){       
            $g  = $i->groupname;
            
            $q_gchk = $this->{'Radgroupchecks'}->find()
                ->where(['Radgroupchecks.groupname' => $g])
                ->all();
            
            foreach($q_gchk as $j){
                $attribute  = $j->attribute;
                $value      = $j->value;
                $counters_info[$attribute] = $value;
            }
        }
        
        //Once we accumalated all the data; we can now go through them to determine if and what counters are there
        $tc = $this->_look_for_time_counters($counters_info,$username);
        if($tc){
            $counters['time'] = $tc;
        }

        $dc = $this->_look_for_data_counters($counters_info,$username);
        if($dc){
            $counters['data'] = $dc;
        }
        
        return $counters;
    }


    private function _look_for_time_counters($counters_info,$username){
        $counter = false;
        
        if(array_key_exists('Rd-Cap-Type-Time', $counters_info)){
            $counter            = [];
            $counter['cap']     = $counters_info['Rd-Cap-Type-Time'];
            $counter['reset']   = $counters_info['Rd-Reset-Type-Time'];
            
            if(!array_key_exists('Rd-Total-Time', $counters_info)){ //With Top-Ups its not defined on profile but on user level   
                $total_time = $this->_query_radcheck($username,'Rd-Total-Time');
                if($total_time){
                    $counter['value'] = $total_time;
                }           
            }else{
                $counter['value']   = $counters_info['Rd-Total-Time'];
            }
                 
			//Defaults for mac_counter and reset_interval
			$mac_counter		= false;
			$reset_interval		= false;
			if($counter['reset'] == 'dynamic'){
				$reset_interval = $counters_info['Rd-Reset-Interval-Time'];
			}
			if(array_key_exists('Rd-Mac-Counter-Time', $counters_info)){
			    $mac_counter		= $counters_info['Rd-Mac-Counter-Time'];
		    }
			$counter['reset_interval'] 	= $reset_interval;
			$counter['mac_counter'] 	= $mac_counter;
        }
        return $counter;
    }

    private function _look_for_data_counters($counters_info,$username){
        $counter = false;

        if(array_key_exists('Rd-Cap-Type-Data', $counters_info)){
            $counter            = [];
            $counter['cap']     = $counters_info['Rd-Cap-Type-Data'];
            $counter['reset']   = $counters_info['Rd-Reset-Type-Data'];
            
            if(!array_key_exists('Rd-Total-Data', $counters_info)){ //With Top-Ups its not defined on profile but on user level   
                $total_data = $this->_query_radcheck($username,'Rd-Total-Data');
                if($total_data){
                    $counter['value'] = $total_data;
                }           
            }else{
                $counter['value']   = $counters_info['Rd-Total-Data'];
            }            

			//Defaults for mac_counter and reset_interval
			$mac_counter		= false;
			$reset_interval		= false;
			if($counter['reset'] == 'dynamic'){
				$reset_interval = $counters_info['Rd-Reset-Interval-Data'];
			}
			
			if(array_key_exists('Rd-Mac-Counter-Data', $counters_info)){
			    $mac_counter		= $counters_info['Rd-Mac-Counter-Data'];
		    }
			
			$counter['reset_interval'] 	= $reset_interval;
			$counter['mac_counter'] 	= $mac_counter;
        }
        return $counter;
    }
    
    private function _query_radcheck($username,$attribute){
        $retval = false;
        $q_r = $this->{'Radchecks'}->find()->where(['Radchecks.username' => $username, 'Radchecks.attribute' => $attribute])->first();
        if($q_r){
            $retval = $q_r->value;
        }
        return $retval;   
    }    
}

