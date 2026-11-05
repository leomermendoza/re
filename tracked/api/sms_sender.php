<?php

date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_OFF);


/*
|--------------------------------------------------------------------------
| LOCAL ORANGE PI DATABASE
|--------------------------------------------------------------------------
*/

const LOCAL_DB_HOST = '127.0.0.1';
const LOCAL_DB_USER = 'root';
const LOCAL_DB_PASS = 'MyNewPassword123!';
const LOCAL_DB_NAME = 'tracked_local';


/*
|--------------------------------------------------------------------------
| SERVER PC DATABASE
|--------------------------------------------------------------------------
*/

const SERVER_DB_HOST = '192.168.2.103';
const SERVER_DB_USER = 'tracked_user';
const SERVER_DB_PASS = 'MyNewPassword123!';
const SERVER_DB_NAME = 'tracked_db';



const HTTPSMS_URL = 'https://api.httpsms.com/v1/messages/send';

const HTTPSMS_API_KEY = 'uk_DfYejy8So7dYEtfAm8N5N3FKJqbPvS7AZD7P69GiCtcxDBLZ-O4j9WruWTxZ3_DA';

const HTTPSMS_FROM = '+639672042010';


/*
|--------------------------------------------------------------------------
| SIM800L
|--------------------------------------------------------------------------
*/

const SIM800L_PORT = '/dev/ttyS2';
const SIM800L_BAUD = 9600;


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

const BATCH_SIZE = 10;

const HTTPSMS_CONNECT_TIMEOUT = 3;
const HTTPSMS_TIMEOUT = 10;

const SIM800L_COMMAND_TIMEOUT = 3;
const SIM800L_SEND_TIMEOUT = 20;


/*
|--------------------------------------------------------------------------
| LOGGING
|--------------------------------------------------------------------------
*/

function log_sms($message)
{
    error_log('[TrackED SMS] ' . $message);
}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

function connect_database($host, $user, $pass, $name)
{
    $conn = @new mysqli(
        $host,
        $user,
        $pass,
        $name
    );

    if ($conn->connect_error) {
        return null;
    }

    if (!$conn->set_charset('utf8mb4')) {
        return null;
    }

    return $conn;
}


/*
|--------------------------------------------------------------------------
| INTERNET CHECK
|--------------------------------------------------------------------------
*/

function internet_available()
{
    $targets = [
        ['1.1.1.1', 443],
        ['8.8.8.8', 443]
    ];

    foreach ($targets as $target) {

        $errno = 0;
        $errstr = '';

        $socket = @fsockopen(
            $target[0],
            $target[1],
            $errno,
            $errstr,
            1.5
        );

        if ($socket !== false) {

            fclose($socket);

            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| HTTPSMS SEND
|--------------------------------------------------------------------------
*/

function send_httpsms($to, $message, &$error)
{
    $error = '';


    /*
     * Check cURL.
     */

    if (!function_exists('curl_init')) {

        $error = 'PHP cURL is not installed.';

        return false;
    }


    /*
     * Check API key.
     */

    if (
        HTTPSMS_API_KEY === '' ||
        HTTPSMS_API_KEY === 'YOUR_NEW_HTTPSMS_API_KEY'
    ) {

        $error = 'HTTPSMS API key is not configured.';

        return false;
    }


    /*
     * Check sender.
     */

    if (
        HTTPSMS_FROM === '' ||
        HTTPSMS_FROM === '+00000000000'
    ) {

        $error = 'HTTPSMS sender number is not configured.';

        return false;
    }


    /*
     * Build JSON payload.
     */

    $payload = json_encode(
        [
            'content' => $message,
            'from'    => HTTPSMS_FROM,
            'to'      => $to
        ],
        JSON_UNESCAPED_UNICODE
    );


    if ($payload === false) {

        $error = 'Could not create HTTPSMS JSON payload.';

        return false;
    }


    /*
     * Send request.
     */

    $curl = curl_init(HTTPSMS_URL);

    if ($curl === false) {

        $error = 'Could not initialize cURL.';

        return false;
    }


    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_CONNECTTIMEOUT =>
                HTTPSMS_CONNECT_TIMEOUT,

            CURLOPT_TIMEOUT =>
                HTTPSMS_TIMEOUT,

            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . HTTPSMS_API_KEY,
                'Accept: application/json',
                'Content-Type: application/json'
            ],

            CURLOPT_POSTFIELDS => $payload
        ]
    );


    $response = curl_exec($curl);

    $curl_error = curl_error($curl);

    $http_code = (int)curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );


    curl_close($curl);


    /*
     * cURL failed.
     */

    if ($response === false) {

        $error =
            'HTTPSMS cURL error: ' .
            ($curl_error !== ''
                ? $curl_error
                : 'unknown error');

        return false;
    }


    /*
     * HTTP success.
     */

    if (
        $http_code >= 200 &&
        $http_code < 300
    ) {

        return true;
    }


    /*
     * HTTPSMS rejected request.
     */

    $error =
        'HTTPSMS HTTP ' .
        $http_code .
        ': ' .
        substr(
            (string)$response,
            0,
            500
        );

    return false;
}


