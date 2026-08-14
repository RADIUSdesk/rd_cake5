<?php

//as www-data
//cd /var/www/html/cake4/rd_cake && bin/cake auto_clean_mesh_desk

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

use Cake\I18n\DateTime;
use Cake\I18n\Time;


class AutoCleanMeshDeskCommand extends Command
{

    protected ?object $NodeIbssConnections = null;
    protected ?object $NodeStations = null;
    protected ?object $TempReports = null;
    protected ?object $NodeUptmHistories = null;
    protected ?object $ApStations = null;
    protected ?object $ApUptmHistories = null;

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax             
        $this->NodeIbssConnections  = $this->fetchTable('NodeIbssConnections');
        $this->NodeStations         = $this->fetchTable('NodeStations');
        $this->TempReports          = $this->fetchTable('TempReports');
        $this->NodeUptmHistories    = $this->fetchTable('NodeUptmHistories');
        $this->ApStations           = $this->fetchTable('ApStations');
        $this->ApUptmHistories      = $this->fetchTable('ApUptmHistories');
        
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->out('==============================');
        $io->out('---- Auto Clean MESHdesk -----');
        $io->out('----RADIUSdesk 2012-2026------');
        $io->out('______________________________');

        $hour   	= (60*60);
        $day    	= $hour*24;
        $week   	= $day*8;//Change to 8 days
		$modified 	= date("Y-m-d H:i:s", time()-$week);
        $io->info("Auto Clean-up of MESHdesk data older than one week ".APP);
        $this->NodeStations->deleteAll(['NodeStations.modified <' => $modified]);
		$this->NodeIbssConnections->deleteAll(['NodeIbssConnections.modified <' => $modified]);
		
		$this->NodeUptmHistories->deleteAll(['NodeUptmHistories.modified <' => $modified]);
		
		$this->ApStations->deleteAll(['ApStations.modified <' => $modified]);
		$this->ApUptmHistories->deleteAll(['ApUptmHistories.modified <' => $modified]);
			
		//--Delete TempReports if it is older than 30 minutes--;
		$now        = new DateTime();
		$hour_old	= $now->subMinutes(30);
		$this->TempReports->deleteAll(['TempReports.timestamp <' => $hour_old]);	
			
        return static::CODE_SUCCESS;
    }
}
