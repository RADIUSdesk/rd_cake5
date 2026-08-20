<?php
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;
use Cake\Core\Configure;
use Cake\Utility\Inflector;
use Cake\ORM\Query;

use Cake\Datasource\ConnectionManager;

use App\Model\Table\RadacctsTable;
use App\Model\Table\UsersTable;
use App\Model\Table\TimezonesTable;
use App\Model\Table\RealmsTable;
use App\Model\Table\VouchersTable;
use App\Model\Table\RadchecksTable;
use App\Model\Table\PermanentUsersTable;

class RadacctsFastController extends AppController {

    protected $main_model 	= 'Radaccts';
    protected $time_zone    = 'UTC'; //Default for timezone 
    protected  $fields  	= [
        'total_in' => 'sum(Radaccts.acctinputoctets)',
        'total_out' => 'sum(Radaccts.acctoutputoctets)',
        'total' => 'sum(Radaccts.acctoutputoctets) + sum(Radaccts.acctinputoctets)',
    ];
    
    protected RadacctsTable $Radaccts;
    protected UsersTable $Users;
    protected TimezonesTable $Timezones;
    protected RealmsTable $Realms;
    protected VouchersTable $Vouchers;
    protected RadchecksTable $Radchecks;
    protected PermanentUsersTable $PermanentUsers;

    public function initialize():void
    {
        parent::initialize();       
        $this->Radaccts     = $this->fetchTable('Radaccts');
        $this->Users        = $this->fetchTable('Users');
        $this->Timezones    = $this->fetchTable('Timezones');        
        $this->Realms       = $this->fetchTable('Realms');
        $this->Vouchers     = $this->fetchTable('Vouchers');
        $this->Radchecks    = $this->fetchTable('Radchecks');
        $this->PermanentUsers   = $this->fetchTable('PermanentUsers');
        
        $this->loadComponent('Aa');
        $this->loadComponent('Kicker');
        $this->loadComponent('Counters');
        $this->loadComponent('TimeCalculations');
    }
    
    public function index(){
  
        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }

        $user_id    = $user['id'];
        
         $req_q = $this->request->getQuery();
         	
      	$realm_clause = [];
      	$found_realm  = false;
     	$q_realms  = $this->Realms->find()->where(['Realms.cloud_id' => $req_q['cloud_id']])->all();
      	foreach($q_realms as $r){
      		$found_realm = true;
          	$realm_clause[] = $r->name;
     	}
     	if(!$found_realm){
     		$this->Aa->fail_no_rights("No Realms owned by this cloud"); //If the list of realms for this cloud is empty reject the request
        	return false;
     	}
     	
     	$where[] = ['realm IN' => $realm_clause]; 
        
        //Make sure there is a cloud id
        if(!isset($req_q['cloud_id'])){
        	$this->Aa->fail_no_rights("Required Cloud ID Missing");
        	return false;
       	}
       	
       	//====== Only_connectd filter ==========
        $only_connected = false;
        $extra_info     = false;
        if($this->request->getQuery('only_connected')){
            if($this->request->getQuery('only_connected') == 'true'){
                $only_connected = true;
                $where[] = $this->main_model.".acctstoptime IS NULL";
            }
        }
        
        //===== PAGING (MUST BE LAST) ======
        $limit  = 50;   //Defaults
        $page   = 1;
        $offset = 0;

        if(null !== $this->request->getQuery('limit')){
            $limit  = $this->request->getQuery('limit');
            $page   = $this->request->getQuery('page');
            $offset = $this->request->getQuery('start');
        }
                   
        $query      = $this->Radaccts->find()->where($where);
        $results    = $query->page($page)->limit($limit)->offset($offset)->all();
        $total      = $query->count();
        
