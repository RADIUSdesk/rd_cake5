<?php

declare(strict_types=1);

namespace App\Service;
use Cake\Log\Log;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\ORM\Locator\LocatorAwareTrait;

class ConnectAndRedirectService {

    use LocatorAwareTrait;
    protected $profCompPrefix = 'SimpleAdd_';
    
    public function __construct(){

        Configure::load('ConnectAndRedirect');
    }
       
    public function updateApCaptivePortal($ent_cp,$cloud_id){
    
        Configure::load('ConnectAndRedirect');     
        $special_uam_url = Configure::read('Cnr.special_uam_url');
        $uam_secret      = Configure::read('Cnr.uam_secret');
        $portals = $this->fetchTable('ApProfileExitCaptivePortals'); 
         
        if($ent_cp->connect_and_redirect){
            $ent_cp->uam_url    = $special_uam_url;
            $ent_cp->uam_secret = $uam_secret;
            $portals->save($ent_cp);
            $this->_completeApSetup($ent_cp,$cloud_id);
        }else{
            $this->_teardownApSetup($ent_cp,$cloud_id);
        }
    }
       
    private function _teardownApSetup($ent_cp,$cloud_id) {
        $ap_profile_exits   = $this->fetchTable('ApProfileExits');
        $profiles           = $this->fetchTable('Profiles');
        $profile_components = $this->fetchTable('ProfileComponents');
        $user_groups        = $this->fetchTable('Radusergroups');
        $permanent_users    = $this->fetchTable('PermanentUsers');
        
        $exit_id            = $ent_cp->ap_profile_exit_id;
        $exit               = $ap_profile_exits->find()->where(['ApProfileExits.id' => $exit_id])->first();
        if($exit){
            $ap_profile_id  = $exit->ap_profile_id;
            $start_with     = Configure::read('Cnr.start_with');
            $name           = $start_with.'_a_'.$ap_profile_id.'_'.$exit_id;            
            $realm_id       = intval($exit->realm_list);
            
            $profile = $profiles->find()
                ->where([
                    'Profiles.name' => $name,
                    'Profiles.cloud_id' => $cloud_id                    
                ])
                ->first();
                
            if($profile){
                $ugs = $user_groups->find()
                    ->where(
                        [
                            'username'  => $profile->name
                        ]
                    )->all();
                foreach($ugs as $user_group){
                    Log::info("Found Usergroup :" . $user_group->groupname);
                    $this->_clearRadius($user_group->groupname);
                    $profile_components->deleteAll(['ProfileComponents.name' => $user_group->groupname]); 
                    $user_groups->delete($user_group);                  
                }
                $permanent_users->deleteAll(['PermanentUsers.username' => $name, 'PermanentUsers.cloud_id' => $cloud_id]);
                $profiles->delete($profile);               
            }
            $this->_doDynamicClients($cloud_id,$ap_profile_id,$exit_id,$realm_id);         
        }    
    }
    
