<?php
/**
 * Created by G-edit.
 * User: dirkvanderwalt
 * Date: 14/November/2025
 * Time: 00:00
 */
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\Utility\Inflector;
use Cake\I18n\DateTime;
use Cake\I18n\Time;

use App\Model\Table\ApVpnConnectionsTable;
use App\Model\Table\ApVpnStatsTable;
use App\Model\Table\ApVpnSessionsTable;
use App\Model\Table\TimezonesTable;

class VpnReportsController extends AppController {


    protected $mode         = 'ap';
    protected $time_zone    = 'UTC'; //Default for timezone
    protected $span         = 'hour';
    
    protected $fields       = [
        't_bytes'       => 'sum(bytes)',
    ];
    
    protected ApVpnConnectionsTable $ApVpnConnections;
    protected ApVpnStatsTable $ApVpnStats;
    protected ApVpnSessionsTable $ApVpnSessions;
    protected TimezonesTable $Timezones;
    
    public function initialize():void{
        parent::initialize();
        $this->ApVpnConnections    = $this->fetchTable('ApVpnConnections');
        $this->ApVpnStats          = $this->fetchTable('ApVpnStats');
        $this->ApVpnSessions       = $this->fetchTable('ApVpnSessions');
        $this->Timezones           = $this->fetchTable('Timezones');   
        $this->loadComponent('Aa');
        $this->loadComponent('JsonErrors');
    }
 
                   
  	public function indexDataView(){
        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if (!$user) {
            return;
        }
        
        $data   = [];        
        $ap_id  = $this->request->getQuery('ap_id');
        $this->_setTimeZone();
              
        if($ap_id){   
            $vpnConnections = $this->{'ApVpnConnections'}->find()
                ->where(['ApVpnConnections.ap_id' =>$ap_id])
                ->orderBy(['ApVpnConnections.name ASC']) // The sort order is the opposite or VpnConnectionsController
                ->all();
            foreach($vpnConnections as $vpnConnection){      
                $data[] = $this->vpnConReport($vpnConnection);
            }       
        }
   
        //___ FINAL PART ___
        $this->set([
            'items'         => $data,
            'success'       => true,
            'totalCount'    => count($data)
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    private function vpnConReport($vpnConnection){
    
        $reportData = [];
        $reportData['name']         = $vpnConnection->name;
        $reportData['id']           = $vpnConnection->id;
        $reportData['vpn_type']     = $vpnConnection->vpn_type;
        $reportData['last_seen']    = 'never';
        
        if($this->request->getQuery('span')){
            $this->span = $this->request->getQuery('span');
        }
        
        $reportData['totals']   = []; //Default empty
        if($this->span == 'hour'){
            $stats = $this->_getHourlyData($vpnConnection->id);
            $reportData['graph_items']  = $stats['items'];
            $reportData['totals']       = $stats['totals'];
            [$sessions, $other]         = $this->_getHourlySessions($vpnConnection->id);
            $reportData['sessions']     = $sessions;
            $reportData['other']        = $other;
        } 
        
        if($this->span == 'day'){
            $stats = $this->_getDailyData($vpnConnection->id);
            $reportData['graph_items']  = $stats['items'];
            $reportData['totals']       = $stats['totals'];
            [$sessions, $other]         = $this->_getDailySessions($vpnConnection->id);
            $reportData['sessions']     = $sessions;
            $reportData['other']        = $other;
        }
        
        if($this->span == 'week'){
            $stats = $this->_getWeeklyData($vpnConnection->id);
            $reportData['graph_items']  = $stats['items'];
            $reportData['totals']       = $stats['totals'];
            [$sessions, $other]         = $this->_getWeeklySessions($vpnConnection->id);
            $reportData['sessions']     = $sessions;
            $reportData['other']        = $other;
        }           
        return $reportData;   
    } 
    private function _getSessions($connectionId,$duration){
    
        $otherData   = [];   
        $currentTime = DateTime::now();
        $slotStart   = $currentTime->subHours($duration);       
        $query       = $this->ApVpnSessions->find();
        $sessions    = $query->where(['OR' => [
                    [
                        'ApVpnSessions.starttime >='            => $slotStart,
                        'ApVpnSessions.ap_vpn_connection_id'    => $connectionId
                    ],
                    [
                        'ApVpnSessions.stoptime IS NULL',
                        'ApVpnSessions.ap_vpn_connection_id'    => $connectionId
                    ]
                ]
            ])->all();
                  
        foreach($sessions as $session){
            $starttime             = $session->starttime;
            $session->starttime    = $session->starttime->setTimezone($this->time_zone)->format('Y-m-d H:i:s');
            $last_update           = $starttime->addSeconds($session->sessiontime);
            $session->last_contact = $last_update->setTimezone($this->time_zone)->format('Y-m-d H:i:s'); 
            $session->last_contact_in_words = $last_update
                ->setTimezone($this->time_zone)
                ->timeAgoInWords([
                    'accuracy' => 'minute',
                    'end' => '1 day'  // after 1 day it becomes: "on 2025-01-01"
                ]); 
            
            $otherData['last_contact'] = $session->last_contact;
            $otherData['last_contact_in_words'] = $session->last_contact_in_words;
                  
            if($session->stoptime){
                $session->stoptime   = $session->stoptime->setTimezone($this->time_zone)->format('Y-m-d H:i:s');
            }else{
                $session->open_session  = true;
                $session->stale_session = true;
                if ($currentTime->diffInMinutes($last_update) < 25) {
                        // difference is less than 25 minutes
                        $session->stale_session = false;
                }
                $otherData['open_session'] = $session->open_session;
                $otherData['stale_session'] = $session->stale_session;                                
            }
        }                     
        return [$sessions,$otherData];    
    }
    
    private function _getData($connectionId, $interval, $duration){
        $items = [];
        $start = 1;
        $currentTime = DateTime::now();
        $slotStart   = $currentTime->subHours($duration);
        $totalTx = 0;
        $totalRx = 0;

        while ($slotStart < $currentTime) {
            $slotEnd            = $slotStart->addMinutes($interval)->subSeconds(1);
            $formattedSlotStart = $slotStart->i18nFormat("E\nHH:mm", $this->time_zone);

            $whereConditions = [
                'ApVpnStats.ap_vpn_connection_id' => $connectionId,
                'modified >=' => $slotStart,
                'modified <=' => $slotEnd
            ];

            $query = $this->ApVpnStats->find();
            $result = $query->select([
                'tx_bytes'  => $query->func()->sum('tx_bytes'),
                'rx_bytes'  => $query->func()->sum('rx_bytes')
            ])->where($whereConditions)->first();

            if ($result) {
                $result->id = $start;
                $result->slot_start_txt = $formattedSlotStart;
                $result->time_unit = $formattedSlotStart;

                // Define the list of properties to cast to integers
                $propertiesToCast = [
                    'tx_bytes', 'rx_bytes'
                ];

                // Cast each property to an integer
                foreach ($propertiesToCast as $property) {
                    $result->{$property} = (int)$result->{$property};
                }
                
                $totalTx += $result->tx_bytes;
                $totalRx += $result->rx_bytes;
                $items[] = $result;
            }

            $slotStart = $slotStart->addMinutes($interval);
            $start++;
        }

        return [
            'items' => $items,
            'totals' => [
                'tx_bytes'      => $totalTx,
                'rx_bytes'      => $totalRx,
                'total_bytes'   => $totalTx + $totalRx     
            ]
        ];
    }
    
    //--------------------
    private function _getHourlyData($connectionId){

        return $this->_getData($connectionId, 10, 1);
    }

    private function _getDailyData($connectionId){

        return $this->_getData($connectionId, 60, 24);
    }
    
    private function _getWeeklyData($connectionId){
        // One week duration in hours (7 days * 24 hours)
        $duration = 7 * 24;
        // Interval for weekly data in minutes (24 hours)
        $interval = 24*60;
        return $this->_getData($connectionId, $interval, $duration);
    }  
    
    //------------------
    private function _getHourlySessions($connectionId){
        return $this->_getSessions($connectionId,1);
    }

    private function _getDailySessions($connectionId){
        return $this->_getSessions($connectionId, 24);
    }
    
    private function _getWeeklySessions($connectionId){
        $duration = 7 * 24;
        return $this->_getSessions($connectionId, $duration);
    }  
        
    private function _setTimezone(){ 
        //New way of doing things by including the timezone_id
        if($this->request->getQuery('timezone_id') != null){
            $tz_id = $this->request->getQuery('timezone_id');
            $ent = $this->{'Timezones'}->find()->where(['Timezones.id' => $tz_id])->first();
            if($ent){
                $this->time_zone = $ent->name;
            }
        }
    }        
}

?>
