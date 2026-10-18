<?php

namespace App\Http\Controllers;

use App\Services\OutboundEmailRecorder;
use Illuminate\Http\Response;

class OutboundEmailOpenController extends Controller
{
    public function __invoke(string $token, OutboundEmailRecorder $recorder): Response
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1) {
            $recorder->recordOpen($token);
        }

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);

        return response($gif === false ? '' : $gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
