<?php

namespace Montanabay39\Mpesa\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Montanabay39\Mpesa\Models\MpesaTransaction;

class C2B_Controller extends Controller
{
    /**
     * Provide for timestamp or live api transactions
     * @var string $timestamp
     */
    protected $timestamp;

    /**
     * The Callback common part of the URL eg "https://domain.com/callbacks/"
     * @var string $callbackURL
     */
    protected $callbackURL;

    /**
     * Provide environment for sandbox or live api transactions
     * @var string $environment
     */
    protected $environment;

    /**
     * Provides common endpoint for transaction, depending on the environment.
     * @var string $baseURL
     */
    protected $baseURL;

    /**
     * The consumer key
     * @var string $consumerKey
     */
    protected $consumerKey;

    /**
     * The consumer key secret
     * @var string $consumerSecret
     */
    protected $consumerSecret;

    /**
     * The MPesa C2b Paybill number
     * @var int $shortCode
     */
    protected $shortCode;

    /**
     * The Mpesa portal Username
     * @var string $initiatorUsername
     */
    protected $initiatorUsername;

    /**
     * The Mpesa portal Password
     * @var string $initiatorPassword
     */
    protected $initiatorPassword;

    /**
     * The signed API credentials
     * @var string $cred
     */
    protected $credentials;

    /**
     * Construct method
     *
     * Initializes the class with an array of API values.
     *
     * @param array $config
     * @return void
     * @throws exception if the values array is not valid
     */

    public function __construct()
    {
        $this->timestamp         = Carbon::now()->format('YmdHis');
        $this->callbackURL       = config('app.url');
        $this->environment       = config('mpesa.c2b.environment');
        $this->baseURL           = 'https://' . ($this->environment == 'production' ? 'api' : 'sandbox') . '.safaricom.co.ke';
        $this->consumerKey       = config('mpesa.c2b.consumer.key');
        $this->consumerSecret    = config('mpesa.c2b.consumer.secret');
        $this->shortCode         = config('mpesa.c2b.shortcode');
        $this->initiatorUsername = config('mpesa.c2b.initiator.username');
        $this->initiatorPassword = config('mpesa.c2b.initiator.password');
        $this->certificate       = File::get(public_path() . '/vendor/mpesa/certificates/' . $this->environment . '.cer');
        openssl_public_encrypt($this->initiatorPassword, $output, $this->certificate, OPENSSL_PKCS1_PADDING);
        $this->credentials       = base64_encode($output);
    }

    /**
     * C2B Transaction simulation // Debugging only.
     *
     * This method is used to simulate a C2B Transaction to test your ConfirmURL and ValidationURL in the Client to Business method
     *
     * @param int $amount The amount to send to Paybill number
     * @param int $msisdn A Safaricom phone number to simulate transaction in the format 2547xxxxxxxx
     * @param string $reference An account name for the transaction
     * @return object Curl Response from submit method, false on failure
     */

    public function transaction(Request $request)
    {
        $data = json_encode([
            'ShortCode'     => $this->shortCode,
            'CommandID'     => 'CustomerPayBillOnline',
            'Amount'        => $request->amount,
            'Msisdn'        => '254' . substr($request->phoneNumber, -9), // supports translations in KENYA only!!
            'BillRefNumber' => $request->reference
        ]);

        $endpoint = $this->baseURL . '/mpesa/c2b/v1/simulate';
        $response = $this->submit($endpoint, $data);

        return json_encode($response);
    }

