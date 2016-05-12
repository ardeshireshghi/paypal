<?php

define('DB_USER', getEnv('DB_USER'));
define('DB_PASSWORD', getEnv('DB_PASSWORD'));
define('DB_NAME', getEnv('DB_NAME'));

/**
 * Class:           Paypal IPN listener
 * Description:     Manages paypal ipn messages
 *
 * @author      Ardeshir Eshghi <ardeshir.eshghi@streamingtank.com>
 * @version     0.1
 */

 class IpnListener {

    /**
     *  MySqli connection instance
     * @var [mysqli]
     */
    protected $dbConnection;

    /**
     *
     * @var [string]
     */
    protected $DBError;

    /**
     *  Request input
     * @var [array]
     */
    protected $input;

    public function __construct(mysqli $connection = null)
    {
        try {
            $this->dbConnection = ($connection instanceof mysqli) ?
                                    $connection : $this->getDBConnection();

        } catch(Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }
    }

    /**
     * Gets the DB connection object
     */
    protected function getDBConnection()
    {

        $connection = new mysqli('localhost', DB_USER, DB_PASSWORD, DB_NAME);

        if ($connection->connect_errno) {
            $this->setDBError($connection->connect_error);
            throw new Exception($connection->connect_error);
        }

        return $connection;
    }


    /**
     * Sets the DB connection error
     *
     * @param string $DBError
     */
    protected function setDBError($DBError)
    {
        $this->DBError = $DBError;
    }

    /**
     * Gets the DB connection error
     *
     */
    protected function getDBError()
    {
        return (isset($this->DBError)) ? $this->DBError : null;
    }

    /**
     * Sets the DB connection error
     *
     * @param array $input
     * @return Boolean
     */
    public function processIpnMessage($input)
    {

        try {
            $this->input = $input;

            // Do nothing in non-payment status messages
            if (!isset($this->input['payment_status'])) {
                return false;
            }

            if (isset($this->input['item_number1'])) {
                $userId = substr($this->input['item_number1'], 13);
            }

            // When the payment status is not complete
            if ($this->input['payment_status'] !== "Completed") {
                if (!$this->logPaymentStatus($this->input['payment_status'], $userId)) {
                    throw new Exception('Could not update user: ' . $this->getDBError());
                }
            }

            // When payment is completed update user
            $transactionId = $this->input['txn_id'];

            if (!$this->activateUser($userId, $transactionId)) {
                throw new Exception('Could not update user: ' . $this->getDBError());
            } else {
                print 'success';
            }

            // Close connection
            $this->dbConnection->close();
            return true;

        } catch (Exception $e) {
            error_log($e->getMessage());
            $this->dbConnection->close();
            throw $e;
        }
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
        if (is_null($userId))
            return false;

        $userId = $this->dbConnection->real_escape_string($userId);
        $status = $this->dbConnection->real_escape_string($status);

        $query = sprintf("UPDATE users SET paypal_payment_status = '%s' WHERE id=%d", $status, $userId);
        return $this->executeQuery($query);
    }

    /**
     * Updates user record by flagging is_active to true
     *
     * @param int $userId
     * @param string $transactionId
     * @return Boolean
     */
    protected function activateUser($userId = null, $transactionId = null)
    {

        // Activate user Store in the DB
        if ($userId == null ||  $transactionId == null)
            return false;

        // Escape output values
        $userId             = $this->dbConnection->real_escape_string($userId);
        $paymentStatus      = $this->dbConnection->real_escape_string($this->input['payment_status']);
        $paymentDetails     = json_encode($this->input);

        $query = sprintf("UPDATE users SET paypal_transaction_id = '%s', paypal_payment_status = '%s', paypal_payment_details = '%s', is_active = 1 WHERE id=%d", $transactionId, $paymentStatus, $paymentDetails, $userId);

        return $this->executeQuery($query);

    }

    /**
     * Executes mySQL DB query
     *
     * @param string $query
     * @return Boolean
     */
    protected function executeQuery($query)
    {

        // Perform Query
        $this->result = $this->dbConnection->query($query);


        if (!$this->result) {
            // Prepare error message
            $errorMessage  = 'Invalid query: ' .$this->dbConnection->error . "<br>";
            $this->setDBError($errorMessage);
        }

        return $this->result;
    }


    /**
     * Read DB query results
     *
     * @return array
     */
    protected function fetchQueryResult()
    {

        if (!$this->result) {
            return array();
        }

        $queryResults = array();

        while($row = $this->result->fetch_assoc()) {
            $queryResults[] = $row;
        }

        /* free result set */
        if (isset($this->result)) {
            $this->result->close();
        }

        return $queryResults;
    }
}

$input = ($_POST) ? $_POST : $_GET;

if ($input) {
    try {
        $myIpnListener = new IpnListener( new mysqli('localhost', DB_USER, DB_PASSWORD, DB_NAME));
        $myIpnListener->processIpnMessage($input);
    }catch(Exception $e) {
        print 'Error: ' . $e->getMessage();
    }
}
