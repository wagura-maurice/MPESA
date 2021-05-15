<?php

namespace Montanabay39\Mpesa\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Montanabay39\Mpesa\Models\MpesaTransaction;

class LNMO_Controller extends Controller
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
     * The Lipa Na MPesa paybill number
     * @var int $shortCode
     */
    protected $shortCode;

    /**
     * The Lipa Na MPesa paybill number SAG Key
     * @var string $key
     */
    protected $Key;

    /**
     * The Lipa Na MPesa password
     * @var string $password
     */
    protected $Password;

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
        $this->environment       = config('mpesa.lnmo_environment');
        $this->baseURL           = 'https://' . ($this->environment == 'production' ? 'api' : 'sandbox') . '.safaricom.co.ke';
        $this->consumerKey       = config('mpesa.lnmo_consumer_key');
        $this->consumerSecret    = config('mpesa.lnmo_consumer_secret');
        $this->shortCode         = config('mpesa.lnmo_shortcode');
        $this->key               = config('mpesa.lnmo_key');
        $this->password          = base64_encode($this->shortCode . $this->key . $this->timestamp);
        $this->initiatorUsername = config('mpesa.lnmo_initiator_username');
        $this->initiatorPassword = config('mpesa.lnmo_initiator_password');
        $this->certificate       = File::get(public_path() . '/vendor/mpesa/certificates/' . $this->environment . '.cer');
        openssl_public_encrypt($this->initiatorPassword, $output, $this->certificate, OPENSSL_PKCS1_PADDING);
        $this->credentials       = base64_encode($output);
    }

    /*********************************************************************
     *
     * LNMO APIs
     * 
     * Resources: https://peternjeru.co.ke/safdaraja/ui/#lnm_tutorial
     * 
     * *******************************************************************/
    /**
     * lnmo request
     *
     * This method is used to initiate online payment on behalf of a customer.
     *
     * @param array $request from mpesa api
     * @return json respone for payment detials i.e transcation code and timestamps e.t.c
     */
    public function transaction(Request $request)
    {
        // transactions endpoint provided by service provider.
        $endpoint = $this->baseURL . '/mpesa/stkpush/v1/processrequest';
        // data to be sent for processing.
        $data = json_encode([
            'BusinessShortCode' => $this->shortCode,
            'Password'          => $this->password,
            'Timestamp'         => $this->timestamp,
            'TransactionType'   => $request->type ?? 'CustomerPayBillOnline', // ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline]
            'Amount'            => $request->amount,
            'PartyA'            => '254' . substr($request->phoneNumber, -9), // supports translations in KENYA only!!
            'PartyB'            => $this->shortCode,
            'PhoneNumber'       => '254' . substr($request->phoneNumber, -9), // supports translations in KENYA only!!
            'CallBackURL'       => route('mpesa.lnmo.callback'),
            'AccountReference'  => $request->reference,
            'TransactionDesc'   => $request->reference . ' Push STK Transaction'
        ]);
        // send data for processing
        $response = $this->submit($endpoint, $data);

        try {
            // save transaction details if response is valid
            if (isset($response->ResponseCode) && $response->ResponseCode == 0) {
                $transaction = MpesaTransaction::create([
                    'partyA'               => json_decode($data)->PartyA,
                    'partyB'               => json_decode($data)->PartyB,
                    'transactionType'      => 'LNMO',
                    'transactionAmount'    => json_decode($data)->Amount,
                    'transactionCode'      => NULL,
                    'transactionTimeStamp' => $this->timestamp,
                    'transactionDetails'   => json_decode($data)->TransactionDesc,
                    'transactionId'        => $response->CheckoutRequestID,
                    'accountReference'     => json_decode($data)->AccountReference,
                    'responseFeedBack'     => json_encode(['transaction' => $response])
                ]);

                return response()->json($transaction);
            }
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('LNMO TRANSACTION');
            Log::info(print_r($th->getMessage()));
        }
    }

    /**
     * lnmo callback
     *
     * This method is used to confirm a lnmo Transaction that has passed various methods set by the developer during validation
     *
     * @param array $request from mpesa api
     * @return json respone for payment detials i.e transcation code and timestamps e.t.c
     */
    public function callback(Request $request)
    {
        $callback = $request['Body']['stkCallback'];

        try {
            // find transaction via CheckoutRequestID as the unique transaction Id.
            $transaction = MpesaTransaction::where(['transactionId' => $callback['CheckoutRequestID']])->firstOrFail();

            if($transaction) {

                isset($callback['ResultCode']) && $callback['ResultCode'] == 0 ? $transaction->update([
                    'transactionCode'  => $callback['CallbackMetadata']['Item'][1]['Value'],
                    'responseFeedBack' => json_encode(array_merge(json_decode($transaction->responseFeedBack, true), ['callback' => $request->all()])),
                    '_status'          => MpesaTransaction::ACCEPTED
                ]) : $transaction->update([
                    'transactionCode'  => $callback['CallbackMetadata']['Item'][1]['Value'],
                    'responseFeedBack' => json_encode(array_merge(json_decode($transaction->responseFeedBack, true), ['callback' => $request->all()])),
                    '_status'          => MpesaTransaction::REJECTED
                ]);

                return response()->json($transaction);
            }
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('LNMO CALLBACK');
            Log::info(print_r($th->getMessage()));
        }
    }

    /**
     * lnmo query
     *
     * This method is used to check the status of a Lipa Na M-Pesa Online Payment.
     *
     * @param array $request from mpesa api
     * @return json respone for payment detials i.e transcation code and timestamps e.t.c
     */
    public function query(Request $request)
    {
        $endpoint = $this->baseURL . '/mpesa/stkpushquery/v1/query';
        
        $data = json_encode([
            'BusinessShortCode' => $this->shortCode,
            'Password'          => $this->password,
            'Timestamp'         => $this->timestamp,
            'CheckoutRequestID' => $request->CheckoutRequestID
        ]);

        $response = $this->submit($endpoint, $data);

        // dd($response);

        try {

            $transaction = MpesaTransaction::where(['transactionId' => $request->CheckoutRequestID])->firstOrFail();

            if($transaction) {

                $response->ResultCode == 0 ? $transaction->update([
                    'responseFeedBack' => json_encode(array_merge(json_decode($transaction->responseFeedBack, true), ['query' => $response])),
                    '_status'          => MpesaTransaction::ACCEPTED
                ]) : $transaction->update([
                    'responseFeedBack' => json_encode(array_merge(json_decode($transaction->responseFeedBack, true), ['query' => $response])),
                    '_status'          => MpesaTransaction::REJECTED
                ]);

                return response()->json($transaction);
            }
            
        } catch (\Throwable $th) {
            // throw $th;\
            Log::info('LNMO QUERY');
            Log::info(print_r($th->getMessage()));
        }
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
            if (!Cache::has('LMNO_AccessToken')) {
                return Cache::remember('LMNO_AccessToken', now()->addMinutes(59), function () {
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
                return Cache::get('LMNO_AccessToken');
            }
        } catch (\Throwable $th) {
            // throw $th;
            Log::info('LNMO GENERATE ACCESS TOKEN');
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
            Log::info('LNMO SUBMIT');
            Log::info(print_r($th->getMessage()));
        }
    }
}
