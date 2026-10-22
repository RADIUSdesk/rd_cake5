<?php
/**
 * Created by G-edit.
 * User: dirkvanderwalt
 * Date: 15/OCT/2026
 * Time: 00:00
 */

namespace App\Controller;
use App\Controller\AppController;
use Cake\Log\Log;

use Cake\Http\Response;

class WhatsAppController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        // No auth, no CSRF for webhook endpoints
        $this->Authentication->allowUnauthenticated(['index', 'webhook']);
    }

    public function index(): ?Response {
    
        $this->request->allowMethod(['get', 'post']);             
        if ($this->request->is('post')) {			
		    $req_d	= $this->request->getData();
		    
		    // 1. Log the data as a data structure in the logs (logs/debug.log or logs/error.log)
            Log::debug('WhatsApp API Data received: ' . print_r($req_d, true));
        
            // Alternatively, write it back as JSON for ease of reading:
            Log::debug('WhatsApp API JSON: ' . json_encode($req_d, JSON_PRETTY_PRINT));

            // 2. Send a clean HTTP 200 answer back to WhatsApp
            return $this->response
                ->withStatus(200)
                ->withType('application/json')
                ->withStringBody(json_encode(['status' => 'success']));		    		
		}
       
        if (!$this->request->is('post')) {

            // Meta sends both dotted and underscored keys — check both
            $mode      = $this->request->getQuery('hub.mode')
                      ?? $this->request->getQuery('hub_mode');
            $token     = $this->request->getQuery('hub.verify_token')
                      ?? $this->request->getQuery('hub_verify_token');
            $challenge = $this->request->getQuery('hub.challenge')
                      ?? $this->request->getQuery('hub_challenge');

            $myVerifyToken = 'greatsecret'; // ideally load from config/env

            if ($mode === 'subscribe' && $token === $myVerifyToken) {
                // Return ONLY the challenge, as plain text
                return $this->response
                    ->withType('text/plain')
                    ->withStringBody((string)$challenge);
            }

            return $this->response
                ->withStatus(403)
                ->withType('text/plain')
                ->withStringBody('Forbidden');
        }
        
    }
}
