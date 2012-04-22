<?
/****************************************************************************
 * Organization: Wireless Nomad
 * Script:       billRecurringPayments.php
 * Purpose:      To bill monthly recurring payments.
 * Author:       Colan Schwartz
 * Date:         2007-03-27
 * License:      GPLv3
 *
 * Note 1:  **This script must be run every day**
 * Note 2:  **This script must be restricted with htaccess**
 ****************************************************************************/


/****************************************************************************
 *** INCLUDES ***************************************************************
 ****************************************************************************/
require_once("ldap_billing.php");


/****************************************************************************
 *** GLOBAL CONSTANTS *******************************************************
 ****************************************************************************/

// Running modes.
define('DEBUG_MODE', TRUE);
define('TEST_MODE',  TRUE);

// E-mail addresses.
define('TEST_EMAIL',  "CHANGE THIS");
define('ADMIN_EMAIL', "CHANGE THIS");

// Web pages.
define('CREDIT_CARD_UPDATE_PAGE', "CHANGE THIS");

// Payment processor gateway info.
define('GATEWAY_URL',       "CHANGE THIS");
define('GATEWAY_LOGIN_ID',  "CHANGE THIS");
define('GATEWAY_LOGIN_KEY', "CHANGE THIS");
define('GATEWAY_RESPONSE_DELIMITER', "|");

// Database Info.
define('DATABASE_SERVER',   "localhost");
DEFINE('DATABASE_USER',     "billing");
DEFINE('DATABASE_PASSWORD', "CHANGE THIS");
DEFINE('DATABASE_NAME',     "billing");

// Response elements and their position in the response string.
define('RESPONSE_CODE',                    1);
define('RESPONSE_SUBCODE',                 2);
define('RESPONSE_REASON_CODE',             3);
define('RESPONSE_REASON_TEXT',             4);
define('RESPONSE_APPROVAL_CODE',           5);
define('RESPONSE_AVS_RESULT_CODE',         6);
define('RESPONSE_TRANSACTION_ID',          7);
define('RESPONSE_CARD_CODE_VERIFICATION', 39);
define('RESPONSE_CAVV_CODE',              40);

// We'll log a special response code to our DB when we're in test mode.
define('RESPONSE_CODE_TEST_CODE',          4);


/****************************************************************************
 * MAIN SCRIPT **************************************************************
 ****************************************************************************/

// Exit if we've already been run today.
if (scriptRanToday()) {
  debugMessage("ERROR: This script has already been run today. Aborting.");
  exit(1);
}

// Show some status info.
debugMessage("This script hasn't been run today (in live mode).  Processing.");
debugMessage("Test mode is " .TEST_MODE?"ON":"OFF" . ".");
debugMessage("Payments will ".TEST_MODE?"NOT ":""."be processed.");
debugMessage("E-mails will " .TEST_MODE?"NOT ":"" . "be sent to the " .
             "intended recipient.");

// Update the timestamp for when the script was last run.
debugMessage("Updating timestamp in DB.");
if (!updateScriptTimestamp()) {
  debugMessage("ERROR: The script timestamp could not be updated.");
  exit(1);
}

// Get all of the customer info.
$customers = ldap_billing_getCustomers();

