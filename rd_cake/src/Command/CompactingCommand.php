<?php

//cd /var/www/html/cake4/rd_cake && bin/cake compacting

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

class CompactingCommand extends Command
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {

     	$io->info("Start the Compacting of the user_stats table");
        
        $io->warning("MOST OF THIS function has been replaced by a trigger on the radacct table - Please apply the SQL patch if you have not yet");
        $io->warning("Keep this script in CRON since we will replace it with a Command in CakePHP which acts as a drop in replacement to do some housekeeping");
        $io->warning("BYE :-)");
        return static::CODE_SUCCESS;
    }    
}

