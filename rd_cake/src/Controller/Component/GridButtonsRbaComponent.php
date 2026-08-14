<?php
//----------------------------------------------------------
//---- Author: Dirk van der Walt
//---- License: GPL v3
//---- Description: A component used to check and produce Ajax-ly called grid tooblaar items
//---- Date: 08-JUL-2025
//------------------------------------------------------------

namespace App\Controller\Component;
use Cake\Controller\Component;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

class GridButtonsRbaComponent extends Component {

    protected array $components 	= ['GridButtonsBase'];
    
    public function initialize(array $config): void {
        parent::initialize($config);
    }
    
    public function returnButtons($role='admin',$specific = false){
    
        $ctrl_name  = $this->getController()->getRequest()->getParam('controller');      
        return $this->_rbaButtonsFor($ctrl_name,$role);
    }
       
    private function _rbaButtonsFor($ctrl_name,$role){
    
        $ctrl_name  = 'Rba'.$ctrl_name;     
        $fileName   = $ctrl_name.'.php'; // Replace with your config file name
        $filePath   = CONFIG . $fileName;

        if (!file_exists($filePath)) {
            return [];
        }
               
        Configure::load($ctrl_name);
        $acl  = Configure::read($ctrl_name);

        // Get allowed actions for the role
        $allowedActions = $acl[$role];
        
        if($ctrl_name == 'RbaPermanentUsers'){     
            return [
                $this->_fetchPuBasic($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchPuCsvUpDown($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchPuExtras($allowedActions),
            ];
        }
               
        if($ctrl_name == 'RbaProfiles'){     
            return [
                $this->_fetchProfiles($allowedActions)
            ];
        }
        
        if($ctrl_name == 'RbaProfileComponents'){     
            return [
                $this->_fetchProfileComponents($allowedActions)
            ];
        }
        
        if($ctrl_name == 'RbaRealms'){     
            return [
                $this->_fetchRealmsBasic($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchRealmsCsvDown($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchRealmsOther($allowedActions)               
            ];
        }
        
        if($ctrl_name == 'RbaNas'){     
            return [
                $this->_fetchNasBasic($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchNasOther($allowedActions)               
            ];
        }
        
        if($ctrl_name == 'RbaRadaccts'){     
            return [
                $this->_fetchRadacctsBasic($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchRadacctsCsvDown($allowedActions),
                [ 'xtype' => 'tbseparator'],
                $this->_fetchRadacctsKickClose($allowedActions),
                [ 'xtype' => 'tbseparator'],
                
                
                [
                    'xtype'   => 'component', 
                    'itemId'  => 'totals',  
                     'tpl' => [
                            '<div class="radacct-stats">',

                                '<tpl if="activeData == true">',
                                    '<div class="stat-item">',
                                        '<i class="fa fa-arrow-down"></i>',
                                        '<span class="value">{in}</span>',
                                        '<span class="label">In</span>',
                                    '</div>',

                                    '<div class="stat-item">',
                                        '<i class="fa fa-arrow-up"></i>',
                                        '<span class="value">{out}</span>',
                                        '<span class="label">Out</span>',
                                    '</div>',

                                    '<div class="stat-item">',
                                        "<span class='fa' style='font-family:FontAwesome;'>&#xf0ec</span>",
                                        '<span class="value">{total}</span>',
                                        '<span class="label">Total</span>',
                                    '</div>',
                                '</tpl>',
                                '<div class="stat-item">',
                                    '<i class="fa fa-users"></i>',
                                    '<span class="value">{total_connected}</span>',
                                    '<span class="label">Sessions</span>',
                                '</div>',

                            '</div>'
                     
                     /*
                        "<div style='font-size:larger;width:400px;'>",
                        "<ul class='fa-ul'>",
                        "<tpl if='activeData == true'>",
                            "<li style='padding:2px;'>",
                            "<span class='fa-li' style='font-family:FontAwesome;'>&#xf1c0</span> {in} in {out} out {total} total</span></li>",
                        "</tpl>",
                        "<li style='padding:2px;'><i class='fa-li fa fa-arrow-right'></i> {total_connected} items</li>",
                        "</ul>",
                        "</div>"   */                 
                    ],
                    'data'   =>  [],
                    'cls'    => 'lblRd'
                ]              
            ];
        }
                    
    }
       
    //---Grid Permanent Users--- 
     
    private function _fetchPuBasic($allowedActions){       

        //--*--
        
        $items = [];
        
        if (in_array('*', $allowedActions)) {       
            $items = [
                $this->GridButtonsBase->getBtnReloadTimer(),
                $this->GridButtonsBase->getBtnAdd(),
                $this->GridButtonsBase->getBtnDelete(),
			    $this->GridButtonsBase->getBtnEdit()
            ];          
        } 
        
        //--Others--
        if(in_array('index', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnReloadTimer());      
        }
        if(in_array('add', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnAdd());      
        }
        if(in_array('delete', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnDelete());      
        }
        if(in_array('viewBasicInfo', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnEdit());      
        }
                
        $menu = [
            'xtype' => 'buttongroup',
            'title' => null,
            'border' => false,
            'bodyBorder' => false,
            'frame' => false, 
            'items' => $items 
        ];
                 
        return $menu;
    }
    
    private function _fetchPuExtras($allowedActions){
    
        $menu  = null;
        $items = [];
        
        if (in_array('*', $allowedActions)) {   

            $items = [
                [
                    'xtype'     => 'button', 
                    'glyph'     => Configure::read('icnEmail'),
                    'scale'     => $this->GridButtonsBase->getScale(), 
                    'itemId'    => 'email', 
                    'tooltip'   => __('e-Mail Credentials'),
                    'ui'        => $this->GridButtonsBase->getBtnUiMail()
                ],
                $this->GridButtonsBase->getBtnPassword(),
              //  $this->GridButtonsBase->getBtnEnable(),
                $this->GridButtonsBase->getBtnAdminState(),
                $this->GridButtonsBase->getBtnRadius(),
                $this->GridButtonsBase->getBtnGraph(),
                $this->GridButtonsBase->getBtnByod(),
                $this->GridButtonsBase->getBtnTopUp()
            ];      
        }
        
        //--Others--
        if(in_array('emailUserDetails', $allowedActions)){
            array_push($items,[
                'xtype'     => 'button', 
                'glyph'     => Configure::read('icnEmail'),
                'scale'     => $this->GridButtonsBase->getScale(), 
                'itemId'    => 'email', 
                'tooltip'   => __('e-Mail Credentials'),
                'ui'        => $this->GridButtonsBase->getBtnUiMail()
           ]);      
        }
        
        if(in_array('viewPassword', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnPassword());      
        }
        if(in_array('enableDisable', $allowedActions)){
            //array_push($items,$this->GridButtonsBase->getBtnEnable());
            array_push($items,$this->GridButtonsBase->getBtnAdminState());    
        }
        
        //'btnRadius',
        //'btnGraph',
        //'btnByod',
        //'btnTopup',
        if(in_array('btnRadius', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnRadius());      
        }
        
        if(in_array('btnGraph', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnGraph());      
        }       
        if(in_array('btnByod', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnByod());      
        }      
        if(in_array('btnTopup', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnTopUp());      
        }
        
         if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false, 
                'items' => $items
            ];  
        }     
        return $menu; 

    }
    
    private function _fetchPuCsvUpDown($allowedActions){
    
        $menu  = null;
        $items = [];
        
        if (in_array('*', $allowedActions)) {       
            $items = [
                 $this->GridButtonsBase->getBtnCsvUpload(),
                 $this->GridButtonsBase->getBtnCsvDownload()               
            ];          
        } 
        
        //--Others--
        if(in_array('import', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnCsvUpload());      
        }
        if(in_array('exportCsv', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnCsvDownload());      
        }
        
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,
                'width' => 110,
                'items' => $items
            ];  
        }     
        return $menu;    
    }
    
    //---END Grid Permanent Users---  
    
    //--- Grid Profiles ---
    
    private function _fetchProfiles($allowedActions){       

        $menu   = null;
        $items  = [];
     /*   
        $edit   = [
            'xtype' 	=> 'splitbutton',   
            'glyph' 	=> Configure::read('icnEdit'),    
            'scale' 	=> $this->GridButtonsBase->getScale(), 
            'itemId' 	=> 'edit',      
            'tooltip'	=> __('Edit'),
            'ui'        => $this->GridButtonsBase->getBtnUiEdit(),
            'menu'      => [
                    'items' => [
                        [ 'text'  => __('Simple Edit'),  	'itemId'    => 'simple', 	'group' => 'edit', 'checked' => true, 	'glyph' => Configure::read('icnEdit') ],
                        [ 'text'  => __('FUP Edit'),   		'itemId'    => 'fup', 		'group' => 'edit' ,'checked' => false, 	'glyph' => Configure::read('icnHandshake')], 
                        [ 'text'  => __('Advanced Edit'),   'itemId'    => 'advanced',	'group' => 'edit' ,'checked' => false, 	'glyph' => Configure::read('icnGears')],  
                    ]
            ]
        ];*/
              
        if (in_array('*', $allowedActions)) {       
            $items = [
                $this->GridButtonsBase->getBtnReload(),
                $this->GridButtonsBase->getBtnAdd(),
                $this->GridButtonsBase->getBtnDelete(),
                [ 'xtype' => 'tbseparator'],
			    $this->GridButtonsBase->getBtnSimpleEdit(),
			    $this->GridButtonsBase->getBtnFupEdit(),
			    $this->GridButtonsBase->getBtnAdvEdit(),
			    [ 'xtype' => 'tbseparator'],
			    $this->GridButtonsBase->getBtnProfComp()
            ];          
        } 
        
        //--Others--
        if(in_array('index', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnReload());      
        }
        
        if(in_array('simpleAdd', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnAdd());      
        }
        
        if(in_array('delete', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnDelete());      
        }
        
        if((in_array('manageComponents', $allowedActions))||(in_array('simpleView', $allowedActions))||(in_array('fupView', $allowedActions))){
            array_push($items,$edit);      
        }
        
        if(in_array('btnProfileComponents', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnProfComp());
        }
              
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,  
                'items' => $items
            ];  
        }     
        return $menu;    
    }
        