        $this->set([
            'items'         => $results,
            'success'       => true,
            'totalCount'    => $total,
            'totalIn'       => 0,
            'totalOut'      => 0,
            'totalInOut'    => 0,
            'metaData'      => [
                'totalIn'       => 0,
                'totalOut'      => 0,
                'totalInOut'    => 0,
                'totalCount'    => $total
            ]
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    } 
	 
    public function indexZ(){
        //-- Required query attributes: token;
        //-- Optional query attribute: sel_language (for i18n error messages)
        //-- also LIMIT: limit, page, start (optional - use sane defaults)
        //-- FILTER <- This will need fine tunning!!!!
        //-- AND SORT ORDER <- This will need fine tunning!!!!

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }

        $user_id    = $user['id'];
        

        $fields = [
            'total_in' => 'sum(Radaccts.acctinputoctets)',
            'total_out' => 'sum(Radaccts.acctoutputoctets)',
            'total' => 'sum(Radaccts.acctoutputoctets) + sum(Radaccts.acctinputoctets)',
        ];

        $query = $this->{$this->main_model}->find();

        if(!$this->_build_common_query($query, $user)){
        	return;
        }
        
        //==== TIMEZONE ======
        $tz = 'UTC';
        
        if($this->request->getQuery('timezone_id') != null){
            $tz_id = $this->request->getQuery('timezone_id');
            $ent = $this->{'Timezones'}->find()->where(['Timezones.id' => $tz_id])->first();
            if($ent){
                $tz = $ent->name;
            }
        }
        
        //===== PAGING (MUST BE LAST) ======
        $limit  = 50;   //Defaults
        $page   = 1;
        $offset = 0;

        if(null !== $this->request->getQuery('limit')){
            $limit  = $this->request->getQuery('limit');
            $page   = $this->request->getQuery('page');
            $offset = $this->request->getQuery('start');
        }

        $query->page($page);
        $query->limit($limit);
        $query->offset($offset);
        
        $total  = $query->count();
        $q_r    = $query->all();
        
        exit;
                      
        $query_total = $this->{$this->main_model}->find();
        if(!$this->_build_common_query($query_total, $user,true)){
        	return;
        }
        $t_q    = $query_total->select($fields)->first();

        $items  = [];

        foreach($q_r as $i){
              
            $i->user_type     = 'unknown';
            $i->online_human  = '';

            if($i->acctstoptime == null){
                $online_time        = time()-strtotime($i->acctstarttime);
                $i->active          = true; 
                $i->online_human    = $this->TimeCalculations->time_elapsed_string($i->acctstarttime,false,true);
            }else{
                $online_time    = $i->acctstoptime->setTimezone($tz)->format('Y-m-d H:i:s');
                $i->active      = false;
            }           
            $i->acctstarttime   = $i->acctstarttime->setTimezone($tz)->format('Y-m-d H:i:s');
            $i->id              = $i->radacctid;
            $i->acctstoptime    = $online_time;
            
            if($i->permanent_user){
            
                $i->pu_active   = $i->permanent_user->active;
                $i->pu_site     = $i->permanent_user->site;
                $i->pu_extra_name = $i->permanent_user->extra_name;
                $i->pu_extra_value = $i->permanent_user->extra_value;
            }
                                          
            array_push($items,$i);
           
        }                
        $this->set([
            'items'         => $items,
            'success'       => true,
            'totalCount'    => $total,
         //   'totalIn'       => $t_q->total_in,
          //  'totalOut'      => $t_q->total_out,
         //   'totalInOut'    => $t_q->total,
            'metaData'      => [
            //    'totalIn'       => $t_q->total_in,
            //    'totalOut'      => $t_q->total_out,
             //   'totalInOut'    => $t_q->total,
                'totalCount'    => $total
            ]
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }

    public function delete($id = null) {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        $req_d			= $this->request->getData();

        //FIXME We need to find a creative wat to determine if the Access Provider can delete this accounting data!!!
	     if(isset($req_d['id'])){
            //$this->_voucher_status_check($req_d['id']);
            $this->{$this->main_model}->query()->delete()->where(['radacctid' => $req_d['id']])->execute();
        }else{                          //Assume multiple item delete
            foreach($req_d as $d){ 
                //$this->_voucher_status_check($d['id']);
                $this->{$this->main_model}->query()->delete()->where(['radacctid' => $d['id']])->execute();
            }         
        }

        $fail_flag = false;
        if($fail_flag == true){
            $this->set([
                'success'   => false,
                'message'   => __('Could not delete some items'),
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }else{
            $this->set([
                'success' => true
            ]);
            $this->viewBuilder()->setOption('serialize', true);
        }
	}
	
	
	public function kickActiveUsername(){
		//__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        //It should have a token if it made it to here :-)
        $q_data		= $this->request->getQuery();
        $token 		= $q_data['token'];
        $username 	= $q_data['username'];
		$data		= [];
		
		
		$e_username = $this->{$this->main_model}->find()->where(['Radaccts.username' => $username,'Radaccts.acctstoptime IS NULL'])->all();
		foreach($e_username as $ent){
			$data = $this->Kicker->kick($ent,$token); //Sent it to the Kicker (We include the token in order to make API calls if needed
		}	
		$this->set([
            'success'       => true,
            'data'          => $data
        ]);
        $this->viewBuilder()->setOption('serialize', true);
	
	}
	

    public function kickActive(){
    
        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }
        
        //It should have a token if it made it to here :-)
        $q_data	= $this->request->getQuery();
        $token 	= $q_data['token'];

        
        $some_session_closed    = false;
        $count                  = 0;
        $msg                    = 'Could not locate session';
        $data                   = ['title' => 'Session Not Found', 'message' => $msg, 'type' =>'warn'];
        $req_q      			= $this->request->getQuery();

        foreach(array_keys($req_q) as $key){
            if(preg_match('/^\d+/',$key)){
                $ent = $this->{$this->main_model}->find()->where(['Radaccts.radacctid' => $key])->first();
                $count++;               
                if($ent->acctstoptime !== null){
                    $some_session_closed = true;
                }else{
                    $data = $this->Kicker->kick($ent,$token); //Sent it to the Kicker (We include the token in order to make API calls if needed
                }
            }
        }  
        
        if($count >0){      
            $data = ['title' => 'Disconnect Sent', 'message' => 'Disconnect Instructions Sent', 'type' =>'info'];
        }   

        if(($some_session_closed)&&($count>0)){
            $msg = 'Sessions Is already Closed';
            if($count > 1){
                $msg = 'Some Sessions Are already Closed';
            }
            $data = ['title' => 'Session Closed Already', 'message' => $msg, 'type' =>'warn'];
        }
    
        $this->set([
            'success'       => true,
            'data'          => $data
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }

    public function closeOpen(){

        //__ Authentication + Authorization __
        $user = $this->_ap_right_check();
        if(!$user){
            return;
        }       
        $req_q    = $this->request->getQuery();
        foreach(array_keys($req_q) as $key){
            if(preg_match('/^\d+/',$key)){
                $qr = $this->{$this->main_model}->find()->where(['Radaccts.radacctid' => $key])->first();
                if($qr){
                    if($qr->acctstoptime == null){
                        $now = date('Y-m-d h:i:s');
                        $d['acctstoptime'] = $now;
						$this->{$this->main_model}->patchEntity($qr,$d);
                        $this->{$this->main_model}->save($qr);
                    }
                }  
            }
        }

        $this->set([
            'success' => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }

    //--------- END BASIC CRUD ---------------------------

    //----- Menus ------------------------
    public function menuForGrid(){

        $user = $this->Aa->user_for_token($this);
        if(!$user){   //If not a valid user
            return;
        }

        $timezone_id    = 316; //London by default  
        $e_user         = $this->{'Users'}->find()->where(['Users.id' => $user['id']])->first();
        if($e_user->timezone_id){
            $timezone_id = $e_user->timezone_id;
        } 

        $scale = 'large';

        $menu = [
                ['xtype' => 'buttongroup','title' => null, 'items' => [
                    ['xtype' =>  'splitbutton',  'glyph' => Configure::read('icnReload'), 'scale'   => $scale, 'itemId'    => 'reload',   'tooltip'    => __('Reload'),
                        'menu'  => [
                            'items' => [
                                '<b class="menu-title">'.__('Reload every').':</b>',
                              //  array( 'text'   => _('Cancel auto reload'),   'itemId' => 'mnuRefreshCancel', 'group' => 'refresh', 'checked' => true ),
                                ['text'  => __('30 seconds'),      'itemId'    => 'mnuRefresh30s', 'group' => 'refresh','checked' => false ],
                                ['text'  => __('1 minute'),        'itemId'    => 'mnuRefresh1m', 'group' => 'refresh' ,'checked' => false],
                                ['text'  => __('5 minutes'),       'itemId'    => 'mnuRefresh5m', 'group' => 'refresh', 'checked' => false ],
                                ['text'  => __('Stop auto reload'),'itemId'    => 'mnuRefreshCancel', 'group' => 'refresh', 'checked' => true]
                            ]
                        ]
                ],
                [
                    'xtype' => 'tbseparator'
                ],
                [
                        'xtype'         => 'button',
                         
                        //To list all
                        'glyph'         => Configure::read('icnWatch'),
                        'pressed'       => false,
                        
                        //To list only active
                        //'glyph'         => Configure::read('icnLight'),
                        //'pressed'       => true,
                                
                        'scale'         => $scale,
                        'itemId'        => 'connected',
                        'enableToggle'  => true,
                         
                        'ui'            => 'button-green',  
                        'tooltip'       => __('Show only currently connected')
                ],
                [
                    'xtype' => 'tbseparator'
                ],
                [
                    'xtype'         => 'cmbTimezones', 
                    'width'         => 200, 
                    'itemId'        => 'cmbTimezone',
                    'name'          => 'timezone_id', 
                    'fieldLabel'    => '',
                    'padding'       => '7 0 0 0',
                    'margin'        => 0,
                    'value'         => $timezone_id
                ],
                [
                    'xtype' => 'tbseparator'
                ],
                [
                        'xtype'         => 'button',
                        'glyph'         => Configure::read('icnInfoCircle'),
                        'pressed'       => false,                               
                        'scale'         => $scale,
                        'itemId'        => 'btnInfo',
                        'enableToggle'  => true,
                        'tooltip'       => __('Include more info (loads slower)')
                ],               
                ]],
                ['xtype' => 'buttongroup','title' => null, 'items' => [
                    ['xtype' => 'button', 'glyph'     => Configure::read('icnCsv'), 'scale' => $scale, 'itemId' => 'csv',      'tooltip'=> __('Export CSV')],
                    ['xtype' => 'button', 'glyph'     => Configure::read('icnGraph'), 'scale' => $scale, 'itemId' => 'graph',    'tooltip'=> __('Usage graph')],
                ]],
                ['xtype' => 'buttongroup','title' => null, 'items' => [
                    ['xtype' => 'button', 'glyph'     => Configure::read('icnKick'),'scale' => $scale, 'itemId' => 'kick', 'tooltip'=> __('Kick user off')],
                    ['xtype' => 'button', 'glyph'     => Configure::read('icnClose'),'scale' => $scale, 'itemId' => 'close','tooltip'=> __('Close session')],
                ]],
                [
                    'xtype'   => 'component', 
                    'itemId'  => 'totals',  
                     'tpl'    => [
                        "<div style='font-size:larger;width:400px;'>",
                        "<ul class='fa-ul'>",
                        "<li style='padding:2px;'>",
                        "<span class='fa-li' style='font-family:FontAwesome;'>&#xf1c0</span> {in} in {out} out {total} total</span></li>",
                        "<li style='padding:2px;'><i class='fa-li fa fa-arrow-right'></i> {total_connected} items</li>",
                        "</ul>",
                        "</div>"                    
                    ],
                    'data'   =>  [],
                    'cls'    => 'lblRd'
                ]               
        ];
        

        $this->set([
            'items'         => $menu,
            'success'       => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }

    //______ END EXT JS UI functions ________

  	function _build_common_query($query, $user,$totals=false){ //We add the $totals flag. If it is set to true; we do not do the sort part (breaks with Postgresql)

        $where = [];
        $joins = [];     
        $req_q = $this->request->getQuery();
               
		//Make sure there is a cloud id
        if(!isset($req_q['cloud_id'])){
        	$this->Aa->fail_no_rights("Required Cloud ID Missing");
        	return false;
       	}      	
                      
        //====== Only_connectd filter ==========
        $only_connected = false;
        $extra_info     = false;
        if($this->request->getQuery('only_connected')){
            if($this->request->getQuery('only_connected') == 'true'){
                $only_connected = true;
                array_push($where,$this->main_model.".acctstoptime IS NULL");
            }
        } 
        
        if($this->request->getQuery('extra_info')){
            if($this->request->getQuery('extra_info') == 'true'){
                $extra_info = true;
            }
        } 
                         
        //===== SORT =====
        //Default values for sort and dir
        $sort   = 'Radaccts.username';
        $dir    = 'DESC';
        

        if($this->request->getQuery('sort')){
            //Permanent users (extra info)
            if((str_starts_with($this->request->getQuery('sort'), 'pu_'))&&($extra_info)){
                $pu_sort = $this->request->getQuery('sort');
                $pu_sort = str_replace('pu_','',$pu_sort);
                $sort = 'PermanentUsers.'.$pu_sort;                   
            }else{                  
                $sort = $this->main_model.'.'.$this->request->getQuery('sort');
            }
            //Here we do a trick if we only list active connections since we can't order by null
            if(($sort == 'Radaccts.acctstoptime') && ($only_connected)){
                $sort = 'Radaccts.acctstarttime';
            }
            $dir  = $this->request->getQuery('dir');
        }
        
        if(!$totals){        
          ////  $query->orderBy([$sort => $dir]);
        }

        //==== END SORT ===

        //======= For a specified username filter *Usually on the edit of user / voucher ======
        if($this->request->getQuery('username')){
            $un = $this->request->getQuery('username');
            array_push($where, [$this->main_model.".username" => $un]);
        }

        //======= For a specified callingstationid filter *Usually on the edit of device ======
        if($this->request->getQuery('callingstationid')){
            $cs_id = $this->request->getQuery('callingstationid');
            array_push($where, [$this->main_model.".callingstationid" => $cs_id]);
        }

        //====== REQUEST FILTER =====
        if($this->request->getQuery('filter')){
            $req_q 	= $this->request->getQuery(); 
            $filter = json_decode($req_q['filter']); 

            foreach($filter as $f){
                                      
                //Strings
                if($f->operator == 'like'){
                    //Permanent Users' properties will start with pu_
                    if((str_starts_with($f->property, 'pu_'))&&($extra_info)){
                        $pu_col = $f->property;
                        $pu_col = str_replace('pu_','',$pu_col);
                        $col = 'PermanentUsers.'.$pu_col;                   
                    }else{                 
                        $col = $this->main_model.'.'.$f->property;
                    }
                    array_push($where, ["$col LIKE" => '%'.$f->value.'%']);
                }
                
                //Bools
                if($f->operator == '=='){
                    if(($f->property == 'pu_active')&&($extra_info)){
                        array_push($where, ["PermanentUsers.active" => $f->value]);
                    }
                }
                //Date
                if(($f->operator == 'gt')||($f->operator == 'lt')||($f->operator == 'eq')){
                    //date we want it in "2013-03-12"
                    $col = $this->main_model.'.'.$f->property;
                    if($f->operator == 'eq'){
                        array_push($where, ["DATE($col)" => $f->value]);
                    }

                    if($f->operator == 'lt'){
                        array_push($where, ["DATE($col) <" => $f->value]);
                    }
                    if($f->operator == 'gt'){
                        array_push($where, ["DATE($col) >" => $f->value]);
                    }
                }
                //Lists
                if($f->operator == 'in'){
                    if($f->property == 'user_type'){
                        $list_array = [];
                        foreach($f->value as $filter_list){
                            array_push($list_array, ["Radchecks.attribute" => "Rd-User-Type", "Radchecks.value" => $filter_list]);
                        }
                        array_push($joins, [
                            'table'         => 'radcheck',
                            'alias'         => 'Radcheck',
                            'type'          => 'LEFT',
                            'conditions'    =>  ['(Radchecks.username = Radaccts.callingstationid) OR (Radchecks.username = Radaccts.username)']
                        ]);

                    ////    $query->join($joins);

                        array_push($where, ['OR' => $list_array]);
                    }
                }
            }
        }
        //====== END REQUEST FILTER =====
                
        if($only_connected){
            if($extra_info){     
             ////   $query->contain('PermanentUsers', function (Query $q) {
            ////        return $q
            ////            ->select(['active', 'site','ppsk','extra_name','extra_value']);
            ////    });
            }
         }
           
        //====== CLOUD's Realms FILTER =====       	
      	$realm_clause   = [];
      	$found_realm    = false;
     	$q_realms       = $this->Realms->find()->where(['Realms.cloud_id' => $req_q['cloud_id']])->all();
      	foreach($q_realms as $r){
      		$found_realm = true;
          	$realm_clause[] = $r->name;
     	}
     	if(!$found_realm){
     		$this->Aa->fail_no_rights("No Realms owned by this cloud"); //If the list of realms for this cloud is empty reject the request
        	return false;
     	}
     	$where += ['Radaccts.realm IN' => $realm_clause];            
        $query->where($where); 
        print_r($where);           
        return true;
    }

    function _build_common_queryZZ($query, $user,$totals=false){ //We add the $totals flag. If it is set to true; we do not do the sort part (breaks with Postgresql)

        $where = [];
        $joins = [];     
        $req_q = $this->request->getQuery();
               
		//Make sure there is a cloud id
        if(!isset($req_q['cloud_id'])){
        	$this->Aa->fail_no_rights("Required Cloud ID Missing");
        	return false;
       	}      	
                      
        //====== Only_connectd filter ==========
        $only_connected = false;
        $extra_info     = false;
        if($this->request->getQuery('only_connected')){
            if($this->request->getQuery('only_connected') == 'true'){
                $only_connected = true;
                array_push($where,$this->main_model.".acctstoptime IS NULL");
            }
        } 
        
        if($this->request->getQuery('extra_info')){
            if($this->request->getQuery('extra_info') == 'true'){
                $extra_info = true;
            }
        } 
                         
        //===== SORT =====
        //Default values for sort and dir
        $sort   = 'Radaccts.username';
        $dir    = 'DESC';
        

        if($this->request->getQuery('sort')){
            //Permanent users (extra info)
            if((str_starts_with($this->request->getQuery('sort'), 'pu_'))&&($extra_info)){
                $pu_sort = $this->request->getQuery('sort');
                $pu_sort = str_replace('pu_','',$pu_sort);
                $sort = 'PermanentUsers.'.$pu_sort;                   
            }else{                  
                $sort = $this->main_model.'.'.$this->request->getQuery('sort');
            }
            //Here we do a trick if we only list active connections since we can't order by null
            if(($sort == 'Radaccts.acctstoptime') && ($only_connected)){
                $sort = 'Radaccts.acctstarttime';
            }
            $dir  = $this->request->getQuery('dir');
        }
        
        if(!$totals){        
            $query->orderBy([$sort => $dir]);
        }

        //==== END SORT ===

        //======= For a specified username filter *Usually on the edit of user / voucher ======
        if($this->request->getQuery('username')){
            $un = $this->request->getQuery('username');
            array_push($where, [$this->main_model.".username" => $un]);
        }

        //======= For a specified callingstationid filter *Usually on the edit of device ======
        if($this->request->getQuery('callingstationid')){
            $cs_id = $this->request->getQuery('callingstationid');
            array_push($where, [$this->main_model.".callingstationid" => $cs_id]);
        }

        //====== REQUEST FILTER =====
        if($this->request->getQuery('filter')){
            $req_q 	= $this->request->getQuery(); 
            $filter = json_decode($req_q['filter']); 

            foreach($filter as $f){
                                      
                //Strings
                if($f->operator == 'like'){
                    //Permanent Users' properties will start with pu_
                    if((str_starts_with($f->property, 'pu_'))&&($extra_info)){
                        $pu_col = $f->property;
                        $pu_col = str_replace('pu_','',$pu_col);
                        $col = 'PermanentUsers.'.$pu_col;                   
                    }else{                 
                        $col = $this->main_model.'.'.$f->property;
                    }
                    array_push($where, ["$col LIKE" => '%'.$f->value.'%']);
                }
                
                //Bools
                if($f->operator == '=='){
                    if(($f->property == 'pu_active')&&($extra_info)){
                        array_push($where, ["PermanentUsers.active" => $f->value]);
                    }
                }
                //Date
                if(($f->operator == 'gt')||($f->operator == 'lt')||($f->operator == 'eq')){
                    //date we want it in "2013-03-12"
                    $col = $this->main_model.'.'.$f->property;
                    if($f->operator == 'eq'){
                        array_push($where, ["DATE($col)" => $f->value]);
                    }

                    if($f->operator == 'lt'){
                        array_push($where, ["DATE($col) <" => $f->value]);
                    }
                    if($f->operator == 'gt'){
                        array_push($where, ["DATE($col) >" => $f->value]);
                    }
                }
                //Lists
                if($f->operator == 'in'){
                    if($f->property == 'user_type'){
                        $list_array = [];
                        foreach($f->value as $filter_list){
                            array_push($list_array, ["Radchecks.attribute" => "Rd-User-Type", "Radchecks.value" => $filter_list]);
                        }
                        array_push($joins, [
                            'table'         => 'radcheck',
                            'alias'         => 'Radcheck',
                            'type'          => 'LEFT',
                            'conditions'    =>  ['(Radchecks.username = Radaccts.callingstationid) OR (Radchecks.username = Radaccts.username)']
                        ]);

                        $query->join($joins);

                        array_push($where, ['OR' => $list_array]);
                    }
                }
            }
        }
        //====== END REQUEST FILTER =====
                
        if($only_connected){
            if($extra_info){     
                $query->contain('PermanentUsers', function (Query $q) {
                    return $q
                        ->select(['active', 'site','ppsk','extra_name','extra_value']);
                });
            }
         }
           
        //====== CLOUD's Realms FILTER =====  	
      	$realm_clause = [];
      	$found_realm  = false;
     	$q_realms  = $this->{'Realms'}->find()->where(['Realms.cloud_id' => $req_q['cloud_id']])->all();
      	foreach($q_realms as $r){
      		$found_realm = true;
          	array_push($realm_clause, [$this->main_model.'.realm' => $r->name]);
     	}
     	if($found_realm){
     		array_push($where, ['OR' => $realm_clause]);
     	}else{
     		$this->Aa->fail_no_rights("No Realms owned by this cloud"); //If the list of realms for this cloud is empty reject the request
        	return false;
     	}      
        //====== END Realm FILTER =====        
        
        $query->where($where);  
                
        return true;
    }


   
    private function _voucher_status_check($id){

        //Find the count of this username; if zero check if voucher; if voucher change status to 'new';
        $q_r = $this->{$this->main_model}->find()->contain(['Radchecks'])->where(['Radaccts.radacctid' => $id])->first();
        if($q_r){
            $user_type = 'unknown';
            $un = $q_r->username;
            //Get the user type
            if(count($q_r->radchecks) > 0){
                foreach($q_r->radchecks as $rc){
                    if($rc->attribute == 'Rd-User-Type'){
                        $user_type = $rc->value;
                    }
                }
            }
            //Check if voucher
            if($user_type == 'voucher'){

                $count = $this->{$this->main_model}->find()->where(['Radaccts.username' => $un])->count();
                if($count == 1){
                    $qr = $this->Vouchers->find()->where(['Vouchers.name' => $un])->first();
                    if($qr){
                        $dseries = [];
                        $dseries['id'] = $qr->id;
                        $dseries['status'] = 'new';
                        $dseries['perc_data_used'] = null;
                        $dseries['perc_time_used'] = null;

                        $voucherEntity = $this->Vouchers->newEnity($dseries);
                        $this->Vouchers->save($voucherEntity);

                    }
                }
            }
        }
    }

	private function _find_user_profile($username){
        $profile = false;
        $q_r = $this->Radchecks->find()->where(['Radchecks.username' => $username,'Radchecks.attribute' => 'User-Profile'])->first();
        if($q_r){
            $profile = $q_r->value;
        }
        return $profile;
    }


}
