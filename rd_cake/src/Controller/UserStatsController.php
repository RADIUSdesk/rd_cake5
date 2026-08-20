<?php
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;
use Cake\I18n\DateTime;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use App\Model\Table\UserStatsTable;
use App\Model\Table\PermanentUsersTable;
use App\Model\Table\RealmsTable;
use App\Model\Table\NasTable;
use App\Model\Table\DynamicClientsTable;
use App\Model\Table\TimezonesTable;


class UserStatsController extends AppController {

    protected $main_model   = 'UserStats';
    protected $time_zone    = 'UTC'; //Default for timezone
    
    protected UserStatsTable $UserStats;
    protected PermanentUsersTable $PermanentUsers;
    protected RealmsTable $Realms;
    protected NasTable $Nas;
    protected DynamicClientsTable $DynamicClients;
    protected TimezonesTable $Timezones;


    protected  $fields = [
                        'data_in' => 'sum(UserStats.acctinputoctets)',
                        'data_out' => 'sum(UserStats.acctoutputoctets)',
                        'total' => 'sum(UserStats.acctoutputoctets)+ sum(UserStats.acctinputoctets)',
          ];

    public function initialize():void
    {
        parent::initialize();
        
        $this->UserStats        = $this->fetchTable('UserStats');
        $this->PermanentUsers   = $this->fetchTable('PermanentUsers');
        $this->Realms           = $this->fetchTable('Realms');
        $this->Nas              = $this->fetchTable('Nas');
        $this->DynamicClients   = $this->fetchTable('DynamicClients');
        $this->Timezones            = $this->fetchTable('Timezones');
        
        $this->loadComponent('Aa');
    }

    public function index(){

        $span   = 'daily';      //Can be daily weekly or monthly
        $day    = $this->request->getQuery('day'); //day will be in format 'd/m/Y'      
        if($day){
            $ft_day = DateTime::createFromFormat('d/m/Y',$day);     
        }else{
            $ft_day = DateTime::now();
        }    
        
        if(null !== $this->request->getQuery('span')){
            $span = $this->request->getQuery('span');
        }

        $ret_info = [];

        //Daily Stats
        if($span == 'daily'){
            $ret_info   = $this->_getDaily($ft_day);
        }

        //Weekly
        if($span == 'weekly'){
           $ret_info    = $this->_getWeekly($ft_day);
        }

        //Monthly
        if($span == 'monthly'){
            $ret_info    = $this->_getMonthly($ft_day);  
        }
        
        if($ret_info){
            $this->set([
                'items'         => $ret_info['items'],
                'success'       => true,
                //This is actually the correct way / place for meta-data
                'metaData'      => [
                    'totalIn'       => $ret_info['total_in'],
                    'totalOut'      => $ret_info['total_out'],
                    'totalInOut'    => $ret_info['total_in_out'],
                ],
                'totalIn'       => $ret_info['total_in'],
                'totalOut'      => $ret_info['total_out'],
                'totalInOut'    => $ret_info['total_in_out']
            ]);
            
        }else{
            $this->set([
                'success'       => false
            ]);
        }
        $this->viewBuilder()->setOption('serialize', true); 
    }

