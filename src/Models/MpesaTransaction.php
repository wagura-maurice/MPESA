<?php

namespace Montanabay39\Mpesa\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    const PROCESSING = 'processing';
    const ACCEPTED   = 'accepted';
    const REJECTED   = 'rejected';

    protected $table = 'mpesa_transactions';

    protected $fillable = [
        'partyA',
        'partyB',
        'transactionType',
        'transactionAmount',
        'transactionCode',
        'transactionTimeStamp',
        'transactionDetails',
        'transactionId',
        'accountReference',
        'responseFeedBack',
        '_status'
    ];

    public static $createRules = [
        'partyA'               => 'required|string',
        'partyB'               => 'required|string',
        'transactionType'      => 'required|integer',
        'transactionAmount'    => 'required|string',
        'transactionCode'      => 'nullable|string',
        'transactionTimeStamp' => 'required|timestamp',
        'transactionDetails'   => 'nullable|string',
        'transactionId'        => 'required|string',
        'accountReference'     => 'required|string',
        'responseFeedBack'     => 'required|string'
    ];

    public static $updateRules = [
        'partyA'               => 'nullable|string',
        'partyB'               => 'nullable|string',
        'transactionType'      => 'nullable|integer',
        'transactionAmount'    => 'nullable|string',
        'transactionCode'      => 'nullable|string',
        'transactionTimeStamp' => 'nullable|timestamp',
        'transactionDetails'   => 'nullable|string',
        'transactionId'        => 'nullable|string',
        'accountReference'     => 'nullable|string',
        'responseFeedBack'     => 'nullable|string'
    ];
}
