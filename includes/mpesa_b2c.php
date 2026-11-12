<?php
/**
 * Safaricom Daraja B2C client for payroll salary transfers.
 */

declare(strict_types=1);

final class MpesaB2cException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $requestMayHaveReachedGateway = false
    ) {
        parent::__construct($message);
    }
}

/**
 * @throws MpesaB2cException
 */
function mpesa_b2c_request(string $url, array $headers, ?array $payload = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new MpesaB2cException('Unable to initialize the Daraja connection.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MpesaB2cException('Unable to encode the Daraja request.');
        }
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }

    if (!curl_setopt_array($curl, $options)) {
        curl_close($curl);
        throw new MpesaB2cException('Unable to configure the Daraja connection.');
    }
    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($response)) {
        throw new MpesaB2cException(
            'Daraja connection failed: ' . ($curlError !== '' ? $curlError : 'unknown transport error'),
            $payload !== null
        );
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new MpesaB2cException(
            'Daraja returned an invalid response.',
            $payload !== null && $httpStatus >= 500
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = (string) (
            $decoded['errorMessage']
            ?? $decoded['ResponseDescription']
            ?? 'Daraja rejected the request.'
        );
        throw new MpesaB2cException($message, $payload !== null && $httpStatus >= 500);
    }

    return $decoded;
}

/**
 * @throws MpesaB2cException
 */
function sendMpesaSalary(string $phoneNumber, int $amount, string $transactionDesc): array
{
    if (!preg_match('/^2547[0-9]{8}$/', $phoneNumber)) {
        throw new MpesaB2cException('The employee M-Pesa number must use 2547XXXXXXXX format.');
    }
    if ($amount < 1) {
        throw new MpesaB2cException('The salary amount must be a positive whole number of Kenyan shillings.');
    }

    $environment = (string) env('MPESA_ENV', 'sandbox');
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        throw new MpesaB2cException('MPESA_ENV must be sandbox or production.');
    }

    $consumerKey = trim((string) env('MPESA_CONSUMER_KEY', ''));
    $consumerSecret = trim((string) env('MPESA_CONSUMER_SECRET', ''));
    $shortcode = trim((string) env('MPESA_SHORTCODE', ''));
    $initiatorName = trim((string) env('MPESA_B2C_INITIATOR_NAME', ''));
    $securityCredential = trim((string) env('MPESA_B2C_SECURITY_CREDENTIAL', ''));
    $resultUrl = trim((string) env('MPESA_B2C_RESULT_URL', ''));
    $timeoutUrl = trim((string) env('MPESA_B2C_TIMEOUT_URL', ''));
    $callbackToken = trim((string) env('MPESA_B2C_CALLBACK_TOKEN', ''));

    if (
        $consumerKey === '' ||
        $consumerSecret === '' ||
        $shortcode === '' ||
        $initiatorName === '' ||
        $securityCredential === '' ||
        $resultUrl === '' ||
        $timeoutUrl === '' ||
        strlen($callbackToken) < 32
    ) {
        throw new MpesaB2cException('Daraja B2C is not fully configured.');
    }

    foreach ([$resultUrl, $timeoutUrl] as $callbackUrl) {
        $parts = parse_url($callbackUrl);
        if (
            !is_array($parts) ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            throw new MpesaB2cException('Daraja B2C callback URLs must be public HTTPS URLs.');
        }
    }

    $baseUrl = $environment === 'production'
        ? (string) env('MPESA_PRODUCTION_URL', 'https://api.safaricom.co.ke')
        : (string) env('MPESA_SANDBOX_URL', 'https://sandbox.safaricom.co.ke');
    $baseUrl = rtrim($baseUrl, '/');
    $baseUrlParts = parse_url($baseUrl);
    if (
        !is_array($baseUrlParts) ||
        strtolower((string) ($baseUrlParts['scheme'] ?? '')) !== 'https' ||
        empty($baseUrlParts['host']) ||
        isset($baseUrlParts['user']) ||
        isset($baseUrlParts['pass'])
    ) {
        throw new MpesaB2cException('Daraja API base URL must use HTTPS.');
    }

    $addCallbackToken = static function (string $url) use ($callbackToken): string {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query(['token' => $callbackToken]);
    };

    $tokenResponse = mpesa_b2c_request(
        $baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
        [
            'Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret),
            'Content-Type: application/json',
        ]
    );
    $accessToken = $tokenResponse['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new MpesaB2cException('Daraja did not return an access token.');
    }

    $payload = [
        'InitiatorName' => $initiatorName,
        'SecurityCredential' => $securityCredential,
        'CommandID' => 'SalaryPayment',
        'Amount' => $amount,
        'PartyA' => $shortcode,
        'PartyB' => $phoneNumber,
        'Remarks' => mb_substr($transactionDesc, 0, 100),
        'QueueTimeOutURL' => $addCallbackToken($timeoutUrl),
        'ResultURL' => $addCallbackToken($resultUrl),
        'Occasion' => 'Monthly Salary',
    ];

    return mpesa_b2c_request(
        $baseUrl . '/mpesa/b2c/v1/paymentrequest',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        $payload
    );
}
