<?php


function endozpay_decode_jwt_payload($jwt)
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;

    list($header_b64, $payload_b64, $signature_b64) = $parts;

    // Decode payload
    $payload_json = base64_decode(strtr($payload_b64, '-_', '+/'));
    return json_decode($payload_json, true);
}



function wc_logger( $message, $context = [] ) {
    $logger = wc_get_logger();
    $context = array_merge( [ 'source' => 'endozpay' ], $context );
    $logger->info( $message, $context );
}
