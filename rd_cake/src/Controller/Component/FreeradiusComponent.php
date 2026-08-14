<?php

//----------------------------------------------------------
//---- Author: Dirk van der Walt
//---- License: GPL v3
//---- Description: 
//---- Date: 20-11-2012
//------------------------------------------------------------


namespace App\Controller\Component;
use Cake\Controller\Component;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

class FreeradiusComponent extends Component {


    //We create an array containing all the includes:
    private $includes       = array();
    private $initial        = array();
    //We create an array containing all the VENDORS:
    private $vendors        = array();
    private $fr_internal    = '';

   public function getVendors(){
        $this->_getVendors();
        return $this->vendors;
    }

    public function getAttributes($vendor){
        $this->fr_internal = Configure::read('freeradius.path_to_dictionary_files').'dictionary.freeradius.internal';
        $this->_getVendors();
       // print_r($this->vendors);
        return $this->vendors[$vendor]['attributes'];
    }

    private function _getVendors(){

        $main_dictionary_file       = Configure::read('freeradius.main_dictionary_file');
        $path_to_dictionary_files   = Configure::read('freeradius.path_to_dictionary_files');

        $this->vendors['Misc']                  = ['name' => 'Misc', 'id' => 'Misc', 'has_number' => false, 'attributes' => []];
        $this->vendors['FreeRADIUS internal']   = ['name' => 'FreeRADIUS internal', 'id' => 'FreeRADIUS internal', 'has_number' => false, 'attributes' => []];
        $this->vendors['FreeRADIUS Custom']     = ['name' => 'FreeRADIUS Custom', 'id' => 'FreeRADIUS Custom', 'has_number' => false, 'attributes' => []];

        //The main dictionary file can contain private attributes.... add them here:
        $this->_add_attributes_to_vendor($main_dictionary_file,'FreeRADIUS Custom');

        //Prime the includes array
        $this->_look_for_includes($main_dictionary_file,true);

        //After we have a list of the includes from $this->main_dictionary_file
        //We will build an array by looking for files included inside its includelist
        //If it does not start with "/" we asume it sits under $path_to_dictionary_files
        
        foreach($this->initial as $include_file){
            array_push($this->includes,$include_file);
            $this->_look_for_includes($include_file);   
        }
        //loop through this includes array and check all the vendors out:
        //The '_look_for_vendors()' function will extract the vendors.

        foreach($this->includes as $include_file){
            $pattern ='/^\/+/';
            if(preg_match($pattern,$include_file)){
                $this->_look_for_vendors($include_file);    
            }else{
                $this->_look_for_vendors($path_to_dictionary_files.$include_file);
            }
        }
        //print_r($this->vendors);        
    }

    
    //---------------------------------------------------------------
    //----- Private function looking for includes in specified file--
    //---------------------------------------------------------------
    private function _look_for_includes ($file_to_look_for_includes,$initial=false){

        $lines = file($file_to_look_for_includes);

        foreach($lines as $line){

            $line = rtrim($line);
            $pattern = '/^\s*\$INCLUDE/';
            if(preg_match($pattern,$line)){

                $filename = preg_split("/\s+/",$line);
                if($initial){
                    array_push($this->initial, $filename[1]);
                }else{
                    array_push($this->includes, $filename[1]);
                }
                #echo $filename[1]."<br>\n";
                
            }
        }
        
    }


    //---------------------------------------------------------------
    //----- Private function looking for vendors in specified  file--
    //---------------------------------------------------------------
    private function _look_for_vendors ($file_to_look_for_includes){

        $lines = file($file_to_look_for_includes);
        foreach($lines as $line){

            $line = rtrim($line);
            $pattern = '/^\s*VENDOR/';
            if(preg_match($pattern,$line)){

                $vendor = preg_split("/\s+/",$line);
               // echo $vendor[1]."<br>\n";
                $this->vendors[$vendor[1]] = ['name' => $vendor[1], 'id' => $vendor[1],'number' => $vendor[2], 'attributes' => [],'has_number' => true];
                
                $this->_add_attributes_to_vendor($file_to_look_for_includes,$vendor[1]);
                return;

            }
        }
        //If there was not a VENDOR 
        //If no vendor was specified, add the attributes under misc

        //If the $file_to_look_for_includes = dictionary.freeradius.internal => FreeRADIUS internal
        if($file_to_look_for_includes == $this->fr_internal){
            $this->_add_attributes_to_vendor($file_to_look_for_includes,"FreeRADIUS internal");
        }else{
            $this->_add_attributes_to_vendor($file_to_look_for_includes,"Misc");
        }

    }

    private function _add_attributes_to_vendor($file_to_look_for_attributes,$vendor){

         $lines = file($file_to_look_for_attributes);
        foreach($lines as $line){

            $line = rtrim($line);
            $pattern = '/^\s*ATTRIBUTE/';
            if(preg_match($pattern,$line)){
                $attribute = preg_split("/\s+/",$line);
                //echo $attribute[1]."<br>\n";
                $has_tag = false;
                if(isset($attribute[4])){
                    if(preg_match('/has_tag/',$attribute[4])){
                        $has_tag = true;
                    }
                }                
                array_push($this->vendors[$vendor]['attributes'],['name' => $attribute[1], 'id' => $attribute[1] ,'number' => $attribute[2], 'type' => $attribute[3], 'has_tag' => $has_tag]);
            }
        }
    }
}
?>