/*
|--------------------------------------------------------------------------
| SIM800L SERIAL CONFIGURATION
|--------------------------------------------------------------------------
*/

function configure_sim800l(&$error)
{
    $error = '';


    if (!file_exists(SIM800L_PORT)) {

        $error =
            'SIM800L port not found: ' .
            SIM800L_PORT;

        return false;
    }


    $command =
        'stty -F ' .
        escapeshellarg(SIM800L_PORT) .
        ' ' .
        SIM800L_BAUD .
        ' cs8 -cstopb -parenb' .
        ' -ixon -ixoff -crtscts' .
        ' raw -echo 2>&1';


    $output = [];

    $return_code = 0;


    exec(
        $command,
        $output,
        $return_code
    );


    if ($return_code !== 0) {

        $error =
            'Cannot configure serial port: ' .
            implode(' ', $output);

        return false;
    }


    return true;
}


/*
|--------------------------------------------------------------------------
| SIM800L READ
|--------------------------------------------------------------------------
*/

function sim800l_read($fp, $seconds = 2)
{
    $result = '';

    $end_time =
        microtime(true) +
        $seconds;


    while (
        microtime(true) <
        $end_time
    ) {

        $data = fread(
            $fp,
            4096
        );


        if (
            $data !== false &&
            $data !== ''
        ) {

            $result .= $data;
        }


        usleep(50000);
    }


    return $result;
}


/*
|--------------------------------------------------------------------------
| SIM800L COMMAND
|--------------------------------------------------------------------------
*/

function sim800l_command(
    $fp,
    $command,
    $wait = 2
) {

    /*
     * Clear old response first.
     */

    while (
        ($old = fread($fp, 4096)) !== false &&
        $old !== ''
    ) {
        // Drain buffer.
    }


    fwrite(
        $fp,
        $command . "\r"
    );


    return sim800l_read(
        $fp,
        $wait
    );
}


/*
|--------------------------------------------------------------------------
| SIM800L SEND SMS
|--------------------------------------------------------------------------
*/