// Process all customers.
foreach ($customers as $customer) {
  debugMessage("Processing customer ".$customer->id.".");

  // Process every product for each customer.
  foreach ($customer->products as $product) {

    debugMessage("Processing product ".$product->name.".");

    // Split processing into payment methods.
    switch ($customer->paymentMethod) {

      case "cheque":
        // If today is the billing date for this customer and product,
        // send an invoice to the customer.
        if (billingDateIsToday($product->billingDate)) {
          debugMessage("This customer's billing date is today.");
          debugMessage("Mailing invoice for ".$product->price);
          if (emailInvoice($customer, $product)) {
            debugMessage("Invoice mailed.");
          } else {
            debugMessage("ERROR: Invoice could NOT be mailed.");
          }
        }
        break;

      case "credit card":
        // If today is the billing date for this customer and product,
        // bill the customer's credit card.
        if (billingDateIsToday($product->billingDate)) {
          debugMessage("This customer's billing date is today.");
          debugMessage("Attempting to charge this customer's credit card.");
          billCustomer($customer, $product);

        } else /* billing date is not today */ {
          // Try to bill the customer again if it failed last time.
          if ($product->billFailures) {
            debugMessage("At least 1 attempt to charge customer's credit " .
                         "card failed, trying again.");

            // Reset the number of bill failures if it worked.
            if (billCustomer($customer, $product)) {
              if (setBillFailures($customer, $product,
                                   $product->billFailures = 0)) {
                debugMessage("The number of bill failures has been reset.");
              } else {
                debugMessage("ERROR: Unable to reset the number of bill " .
                             "failures.");
              }

            } else /* billing failed again */ {
              // Increment the number of bill failures.
              if (setBillFailures($customer, $product,
                                   ++$product->billFailures)) {
                debugMessage("Incrementing the number of bill failures to " .
                             $product->billFailures . ".");
              } else {
                debugMessage("ERROR: Unable to increment the number of bill " .
                             "failures to " $product->billFailures . ".");
              }
            }

            // If billing this customer and product failed three times,
            // notify the administration so that they can deal with it.
           if ($product->billFailures > 3) {
              debugMessage("At least 3 charge attempts have failed.");
              if (notifyAdminAboutFailures($customer, $product)) {
                debugMessage("The admins have been e-mailed re: " .
                             "multiple failed billing attempts.");
              } else {
                debugMessage("ERROR: Unable to e-mail the admins re: " .
                             "multiple failed billing attempts.");
              }
            }
          }
        }
        break;
    } // switch
  }
}


/****************************************************************************
 *** PRIVATE FUNCTIONS ******************************************************
 ****************************************************************************/

/****************************************************************************
 * Function: billingDateIsToday()
 *
 * Purpose: To check if the supplied monthly billing date is today.
 * 
 * In:  string $suppliedDate -- the billing date
 * Out: <boolean>            -- True if today is the billing date,
 *                              false otherwise.
 ****************************************************************************/
function billingDateIsToday($suppliedDate) {

  // Format today's date and the supplied date.
  $todayDate = getdate();

  // If today is the first of the month, make sure that we didn't miss
  // any billing days because last month had a shorter number of days 
  // than the supplied date.
  if ($todayDate['mday'] == 1) {

    // Figure out the year and month of last month.
    $lastMonth = $todayDate['mon'] - 1;
    $lastMonthYear = $todayDate['year'];
    if (!$lastMonth) {
      $lastMonth = 12;
      $lastMonthYear = $lastMonthYear - 1;
    }

    // Get the number of calendar days for it.
    $numberOfDaysLastMonth = cal_days_in_month(
      CAL_GREGORIAN,
      $lastMonth,
      $lastMonthYear
    );

    // If the last month was shorter, assume that the billing date is
    // the first so that today is a billing day.
    if ($suppliedDate > $numberOfDaysLastMonth) {
      $suppliedDate = 1;
    }
  }

  // If today is the billing date, return TRUE.
  if ($suppliedDate == $todayDate['mday']) {
    return TRUE;
  } else {
    return FALSE;
  }
} // billingDateIsToday()


/****************************************************************************
 * Function: billCustomer()
 *
 * Purpose: To bill the customer for the current product.
 * 
 * In:   array $gateway   -- payment processor gateway info
 *       object $customer -- the customer to bill
 *       object $product  -- the product that is being bought
 * Out:  <boolean>        -- True on success, false on failure.
 * Pre:  GATEWAY_URL, GATEWAY_LOGIN_ID, and GATEWAY_LOGIN_KEY are set.
 * Post: The customer is billed for the product.
 ****************************************************************************/
