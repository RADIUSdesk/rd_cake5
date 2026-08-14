<?php
declare(strict_types=1);

namespace App\Authentication\Authenticator;

use Authentication\Identifier\IdentifierInterface;
use Psr\Http\Message\ServerRequestInterface;

use Authentication\Authenticator\TokenAuthenticator;

class CustomTokenAuthenticator extends TokenAuthenticator
{

    protected function getToken(ServerRequestInterface $request): ?string
    {
        $token = $this->getTokenFromHeader($request, $this->getConfig('header'));
        if ($token === null) {
            $token = $this->getTokenFromQuery($request, $this->getConfig('queryParam'));
            if(!$token){
                $token = $this->getTokenFromPost($request, $this->getConfig('queryParam'));
            }
        }

        $prefix = $this->getConfig('tokenPrefix');
        if ($prefix !== null && is_string($token)) {
            return $this->stripTokenPrefix($token, $prefix);
        }

        return $token;
    }
    
    /**
     * Gets the token from the request POST data
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request that contains login information.
     * @param string $queryParam Request query parameter name
     * @return string|null
     */
    protected function getTokenFromPost(ServerRequestInterface $request, ?string $queryParam): ?string
    {
        if (empty($queryParam)) {
            return null;
        }
        
        $postData = $request->getData();
       
        return $postData[$queryParam] ?? null;
    }
   
}
