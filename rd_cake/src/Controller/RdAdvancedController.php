<?php
/**
 * Created by G-edit.
 * User: dirkvanderwalt
 * Date: 07/SEP/2025
 * Time: 00:00
 */
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;

use Cake\Utility\Inflector;
use Cake\I18n\DateTime;

use App\Model\Table\RadchecksTable;
use App\Model\Table\RadusergroupsTable;
use App\Model\Table\RadgroupchecksTable;
use App\Model\Table\RadacctHistoriesTable;
use App\Model\Table\DynamicClientsTable;
use App\Model\Table\TimezonesTable;



class RdAdvancedController extends AppController {

    protected $timezone    = 'UTC'; //Default for timezone
    
    protected RadchecksTable $Radchecks;
    protected RadusergroupsTable $Radusergroups;
    protected RadgroupchecksTable $Radgroupchecks;
    protected RadacctHistoriesTable $RadacctHistories;
    protected DynamicClientsTable $DynamicClients;
    protected TimezonesTable $Timezones;
    
    public function initialize():void{
        parent::initialize();
        $this->Radchecks         = $this->fetchTable('Radchecks');
        $this->Radusergroups     = $this->fetchTable('Radusergroups');
        $this->Radgroupchecks    = $this->fetchTable('Radgroupchecks');
        $this->RadacctHistories  = $this->fetchTable('RadacctHistories');
        $this->DynamicClients    = $this->fetchTable('DynamicClients');
        $this->Timezones         = $this->fetchTable('Timezones');
        
        $this->Authentication->allowUnauthenticated([ 'infoForUsername']); 
    }
      
  	//**http://127.0.0.1/cake4/rd_cake/rd-advanced/info-for-username.json?username=clict-to-connect@radiusdesk.com&mac=64-64-4A-DD-07-FC&nasid=ZA-100**
    public function infoForUsername(){
    
        $mac        = $this->request->getQuery('mac');
        $username   = $this->request->getQuery('username');
        $nasid      = $this->request->getQuery('nasid');
        $data       = [];
        $success    = false;
        
        if($mac && $username && $nasid){
            $data       = $this->_getInfo($mac,$username,$nasid);
            $success    = $data['success'];
            unset($data['success']);
        }
     
        $this->set([
            'data'      => $data,
            'success'   => $success
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    
    private function _getInfo($mac,$username,$nasid){
    
        $data = [];
        $data['mac']        = $mac; 
        $data['username']   = $username; 
        $data['nasid']      = $nasid;
        $data['success']    = false; 
        
        $profile = $this->Radchecks->find()
            ->where([
                'username'  => $username,
                'attribute' => 'User-Profile'
            ])
            ->first();
            
        if($profile){
            $profile_name       = $profile->value;
            $data['profile']    = $profile_name;
            $usergroups         = $this->Radusergroups->find()->where(['username' => $profile_name])->all();
            foreach($usergroups as $usergroup){
                $groupchecks = $this->Radgroupchecks->find()->where(['groupname' => $usergroup->groupname, 'attribute LIKE' => 'Rd-Adv-%'])->all();
                foreach($groupchecks as $groupcheck){
                    $data[$groupcheck->attribute] = intval($groupcheck->value);
                    $data['success'] = true;
                }        
            }                  
        }
        
        if($data['success']){
        
            //Find the timezone
            $dynamic_client = $this->DynamicClients->find()->where(['nasidentifier' => $nasid])->first();
            if($dynamic_client){          
                $timezone_id = $dynamic_client->timezone;
                if(intval($timezone_id)>0){
                    $timezone = $this->Timezones->find()->where(['id' => $timezone_id])->first();
                    if($timezone){
                        $this->timezone = $timezone->name;
                    }
                }
            }
            $data['timezone'] = $this->timezone;
            $counts = $this->_countsForUserAndMac($username,$mac,$this->timezone);
            $data   = array_merge($data, $counts);
            
        }
                     
        return $data;  
    }
    
        /**
     * Count records for "today" and "this month" (timezone-aware).
     *
     * @param string $username e.g. 'za-1@radiusdesk.com'
     * @param string $callingStationId e.g. '64-64-4A-DD-07-AA'
     * @param string|\DateTimeZone $tz e.g. 'Africa/Johannesburg'
     * @param string $tsField timestamp column in RadacctHistories (default 'timestamp')
     * @return array ['day' => int, 'month' => int]
     */
    private function _countsForUserAndMac(string $username, string $callingStationId, $tz): array{

        $tsField    = 'acctstarttime';
        
        // Local (user) time boundaries
        $nowLocal    = DateTime::now($tz);
        $dayStartL   = $nowLocal->startOfDay();
        $dayEndL     = $nowLocal->endOfDay();
        $monthStartL = $nowLocal->startOfMonth()->startOfDay();
        $monthEndL   = $nowLocal->endOfMonth()->endOfDay();

        // Convert local bounds to UTC for querying a UTC DB
        $dayStartUTC   = $dayStartL->setTimezone('UTC');
        $dayEndUTC     = $dayEndL->setTimezone('UTC');
        $monthStartUTC = $monthStartL->setTimezone('UTC');
        $monthEndUTC   = $monthEndL->setTimezone('UTC');

        $base = [
            'username'          => $username,
            'callingstationid'  => $callingStationId
        ];       

        // Day count
        $qDay = $this->RadacctHistories->find()
            ->where($base)
            ->where(function($exp) use ($tsField, $dayStartUTC, $dayEndUTC) {
                return $exp->between($tsField, $dayStartUTC, $dayEndUTC);
            });
        $dayCount = $qDay->count();

        // Month count
        $qMonth = $this->RadacctHistories->find()
            ->where($base)
            ->where(function($exp) use ($tsField, $monthStartUTC, $monthEndUTC) {
                return $exp->between($tsField, $monthStartUTC, $monthEndUTC);
            });
        $monthCount = $qMonth->count();

        return ['dayCount' => $dayCount, 'monthCount' => $monthCount];
    }
           
}
