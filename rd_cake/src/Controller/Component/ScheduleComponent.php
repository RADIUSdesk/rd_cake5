<?php
//----------------------------------------------------------
//---- Author: Dirk van der Walt
//---- License: GPL v3
//---- Description: 
//---- Date: 10-Feb-2023
//------------------------------------------------------------

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\I18n\DateTime;

class ScheduleComponent extends Component {


      
    public function initialize(array $config):void{
   
    }

  	public function getSchedule($span = 30){
  	
  		$start_time = DateTime::now()->startOfDay();
        $end_time   = $start_time->addDay();   
        $items		= [];
        $counter	= 0;
        
        while($start_time < $end_time){       	
        	array_push($items,[
        		'id'	=> $counter, 
            	"time"	=> $start_time->i18nFormat('HH:mm').' to  '.$start_time->addMinutes($span)->i18nFormat('HH:mm') ,
            	"begin" => ($start_time->hour * 60)+ $start_time->minute,
            	"end"   => ($start_time->hour * 60)+ $start_time->minute+$span,
            	"mo"	=> true,
            	"tu"	=> true,
            	"we"	=> true,
            	"th"	=> true,
            	"fr"	=> true,
            	"sa"	=> true,
            	"su"	=> true
            ]);
            $start_time = $start_time->addMinutes($span);
        	$counter++;
        }     
        
        return $items;	
  	}
}
