<?php
declare(strict_types=1);

namespace App\Authentication\Identifier;

use Authentication\Identifier\TokenIdentifier;


class CustomTokenIdentifier extends TokenIdentifier
{
    /**
     * Extract token from the request.
     */
    protected function getToken(ServerRequestInterface $request): ?string
    {
        $token = parent::getToken($request); // First, try the default behavior

        if ($token) {
            return $token;
        }

        // Check the POST data for the token if it's not in headers or GET
        $parsedBody = $request->getData();

        if (is_array($parsedBody) && isset($parsedBody['token'])) {
            return $parsedBody['token'];
        }

        return null; // No token found
    }
}