function billCustomer($customer, $product) {

  // Build the transaction data structure.
  $transactionData = buildTransaction(GATEWAY_LOGIN_ID, GATEWAY_LOGIN_KEY,
                                      $customer, $product);

  // Send it to the credit card processor and get the results.
  $results = submitTransaction(GATEWAY_URL, $transactionData);
  debugMessage("Gateway response: $results");

  // Try it again if it didn't work.
  if (!$success = get_response_element(RESPONSE_CODE, $results)) {
    debugMessage("ERROR: Transaction failed, trying again.");
    debugMessage("Response reason code & text: " .
                 get_response_element(RESPONSE_REASON_CODE, $results) .
                 get_response_element(RESPONSE_REASON_TEXT, $results));
    $results = submitTransaction(GATEWAY_URL, $transactionData);
    debugMessage("Gateway response: $results");
    if (!$success = get_response_element(RESPONSE_CODE, $results)) {
      debugMessage("ERROR: Transaction failed both times.");
      debugMessage("Response reason code & text: " .
                   get_response_element(RESPONSE_REASON_CODE, $results) .
                   get_response_element(RESPONSE_REASON_TEXT, $results));
    }
  }

  if ($success) {
    debugMessage("Transaction succeeded.");
  }

  // Store the results in the database
  if (logTransactionResults($customer, $product, $results)) {
    debugMessage("Transaction results have been logged to the DB.");
  } else {
    debugMessage("ERROR: Transaction results couldn't be logged to the DB.");
  }

  if (!$success) {
    // Alert the customer if the transaction attempts failed.
    if (tellCustomerToUpdateCreditCard($customer, $product)) {
      debugMessage("E-mailed customer about updating credit card.");
    } else {
      debugMessage("ERROR: Credit card update message could NOT be mailed.");
    }

    // Increment the number of failures for this customer and product.
    if (setBillFailures($customer, $product, ++$product->billFailures)){
      debugMessage("Incremented the number of billing failures for " .
                   "customer and product.");
    } else {
      debugMessage("ERROR: Unable to increment the number of billing " .
                   "failures for customer and product.");
    }
  }

  // Return the results.
  return $success;

} // billCustomer()


/****************************************************************************
 * Function: notifyAdminAboutFailures()
 *
 * Purpose: To notify the administration about a customer's unsuccessful
 *          billing attempts.
 * 
 * In:   object $customer -- the customer whose billing is failing
 *       object $product  -- the product that is being bought
 * Out:  <boolean>        -- True on success, false on failure.
 * Pre: ADMIN_EMAIL is set.
 * Post: The administration is notified about the failures.
 ****************************************************************************/
function notifyAdminAboutFailures($customer, $product) { 

  // Set the subject.
  $subject = "[Wireless Nomad] Multiple attempts failed at billing customer";

  // Compose a message.
  $message = "Re: Customer \"" . $customer->id . "\":\n";
  $message .= "\n";
  $message .= "On " . $product->billingDate . " we attempted to bill the " .
    "above customer $" . $product->price . " for " . $product->name . ", " .
    "but it didn't work.  We've also tried every day since then, " .
    "and those attempts didn't work either.\n";

  // Send it.
  return mailWrapper(ADMIN_EMAIL, $subject, $message);

} // notifyAdminAboutFailures()


/****************************************************************************
 * Function: emailInvoice()
 *
 * Purpose: E-mail an invoice to a customer.
 * 
 * In:   object $customer -- the customer to invoice
 *       object $product  -- the product that should be on the invoice
 * Out:  <boolean>        -- True on success, false on failure.
 * Post: The customer is sent an invoice.
 ****************************************************************************/