    private function _getDaily($ft_day){
    
        $items          = [];
        $total_in       = 0;
        $total_out      = 0;
        $total_in_out   = 0;
        $start          = 0;

        $base_search    = $this->_base_search();
       
        $day_end        = $ft_day->endOfDay();//->i18nFormat('yyyy-MM-dd HH:mm:ss');    
        $slot_start     = $ft_day->startOfDay(); //Prime it      
        $this->_setTimeZone();
        
        while($slot_start < $day_end){
        
            $slot_start_h_m = $slot_start->i18nFormat("E\nHH:mm");
            $slot_start_txt = $slot_start->i18nFormat('yyyy-MM-dd HH:mm:ss');
            $slot_end_txt   = $slot_start->addHours(1)->subSeconds(1)->i18nFormat('yyyy-MM-dd HH:mm:ss');
            $where          = $base_search;            
            $query          = $this->{$this->main_model}->find();
            $slot_start     = $slot_start->addHours(1); 
                      
            $time_start = $query->func()->CONVERT_TZ([
                "'$slot_start_txt'"     => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
            
            $time_end = $query->func()->CONVERT_TZ([
                "'$slot_end_txt'"       => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
                      
            array_push($where, ["created >=" => $time_start]);
            array_push($where, ["created <=" => $time_end]);
            
            $query = $this->{$this->main_model}->find();
            
            $query->select($this->fields)->where($where);
          
            $q_r = $query->first();
             
            if($q_r){
                 
                $d_in           = $q_r->data_in;
                $total_in       = $total_in + $d_in;

                $d_out          = $q_r->data_out;
                $total_out      = $total_out + $d_out;
                
                $total_in_out   = $total_in_out + ($d_in + $d_out);
                
                array_push($items, ['id' => $start, 'time_unit' => $slot_start_h_m, 'data_in' => $d_in, 'data_out' => $d_out]);
            }
            $start++;
        }
        return(['items' => $items, 'total_in' => $total_in, 'total_out' => $total_out, 'total_in_out' => $total_in_out]);
    }

    private function _getWeekly($ft_day){
        $items          = [];
        $total_in       = 0;
        $total_out      = 0;
        $total_in_out   = 0;
        $week_end       = $ft_day->endOfWeek();//->i18nFormat('yyyy-MM-dd HH:mm:ss');    
        $slot_start     = $ft_day->startOfWeek(); //Prime it 
        $count          = 0;
        $base_search    = $this->_base_search();
        $days           = ["Monday", "Tuesday","Wednesday", "Thusday", "Friday", "Saturday", "Sunday"];
        $this->_setTimeZone();
       
        while($slot_start < $week_end){
        
            $slot_start_h_m     = $slot_start->i18nFormat("eee dd MMM");
            $where              = $base_search; 
            $slot_start_txt     = $slot_start->i18nFormat('yyyy-MM-dd HH:mm:ss');
            $slot_end_txt       = $slot_start->addDays(1)->subSeconds(1)->i18nFormat('yyyy-MM-dd HH:mm:ss'); //Our interval is one day
              
            $query = $this->{$this->main_model}->find();
            $time_start = $query->func()->CONVERT_TZ([
                "'$slot_start_txt'"     => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
            
            $time_end = $query->func()->CONVERT_TZ([
                "'$slot_end_txt'"       => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
            
            $where  = $base_search;   
            array_push($where, ["created >=" => $time_start]);
            array_push($where, ["created <=" => $time_end]);
            
            $q_r = $this->{$this->main_model}->find()
                ->select($this->fields)
                ->where($where)
                ->first();

            if($q_r){
                $d_in           = $q_r->data_in;
                $total_in       = $total_in + $d_in;

                $d_out          = $q_r->data_out;
                $total_out      = $total_out + $d_out;
                $total_in_out   = $total_in_out + ($d_in + $d_out);
                array_push($items, ['id' => $count, 'time_unit' => $slot_start_h_m, 'data_in' => $d_in, 'data_out' => $d_out]);
            }

            //Get the nex day in the slots (we move one day on)
            $slot_start         = $slot_start->addDays(1);
            
            $count++;
        }
        return(['items' => $items, 'total_in' => $total_in, 'total_out' => $total_out, 'total_in_out' => $total_in_out]);
    }

    private function _getMonthly($ft_day){
    
        $items          = [];
        $total_in       = 0;
        $total_out      = 0;
        $total_in_out   = 0;                
        if(property_exists($this,'data_limit_active')){ 
            $slot_start  = $this->_start_of_month($ft_day,$this->start_of_month,$this->start_hour,$this->start_minute);
            $month_end   = $slot_start->addMonths(1)->subSeconds(1);
        }else{
            $month_end      = $ft_day->endOfMonth();//->i18nFormat('yyyy-MM-dd HH:mm:ss');    
            $slot_start     = $ft_day->startOfMonth(); //Prime it 
        }
        
        $base_search    = $this->_base_search();
        $id_counter     = 1;       
        $this->_setTimeZone();  
        
       
        while($slot_start < $month_end){
        
            $where              = $base_search;
            $slot_start_h_m     = $slot_start->i18nFormat("dd MMM");  
            $slot_start_txt     = $slot_start->i18nFormat('yyyy-MM-dd HH:mm:ss');
            $slot_end_txt       = $slot_start->addDays(1)->subSeconds(1)->i18nFormat('yyyy-MM-dd HH:mm:ss'); //Our interval is one day
            
            $query = $this->{$this->main_model}->find();
            $time_start = $query->func()->CONVERT_TZ([
                "'$slot_start_txt'"     => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
            
            $time_end = $query->func()->CONVERT_TZ([
                "'$slot_end_txt'"       => 'literal',
                "'$this->time_zone'"    => 'literal',
                "'+00:00'"              => 'literal',
            ]);
                       
            array_push($where, ["created >=" => $time_start]);
            array_push($where, ["created <=" => $time_end]);
                
            $q_r = $this->{$this->main_model}->find()->select($this->fields)->where($where)->first();
            if($q_r){   
                $d_in           = $q_r->data_in;
                $total_in       = $total_in + $d_in;
                $d_out          = $q_r->data_out;
                $total_out      = $total_out + $d_out;
                $total_in_out   = $total_in_out + ($d_in + $d_out);
                array_push($items, ['id' => $id_counter, 'time_unit' => $slot_start_h_m, 'data_in' => $d_in, 'data_out' => $d_out]);
            }
            
            $slot_start = $slot_start->addDays(1);
            $id_counter ++;
        }    
        //$base_search    = $this->base_search; //FIXME WE DONT NEED THIS
        return(['items' => $items, 'total_in' => $total_in, 'total_out' => $total_out, 'total_in_out' => $total_in_out]);
    }

    private function _base_search(){

        $type           = 'permanent';
        $base_search    = [];
        $username       = $this->request->getQuery('username');

        if(null !== $this->request->getQuery('type')){
            $type = $this->request->getQuery('type');
            //Permanent users an vouchers
            if(($type == 'permanent')||($type == 'voucher')||($type == 'user')||($type == 'activity_viewer')){
            
                if($this->request->getQuery('permanent_id')!== null){
                    $p_id = $this->request->getQuery('permanent_id');
                    $q_r = $this->PermanentUsers->find()->where(['PermanentUsers.id' => $p_id])->first();
                    if($q_r){
                        $username = $q_r->username;
                    }                    
                }
                array_push($base_search, ['UserStats.username' => $username]);
                
            }
            //Devices
            if($type == 'device'){
                array_push($base_search, ['UserStats.callingstationid' => $username]);
            }
            //Realms
            if($type == 'realm'){

                $q_r = $this->Realms->find()->where(['Realms.id' => $username])->first();

                if($q_r){
                    $realm_name = $q_r->name;
                    array_push($base_search, ['UserStats.realm' => $realm_name]);
                }
            }
            //Nas
            if($type == 'nas'){

                $q_r = $this->Nas->find()->where(['Nas.id' => $username])->first();

                if($q_r){ 
                    $nas_identifier = $q_r->nasidentifier;
                    array_push($base_search, ['UserStats.nasidentifier' => $nas_identifier]);
                }
            }
            
            //Dynamic clients
            if($type == 'dynamic_client'){
                $q_r = $this->DynamicClients->find()->where(['DynamicClients.id' => $username])->first();
                if($q_r){     
                    $nas_identifier = $q_r->nasidentifier;
                    $this->item_name= $nas_identifier;
                    if($q_r->data_limit_active){
                        $this->data_limit_active = $q_r->data_limit_active;
                        $this->start_of_month    = $q_r->data_limit_reset_on;
                        $this->start_hour        = $q_r->data_limit_reset_hour; 
                        $this->start_minute      = $q_r->data_limit_reset_minute;  
                    }
                    array_push($base_search, ['UserStats.nasidentifier' => $nas_identifier]);
                }
            }
            
            
            //Specific NAS 
            if($type == 'nas_id'){

                $q_r = $this->DynamicClients->find()->where(['DynamicClients.nasidentifier' => $username])->first();

                if($q_r){     
                    $nas_identifier = $q_r->nasidentifier;
                    $this->item_name= $nas_identifier;
                    if($q_r->data_limit_active){
                        $this->data_limit_active = $q_r->data_limit_active;
                        $this->start_of_month    = $q_r->data_limit_reset_on;
                        $this->start_hour        = $q_r->data_limit_reset_hour; 
                        $this->start_minute      = $q_r->data_limit_reset_minute;  
                    }
                    array_push($base_search, ['UserStats.nasidentifier' => $nas_identifier]);
                }
            }
            
                 
        }
        $this->base_search = $base_search;
        return $base_search;
    }
    
    private function _setTimezone(){    
        //HEADSUP This might cause problems in future since its dependent on the ID value in the config file
        //ALSO We need to modify the wizard to populate the dynamic_clients timzone value
        
        if($this->request->getQuery('timezone_id')){
            $tz_id = $this->request->getQuery('timezone_id');
            $ent_tz = $this->{'Timezones'}->find()->where(['Timezones.id' => $tz_id])->first();
            if($ent_tz){
                $this->time_zone = $ent_tz->name;
                return; //No need to go further
            }      
        }
        
         
        $where      = $this->base_search;
        $q_r        = $this->UserStats->find()->select(['UserStats.nasidentifier'])->where($where)->orderBy(['UserStats.created DESC'])->first();
        if($q_r){
            $nasidentifier  = $q_r->nasidentifier;
            $e_dc        = $this->{'DynamicClients'}->find()
                            ->where(['DynamicClients.nasidentifier' => $nasidentifier])
                            ->first();
            if($e_dc){
                if($e_dc !== ''){
                    $tz_id = $e_dc->timezone;
                    if($tz_id != ''){
                        $ent_tz = $this->{'Timezones'}->find()->where(['Timezones.id' => $tz_id])->first();
                        if($ent_tz){
                            $this->time_zone = $ent_tz->name;
                        }
                    }
                } 
            }
        }
    }

}