    //--- END Grid Profiles ---
    
    //--- Grid ProfileComponents ---
    
    private function _fetchProfileComponents($allowedActions){       

        $menu   = null;
        $items  = [];
                     
        if (in_array('*', $allowedActions)) {       
            $items = [
                $this->GridButtonsBase->getBtnReload(),
                $this->GridButtonsBase->getBtnAdd(),
                $this->GridButtonsBase->getBtnDelete(),
			    $this->GridButtonsBase->getBtnEdit()
            ];          
        } 
        
        //--Others--
        if(in_array('index', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnReload());      
        }
        
        if(in_array('add', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnAdd());      
        }        
        
        if(in_array('delete', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnDelete());      
        }
        
        if(in_array('edit', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnEdit());      
        }
                     
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,  
                'items' => $items
            ];  
        }     
        return $menu;    
    }        
    //--- END Grid Profiles ---
    
   //--- Realms --- 
    private function _fetchRealmsBasic($allowedActions){       

        $menu   = null;
        $items  = [];
                     
        if (in_array('*', $allowedActions)) {       
            $items = [
                $this->GridButtonsBase->getBtnReload(),
                $this->GridButtonsBase->getBtnAdd(),
                $this->GridButtonsBase->getBtnDelete(),
			    $this->GridButtonsBase->getBtnEdit()
            ];          
        } 
        
        //--Others--
        if(in_array('index', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnReload());      
        }
        
        if(in_array('add', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnAdd());      
        }        
        
        if(in_array('delete', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnDelete());      
        }
        
        if(in_array('edit', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnEdit());      
        }
                     
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,  
                'items' => $items
            ];  
        }     
        return $menu;    
    }
    
    private function _fetchRealmsCsvDown($allowedActions){
    
        $menu  = null;
        $items = [];
        
        if (in_array('*', $allowedActions)) {       
            $items = [
                 $this->GridButtonsBase->getBtnCsvDownload()               
            ];          
        } 
        
        //--Others--
        if(in_array('exportCsv', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnCsvDownload());      
        }
        
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false, 
                'width' => 60,
                'items' => $items
            ];  
        }     
        return $menu;    
    }  
    
    private function _fetchRealmsOther($allowedActions){
    
        $menu   = null;
        $items  = [];
        
        $btnLogo = [
            'xtype'     => 'button', 
            'glyph'     => Configure::read('icnCamera'),
            'scale'     => $this->GridButtonsBase->getScale(), 
            'itemId'    => 'logo',     
            'tooltip'   => __('Edit logo')
        ];
        $btnVlan = [
            'xtype'     => 'button', 
            'glyph'     => Configure::read('icnTag'),
            'scale'     => $this->GridButtonsBase->getScale(), 
            'itemId'    => 'vlans',     
            'tooltip'   => __('Manage VLANs'),
            'ui'        => 'button-metal'
        ];
        $btnPmk = [
            'xtype'     => 'button', 
            'glyph'     => Configure::read('icnLock'),
            'scale'     => $this->GridButtonsBase->getScale(), 
            'itemId'    => 'pmks',     
            'tooltip'   => __('Manage PMKs'),
            'ui'        => 'button-metal'
        ];
        
        $btnPasspoint = [
            'xtype'     => 'button', 
            'glyph'     => Configure::read('icnWifi2'),
            'scale'     => $this->GridButtonsBase->getScale(), 
            'itemId'    => 'passpoint',     
            'tooltip'   => __('Passpoint/HS2.0'),
            'ui'        => 'button-metal'
        ];
     
        if (in_array('*', $allowedActions)) {       
            $items = [
                 $this->GridButtonsBase->getBtnGraph(),
                 $btnLogo,
                 $btnVlan,
                 $btnPmk,
                 $btnPasspoint                              
            ];          
        } 
        
        //--Others--
        if(in_array('btnGraph', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnGraph());      
        }
        if(in_array('btnLogo', $allowedActions)){
            array_push($items,$btnLogo);      
        }
        if(in_array('btnVlan', $allowedActions)){
            array_push($items,$btnVlan);      
        }
        if(in_array('btnPmk', $allowedActions)){
            array_push($items,$btnPmk);      
        }
        
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false, 
                'items' => $items
            ];  
        }     
        return $menu;    
    } 
    
    //--- END Realms ---      
    
    //--- Nas --- 
    private function _fetchNasBasic($allowedActions){       

        $menu   = null;
        $items  = [];
                     
        if (in_array('*', $allowedActions)) {       
            $items = [
                $this->GridButtonsBase->getBtnReload(),
                $this->GridButtonsBase->getBtnAdd(),
                $this->GridButtonsBase->getBtnDelete(),
			    $this->GridButtonsBase->getBtnEdit()
            ];          
        } 
        
        //--Others--
        if(in_array('index', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnReload());      
        }
        
        if(in_array('add', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnAdd());      
        }        
        
        if(in_array('delete', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnDelete());      
        }
        
        if(in_array('edit', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnEdit());      
        }
                     
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,  
                'items' => $items
            ];  
        }     
        return $menu;    
    }
      
    private function _fetchNasOther($allowedActions){
    
        $menu   = null;
        $items  = [];
                   
        if (in_array('*', $allowedActions)) {       
            $items = [
                 $this->GridButtonsBase->getBtnGraph()                     
            ];          
        } 
               
        //--Others--
        if(in_array('btnGraph', $allowedActions)){
            array_push($items,$this->GridButtonsBase->getBtnGraph());      
        }      
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false,  
                'items' => $items
            ];  
        }     
        return $menu;    
    } 
    
    //--- END Nas ---
    
    //--- Radaccts ---
    private function _fetchRadacctsBasic($allowedActions){    
        $menu   = [
            'xtype' => 'buttongroup',
            'title' => null,
            'border' => false,
            'bodyBorder' => false,
            'frame' => false, 
            'items' => [
                $this->GridButtonsBase->getBtnReloadTimer(),
                [
                    'xtype' => 'tbseparator'
                ],
                [
                        'xtype'         => 'button',                        
                        //To list all
                        //'glyph'         => Configure::read('icnWatch'),
                        //'pressed'       => false,
                                                
                        //To list only active
                        'glyph'         => Configure::read('icnLight'),
                        'pressed'       => true,
                                                    
                        'scale'         => 'large',
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
                    'value'         => $this->getController()->timezone_id
                ],
                [
                    'xtype' => 'tbseparator'
                ],
                [
                        'xtype'         => 'button',
                        'glyph'         => Configure::read('icnInfoCircle'),
                        'pressed'       => false,                               
                        'scale'         => 'large',
                        'itemId'        => 'btnInfo',
                        'enableToggle'  => true,
                        'tooltip'       => __('Include more info (loads slower)')
                ]               
            ]
        ];
        return $menu;    
    }
        
    private function _fetchRadacctsCsvDown($allowedActions){
    
        $menu       = null;
        $items      = [];    
        $items[]    = $this->GridButtonsBase->getBtnGraph();
        
        if (in_array('*', $allowedActions)) {       
            $items[] = $this->GridButtonsBase->getBtnCsvDownload();          
        } 
        
        //--Others--
        if(in_array('exportCsv', $allowedActions)){
            $items[] = $this->GridButtonsBase->getBtnCsvDownload();      
        }
        
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false, 
                'items' => $items
            ];  
        }     
        return $menu;    
    }
    
    private function _fetchRadacctsKickClose($allowedActions){
    
        $menu       = null;
        $items      = [];    
               
        if (in_array('*', $allowedActions)) {       
            $items[] = $this->GridButtonsBase->getBtnKickActive();
            $items[] = $this->GridButtonsBase->getBtnCloseOpen();         
        } 
        
        //--Others--
        if(in_array('kickActive', $allowedActions)){
            $items[] = $this->GridButtonsBase->getBtnKickActive();      
        }
        if(in_array('closeOpen', $allowedActions)){
            $items[] = $this->GridButtonsBase->getBtnCloseOpen();      
        }
        
        if(count($items)>0){
            $menu = [
                'xtype' => 'buttongroup',
                'title' => null,
                'border' => false,
                'bodyBorder' => false,
                'frame' => false, 
                'items' => $items
            ];  
        }     
        return $menu;    
    } 
    
    //--- END Radaccts ---      
    
}