function emailInvoice($customer, $product) { 

  // Maybe we should also send the admins a copy of this invoice?
  // They could then be deleted as they come in or some such thing.

  // Set the subject.
  $subject = "[Wireless Nomad] Monthly invoice";

  // Compose a message.
  $message = "This is an invoice from Wireless Nomad for " .
             $customer->id . ".\n";
  $message .= "\n";
  $message .= "Please send a cheque to Wireless Nomad for " . $product->name .
              " with the amount of " . $product->price . ".\n";

  // Send it.
  return mailWrapper($customer->email, $subject, $message);

} // emailInvoice()


/****************************************************************************
 * Function: buildTransaction()
 *
 * Purpose: Build a transaction data structure for sending to the credit
 *          card processor.
 * 
 * In:   string $login_id -- the gateway user ID
 *       string $password -- the gateway user password
 *       object $customer -- the customer that is being billed
 *       object $product  -- the product that is being billed
 * Out:  <object>         -- the data structure
 * Pre:  TEST_MODE is set.
 ****************************************************************************/
function buildTransaction($login_id, $password, $customer, $product) {

  // Fill the data structure.
  $transactionFields = array(
    "x_login"            => $login_id, /* Required */
    "x_tran_key"         => $password, /* Required */
    "x_version"          => "3.1",     /* Required */
    "x_test_request"     => TEST_MODE ? TRUE : FALSE,
    "x_delim_char"       => GATEWAY_RESPONSE_DELIMITER,
    "x_delim_data"       => "TRUE",         /* Required */
    "x_url"              => "FALSE",
    "x_type"             => "AUTH_CAPTURE", /* Required */
    "x_method"           => "CC",
    "x_relay_response"   => "FALSE",               /* Required */
    "x_card_num"         => $customer->cardNumber, /* Required */
    "x_exp_date"         => $customer->expiryDate, /* Required */
    "x_amount"           => $product->price,       /* Required */

    // We can worry about this stuff later.
    // "x_description"      => $product->name,
    // "x_first_name"       => $customer->firstName,
    // "x_last_name"        => $customer->lastName,
    // "x_address"          => $customer->streetAddress,
    // "x_city"             => $customer->city,
    // "x_state"            => $customer->province,
    // "x_zip"              => $customer->postalCode,

    // I really don't think we need this stuff.
    // "CustomerBirthMonth" => "Customer Birth Month: 12",
    // "CustomerBirthDay"   => "Customer Birth Day: 1",
    // "CustomerBirthYear"  => "Customer Birth Year: 1959",
    // "SpecialCode"        => "Promotion: Spring Sale",
  );

  // Format it as a string.
  $transactionString = "";
  foreach ($transactionFields as $key => $value) {
    $transactionString .= "$key=" . urlencode($value) . "&";
  }

  // Return it.
  return rtrim($transactionString, "& ");
} // buildTransaction()


/****************************************************************************
 * Function: submitTransaction()
 *
 * Purpose: Submit a billing transaction to the credit card processor.
 * 
 * In:   string $url  -- the URL that will process the transaction
 *       string $data -- the transaction data to send
 * Out:  <object>     -- the results of submitting the transaction
 ****************************************************************************/
