<?php

/**
 * Importing Paypal REST API required namespaces
 *
 */

use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Address;
use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\FundingInstrument;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Api\ExecutePayment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\CreditCard;
use PayPal\Api\Authorization;
use PayPal\Api\Payee;

/**
 * Paypal config path
 */
define("PP_CONFIG_PATH", dirname(__FILE__)."/../libraries/paypal");

/**
 * Class:           Paypal API controller
 * Description:     Manages paypal payments
 *
 * @category    Controller
 * @package    Laravel
 * @author     Ardeshir Eshghi <ardeshir.eshghi@streamingtank.com>
 * @version         0.1
 */
class PaypalController extends BaseController {

    const PAYPAL_API_CLIENT_ID  = "";
    const PAYPAL_API_CLIENT_SECRET  = "";
    const PAYPAL_IPN_URL = "https://www.paypal.com/cgi-bin/webscr";

    protected static $paypalErrors = array("INTERNAL_SERVICE_ERROR", "VALIDATION_ERROR", "EXPIRED_CREDIT_CARD", "EXPIRED_CREDIT_CARD_TOKEN", "INVALID_ACCOUNT_NUMBER", "INVALID_RESOURCE_ID", "DUPLICATE_REQUEST_ID", "TRANSACTION_LIMIT_EXCEEDED", "TRANSACTION_REFUSED", "REFUND_TIME_LIMIT_EXCEEDED", "FULL_REFUND_NOT_ALLOWED_AFTER_PARTIAL_REFUND", "TRANSACTION_ALREADY_REFUNDED", "PERMISSION_DENIED", "CREDIT_CARD_REFUSED", "CREDIT_CARD_CVV_CHECK_FAILED", "PAYEE_ACCOUNT_RESTRICTED", "PAYMENT_NOT_APPROVED_FOR_EXECUTION", "INVALID_PAYER_ID", "PAYEE_ACCOUNT_LOCKED_OR_CLOSED", "PAYMENT_APPROVAL_EXPIRED", "PAYMENT_EXPIRED", "DATA_RETRIEVAL", "PAYEE_ACCOUNT_NO_CONFIRMED_EMAIL", "PAYMENT_STATE_INVALID", "AMOUNT_MISMATCH", "CURRENCY_NOT_ALLOWED", "CURRENCY_MISMATCH", "AUTHORIZATION_EXPIRED", "INVALID_ARGUMENT", "PAYER_ID_MISSING_FOR_CARD_TOKEN", "CARD_TOKEN_PAYER_MISMATCH", "AUTHORIZATION_CANNOT_BE_VOIDED", "RATE_LIMIT_REACHED", "UNAUTHORIZED_PAYMENT", "DCC_UNSUPPORTED_CURRENCY_CC_TYPE", "DCC_CC_TYPE_NOT_SUPPORTED", "DCC_REAUTHORIZATION_NOT_ALLOWED", "CANNOT_REAUTH_INSIDE_HONOR_PERIOD");

    protected $apiContext;
    protected $payer;
    protected $amount;
    protected $item;
    protected $itemList;
    protected $redirectUrls;
    protected $payment;
    protected $execution;
    protected $paymentOptions;
    protected $payee;

    /**
     * Constructor
     */
    public function __construct(
        Payer $payer = null,
        Amount $amount = null,
        Item $item = null,
        ItemList $itemList = null,
        RedirectUrls $redirectUrls = null,
        Payment $payment = null,
        PaymentExecution $paymentExecution = null,
        Transaction $transaction = null,
        Payee $payee = null,
        ApiContext $apiContext = null)  {

            $this->apiContext   = ($apiContext instanceof ApiContext) ? $apiContext : new ApiContext(new OAuthTokenCredential(self::PAYPAL_API_CLIENT_ID, self::PAYPAL_API_CLIENT_SECRET));
            $this->payer        = ($payer instanceof Payer) ? $payer : new Payer();
            $this->amount       = ($amount instanceof Amount) ? $amount : new Amount();
            $this->item         = ($item instanceof Item) ? $item : new Item();
            $this->itemList     = ($itemList instanceof ItemList) ? $itemList : new ItemList();
            $this->redirectUrls = ($redirectUrls instanceof RedirectUrls) ? $redirectUrls : new RedirectUrls();
            $this->payment      = ($payment instanceof Payment) ? $payment : new Payment();
            $this->execution    = ($execution instanceof PaymentExecution) ? $execution : new PaymentExecution();
            $this->transaction  = ($transaction instanceof Transaction) ? $transaction  : new Transaction();
            $this->payee        = ($payee instanceof Payee) ? $payee : new Payee();
    }