    /**
     * C2B Validation
     *
     * This method is used to validate a C2B Transaction against various methods set by the developer
     *
     * @param array $request from mpesa api
     * @return json respond for payment accepted or rejected
     */
    public function validation(Request $request)
    {
        try {
            // save transaction details if response is valid
            $transaction = MpesaTransaction::create([
                'partyA'               => '254' . substr($request->MSISDN, -9), // supports translations in KENYA only!!
                'partyB'               => $request->shortCode,
                'transactionType'      => 'C2B',
                'transactionAmount'    => $request->TransAmount,
                'transactionCode'      => $request->TransID,
                'transactionTimeStamp' => $request->TransTime,
                'transactionDetails'   => $request->BillRefNumber . ' C2B STK SIM Transaction',
                'transactionId'        => $request->TransTime,
                'accountReference'     => $request->BillRefNumber,
                'responseFeedBack'     => json_encode(['validation' => $request->all()])
            ]);

            // response to safaricom: if transaction accept else reject.
            return $transaction ?
                response()->json([
                    "ResultCode"        => 0,
                    "ResultDesc"        => "Accepted", // Transaction Accepted
                    "ThirdPartyTransID" => $request->TransTime
                ]) : response()->json([
                    "ResultCode"        => 1,
                    "ResultDesc"        => "Rejected", // Transaction Rejected
                    "ThirdPartyTransID" => $request->TransTime
                ]);

        } catch (\Throwable $th) {
            // throw $th;
            Log::info('C2B TRANSACTION VALIDATION');
            Log::info(print_r($th->getMessage()));
        }
    }

    /**
     * C2B Confirmation
     *
     * This method is used to confirm a C2B Transaction that has passed various methods set by the developer during validation
     *
     * @param array $request from mpesa api
     * @return json respone for payment detials i.e transcation code and timestamps e.t.c
     */
    public function confirmation(Request $request)
    {
        try {
            // find transaction via ThirdPartyTransID as the unique transaction Id.
            $transaction = MpesaTransaction::where(['transactionId' => $request->ThirdPartyTransID])->firstOrFail();
            // update transaction status
            $transaction->update(['_status' => MpesaTransaction::ACCEPTED]);
            // response to safaricom:
            return response()->json([
                "C2BPaymentConfirmationResult" => "Success"
            ]);
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('C2B TRANSACTION CONFIRMATION');
            Log::info(print_r($th->getMessage()));
        }
    }

    /**
     * Check Balance
     *
     * Check C2B balance
     *
     * @return object Curl Response from submit method, false on failure
     */
    public function balance()
    {
        $data = json_encode([
            'CommandID'          => 'AccountBalance',
            'PartyA'             => $this->shortCode,
            'IdentifierType'     => 4,
            'Remarks'            => 'Account Balance: ' . $this->shortCode,
            'Initiator'          => $this->initiatorUsername,
            'SecurityCredential' => $this->credentials,
            'QueueTimeOutURL'    => route('c2b.balance.callback'),
            'ResultURL'          => route('c2b.balance.callback')
        ]);

        $endpoint = $this->baseURL . '/mpesa/accountbalance/v1/query';
        $response = $this->submit($endpoint, $data);

        return json_encode($response);
    }

    public function balanceCallback(Request $request)
    {
        Log::info("C2B BALANCE");
        Log::info(print_r($request->all(), true));
        
        return;
    }

    /**
     * Transaction status request
     *
     * This method is used to check a transaction status
     *
     * @param string $tCode eg LH7819VXPE
     * @return object Curl Response from submit method, false on failure
     */

    public function status(Request $request)
    {
        $data = json_encode([
            'CommandID'          => 'TransactionStatusQuery',
            'PartyA'             => $this->shortCode,
            'IdentifierType'     => 4,
            'Remarks'            => 'Transaction status query: ' . $request->transactionCode,
            'Initiator'          => $this->initiatorUsername,
            'SecurityCredential' => $this->credentials,
            'QueueTimeOutURL'    => route('c2b.status.callback'),
            'ResultURL'          => route('c2b.status.callback'),
            'TransactionID'      => $request->transactionCode,
            'Occasion'           => 'Transaction status query: ' . $request->transactionCode
        ]);

        $endpoint = $this->baseURL . '/mpesa/transactionstatus/v1/query';
        $response = $this->submit($endpoint, $data);

        return json_encode($response);
    }

    public function statusCallback(Request $request)
    {
        Log::info("C2B TRANSACTION STATUS CALLBACK");
        Log::info(print_r($request->all(), true));

        return;
    }

