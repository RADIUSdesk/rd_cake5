<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Controller\Controller;
use Cake\View\JsonView;

use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\Event\EventInterface;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/5/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('FormProtection');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        
         //Load the Config file we originally always had loaded
        Configure::load('RadiusDesk','default');

        $this->loadComponent('Flash');
        
        // Add this line to check authentication result and lock your site
        $this->loadComponent('Authentication.Authentication');
        $this->loadComponent('Aa');
        /*
         * Enable the following component for recommended CakePHP form protection settings.
         * see https://book.cakephp.org/5/en/controllers/components/form-protection.html
         */
        //$this->loadComponent('FormProtection');
    }
    
    public function viewClasses(): array
    {
        return [JsonView::class];
    }
    
    protected function _ap_right_check(){

        $user = $this->Aa->user_for_token_with_cloud($this);
        if(!$user){   //If not a valid user
            return;
        }
        
        $rbaAllowed = $this->Aa->checkRbaAccess($user);
        
        if (!$rbaAllowed) {
            return $this->Aa->denyRbaAccess();
        }       
        return $rbaAllowed;
    }
    
    public function beforeRender(EventInterface $event)
    {
        parent::beforeRender($event);

        if ($this->request->is('json')) {
            $this->viewBuilder()->setOption('serialize', true);
        }
    }
}