function send_sim800l(
    $to,
    $message,
    &$error
) {

    $error = '';


    /*
     * Configure serial port.
     */

    if (
        !configure_sim800l($error)
    ) {

        return false;
    }


    /*
     * Open serial port.
     */

    $fp = @fopen(
        SIM800L_PORT,
        'r+b'
    );


    if (!$fp) {

        $error =
            'Cannot open ' .
            SIM800L_PORT;

        return false;
    }


    stream_set_blocking(
        $fp,
        false
    );


    try {

        /*
         * AT test.
         */

        $reply =
            sim800l_command(
                $fp,
                'AT',
                SIM800L_COMMAND_TIMEOUT
            );


        if (
            strpos(
                $reply,
                'OK'
            ) === false
        ) {

            throw new RuntimeException(
                'SIM800L did not respond to AT.'
            );
        }


        /*
         * SIM card test.
         */

        $reply =
            sim800l_command(
                $fp,
                'AT+CPIN?',
                SIM800L_COMMAND_TIMEOUT
            );


        if (
            strpos(
                $reply,
                'READY'
            ) === false
        ) {

            throw new RuntimeException(
                'SIM card is not READY.'
            );
        }


        /*
         * Network registration.
         *
         * CREG:
         *   1 = registered home network
         *   5 = registered roaming
         */

        $reply =
            sim800l_command(
                $fp,
                'AT+CREG?',
                SIM800L_COMMAND_TIMEOUT
            );


        if (
            !preg_match(
                '/\+CREG:\s*\d,\s*([15])/',
                $reply
            )
        ) {

            throw new RuntimeException(
                'SIM800L is not registered: ' .
                trim($reply)
            );
        }


        /*
         * SMS text mode.
         */

        $reply =
            sim800l_command(
                $fp,
                'AT+CMGF=1',
                SIM800L_COMMAND_TIMEOUT
            );


        if (
            strpos(
                $reply,
                'OK'
            ) === false
        ) {

            throw new RuntimeException(
                'Cannot enable SMS text mode.'
            );
        }


        /*
         * Send SMS command.
         */

        $command =
            'AT+CMGS="' .
            str_replace(
                '"',
                '',
                $to
            ) .
            '"';


        fwrite(
            $fp,
            $command . "\r"
        );


        /*
         * Wait for > prompt.
         */

        $prompt =
            sim800l_read(
                $fp,
                5
            );


        if (
            strpos(
                $prompt,
                '>'
            ) === false
        ) {

            throw new RuntimeException(
                'SIM800L did not provide SMS prompt: ' .
                trim($prompt)
            );
        }


        /*
         * Send exactly the message from sms_queue.
         */

        $message =
            substr(
                $message,
                0,
                300
            );


        fwrite(
            $fp,
            $message
        );


        /*
         * CTRL+Z = send.
         */

        fwrite(
            $fp,
            chr(26)
        );


        /*
         * Wait for result.
         */

        $result =
            sim800l_read(
                $fp,
                SIM800L_SEND_TIMEOUT
            );


        /*
         * Successful SIM800L response:
         *
         * +CMGS:
         * OK
         */

        if (
            strpos(
                $result,
                '+CMGS:'
            ) === false ||
            strpos(
                $result,
                'OK'
            ) === false
        ) {

            throw new RuntimeException(
                'SIM800L SMS failed: ' .
                trim($result)
            );
        }


        fclose($fp);

        return true;

    } catch (Throwable $exception) {

        fclose($fp);

        $error =
            $exception->getMessage();

        return false;
    }
}


/*
|--------------------------------------------------------------------------
| MARK MESSAGE AS SENT
|--------------------------------------------------------------------------
*/

function mark_sent($conn, $id)
{
    $id = (int)$id;


    return $conn->query(
        "UPDATE sms_queue
         SET status='sent'
         WHERE id=$id"
    );
}


/*
|--------------------------------------------------------------------------
| KEEP MESSAGE PENDING
|--------------------------------------------------------------------------
*/

function mark_pending($conn, $id)
{
    $id = (int)$id;


    return $conn->query(
        "UPDATE sms_queue
         SET status='pending'
         WHERE id=$id"
    );
}


/*
|--------------------------------------------------------------------------
| CONNECT TO DATABASES
|--------------------------------------------------------------------------
*/

$server = null;
$local = null;


/*
 * Server database is only attempted when network is available.
 */

if (
    internet_available()
) {

    $server =
        connect_database(
            SERVER_DB_HOST,
            SERVER_DB_USER,
            SERVER_DB_PASS,
            SERVER_DB_NAME
        );
}