    private function _completeApSetup($ent_cp,$cloud_id){
        $ap_profile_exits   = $this->fetchTable('ApProfileExits');
        $realms             = $this->fetchTable('Realms');
        $profiles           = $this->fetchTable('Profiles');
        $profile_components = $this->fetchTable('ProfileComponents');
        $user_groups        = $this->fetchTable('Radusergroups');
        $permanent_users    = $this->fetchTable('PermanentUsers');
             
        $exit_id            = $ent_cp->ap_profile_exit_id;
        $exit               = $ap_profile_exits->find()->where(['ApProfileExits.id' => $exit_id])->first();
        if($exit){
            $ap_profile_id  = $exit->ap_profile_id;
            $realm_id       = intval($exit->realm_list);
            
            if($realm_id){
                Log::info("Found Realm ID " . $realm_id);
                $realm = $realms->find()->where(['Realms.id' => $realm_id])->first();
                $realm_name = $realm->name;                
                //Check if there is a profile called
                $start_with = Configure::read('Cnr.start_with');
                $name = $start_with.'_a_'.$ap_profile_id.'_'.$exit_id;
                Log::info("Look for a Profile with name of :" . $name); 
                $profile = $profiles->find()
                    ->where([
                        'Profiles.name' => $name,
                        'Profiles.cloud_id' => $cloud_id                    
                    ])
                    ->first();
                if(!$profile){
                    Log::info("Profile with name of :" . $name . ' not found Add one');
                    $profile = $profiles->newEntity(['name' => $name,'cloud_id' => $cloud_id]);
                    if ($profiles->save($profile)){
                            
                        //Also add a profile component (Our Convention will have it contain 'SimpleAdd_'+<profile_ID>)
                        $pc_name    = $this->profCompPrefix.$profile->id;
                        $e_pc       = $profile_components->newEntity(['name' => $pc_name,'cloud_id' => $profile->cloud_id]);
                        $profile_components->save($e_pc);
                        //Now attach this to the Profile
                        $ne = $user_groups->newEntity(
                            [
                                'username'  => $profile->name,
                                'groupname' => $e_pc->name,
                                'priority'  => 5
                            ]
                        );
                        $user_groups->save($ne);
                        $this->_doRadius($e_pc->name,$ent_cp->connect_and_redirect_url);
                                                          
                    }                             
                }
                //-- We have the realm_id, profile_id and name -- 
                $profile_id = $profile->id;
                $d_pu = [
                    'username'      => $name,
                    'password'      => Configure::read('Cnr.password'),
                    'active'        => true,
                    'language_id'   => 4,
                    'country_id'    => 4,
                    'profile_id'    => $profile_id,
                    'profile'       => $name,
                    'realm_id'      => $realm_id,
                    'realm'         => $realm_name,
                    'cloud_id'      => $cloud_id                                                         
                ];
                $e_pu  = $permanent_users->newEntity($d_pu);
                $permanent_users->save($e_pu);
                $this->_doDynamicClients($cloud_id,$ap_profile_id,$exit_id,$realm_id);                                                                    
            }
        }
    }
    
    private function _clearRadius($groupname){
    
        $radgroupchecks = $this->fetchTable('Radgroupchecks');
        $radgroupreplies = $this->fetchTable('Radgroupreplies');
        
        //Clear any posible left-overs      
        $radgroupchecks->deleteAll(['groupname' => $groupname]);
        $radgroupreplies->deleteAll(['groupname' => $groupname]);
        
    }
           
    private function _doRadius($groupname,$redirect_url){
    
        $radgroupchecks = $this->fetchTable('Radgroupchecks');
        $radgroupreplies = $this->fetchTable('Radgroupreplies');
        
        //Clear any posible left-overs      
        $radgroupchecks->deleteAll(['groupname' => $groupname]);
        $radgroupreplies->deleteAll(['groupname' => $groupname]);
           
        $speed_upload_amount    = Configure::read('Cnr.bw_up');
        $speed_upload_unit      = 'mbps';
        $speed_upload           = $speed_upload_amount * 1024; //Default is kbps
        if($speed_upload_unit == 'mbps'){
            $speed_upload = $speed_upload * 1024;   
        }

        //__ BW-UP __
        $d_up = [
            'groupname' => $groupname,
            'attribute' => 'WISPr-Bandwidth-Max-Up',
            'op'        => ':=',
            'value'     => $speed_upload,
            'comment'   => 'ConnectAndRedirect'
        ];

        $e_up = $radgroupreplies->newEntity($d_up);
        $radgroupreplies->save($e_up);

        $speed_download_amount  = Configure::read('Cnr.bw_down');
        $speed_download_unit    = 'mbps';
        $speed_download         = $speed_download_amount * 1024; //Default is kbps
        if($speed_download_unit == 'mbps'){
            $speed_download = $speed_download * 1024;   
        }

        //__ BW-DOWN __
        $d_down = [
            'groupname' => $groupname,
            'attribute' => 'WISPr-Bandwidth-Max-Down',
            'op'        => ':=',
            'value'     => $speed_download,
            'comment'   => 'ConnectAndRedirect'
        ];

        $e_down = $radgroupreplies->newEntity($d_down);
        $radgroupreplies->save($e_down);
        
        //__ Session-Time __  
        $d_session_time = [
            'groupname' => $groupname,
            'attribute' => 'Session-Timeout',
            'op'        => ':=',
            'value'     => Configure::read('Cnr.session_time'),
            'comment'   => 'ConnectAndRedirect'        
        ];
        $e_st = $radgroupreplies->newEntity($d_session_time);
        $radgroupreplies->save($e_st);
        
        //__ Redirect-Url (WISPr-Redirection-URL) __  
        $d_url = [
            'groupname' => $groupname,
            'attribute' => 'WISPr-Redirection-URL',
            'op'        => ':=',
            'value'     => $redirect_url,
            'comment'   => 'ConnectAndRedirect'        
        ];
        $e_url = $radgroupreplies->newEntity($d_url);
        $radgroupreplies->save($e_url);        
                      
        //__ Fall Through __    
        $d_fall_through = [
            'groupname' => $groupname,
            'attribute' => 'Fall-Through',
            'op'        => ':=',
            'value'     => 'Yes',
            'comment'   => 'ConnectAndRedirect'        
        ];
        $e_ff = $radgroupreplies->newEntity($d_fall_through );
        $radgroupreplies->save($e_ff );       
    }
      
