<?php

namespace App\Controller;
use App\Controller\AppController;

class TestsController extends AppController{
  
    protected $main_model   = 'TopUpTransactions';
  
    public function initialize():void{  
        parent::initialize();
           
    }
      
    public function index(){
    
    	$this->set(['posts' => true]);
		$this->viewBuilder()->setOption('serialize', true);
      
    }
}