function submitTransaction($url, $data) {

  // These error codes tell us that we need to try again.
  $retry_in_5_error_codes = array(
    19, 20, 21, 22, 23,
    25, 26,
    57, 58, 59, 60, 61, 62, 63,
  );
  $retry_now_error_codes = array(
    120, 121. 122,
  );
  $retry_errors = array_merge($retry_in_5_error_codes, $retry_now_error_codes);

  // Keep trying to submit the transaction until we no longer get
  // one of those "Try again in 5 minutes" error codes.
  do {
    // Initiate a session.
    if ($session = curl_init($url)) {
      debugMessage("Session initialized with payment processor.");
    } else {
      debugMessage("ERROR: Session could NOT be initialized with payment " .
                   "processor.");
    }
  
    // Eliminate header info from the response.
    if (!curl_setopt($session, CURLOPT_HEADER, 0)) {
      debugMessage("ERROR: cURL option CURLOPT_HEADER could NOT be set to 0.");
    }
  
    // Return the response data instead of TRUE.
    if (!curl_setopt($session, CURLOPT_RETURNTRANSFER, 1)) {
      debugMessage("ERROR: cURL option CURLOPT_RETURNTRANSFER could NOT be " .
                   "set to 1.");
    }
  
    // Use HTTP POST to send the form data.
    if (!curl_setopt($session, CURLOPT_POSTFIELDS, $data)) {
      debugMessage("ERROR: cURL option CURLOPT_POSTFIELDS could NOT be set " .
                   "to $data.");
    }
  
    // Uncomment this block if you get no gateway response.
    //if (!curl_setopt($session, CURLOPT_SSL_VERIFYPEER, FALSE)) {
    //  debugMessage("ERROR: cURL option CURLOPT_SSL_VERIFYPEER could NOT be " .
    //               "set to FALSE.");
    //}
  
    $http_code = "";
    do {
      // Execute post & get results.
      if (!$response = curl_exec($session)) {
        debugMessage("ERROR: Payment processor session FAILED:");
        // Error codes can be found here:
        // http://ca.php.net/manual/en/function.curl-getinfo.php
        debugMessage($http_code = curl_getinfo($session, CURLINFO_HTTP_CODE));
  
        // Take a 1 minute nap & then try again if their servers are too busy.
        if ($http_code == 503) {
          debugMessage("Sleeping for 1 minute before trying again.");
          if (sleep(60) == FALSE) {
            debugMessage("ERROR: Unable to sleep.");
          }
        }
      } else {
       debugMessage("Payment processor session completed successfully.");
      }
    } while ($http_code == 503);
    
    // Close the session.
    curl_close($session);

    // Try the transaction again if need be.
    $reason_code = get_response_element(RESPONSE_REASON_CODE, $response);
    if (in_array($reason_code, $retry_in_5_error_codes)) {
      debugMessage("Sleeping for 5 minutes before trying again.");
      if (sleep(300) == FALSE) {
        debugMessage("ERROR: Unable to sleep.");
      }
    } else if (in_array($reason_code, $retry_now_error_codes)) {
      debugMessage("Trying again immediately.");
    }

  } while (in_array($reason_code, $retry_errors));

  // Return them.
  return $response;
} // submitTransaction()


/****************************************************************************
 * Function: tellCustomerToUpdateCreditCard()
 *
 * Purpose: Tell the customer to update his or her credit card information.
 * 
 * In:   object $customer -- the customer whose transaction has been processed
 *       object $product  -- the product billed
 * Out:  <boolean>        -- true on success, false on failure
 * Pre:  CREDIT_CARD_UPDATE_PAGE is set.
 * Post: The customer has been notified about updating his or her credit card.
 ****************************************************************************/
function tellCustomerToUpdateCreditCard($customer, $product) {

  // Set the subject.
  $subject = "[Wireless Nomad] Credit card info needs updating";

  // Compose a message.
  $message = "Please update your credit card infomation at the following " .
             "address:\n" . CREDIT_CARD_UPDATE_PAGE . "\n\n" .
             "This needs to be done within the next three (3) days.\n\n" .
             "An attempt was made to charge your credit card " .
             $product-price . " for " . $product->name . ", but it failed.\n\n";
             "Thanks,\n" . "Wireless Nomad";

  // Send it.
  return mailWrapper($customer->email, $subject, $message);

} // tellCustomerToUpdateCreditCard()


/****************************************************************************
 * Function: mailWrapper()
 *
 * Purpose: To override normal mail() behaviour for testing purposes,
 *          if necessary.
 * 
 * In:   string $to      -- the recipient of the message - if we're in test
 *                          mode, then mail will be sent to the test account
 *                          instead of the real recipient.
 *       string $subject -- the message subject
 *       string $message -- the body of the message
 * Out:  <boolean> -- TRUE on success, FALSE on failure.
 * Pre:  TEST_MODE and TEST_EMAIL are both set.
 ****************************************************************************/