    private function _doDynamicClients($cloud_id,$ap_profile_id,$exit_id,$realm_id){
    
        $aps            = $this->fetchTable('Aps');
        $dynamicClients = $this->fetchTable('DynamicClients');
        $userSettings   = $this->fetchTable('UserSettings');
        $clientRealms   = $this->fetchTable('DynamicClientRealms');
        
        $ap_ids         = [];
        $client_ap_ids  = [];
        
        //Timezone default = 23;
        $tz = 23;
        
        $timezone = $userSettings->find()
            ->where([
                'UserSettings.user_id'  => -1,
                'UserSettings.name'     => 'timezone'
            ])
            ->first();
        
        if($timezone){
            $tz = $timezone->value;
        }      
        
        $ap_list = $aps->find()->where(['Aps.ap_profile_id' => $ap_profile_id])->all();
        foreach($ap_list as $ap){
            $ap_ids[] = $ap->id;      
        }
        
        //APdesk Captive portal is added with the following convention on the nasidentifier 'ap_{ap_id}_cp_{ap_profile_exit_id}'
        $existingClients = $dynamicClients->find()
            ->where([
                'DynamicClients.nasidentifier REGEXP' => "^ap_[0-9]+_cp_{$exit_id}$",
                'DynamicClients.cloud_id'             => $cloud_id
            ])
            ->all();
        foreach($existingClients as $client){       
            
            Log::info("Existing Client" . $client->nasidentifier);
            $ap_id = null;
            if (preg_match('/^ap_(\d+)_cp_(\d+)$/', $client->nasidentifier ?? '', $matches)) {
                $ap_id = (int)$matches[1];  
                $client_ap_ids[] = $ap_id;
                //---Clean UP---         
                if (!in_array($ap_id, $ap_ids)){
                    Log::info("We need to remove DynamicClient" . $client->id);
                }
            } else {
                Log::warning("nasidentifier did not match expected format: " . $client->nasidentifier);
            }                 
        }
        
        foreach($ap_ids as $ap_id){
            if(!in_array($ap_id, $client_ap_ids)){           
                $nasid  = 'ap_'.$ap_id.'_cp_'.$exit_id;
                $client = 'APdesk_'.$ap_profile_id.'_'.$nasid;
                Log::info("ADD Dynamic Client " . $client);
                $client_data = [
                    'name'          => $client, 
                    'nasidentifier' => $nasid,
                    'type'          => 'CoovaMeshdesk',
                    'timezone'      => $tz,
                    'cloud_id'      => $cloud_id                   
                ];
                $e_dc = $dynamicClients->newEntity($client_data);
                $dynamicClients->save($e_dc);
                 
                //---Add a Realm mapping---
                $dcr['dynamic_client_id']   = $e_dc->id;
                $dcr['realm_id']            = $realm_id;
                $realmEntity                = $clientRealms->newEntity($dcr);
                $clientRealms->save($realmEntity);              
            }
        }           
    }
       
}

