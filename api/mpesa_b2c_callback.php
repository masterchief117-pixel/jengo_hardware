<?php
/**
 * Shared Daraja B2C result and timeout callback handler.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mpesa_b2c_callback_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mpesa_b2c_callback_log(string $message, array $context = []): void
{
    error_log('[M-Pesa B2C callback] ' . $message . ' ' . json_encode($context));
}

function handle_mpesa_b2c_callback(bool $isTimeout): never
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        header('Allow: POST');
        mpesa_b2c_callback_reply(405, ['ResultCode' => 1, 'ResultDesc' => 'POST required']);
    }

    $callbackToken = (string) env('MPESA_B2C_CALLBACK_TOKEN', '');
    $submittedToken = $_GET['token'] ?? '';
    if (
        strlen($callbackToken) < 32 ||
        !is_string($submittedToken) ||
        !hash_equals($callbackToken, $submittedToken)
    ) {
        mpesa_b2c_callback_reply(403, ['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 65536) {
        mpesa_b2c_callback_reply(400, ['ResultCode' => 1, 'ResultDesc' => 'Invalid callback body']);
    }

    $payload = json_decode($rawBody, true);
    $result = is_array($payload) ? ($payload['Result'] ?? null) : null;
    if (!is_array($result)) {
        mpesa_b2c_callback_reply(400, ['ResultCode' => 1, 'ResultDesc' => 'Invalid result payload']);
    }

    $conversationId = trim((string) ($result['ConversationID'] ?? ''));
    $originatorConversationId = trim((string) ($result['OriginatorConversationID'] ?? ''));
    if ($conversationId === '' && $originatorConversationId === '') {
        mpesa_b2c_callback_reply(400, ['ResultCode' => 1, 'ResultDesc' => 'Missing conversation identifier']);
    }

    $resultCode = filter_var($result['ResultCode'] ?? null, FILTER_VALIDATE_INT);
    $resultDescription = mb_substr(
        trim((string) ($result['ResultDesc'] ?? ($isTimeout ? 'Daraja request timed out.' : ''))),
        0,
        255
    );
    $resultParameters = $result['ResultParameters']['ResultParameter'] ?? [];
    $parameters = [];
    if (is_array($resultParameters)) {
        foreach ($resultParameters as $parameter) {
            if (is_array($parameter) && isset($parameter['Key'])) {
                $parameters[(string) $parameter['Key']] = $parameter['Value'] ?? null;
            }
        }
    }
    $receipt = trim((string) ($parameters['TransactionReceipt'] ?? ''));
    $callbackAmount = $parameters['TransactionAmount'] ?? null;

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT id, amount_paid, status
             FROM payroll_records
             WHERE (? <> \'\' AND conversation_id = ?)
                OR (? <> \'\' AND originator_conversation_id = ?)
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            $conversationId,
            $conversationId,
            $originatorConversationId,
            $originatorConversationId,
        ]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        if ($record === false) {
            $pdo->rollBack();
            mpesa_b2c_callback_log('No matching payroll record.', [
                'conversation_id' => $conversationId,
                'originator_conversation_id' => $originatorConversationId,
            ]);
            mpesa_b2c_callback_reply(200, ['ResultCode' => 0, 'ResultDesc' => 'Acknowledged']);
        }

        $status = 'Processing (M-Pesa)';
        if ($isTimeout) {
            $status = 'Submission Unknown';
        } elseif ($resultCode === 0 && $receipt !== '') {
            if (
                $callbackAmount !== null &&
                is_numeric($callbackAmount) &&
                (float) $callbackAmount !== (float) $record['amount_paid']
            ) {
                $status = 'Review Required';
                $resultDescription = 'Daraja callback amount did not match the recorded salary.';
            } else {
                $status = 'Paid';
            }
        } elseif (is_int($resultCode) && $resultCode !== 0) {
            $status = 'Failed';
        } else {
            $status = 'Review Required';
        }

        if ($record['status'] !== 'Paid') {
            $update = $pdo->prepare(
                'UPDATE payroll_records
                 SET status = ?,
                     mpesa_receipt = COALESCE(NULLIF(?, \'\'), mpesa_receipt),
                     result_code = ?,
                     result_description = ?
                 WHERE id = ? AND status <> \'Paid\''
            );
            $update->execute([
                $status,
                $receipt,
                $resultCode === false ? null : (string) $resultCode,
                $resultDescription,
                (int) $record['id'],
            ]);
        }

        $pdo->commit();
        mpesa_b2c_callback_reply(200, ['ResultCode' => 0, 'ResultDesc' => 'Acknowledged']);
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[M-Pesa B2C callback] Database update failed: ' . $exception->getMessage());
        mpesa_b2c_callback_reply(500, ['ResultCode' => 1, 'ResultDesc' => 'Temporary processing error']);
    }
}
