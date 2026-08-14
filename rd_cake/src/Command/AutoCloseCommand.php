<?php
//cd /var/www/html/cake4/rd_cake && bin/cake auto_close

//--HEADSUP : We do not use this anymore; we usethis which is much faster: 
//cd /var/www/html/cake4/rd_cake && bin/cake auto_close_sessions

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

class AutoCloseCommand extends Command
{
    protected ?object $Radchecks = null;

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax             
        $this->Radaccts         = $this->fetchTable('Radaccts');
        $this->Nas              = $this->fetchTable('Nas');
        $this->DynamicClients   = $this->fetchTable('DynamicClients');
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // This method satisfies the Command interface requirement if run directly
        $io->out('Usage utility command. Meant to be called inside other commands.');
        return static::CODE_SUCCESS;
    }
       
    // --- Kept public helper methods below for VoucherCommand to call ---
    
    public function check(ConsoleIo $io) {
        $this->_show_header($io);
        $this->_check($io);     
    }

    private function _show_header($io){
        $io->comment('==============================');
        $io->comment('---Stale Session Checking-----');
        $io->comment('-------RADIUSdesk 2024--------');
        $io->comment('______________________________');
    }
    
    private function _check(ConsoleIo $io){

        $io->info("AutoClose::Find NAS with Auto close enabled");

        $q_r = $this->Nas->find()->where(['Nas.session_auto_close' => '1'])->all();

        if($q_r){
            foreach($q_r as $item){
                $nasname        = $item->nasname;
                $close_after    = $item->session_dead_time;
                $nasidentifier  = $item->nasidentifier;
                $io->info("AutoClose::Auto closing potential stale sessions on $nasname after $close_after dead time");
                
                $conn = ConnectionManager::get('default');                
                $conn->execute("UPDATE radacct set acctstoptime=acctupdatetime, acctterminatecause='Clear-Stale-Session' WHERE nasipaddress='$nasname' AND acctstoptime is NULL AND acctupdatetime < NOW() - INTERVAL $close_after SECOND");
                $conn->execute("UPDATE radacct set acctstoptime=acctupdatetime, acctterminatecause='Clear-Stale-Session' where nasidentifier='$nasidentifier' AND acctstoptime is NULL AND acctupdatetime < NOW() - INTERVAL $close_after SECOND");

            }
        }else{
           $io->info("AutoClose::No NAS devices configured for auto session closing");
        }
          
        $io->info("AutoClose::Find DynamicClients with Auto close enabled");

        $q_r = $this->DynamicClients->find()->where(['DynamicClients.session_auto_close' => '1'])->all();

        if($q_r){
            foreach($q_r as $item){
                $nasidentifier  = $item->nasidentifier;
                $calledstationid= $item->calledstationid;
                $close_after    = $item->session_dead_time;
                $io->info("AutoClose::Auto closing potential stale sessions on NASID $nasidentifier after $close_after dead time");
                
                $conn = ConnectionManager::get('default');     
                if($nasidentifier != ''){
                    $conn->execute("UPDATE radacct set acctstoptime=acctupdatetime, acctterminatecause='Clear-Stale-Session' WHERE nasidentifier='$nasidentifier' AND acctstoptime is NULL AND acctupdatetime < NOW() - INTERVAL $close_after SECOND");
                }
                
                 if($calledstationid != ''){
                    $conn->execute("UPDATE radacct set acctstoptime=acctupdatetime, acctterminatecause='Clear-Stale-Session' WHERE calledstationid='$calledstationid' AND acctstoptime is NULL AND acctupdatetime < NOW() - INTERVAL $close_after SECOND");
                }
            }
        }else{
           $io->info("AutoClose::No DynamicClients configured for auto session closing");
        }       
    }
    
}

