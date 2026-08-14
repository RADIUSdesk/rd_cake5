<?php
/** 
 * Edited by G-edit.
 * User: dirkvanderwalt
 * Date: 08-AUG-2026
 * Time: 00:00
 */
namespace App\Controller;
use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Utility\Inflector;

use Cake\I18n\Time;
use Cake\Http\Client;

use App\Model\Table\ApsTable;
use App\Model\Table\NodesTable;
use App\Model\Table\ApActionsTable;
use App\Model\Table\NodeActionsTable;
use App\Model\Table\IperfTestsTable;


class IperfTestsController extends AppController{
    
    protected	$node_action_add    = 'http://127.0.0.1/cake4/rd_cake/node-actions/add.json';
    protected	$ap_action_add      = 'http://127.0.0.1/cake4/rd_cake/ap-actions/add.json';
    protected   $server_list        = [
            ['id'   => 1, 'name'    => '192.168.8.176' ],
            ['id'   => 2, 'name'    => '164.160.89.129']  
        ];
        
    protected ApsTable $Aps;
    protected NodesTable $Nodes;
    protected ApActionsTable $ApActions;
    protected NodeActionsTable $NodeActions;
    protected IperfTestsTable $IperfTests;
    
    public function initialize():void{
        parent::initialize();       
        $this->Aps           = $this->fetchTable('Aps');
        $this->Nodes         = $this->fetchTable('Nodes');
        $this->ApActions     = $this->fetchTable('ApActions');
        $this->NodeActions   = $this->fetchTable('NodeActions');
        $this->IperfTests    = $this->fetchTable('IperfTests');
        $this->Authentication->allowUnauthenticated([
            'submitResults'
        ]);               
    }
    