    /**
     * Sets the payment and go to paypal payment portal
     *
     * @return Redirect to paypal
     */
    public function getIndex()
    {
        // Check Auth
        Auth::requiredLevel(1);

        // Do nothing if user has already paid
        if (Auth::user()->is_active) {
            return;
        }

        // Get payment options from App config
        $this->paymentOptions = (Config::get('app.settings.paypal.paymentOptions')) ? Config::get('app.settings.paypal.paymentOptions') : array();

        // Set item code to the user identifier
        $this->paymentOptions['item']['sku'] = uniqid().Auth::user()->id;

        $this->setPaymentParams();

        try {
            if ($this->createPayment() && (!is_null($approvalUrl = $this->getApprovalUrl()))) {
                // Store payment id in session
                Session::put('paymentId', $this->payment->getId());

                // Redirect to paypal page for payment
                return Redirect::to($approvalUrl);
            }

        } catch (\PPConnectionException $ex) {

            error_log( "Exception: " . $ex->getMessage() . PHP_EOL);
            throw $ex;
        }
    }


    /**
     * Called when user approves the payment to execute the payment
     *
     * @return Redirects to the landing page (on Error redirects to the paypal error view)
     */
    public function getExecute()
    {
        if (!(Input::get('success') && Input::get('success') == 'true'))
            return $this->runPaymentExitProcess();

        if (!Session::has('paymentId')){
            return "No payment id to execute";
        }

        $paymentId = Session::get('paymentId');


        $this->payment = Payment::get($paymentId, $this->apiContext);

        // The payer_id is added to the request query parameters when the user is redirected from paypal back to your site
        $this->execution->setPayer_id(Input::get('PayerID'));

        //Execute the payment
        $response = $this->payment->execute($this->execution, $this->apiContext);


        // Check for errors
        if (isset($response->name)) {
            // When we have a paypal error
            if ($this->hasPaypalError($response)) {
                $error = array('name' => $response->name, 'message' => $response->message);
                Session::put('error', $error);

                return (MODE == 'LIVE') ? Redirect::to('/paypalerror') : Redirect::to('/paypalerror' . MODE_PARAM);
            }
        }

        // Unset session
        Session::forget('paymentId');

        if ($this->addPaymentIdToUser($paymentId)) {
            return (MODE === 'LIVE') ? Redirect::to('/') : Redirect::to('/'.MODE_PARAM);
        }
    }

    /**
     * Paypal IPN listener
     *
     * @return Boolean
     */
    public function postIpn()
    {
        if (!$this->confirmIpnCall()) {
            return false;
        }

        // Log IPN requests from Paypal
        $logNotes = json_encode(Input::all());
        statRecorder::record('ipn_log', array('notes' => $logNotes ));

        if (!Input::get('payment_status'))
            return false;

        if (Input::get('item_number1'))
            $userId = substr(Input::get('item_number1'), 13);

        // When the payment status is not complete
        if (Input::get('payment_status') != "Completed") {
            $this->logPaymentStatus(Input::get('payment_status'), $userId);
            return false;
        }

        // When payment is complete
        $transactionId = Input::get('txn_id');
        return $this->activateUser($userId, $transactionId);
    }


    /**
     * Logs incompleted payment status
     *
     * @param string    $status
     * @param int       $userId
     *
     * @return Boolean
     */
    protected function logPaymentStatus($status = null, $userId = null)
    {
        if (is_null($userId)) {
            return;
        }

        $user = User::find($userId);
        $user->paypal_payment_status = $status;
        return $user->save();
    }


    /**
     * Sets paypal payment parameters
     */
    protected function setPaymentParams()
    {

        $this->payment->setIntent("sale");
        $this->payment->setPayer($this->getPayer());
        $this->payment->setRedirect_urls($this->getRedirectUrls());
        $this->payment->setTransactions(array($this->getTransaction()));
    }

    /**
     * Check for paypal executation error
     *
     * @param object(stdClass)  $response Paypal execution API call response
     * @return Boolean
     */
    protected function hasPaypalError($response)
    {
        return (in_array($response->name, static::$paypalErrors));
    }

    /**
     * Create payment after the params are set
     *
     *
     * @return Boolean
     */
    protected function createPayment()
    {
        return $this->payment->create($this->apiContext);
    }

   /**
     * Get the paypal approval URL to go to paypal payment page
     *
     *
     * @return string  URL (null if not found)
     */

    protected function getApprovalUrl()
    {

        // Retrieve buyer approval url from the `payment` object.
        foreach($this->payment->getLinks() as $link) {
            if ($link->getRel() == 'approval_url') {
                return $link->getHref();
            }
        }

        return null;
    }