function mailWrapper($to, $subject, $message) {

  // Format the message properly.
  $message = wordwrap($message, 70);

  if (TEST_MODE) {
    debugMessage("Sending mail to the test account.");
    return mail(TEST_EMAIL, $subject, $message);
  } else {
    debugMessage("Sending mail to the intended recipient.");
    return mail($to, $subject, $message);
  }

} // mailWrapper()


/****************************************************************************
 * Function: debugMessage()
 *
 * Purpose: If we're in debugging mode, then print the message.  And while
 *          we're at it, keep track of some other debugging info as well.
 * 
 * In:   string $message -- the debugging message to print.
 * Out:  <void>
 * Pre:  DEBUG_MODE is set.
 ****************************************************************************/
function debugMessage($message) {

  if (DEBUG_MODE) {
    print("[" . date("r") . "] " . $message . "\n");
  }

} // debugMessage()


/****************************************************************************
 * Function: get_response_element()
 *
 * Purpose: To get the value of a respnse element from the payment gateway.
 * 
 * In:   integer $element -- the element number in the response
 *       string $response -- the response from the payment processor
 * Out:  <string>         -- the value of the reason element
 ****************************************************************************/
function get_response_element($element, $response) {

  // Break the response elements into an array.
  $elements = explode(GATEWAY_RESPONSE_DELIMITER, $response);

  // Stick a placeholder into the 0th position.
  array_unshift($elements, "");

  // Return the requested element.
  return $elements[$element];

} // get_response_element()


/****************************************************************************
 * Function: updateScriptTimestamp()
 *
 * Purpose: Update the DB's timestamp of when we were last run.
 * 
 * In:   <void>
 * Out:  <boolean>        -- true on success, false on failure
 * Post: The timestamp in the DB has been updated.
 ****************************************************************************/
function updateScriptTimestamp($customer, $product, $results) {

  // Connect to the database server.
  $link = mysql_connect(DATABASE_SERVER, DATABASE_USER, DATABASE_PASSWORD);
  if (!$link) {
    debugMessage('ERROR: Could not connect: ' . mysql_error());
    return FALSE;
  } else {
    debugMessage('Connected successfully to the DB server');
  }

  // Select the DB to use.
  $db_selected = mysql_select_db(DATABASE_NAME, $link);
  if (!$db_selected) {
    debugMessage("ERROR: Can\'t use DB " . DATABASE_NAME . ": " .mysql_error());
    mysql_close($link);
    return FALSE;
  } else {
    debugMessage("Connected successfully to DB name " . DATABASE_NAME . ".");
  }

  // Log the result record.
  $result = mysql_query("UPDATE lastrun SET ts = NOW()");

  if (!$result) {
    debugMessage('ERROR: Invalid query: ' . mysql_error());
    mysql_close($link);
    return FALSE;
  } else {
    debugMessage('Successfully updated the script run timestamp.');
    mysql_close($link);
    return TRUE;
  }
}


/****************************************************************************
 * Function: logTransactionResults()
 *
 * Purpose: To store the results of a transaction in the database.
 * 
 * In:   object $customer -- the customer whose transaction has been processed
 *       object $product  -- the product billed
 *       object $results  -- the results of processing the transaction
 * Out:  <boolean>        -- true on success, false on failure
 * Post: A new record with the results is now in the database.
 ****************************************************************************/