    /**
     * Transaction Reversal
     *
     * This method is used to reverse a transaction
     *
     * @param int $receiver Phone number in the format 2547xxxxxxxx
     * @param string $trx_id Transaction ID of the Transaction you want to reverse eg LH7819VXPE
     * @param int $amount The amount from the transaction to reverse
     * @return object Curl Response from submit method, false on failure
     */

    public function reverseTransaction(Request $request)
    {
        $data = json_encode([
            'Initiator'              => $this->initiatorUsername,
            'SecurityCredential'     => $this->credentials,
            'CommandID'              => 'TransactionReversal',
            'TransactionID'          => $request->TransactionCode,
            'Amount'                 => $request->amount,
            'ReceiverParty'          => '254' . substr($request->phoneNumber, -9), // supports translations in KENYA only!!
            'RecieverIdentifierType' => 1, // [1 => 'MSISDN', 2 => 'Till_Number', 4 => 'Shortcode']
            'ResultURL'              => route('c2b.ke.reverse.transaction.callback'),
            'QueueTimeOutURL'        => route('c2b.ke.reverse.transaction.callback'),
            'Remarks'                => $request->tCode . ' Transaction Reversal',
            'Occasion'               => $request->tCode . ' Transaction Reversal'
        ]);

        $endpoint = $this->baseURL . '/mpesa/reversal/v1/request';
        $response = $this->submit($endpoint, $data);

        return json_encode($response);
    }

    public function reverseTransactionCallback(Request $request)
    {
        Log::info("C2B REVERSE TRANSACTION CALLBACK");
        Log::info(print_r($request->all(), true));

        return;
    }

    /**
     * Register Client to Business URL's
     *
     * Thisthod is used to register URLs for callbacks when money is sent from the MPesa toolkit menu
     *
     * @param string $confirmURL The local URL that MPesa calls to confirm a payment
     * @param string $ValidationURL The local URL that MPesa calls to validate a payment
     * @return object Curl Response from submit method, false on failure
     */
    public function register()
    {
        $data = json_encode([
            'ShortCode'       => $this->shortCode,
            'ResponseType'    => 'Completed', // ['Completed', 'Cancelled']
            'ConfirmationURL' => route('c2b.validation.callback'),
            'ValidationURL'   => route('c2b.confirmation.callback')
        ]);

        $endpoint = $this->baseURL . '/mpesa/c2b/v1/registerurl';
        $response = $this->submit($endpoint, $data);

        return json_encode($response);
    }

    /**
     * Generate Access Token
     *
     * @return object|boolean Curl response or false on failure
     * @throws exception if the Access Token is not valid
     */
    protected function generateAccessToken()
    {
        try {
            if (!Cache::has('C2B_ACCESS_TOKEN')) {
                return Cache::remember('C2B_ACCESS_TOKEN', now()->addMinutes(59), function () {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $this->baseURL . '/oauth/v1/generate?grant_type=client_credentials');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret), 'Content-Type: application/json'));
                    $response = curl_exec($ch);
                    curl_close($ch);

                    $response = json_decode($response);

                    if (!$response->access_token) {
                        return false;
                    } else {
                        return $response->access_token;
                    }
                });
            } else {
                return Cache::get('C2B_ACCESS_TOKEN');
            }
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('C2B GENERATE ACCESS TOKEN');
            Log::info(print_r($th->getMessage()));
        }
    }

    /**
     * Submit Request
     *
     * Handles submission of all API endpoints queries
     *
     * @param string $url The API endpoint URL
     * @param json $data The data to POST to the endpoint $url
     * @return object|boolean Curl response or false on failure
     * @throws exception if the Access Token is not valid
     */
    protected function submit($url, $data)
    {
        try {
            if ($this->generateAccessToken() != '' || $this->generateAccessToken() !== false) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $this->generateAccessToken()));

                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

                $response = curl_exec($curl);
                curl_close($curl);
                return json_decode($response);
            } else {
                return false;
            }
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('C2B SUBMIT');
            Log::info(print_r($th->getMessage()));
        }
    }
}