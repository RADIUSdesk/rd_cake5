<?php

//as www-data
//cd /var/www/html/cake4/rd_cake && bin/cake auto_add_devices

declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use App\Model\Table\AutoDevicesTable;
use App\Model\Table\DevicesTable;
use App\Model\Table\ProfilesTable;
use App\Model\Table\PermanentUsersTable;
use App\Model\Table\RealmsTable;
use App\Model\Table\UsersTable;

class AutoAddDevicesCommand extends Command
{

    protected ?object $DynamicClients   = null;
    protected ?object $Radaccts         = null;
    protected ?object $Timezones        = null;    
    protected  $default_timezone        = 'UTC'; //Default for timezone
    protected  $timezone_lookup         = [];
    
    protected AutoDevicesTable $AutoDevices;
    protected DevicesTable $Devices;
    protected ProfilesTable $Profiles;
    protected PermanentUsersTable $PermanentUsers;
    protected RealmsTable $Realms;
    protected UsersTable $Users;

    public function initialize(): void
    {
        parent::initialize();
        // CakePHP 5 model loading syntax             
        $this->AutoDevices      = $this->fetchTable('AutoDevices');
        $this->Devices          = $this->fetchTable('Devices');
        $this->Profiles         = $this->fetchTable('Profiles');
        $this->PermanentUsers   = $this->fetchTable('PermanentUsers');
        $this->Realms           = $this->fetchTable('Realms');
        $this->Users            = $this->fetchTable('Users');                     
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->info('==================================');
        $io->info('------- Auto Add Devices ---------');
        $io->info('----- RADIUSdesk 2012-2026 -------');
        $io->info('__________________________________');

        $io->info("Auto Add Devices start ".APP);
        $qr = $this->{'AutoDevices'}->find()->all();
        foreach($qr as $i){
            $m = $i->mac;     
            if(
                ($m == 'aa-aa-aa-aa-aa-aa')||
                ($m == 'AA-AA-AA-AA-AA-AA')
            ){
                $io->info("<info>Ignoring RADIUS Auth test entry".$m."</info>");
            }else{
                $this->process_auto_device($i->mac,$i->username,$io);
            }
        }

        //Clear the table for the next lot
        $conn = ConnectionManager::get('default');   
        $conn->execute('TRUNCATE table auto_devices;');
			
        return static::CODE_SUCCESS;
    }
    
    private function process_auto_device($mac,$username,ConsoleIo $io){
        $io->info("Checking the following device $mac");
        
        $count = $this->{'Devices'}->find()->where(['Devices.name' =>$mac])->count();
        if($count == 0){
            $io->info("Device $mac not found - Add it");
            $vendor = $this->FindMac->return_vendor_for_mac($mac);
            
            //Find the Permanent user that this device belongs to:
            $q_r = $this->{'PermanentUsers'}->find()->contain(['Radchecks','Clouds' => ['Users']])->where(['PermanentUsers.username' => $username])->first();
            if($q_r){
              
                //Gather the relevant info (We only need the user_id and profile_id
                $profile_id    = false;
                foreach($q_r->radchecks as $rc){
                    if($rc->attribute == 'User-Profile'){
                        $profile    = $rc->value;
                        $q        = $this->Profiles->find()->where(['Profiles.name' => $profile])->first();
                        if($q){
                            $profile_id = $q->id;
                        }
                    }
                    
                    if($rc->attribute == 'Rd-Realm'){
                        $realm    = $rc->value;
                        $q_realm  = $this->Realms->find()->where(['Realms.name' => $realm])->first();
                        if($q_realm){
                            $realm_id = $q_realm->id;
                        }
                    }
                }
                if($profile_id){
                
                   $token  = $q_r->cloud->user->token;
                    $d     = [];
                    $d['profile_id']  		    = $profile_id;
                    $d['profile']  		        = $profile;
                    $d['cloud_id']				= $q_r->cloud_id;                    
                    $d['realm_id']              = $realm_id;
                    $d['realm']                 = $realm;          
                    $d['permanent_user_id']     = $q_r->id;
                    $d['rd_device_owner']       = $username; //NB to have this
                    $d['name']        		    = $mac;
                    $d['description'] 		    = "Auto add ( $vendor )";
                    $d['active']      		    = 1;
                    $d['track_auth']  		    = false;
                    $d['track_acct']  		    = true;
                    
                    $http = new Client();
                    //$baseUrl = Configure::read('App.fullBaseUrl');
                    
		            $response = $http->post("http://127.0.0.1/cake4/rd_cake/devices/add.json?token=$token",$d);
		            $io->info($response->getBody());
                    $io->info("Added device $mac as Auto add ( $vendor )");
                }

            }else{
                $io->warning("User $username not found in Permanent Users");
            }
            
        }
    }
}
