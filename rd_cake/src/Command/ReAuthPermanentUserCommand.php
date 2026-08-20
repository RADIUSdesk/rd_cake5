<?php

//cd /var/www/html/cake4/rd_cake && bin/cake re_auth_permanent_user <user_id>

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

class ReAuthPermanentUserCommand extends Command
{

    protected ?object $PermanentUsers = null;
    
    protected $session_id   = "12345678";
    protected $username     = "no-one";
    //protected $fw_ip        = "100.85.247.44";
    protected $fw_ip        = "192.168.8.137";
    protected $port         = 3799;
    protected $secret       = 'testing123';

    public function initialize(): void
    {
        parent::initialize();
        $this->PermanentUsers       = $this->fetchTable('PermanentUsers');
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->out('<comment>==============================</comment>');
        $io->out('<comment>---Re Auth Permanent User-----</comment>');
        $io->out('<comment>----RADIUSdesk 2012-2026------</comment>');
        $io->out('<comment>______________________________</comment>');
        
        $user_id = $args->getArgumentAt(0);
        
        if (!$user_id) {
            $io->error('Please supply the Permanent User ID to re-authenticate');
        }
        
        $permanentUser = $this->PermanentUsers->find()->where(['PermanentUsers.id' => $user_id])->first();
        
        if($permanentUser){    
            $static_ip = $permanentUser->static_ip;                      
            if($static_ip !== ''){
            
                if (!filter_var($static_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Invalid IPv4 address
                    throw new \InvalidArgumentException('Invalid IPv4 address provided');
                }
                
                $io->info("STATIC IP IS $static_ip");
                $attributes = "Acct-Session-ID=".$this->session_id.",User-Name=".$this->username.",Framed-IP-Address=$static_ip";
                $io->out("echo \"$attributes\"|radclient -c 1 -n 3 -r 3 -t 3 -x ".$this->fw_ip.":".$this->port." disconnect ".$this->secret);
                shell_exec("echo \"$attributes\"|radclient -c 1 -n 3 -r 3 -t 3 -x ".$this->fw_ip.":".$this->port." disconnect ".$this->secret);
            }                
        }       
        return static::CODE_SUCCESS;
    }
}