    public function iperfIndex(){
    
        $queryData  = $this->request->getQuery();
        $dev_mode   = $queryData['dev_mode'];
        $dev_id     = $queryData['dev_id'];
        $field      = 'node_id';
        
        if($dev_mode == 'ap'){
            $field = 'ap_id';
        }
    
        $tests = $this->IperfTests->find()
            ->where(["IperfTests.{$field}" => $dev_id])
            ->orderBy(['IperfTests.created DESC'])
            ->all();
               
        $this->set([
            'items'     => $tests,
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    
    }
    
    public function iperfServerList(){
         $this->set([
            'items'     => $this->server_list,
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);
    }
    
    public function iperfDefaults(){
    
        $defaults = [
            'server'    => 1, //ID FROM SERVER LIST
            'port'      => 5201,
            'protocol'  => 'tcp',
            'duration'  => 1, //1-60
            'streams'   => 1, //1-16
            'ping'      => true      
        ];
           
        $this->set([
            'data'     => $defaults,
            'success'  => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);  
    }
    
    public function busyCheck(){
    
        $queryData  = $this->request->getQuery();
        $success    = true;
        $data       = [];
        
        if(isset($queryData['dev_mode'])&& ($queryData['dev_mode'] == 'ap')){
            $ap_id = $queryData['dev_id'];
            $apAction = $this->ApActions->find()
                ->where([
                    'ApActions.status IN'   => ['awaiting', 'fetched'],
                    'ApActions.action'      => 'execute_and_reply',
                    'ApActions.ap_id'       => $ap_id,
                    'ApActions.command LIKE' => '/etc/MESHdesk/utils/iperf_to_rd.lua%'
                ])
                ->first();
            if($apAction){
                $data['status'] = $apAction->status;
                $success = false;
            }                    
        }
              
        $this->set([
            'data'     => $data,
            'success'  => $success,
        ]);
        $this->viewBuilder()->setOption('serialize', true);   
    }
       
    public function startTest(){
    
        $queryData  = $this->request->getQuery();
        $req_d 		= $this->request->getData();
        
        if(isset($req_d['dev_mode'])&& ($req_d['dev_mode'] == 'ap')){
            $this->apSpeedTest($req_d['dev_id']);          
        }
        
        $this->set([
            'data'      => [],
            'success'   => true
        ]);
        $this->viewBuilder()->setOption('serialize', true);      
    }
        
    public function submitResults(){

        $this->request->allowMethod(['post', 'put']);
        $raw  = $this->request->getData();
        if (!is_array($raw)) {
            $this->setResponse($this->response->withStatus(400));
            $this->set('result', ['error' => 'Invalid JSON']);
            $this->viewBuilder()->setOption('serialize', ['result']);
            return;
        }

        // Helper to safely extract nested values
        $get = function($arr, $path, $default = null) {
            $p = explode('.', $path);
            $v = $arr;
            foreach ($p as $k) {
                if (!is_array($v) || !array_key_exists($k, $v)) return $default;
                $v = $v[$k];
            }
            return $v;
        };
        
        $mode = $get($raw, 'mode');
        if($mode == 'ap'){
            $dev_id = 'ap_id';
        }
        if($mode == 'mesh'){
            $dev_id = 'node_id';
        }

        // Map fields we care about:
        $data               = [];
        $data[$dev_id]      = $get($raw, 'dev_id');
        $data['mac']        = $get($raw, 'mac');
        $data['ip']         = $get($raw, 'ip');
        $data['port']       = $get($raw, 'port');
        $data['protocol']   = $get($raw, 'protocol');

        // timestamp: try top-level timestamp.time (string) or fall back to now
        $ts = $get($raw, 'timestamp.time');
        if ($ts) {
            $data['timestamp_utc'] = date('Y-m-d H:i:s', strtotime($ts));
        }

        // upload (first top-level block looked at as 'upload' group in your JSON)
        $data['upload_bps'] = intval($get($raw, 'upload.end.sum_sent.bits_per_second') ?? $get($raw, 'end.sum_sent.bits_per_second') ?? $get($raw, 'end.sum_sent.bits_per_second'));
        $data['upload_bytes'] = intval($get($raw, 'upload.end.sum_sent.bytes'));
        $data['upload_retransmits'] = intval($get($raw, 'upload.end.sum_sent.retransmits') ?? $get($raw, 'end.sum_sent.retransmits'));
        $data['upload_mean_rtt_us'] = intval($get($raw, 'upload.end.streams.0.sender.mean_rtt') ?? $get($raw, 'end.streams.0.sender.mean_rtt'));
        $data['sender_tcp_congestion'] = $get($raw, 'upload.end.sender_tcp_congestion') ?? $get($raw, 'end.sender_tcp_congestion');

        // download
        $data['download_bps'] = intval($get($raw, 'download.end.sum_received.bits_per_second'));
        $data['download_bytes'] = intval($get($raw, 'download.end.sum_received.bytes'));
        $data['download_retransmits'] = intval($get($raw, 'download.end.sum_sent.retransmits') ?? $get($raw, 'download.end.sum_received.retransmits'));
        $data['download_mean_rtt_us'] = intval($get($raw, 'download.end.streams.0.receiver.mean_rtt') ?? $get($raw, 'download.end.streams.0.sender.mean_rtt'));
        $data['receiver_tcp_congestion'] = $get($raw, 'download.end.receiver_tcp_congestion') ?? $get($raw, 'end.receiver_tcp_congestion');

        // duration (if present)
        $data['duration_seconds'] = floatval($get($raw, 'upload.start.test_start.duration') ?? $get($raw, 'download.start.test_start.duration') ?? $get($raw, 'upload.start.test_start.duration'));

        // always store entire payload in meta_json
        $data['meta_json'] = json_encode($raw, JSON_UNESCAPED_UNICODE);

        $iperfTest = $this->IperfTests->newEntity($data);
        if ($this->IperfTests->save($iperfTest)) {
            $this->set('result', ['success' => true, 'id' => $iperfTest->id]);
        } else {
            $this->set('result', ['success' => false, 'errors' => $iperfTest->getErrors()]);
            $this->response = $this->response->withStatus(422);
        }
        $this->viewBuilder()->setOption('serialize', ['result']);        
    }
    
    private function apSpeedTest($ap_id){  
    
        $ap         = $this->Aps->find()->where(['Aps.id' => $ap_id])->contain(['ApProfiles'])->first();       
        $queryData  = $this->request->getData();
        
        $port       = $queryData['port'];
        $protocol   = $queryData['protocol'];
        $duration   = $queryData['duration'];
        $streams    = $queryData['streams'];
        $ping       = '';
        if(isset($queryData['ping'])){
            $ping = 'ping';
        }
        
        foreach($this->server_list as $server){
            if($server['id'] == $queryData['server']){
                $srv = $server['name'];
                break;
            }
        }
           	  	
    	if($ap){ 
    	    $cloud_id  = $ap->ap_profile->cloud_id;  	
    		$command   = "/etc/MESHdesk/utils/iperf_to_rd.lua $srv $port $protocol $duration $streams $ping"; //server port protocol duration streams ping
            $a_data    = [
            	'ap_id' 	=> $ap_id,
            	'command' 	=> $command, 
            	'action'	=> 'execute_and_reply',
				'cloud_id'	=> $cloud_id,
				'token'		=> $queryData['token'],
				'sel_language'	=> '4_4'
          	];                  	
          	$http 		= new Client();
			$response 	= $http->post(
			  $this->ap_action_add,
			  json_encode($a_data),
			  ['type' => 'json']
			);   	
    	}      
    }
    
    public function delete() {
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		
        $req_d		= $this->request->getData();
        
	    if(isset($req_d['id'])){   //Single item delete     
            $entity     = $this->IperfTests->get($req_d['id']);              
           	$this->IperfTests->delete($entity);
        }else{                          //Assume multiple item delete
            foreach($req_d as $d){
                $entity     = $this->IperfTests->get($d['id']);                
              	$this->IperfTests->delete($entity);
            }
        }

        if($fail_flag == true){
            $this->set(array(
                'success'   => false,
                'message'   => __('Could not delete some items'),
            ));
            $this->viewBuilder()->setOption('serialize', true); 
        }else{
            $this->set(array(
                'success' => true
            ));
            $this->viewBuilder()->setOption('serialize', true); 
        }
	}
       
}
