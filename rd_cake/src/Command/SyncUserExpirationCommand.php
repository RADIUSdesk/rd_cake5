<?php

namespace App\Command;

//as www-data
//cd /var/www/rdcore/cake4/rd_cake && bin/cake permanent-users:sync-expiration >> /dev/null 2>&1

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\I18n\DateTime;
use DateTimeZone;

use Cake\Controller\ComponentRegistry;
use App\Controller\Component\IspPlumbingComponent;

use App\Model\Table\PermanentUsersTable;

class SyncUserExpirationCommand extends Command {

    protected $IspPlumbing;
    
    protected PermanentUsersTable $PermanentUsers;

    public static function defaultName(): string{
        return 'permanent-users:sync-expiration';
    }

    public function initialize(): void {

        parent::initialize();

        $registry = new ComponentRegistry();
        $this->IspPlumbing = new IspPlumbingComponent($registry);
        $this->PermanentUsers  = $this->fetchTable('PermanentUsers');
    }

    public function execute(Arguments $args, ConsoleIo $io){
    
        //== FIXME Change to match your timezone ===
        $tz  = new \DateTimeZone('Africa/Lagos');
        $now = DateTime::now($tz);

        $expiredCount     = 0;
        $reactivatedCount = 0;

        /*
        * Expire users
        */
         
        do {
            // Laai telkens net die volgende 200 aktiewe rekords
            $users = $this->PermanentUsers->find()
                ->where([
                    'to_date <' => $now,
                    'admin_state' => 'active'
                ])
                ->limit(200)
                ->all();

            if ($users->isEmpty()) {
                break;
            }

            foreach ($users as $user) {            
                $user->admin_state = 'expired';
                if ($this->PermanentUsers->save($user)) {
                    $this->IspPlumbing->disconnectIfActive($user);
                    $expiredCount++;
                }
            }
            
            // Opsioneel: Maak geheue skoon as die lys massief is
            unset($users); 

        } while (true);


        /*
         * Reactivate users
         */
               
        do {
            // Laai telkens net die volgende 200 aktiewe rekords
            $users = $this->PermanentUsers->find()
                ->where([
                    'to_date >=' => $now,
                    'admin_state' => 'expired'
                ])
                ->limit(200)
                ->all();

            if ($users->isEmpty()) {
                break;
            }

            foreach ($users as $user) {            
                $user->admin_state = 'active';
                if ($this->PermanentUsers->save($user)) {
                    $this->IspPlumbing->disconnectIfActive($user);
                    $expiredCount++;
                }
            }
            
            // Opsioneel: Maak geheue skoon as die lys massief is
            unset($users); 

        } while (true);
         
        $io->out("Expired users updated: {$expiredCount}");
        $io->out("Reactivated users updated: {$reactivatedCount}");
    }
}

