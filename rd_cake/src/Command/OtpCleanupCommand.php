<?php

//cd /var/www/html/cake4/rd_cake && bin/cake otp_cleanup

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\I18n\DateTime;

class OtpCleanupCommand extends Command
{
    protected ?object $PermanentUsers = null;
    protected ?object $PermanentUserOtps = null;
    protected ?object $DataCollectorOtps = null;
    protected ?object $DataCollectors = null;
    protected $cut_off = 2; //Cut off two hours

    public function initialize(): void
    {
        parent::initialize();
        $this->PermanentUsers       = $this->fetchTable('PermanentUsers');
        $this->PermanentUserOtps    = $this->fetchTable('PermanentUserOtps');
        $this->DataCollectorOtps    = $this->fetchTable('DataCollectorOtps');
        $this->DataCollectors       = $this->fetchTable('DataCollectors');
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $io->info("==PERMANENT USERS OTP===");
     	$io->info("Remove Awaiting OTP related data older than $this->cut_off hours");
     	$now  		= DateTime::now();
     	$cut_off 	= $now->subHours($this->cut_off);
     	
		$pu_old_list = $this->PermanentUserOtps->find()->where(['PermanentUserOtps.modified <=' => $cut_off,'PermanentUserOtps.status' => 'otp_awaiting' ])->all();
		foreach($pu_old_list as $otp){
		 	$pu = $this->PermanentUsers->find()->where(['PermanentUsers.id' => $otp->permanent_user_id])->first();
		 	if($pu){
		 		$io->info("Delete Permanent User ".$pu->username);
		 		$this->PermanentUsers->delete($pu); 		
		 	}
		 	$this->PermanentUserOtps->delete($otp);    
		 }
		 	 
		$io->info("==CLICK TO CONNECT OTP===");
		$io->info("Remove Awaiting OTP related data older than $this->cut_off hours");

		$dc_old_list = $this->DataCollectorOtps->find()->where(['DataCollectorOtps.modified <=' => $cut_off,'DataCollectorOtps.status' => 'otp_awaiting' ])->all();
		foreach($dc_old_list as $otp){
			$dc = $this->DataCollectors->find()->where(['DataCollectors.id' => $otp->data_collector_id])->first();
			if($dc){
				$this->out("<info>Delete Data Collector ".$dc->email."</info>");
				$this->DataCollectors->delete($dc); 		
			}
			$this->DataCollectorOtps->delete($otp);    
		}

        return static::CODE_SUCCESS;
    }
}