function logTransactionResults($customer, $product, $results) {

  // Connect to the database server.
  $link = mysql_connect(DATABASE_SERVER, DATABASE_USER, DATABASE_PASSWORD);
  if (!$link) {
    debugMessage('ERROR: Could not connect: ' . mysql_error());
    return FALSE;
  } else {
    debugMessage('Connected successfully to the DB server');
  }

  // Select the DB to use.
  $db_selected = mysql_select_db(DATABASE_NAME, $link);
  if (!$db_selected) {
    debugMessage("ERROR: Can\'t use DB " . DATABASE_NAME . ": " .mysql_error());
    mysql_close($link);
    return FALSE;
  } else {
    debugMessage("Connected successfully to DB name " . DATABASE_NAME . ".");
  }

  // Should we store the real response code, or our test one?
  $response_code = TEST_MODE ?
                   RESPONSE_CODE_TEST_CODE :
                   get_response_element(RESPONSE_CODE, $results);

  // Log the result record.
  $result = mysql_query("INSERT INTO log " .
    "( " .
      "transactionId, uid, billedAmount, responseCode, approvalCode, " .
      "reasonCode, reasonText, cvvCode " .
    ") VALUES ( " .
      get_response_element(RESPONSE_TRANSACTION_ID, $results) . ", " .
      $customer->id . ", " .
      $product->price . ", " .
      $response_code . ", " .
      get_response_element(RESPONSE_APPROVAL_CODE, $results) . ", " .
      get_response_element(RESPONSE_REASON_CODE, $results) . ", " .
      get_response_element(RESPONSE_REASON_TEXT, $results) . ", " .
      get_response_element(RESPONSE_CARD_CODE_VERIFICATION, $results) . " " .
    ")");

  if (!$result) {
    debugMessage('ERROR: Invalid query: ' . mysql_error());
    mysql_close($link);
    return FALSE;
  } else {
    debugMessage('Successfully wrote transaction log to DB');
    mysql_close($link);
    return TRUE;
  }

} // logTransactionResults()


/****************************************************************************
 * Function: setBillFailures()
 *
 * Purpose: Set the number of bill failures for this customer and product.
 * 
 * In:   object $customer_id -- the customer ID
 *       object $failures -- the number of failures
 * Out:  <boolean>        -- true on success, false on failure
 * Post: The number of bill failures for this customer and product has been
 *       set to something or reset.
 ****************************************************************************/
function setBillFailures($customer_id, $failures) {
  return ldap_billing_updateRetries($customer_id, $failures);
} // setBillFailures()


/****************************************************************************
 * Function: scriptRanToday()
 *
 * Purpose: To determine if this script has already been run today.
 * 
 * In:   <void>
 * Out:  <boolean> -- true on success, false on failure
 ****************************************************************************/
function scriptRanToday() {

  // If we're in test mode, say that we didn't.
  if (TEST_MODE) {
    return FALSE;
  }

  // Connect to the database server.
  $link = mysql_connect(DATABASE_SERVER, DATABASE_USER, DATABASE_PASSWORD);
  if (!$link) {
    die('ERROR: Could not connect: ' . mysql_error());
  } else {
    debugMessage('Connected successfully to the DB server');
  }

  // Select the DB to use.
  $db_selected = mysql_select_db(DATABASE_NAME, $link);
  if (!$db_selected) {
    debugMessage("ERROR: Can\'t use DB " . DATABASE_NAME . ": " .mysql_error());
    mysql_close($link);
    die(1);
  } else {
    debugMessage("Connected successfully to DB name " . DATABASE_NAME . ".");
  }

  // Check if there was at least one day since the last run.
  $result = mysql_query("SELECT DATEDIFF(NOW(), ts) FROM lastrun");

  // Complain on errors.
  if (!$result) {
    debugMessage('ERROR: Invalid query: ' . mysql_error());
    mysql_close($link);
    die(1);
  }
  
  // Grab the return value.
  if (mysql_result($result, 0)) {
    $return_value = TRUE;
  } else {
    $return_value = FALSE;
  }

  // Free the result, close the DB, & then return the result.
  mysql_free_result($result);
  mysql_close($link);
  return $return_value;
} // scriptRanToday()
