<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

class RdCommand extends Command
{

    protected AutoCloseCommand $AutoClose;
    
    public function initialize():void{
        parent::initialize();
      
        $this->AutoClose = new AutoCloseCommand();
        $this->AutoClose->initialize();
    }
    
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // Get the first argument passed to the command.
        // For example, from: bin/cake rd auto_close
        $action = $args->getArgumentAt(0);

        if ($action === 'auto_close') {
            $io->out('Performing auto-close routine...');
            $this->AutoClose->check($io); 
        }

        return static::CODE_SUCCESS;
    }
}