    /**
     * Gets the payer information
     *
     * @return object Payer object
     */
    protected function getPayer()
    {
        $paymentMethod = isset($this->paymentOptions['payer']['payment_method']) ?
                         $this->paymentOptions['payer']['payment_method'] : "paypal";
        $this->payer->setPayment_method($paymentMethod);

        return $this->payer;
    }

    /**
     * Gets the redirect URLs
     *
     * @return object
     */
    protected function getRedirectUrls()
    {
        $baseUrl = Request::root();

        $successUrl = "$baseUrl/paypal/execute?success=true";
        $cancelUrl = "$baseUrl/paypal/execute?success=false";
        $this->redirectUrls->setReturn_url($successUrl);
        $this->redirectUrls->setCancel_url($cancelUrl);

        return $this->redirectUrls;
    }


    /**
     * Gets the transaction information
     *
     * @return object
     */
    protected function getTransaction()
    {
        //Transaction is created with a `Payee` and `Amount` types
        $description = isset($this->paymentOptions['transaction']['description']) ?
                        $this->paymentOptions['transaction']['description'] : "";

        $this->transaction->setItemList($this->getItems());
        $this->transaction->setAmount($this->getAmounts());
        $this->transaction->setDescription($description);

        return $this->transaction;
    }

    /**
     * Gets the item information
     *
     *
     * @return object
     */
    protected function getItems()
    {
        $quantity   = isset($this->paymentOptions['item']['quantity']) ? $this->paymentOptions['item']['quantity']  : "1";
        $name       = isset($this->paymentOptions['item']['name']) ? $this->paymentOptions['item']['name']          : "Video";
        $price      = isset($this->paymentOptions['item']['price']) ? $this->paymentOptions['item']['price']        : "1.00";
        $currency   = isset($this->paymentOptions['item']['currency']) ? $this->paymentOptions['item']['currency']  : "GBP";
        $sku        = isset($this->paymentOptions['item']['sku']) ? $this->paymentOptions['item']['sku']            : "0001";

        $this->item->setQuantity($quantity);
        $this->item->setName($name);
        $this->item->setPrice($price);
        $this->item->setCurrency($currency);
        $this->item->setSku($sku);

        return $this->itemList->setItems(array($this->item));
    }

    /**
     * Gets the amount details
     *
     * @return object
     */
    protected function getAmounts()
    {
        $currency   = isset($this->paymentOptions['amount']['currency']) ? $this->paymentOptions['amount']['currency'] : "GBP";
        $total      = isset($this->paymentOptions['amount']['total']) ? $this->paymentOptions['amount']['total'] : "1.00";

        $this->amount->setCurrency($currency);
        $this->amount->setTotal($total);

        return $this->amount;
    }

    /**
     * Runs when a payment execution parameters are not correct
     *
     *
     * @return Redirects to the register page
     */
    protected function runPaymentExitProcess()
    {
        // Unset session
        if (Session::has('paymentId'))
            Session::forget('paymentId');
        // Logout
        if (Auth::check()) {
            Auth::logout();
            return Redirect::to('/');
        }
    }

    /**
     * Updates user record and adds the payment id
     *
     * @param int $paymentId
     *
     * @return Boolean
     */
    protected function addPaymentIdToUser($paymentId)
    {
        $user = User::find(Auth::user()->id);
        $user->paypal_payment_id = $paymentId;
        return $user->save();
    }

    /**
     * Security check to make sure ipn call is from paypal
     *
     * @return Boolean
     */
    protected function confirmIpnCall()
    {
        $input = $_POST;
        $params = array_merge(array(
            'cmd'=>'_notify-validate'
        ), $input);

        $response = $this->makeCallToIpn($params);
        return ($response == "VERIFIED");
    }


    /**
     * Curl call to paypal ipn to confirm ipn message
     *
     * @param array $params Api call parameters
     * @return string   API call response
     */
    protected function makeCallToIpn($params)
    {
        $paramsString = http_build_query($params);
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_URL, self::PAYPAL_IPN_URL);
        curl_setopt($ch,CURLOPT_POST, count($params));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $paramsString);

        //execute post request ti IPN endpoint
        if ( ! $result = curl_exec($ch)) {
            throw new Exception(sprintf("Error Processing Request: %s", curl_error($ch)), 1);
        }

        //close connection
        curl_close($ch);

        return $result;
    }


    /**
     * Updates user record by flagging is_active to true
     *
     * @param int $userId
     * @param string $transactionId
     * @return Boolean
     */
    protected function activateUser($userId, $transactionId = null)
    {
        // Activate user Store in the DB
        $user = User::find($userId);
        $user->paypal_transaction_id = $transactionId;
        $user->paypal_payment_status = Input::get('payment_status');
        $user->paypal_payment_details = json_encode($_POST);
        $user->is_active = 1;
        return $user->save();
    }
}