/*
 * Local Orange Pi database.
 */

$local =
    connect_database(
        LOCAL_DB_HOST,
        LOCAL_DB_USER,
        LOCAL_DB_PASS,
        LOCAL_DB_NAME
    );


/*
|--------------------------------------------------------------------------
| BUILD QUEUE LIST
|--------------------------------------------------------------------------
*/

$queues = [];


if ($server) {

    $queues[] = [
        'server',
        $server
    ];
}


if ($local) {

    $queues[] = [
        'local',
        $local
    ];
}


/*
|--------------------------------------------------------------------------
| NO DATABASE
|--------------------------------------------------------------------------
*/

if (count($queues) === 0) {

    log_sms(
        'No database available.'
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| PROCESS QUEUES
|--------------------------------------------------------------------------
*/

$total = 0;
$sent = 0;
$failed = 0;


foreach (
    $queues as $queue
) {

    $queue_name =
        $queue[0];

    $conn =
        $queue[1];


    /*
     * Only process pending messages.
     */

    $query =
        'SELECT id, mobile, message
         FROM sms_queue
         WHERE status="pending"
         ORDER BY id ASC
         LIMIT ' .
         (int)BATCH_SIZE;


    $result =
        $conn->query($query);


    if (!$result) {

        log_sms(
            $queue_name .
            ' queue query failed: ' .
            $conn->error
        );

        continue;
    }


    while (
        $row =
        $result->fetch_assoc()
    ) {

        $total++;


        $id =
            (int)$row['id'];


        $to =
            trim(
                (string)$row['mobile']
            );


        $message =
            (string)$row['message'];


        /*
         * Invalid queue record.
         */

        if (
            $to === '' ||
            $message === ''
        ) {

            log_sms(
                "queue $id has empty mobile/message"
            );

            mark_pending(
                $conn,
                $id
            );

            $failed++;

            continue;
        }


        $method = null;
        $error = '';


        /*
        |--------------------------------------------------------------------------
        | FIRST ATTEMPT: HTTPSMS
        |--------------------------------------------------------------------------
        */

        if (
            internet_available()
        ) {

            $httpsms_ok =
                send_httpsms(
                    $to,
                    $message,
                    $error
                );


            if ($httpsms_ok) {

                $method =
                    'httpsms';

            } else {

                log_sms(
                    "queue $id HTTPSMS failed: " .
                    $error
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | FALLBACK: SIM800L
        |--------------------------------------------------------------------------
        */

        if (
            $method === null
        ) {

            $error = '';


            $sim800_ok =
                send_sim800l(
                    $to,
                    $message,
                    $error
                );


            if ($sim800_ok) {

                $method =
                    'sim800l';

            } else {

                log_sms(
                    "queue $id SIM800L failed: " .
                    $error
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        if (
            $method !== null
        ) {

            /*
             * Mark sent immediately.
             */

            if (
                mark_sent(
                    $conn,
                    $id
                )
            ) {

                $sent++;


                log_sms(
                    "queue $id sent via " .
                    $method
                );

            } else {



                $failed++;


                log_sms(
                    "queue $id sent via " .
                    $method .
                    ', but database status update failed'
                );
            }

        } else {



            mark_pending(
                $conn,
                $id
            );


            $failed++;
        }
    }
}


/*
|--------------------------------------------------------------------------
| CLOSE DATABASES
|--------------------------------------------------------------------------
*/

if ($server) {

    $server->close();
}


if ($local) {

    $local->close();
}


/*
|--------------------------------------------------------------------------
| FINAL LOG
|--------------------------------------------------------------------------
*/

log_sms(
    'Finished: ' .
    'total=' . $total .
    ' sent=' . $sent .
    ' failed=' . $failed
);


if ($failed > 0) {

    exit(2);
}


exit(0);

?>
