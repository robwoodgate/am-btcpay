<?php
/**
 * BTCPay (Greenfield API)
 * Author: R Woodgate, Cogmentis Ltd.
 * Author URI: https://www.cogmentis.com/.
 *
 * @title BTCPay (Greenfield API)
 *
 * @desc BTCPay lets you accept payments in Bitcoin, using the latest Greenfield API
 *
 * @am_payment_api 6.0
 */

// BTCPay Greenfield API PHP SDK
require __DIR__.'/btcpayserver-greenfield-php-2.6.0/src/autoload.php';

use BTCPayServer\Client\Invoice as ClientInvoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Result\Invoice as ResultInvoice;
use BTCPayServer\Result\Webhook as WebhookResult;
use BTCPayServer\Util\PreciseNumber;

/**
 * @am_payment_api 6.0
 */
class Am_Paysystem_BtcpayGf extends Am_Paysystem_ManualRebill
{
    public const PLUGIN_STATUS = self::STATUS_BETA;
    public const PLUGIN_REVISION = '1.1';

    public const BTCPAY_INVOICE_ID = 'btcpay-invoice-id';

    protected $defaultTitle = 'BTCPay';
    protected $defaultDescription = 'Pay with Bitcoin';
    protected $log; // Invoice log

    private $transactionSpeedOptions = [
        InvoiceCheckoutOptions::SPEED_HIGH => '0 confirmations (instant)',
        InvoiceCheckoutOptions::SPEED_MEDIUM => '1 confirmation (approx 10 minutes)',
        InvoiceCheckoutOptions::SPEED_LOWMEDIUM => '2 confirmations (approx 20 minutes)',
        InvoiceCheckoutOptions::SPEED_LOW => '6 confirmations (approx 1 hour)',
    ];

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function init()
    {
        parent::init();
        Am_Di::getInstance()->productTable->customFields()->add(
            new Am_CustomFieldSelect(
                'txspeed',
                'BTCPay Transaction Speed',
                'Optional. 1 confirmation is generally recommended for transactions under $1k, as it provides moderate protection.',
                null,
                ['empty_title' => 'Use Plugin Default', 'options' => $this->transactionSpeedOptions]
            )
        );
    }

    // NB: This hook ONLY fires on pages where payment systems are loaded
    // such as signup forms, payment history page, and directAction pages
    // If a CC module plugin is enabled, also on member home page
    public function onBeforeRender(Am_Event $e): void
    {
        // Inject BTCPay receipt link into payment history widget
        if (false !== strpos($e->getTemplateName(), 'blocks/member-history-paymenttable')) {
            $v = $e->getView();
            foreach ($v->payments as &$p) {
                if ($p->paysys_id == $this->getId()) {
                    $p->_invoice_url = $this->getConfig('btcpay_server').'/i/'.$p->receipt_id.'/receipt';
                }
            }
        }
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        // Init vars
        $sns = $this->getDi()->session->ns($this->getId()); // session store (for nonce token)
        $serverUrl = $this->getConfig('btcpay_server');
        $pre = "payment.{$this->getId()}";

        // Show warning?
        if ($sns->warning) {
            $form->addProlog("<div class='warning_box'>{$sns->warning}</div>");
            $sns->warning = null; // clear it
        }

        // Handle setup wizard response from BTCPay Server
        if (isset($_POST['apiKey'], $_POST['permissions'])
            && !empty($_GET['btcpay_auth']) && $sns->token == $_GET['btcpay_auth']
        ) {
            $this->logDebug('Setup wizard response: '.print_r($_POST, true));
            $apiData = new BtcpayGfSetupWizardHelper($serverUrl, $_POST);
            if ($apiData->hasSingleStore() && $apiData->hasRequiredPermissions()) {
                // Save tokens
                $this->getDi()->config->saveValue("{$pre}.api_key", $apiData->getApiKey());
                $this->getDi()->config->saveValue("{$pre}.store_id", $apiData->getStoreID());

                // Register a new webhook.
                $webhook = $apiData->registerWebhook($this->getPluginUrl('ipn'));
                $this->logDebug('Webhook Data: '.print_r($webhook->getData(), true));
                $this->getDi()->config->saveValue("{$pre}.webhook_secret", $webhook->getData()['secret']);
            } else {
                $sns->warning = 'Please make sure you only select one store on the BTCPay API authorization page.';
            }

            // Clear btcpay_auth from url
            return Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$this->getId()}", false));
        }

        // BTCPay Server Field
        $form->addText('btcpay_server', ['id' => 'btcpay_server', 'class' => 'am-el-wide', 'placeholder' => 'https://btcpay.example.com'])
            ->setLabel("BTCPay Server URL\n".'Make sure to include https://.')
            ->addRule('required')
            ->addRule('callback', ___('BTCPay Server is invalid'), [$this, '_checkServer'])
        ;

        // Hide other fields until server field is set
        if (!$this->_checkServer()) {
            return;
        }

        // Setup Wizard Button
        try {
            // Create the redirect url
            $permissions = array_merge(BtcpayGfSetupWizardHelper::REQUIRED_PERMISSIONS, BtcpayGfSetupWizardHelper::OPTIONAL_PERMISSIONS);
            $sns->token = $this->getDi()->security->randomString(8); // nonce
            $authUrl = \BTCPayServer\Client\ApiKey::getAuthorizeUrl(
                $serverUrl,
                $permissions,
                $this->getDi()->config->get('site_title').' (aMember)',
                true,
                true,
                $this->getDi()->url("admin-setup/{$this->getId()}?btcpay_auth={$sns->token}", null, false, true),
                'amember'
            );
            // Add button and JS
            $form->addStatic()->setContent('<a id="setup_wizard" class="button" href="'.$authUrl.'">Run setup wizard</a>')
                ->setLabel('Automated Setup Wizard')
            ;
            $form->addStatic()->setContent('This will setup your API Key, Store ID and Webhook Secret automatically.');
        } catch (\Throwable $e) {
            $this->getDi()->logger->error('Error fetching redirect url from BTCPay Server', ['exception' => $e]);
        }

        // Wizard Settings
        $form->addText('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API Key\n".'If not set, use the setup wizard above.')
        ;
        $form->addText('store_id', ['class' => 'am-el-wide'])
            ->setLabel("Store ID\n".'If not set, use the setup wizard above.')
        ;
        $form->addText('webhook_secret', ['class' => 'am-el-wide'])
            ->setLabel("Webhook Secret\n".'If not set, use the setup wizard above.')
        ;
        $form->addAdvCheckbox('debug')
            ->setLabel(sprintf(___("Debug Messages?\n".'Messages will be written to the %sDebug log%s'), '<a href="'.$this->getDi()->url('default/admin-logs/p/debuglog').'">', '</a>'))
        ;

        // Other settings
        $form->addSelect('txspeed')
            ->setLabel("Default Transaction Speed\n".'1 confirmation is generally recommended for transactions under $1k, as it provides moderate protection. BTCPay Store default is 1 confirmation')
            ->loadOptions(['' => 'Use BTCPay Store settings'] + $this->transactionSpeedOptions)
        ;
        $form->addText('refund_percentage', ['size' => 4, 'placeholder' => '0.0'])
            ->setLabel("Refund Reduction (%)\n".'Optional. Specify the percentage by which to reduce the refund, e.g. as processing charge or to compensate for the mining fee. Default: 0.0 (no adjustment)')
        ;
        $form->setDefault('refund_percentage', '0.0');
        $gr = $form->addGroup()
            ->setSeparator(' ')
            ->setLabel(___('Refund Issued Email
            Optional. Sends message when a BTCPay refund is issued, so user can claim their refund. Claim link will be added to user notes in any case so admin can share with user as needed.'))
        ;
        $gr->addAdvCheckbox('send_refund_email');
        $gr->addElement('email_link', 'email_refund_link')
            ->setLabel(___('Email template for refund link notice'))
        ;
    }

    /**
     * Check BTCPay Server URL
     * Uses submitted value, if set, or existing value.
     *
     * @param null|mixed $new
     */
    public function _checkServer($new = null)
    {
        $host = $new ?? $this->getConfig('btcpay_server');
        $host = filter_var($host, FILTER_VALIDATE_URL);

        return !(false === $host || ('http://' !== substr($host, 0, 7) && 'https://' !== substr($host, 0, 8)));
    }

    public function isConfigured()
    {
        return $this->getConfig('btcpay_server')
        && $this->getConfig('api_key')
        && $this->getConfig('store_id')
        && $this->getConfig('webhook_secret');
    }

    public function _process($invoice, $request, $result)
    {
        // Handle free trial: Although BTCPay Server can handle free transactions,
        // it is a better user experience to bypass BTCPay and activate the
        // subscription here. It also creates fewer invoices in BTCPay
        if (doubleval($invoice->first_total) <= 0 && $invoice->isFirstPayment()) {
            $result->setSuccess(new Am_Paysystem_Transaction_Free($this));

            return;
        }

        // Init vars
        $user = $invoice->getUser();
        $transactionSpeed = ''; // Use BTCServer setting
        $prSpeeds = [];
        $posData = [];

        // Invoice amount
        $invAmt = $invoice->isFirstPayment()
            ? $invoice->first_total : $invoice->second_total;

        // Invoice tax
        $invTax = $invoice->isFirstPayment()
            ? $invoice->first_tax : $invoice->second_tax;

        // Transaction speed (slowest)
        foreach ($invoice->getProducts() as $product) { // @var $product Product
            $prSpeeds[] = ($o = $product->data()->get('txspeed'))
                ? $o : $this->getConfig('txspeed');
        }
        $prSpeeds = array_filter(array_unique($prSpeeds));
        foreach ($this->transactionSpeedOptions as $key => $value) {
            if (in_array($key, $prSpeeds)) {
                $transactionSpeed = $key;
            }
        }
        $this->logDebug('Selected Speed: '.(empty($transactionSpeed) ? 'default' : $transactionSpeed).', Product Speeds: '.implode(', ', $prSpeeds));

        // Receipt Data
        foreach ($invoice->getItems() as $item) { // @var $item InvoiceItem
            $price = ($invoice->isFirstPayment() ? $item->first_total : $item->second_total);
            $receiptData['Cart'][$item->item_title] = Am_Currency::render($price, $item->currency).' x '.$item->qty.' = '.Am_Currency::render($price * $item->qty, $item->currency);
        }

        // Setup custom metadata. This will be visible in the invoice and will
        // show up on the invoice details page on BTCPay Server.
        $metaData = [
            'orderUrl' => $this->getDi()->url('signup', null, false, true),
            'buyerName' => $user->getName(),
            'buyerCountry' => $user->country,
            'receiptData' => $receiptData,
            'itemDesc' => $invoice->getLineDescription(),
            'physical' => false, // indicates if physical product
            'taxIncluded' => $invTax, // tax amount (included in the total amount).
        ];

        // Set our custom returnUrl
        $returnUrl = $this->getDi()->surl(
            'payment/'.$this->getId().'/return',
            [
                'id' => $invoice->getSecureId($this->getId()),
                'txn' => '', // {placeholder} is below to avoid urldecode
            ],
            false
        );
        $this->getDi()->logger->error($returnUrl);

        // Checkout options
        $checkoutOptions = new InvoiceCheckoutOptions();
        $checkoutOptions->setRedirectURL($returnUrl.'{InvoiceId}'); // {placeholder}
        $checkoutOptions->setSpeedPolicy($transactionSpeed);

        // Create the invoice on BTCPay Server
        $client = $this->getClient();

        try {
            $btcpayInvoice = $client->createInvoice(
                $this->getConfig('store_id'),
                $invoice->currency,
                PreciseNumber::parseString($invAmt),
                $invoice->public_id,
                $user->email,
                $metaData,
                $checkoutOptions
            );
            $this->logDebug(print_r($btcpayInvoice->getData(), true));
        } catch (\Throwable $e) {
            $this->getDi()->logger->error(
                'Failed to create BTCPay invoice',
                ['exception' => $e]
            );

            throw new Am_Exception_InputError('Incorrect Gateway response received!');
        }

        // Grab checkoutLink and redirect to BTCPay server
        if (!($url = $btcpayInvoice->getData()['checkoutLink'])) {
            $this->getDi()->logger->error(
                'BTCPay invoice has no checkout link',
                ['exception' => $e]
            );

            throw new Am_Exception_InputError('Incorrect Gateway response received!');
        }
        $this->logDebug('Checkout URL: '.$url);
        $result->setAction(new Am_Paysystem_Action_Redirect($url));
    }

    public function getClient()
    {
        return new ClientInvoice($this->getConfig('btcpay_server'), $this->getConfig('api_key'));
    }

    public function getBtcPayInvoice($invoiceId): ?ResultInvoice
    {
        try {
            $client = $this->getClient();

            return $client->getInvoice(
                $this->getConfig('store_id'),
                $invoiceId
            );
        } catch (\Throwable $e) {
            $this->getDi()->logger->error(
                'BTCPay error getting invoice',
                ['exception' => $e]
            );

            throw new Am_Exception_InternalError(
                'BTCPay error getting invoice. '
                ."#[{$this->event['invoiceId']}]."
            );
        }
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_BtсpayGf($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_BtсpayGf($this, $request, $response, $invokeArgs);
    }

    public function directAction($request, $response, $invokeArgs)
    {
        // Default return page: Redirects to cancel or success page as needed
        // Needed because BTCPay uses redirectUrl for both success and cancel,
        // whereas aMember has seperate success/cancel urls
        if ('return' == $request->getActionName()) {
            // Init vars
            $invId = $request->getFiltered('id');
            $txnId = $request->getFiltered('txn');
            $btcpayInvoice = $this->getBtcPayInvoice($txnId);
            $this->invoice = $this->getDi()->invoiceTable->findBySecureId(
                $invId,
                $this->getId()
            );

            // Redirect to main signup page if invoices not found
            if (!$this->invoice || !$btcpayInvoice) {
                $this->logDebug('Invoice not found - redirect to signup page');

                return $response->redirectLocation($this->getDi()->url('signup', null, false, true));
            }

            // Redirect to success page if BTCPay invoice is processing or settled
            // Processing means payment is in the mempool and invoice is fully paid
            // Settled means the payment has the required number of confirmations
            if ($btcpayInvoice->isProcessing() || $btcpayInvoice->isSettled()) {
                $this->logDebug('Invoice paid - redirect to thanks page');

                return $response->redirectLocation($this->getReturnUrl());
            }

            // Redirect to cancel page if BTCPay invoice is any other status
            // This means customer clicked link on Invalid/Expired invoice
            $this->logDebug('Invoice not paid - redirect to cancel page');

            return $response->redirectLocation($this->getCancelUrl());
        }

        // Let parent process it
        return parent::directAction($request, $response, $invokeArgs);
    }

    public function allowPartialRefunds()
    {
        return true;
    }

    public function processRefund(InvoicePayment $payment, Am_Paysystem_Result $result, $amount)
    {
        // BTCPay refunds are not instantaneous - they get requested via API
        // and a link is sent to user to claim their refund. We do, however,
        // mark refunds as completed as soon as the link is sent.

        try {
            // Init vars
            $client = $this->getClient();
            $invoice = $payment->getInvoice();
            $subtractPercentage = (float) $this->getConfig('refund_percentage', '0.0');
            $subtractPercentage = (float) max(0, min(100, $subtractPercentage));

            // Issue refund
            $refund = $client->refundInvoice(
                $this->getConfig('store_id'),
                $payment->receipt_id,
                'Custom',
                'BTC',
                null, // name
                null, // description
                $subtractPercentage,
                PreciseNumber::parseString($amount),
                $invoice->currency,
            );
            $this->logDebug('Issued refund: '.print_r($refund->getData(), true));

            // Add user note
            $refundMsg = 'Receipt ID: '.$payment->receipt_id."\n";
            $refundMsg .= 'Refund ID: '.$refund->getId()."\n";
            $refundMsg .= 'Link: '.$refund->getViewLink()."\n";
            $refundMsg .= 'Amount: '.$amount.' '.$payment->currency."\n";
            $note = ___(
                "Successfully issued refund for aMember invoice #%s.\n\n%s",
                $invoice->invoice_id.'/'.$invoice->public_id,
                $refundMsg
            );
            $this->addUserNote($invoice->getUser(), $note);
            $this->logDebug('Added refund user note');

            // Email user
            if ($this->getConfig('send_refund_email')) {
                $this->sendRefundLinkEmail($invoice, $refund->getViewLink());
                $this->logDebug('Sent refund link to user');
            }

            // All done
            $trans = new Am_Paysystem_Transaction_Manual($this);
            $trans->setAmount($amount);
            $trans->setReceiptId($payment->receipt_id.'-btcpay-refund');
            $result->setSuccess($trans);
        } catch (\Throwable $e) {
            $this->getDi()->logger->error(
                'Failed to refund BTCPay invoice',
                ['exception' => $e]
            );
            $result->setFailed($e->getMessage());
        }
    }

    public static function getEtXml()
    {
        $id = func_get_arg(0);

        // Sync parent class templates
        if ($xml = parent::getEtXml($id)) {
            Am_Di::getInstance()->emailTemplateTable->importXml($xml);
        }

        return <<<CUT
            <table_data name="email_template">
                <row type="email_template">
                    <field name="name">payment.{$id}.email_refund_link</field>
                    <field name="email_template_layout_id">1</field>
                    <field name="lang">en</field>
                    <field name="format">text</field>
                    <field name="subject">%site_title%: Collect Your Refund</field>
                    <field name="txt"><![CDATA[
            We've created a refund request for invoice %invoice.public_id% for product: %product_title%

            However, we need you to confirm the wallet address to send it to.

            Please use this link to complete your refund request, and we will action it asap:
            %refund_link%

            Thank you for your attention!
                    ]]></field>
                </row>

            </table_data>
            CUT;
    }

    public function onSetupEmailTemplateTypes(Am_Event $e)
    {
        parent::onSetupEmailTemplateTypes($e);
        $id = $this->getId();
        $title = $this->getTitle();

        $e->addReturn([
            'id' => "payment.{$id}.email_refund_link",
            'title' => "{$title} Refund Notificaton",
            'mailPeriodic' => Am_Mail::USER_REQUESTED,
            'vars' => [
                'user',
                'invoice',
                'product_title' => ___('Product(s) Title'),
                'refund_link' => ___('Information about refund link, if applicable'),
            ],
        ], "payment.{$id}.email_refund_link");
    }

    public function sendRefundLinkEmail($invoice, $refundLink)
    {
        $id = $this->getId();

        try {
            if ($et = Am_Mail_Template::load("payment.{$id}.email_refund_link")) {
                $et->setUser($invoice->getUser());
                $et->setInvoice($invoice);
                $products = [];
                foreach ($invoice->getProducts() as $product) {
                    $products[] = $product->getTitle();
                }
                $et->setProduct_title(implode(', ', $products));
                $et->setProduct_title_html(sprintf('<ul>%s</ul>', implode("\n", array_map(fn ($_) => sprintf('<li>%s</li>', Am_Html::escape($_)), $products))));

                $et->setRefund_link($refundLink);
                $et->setMailPeriodic(Am_Mail::USER_REQUESTED);
                $et->send($invoice->getUser());
            }
        } catch (Exception $e) {
            // No mail exceptions when  rebilling;
            $this->getDi()->logger->error(
                "Could not send refund link for invoice#{$invoice->public_id}",
                ['exception' => $e]
            );
        }
    }

    /**
     * Convenience method to add a user note.
     *
     * @param Am_Record|string $user    aMember user or email
     * @param string           $content The note content
     *
     * @return null|Am_Record The aMember user or null
     */
    public function addUserNote($user, $content)
    {
        // Lookup user by email if needed
        if (!$user instanceof Am_Record) {
            $user = $this->getDi()->userTable->findFirstByEmail($user);
            if (!$user) {
                return null; // user not found
            }
        }

        // Build and insert note
        $note = $this->getDi()->userNoteRecord;
        $note->user_id = $user->user_id;
        $note->dattm = $this->getDi()->sqlDateTime;
        $note->content = $content;
        $note->insert();

        return $user;
    }

    // Override to selectively log only if Debug is set
    public function logDebug($message): void
    {
        if (!$this->getConfig('debug')) {
            return;
        }
        $short = str_replace('Am_Paysystem_', '', get_class($this));
        $this->logger->info($short.': '.$message, ['class' => get_class($this)]);
    }

    public function getReadme()
    {
        $version = self::PLUGIN_REVISION;
        $ilog_url = Am_Di::getInstance()->url('default/admin-logs/p/invoice');
        $elog_url = Am_Di::getInstance()->url('default/admin-logs/');

        return <<<README
            <strong>BTCPay Plugin v{$version}</strong>
            BTCPay Server is a free and open-source cryptocurrency payment processor which allows you to receive payments in Bitcoin (on-chain and via the Lightning Network) and altcoins directly, with no fees, transaction cost or a middleman.

            If you do not already have a BTCPay server account, please <a target="_blank" href="https://docs.btcpayserver.org/FAQ/Deployment/">choose a deployment method</a>.

            <strong>Instructions</strong>

            1. Upload this plugin's folder and files to the <strong>amember/application/default/plugins/payment/</strong> folder of your aMember installatiion.

            2. Enable the plugin at <strong>aMember Admin -&gt; Setup/Configuration -&gt; Plugins</strong>

            3. Configure the plugin at <strong>aMember Admin -&gt; Setup/Configuration -&gt; BTCPay.</strong>

            <strong>Troubleshooting</strong>
            This plugin writes BTCPay Server responses to the aMember <a href="{$ilog_url}">Invoice log</a>.

            In case of an error, please check there as well as in the aMember <a href="{$elog_url}">Error log</a>.

            -------------------------------------------------------------------------------

            Copyright 2024 (c) Rob Woodgate, Cogmentis Ltd.

            This plugin is provided under the MIT License.

            This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
            WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.

            <strong>Like this plugin?</strong> <a href="https://donate.cogmentis.com" target="_blank">Buy me a coffee</a>

            -------------------------------------------------------------------------------
            README;
    }
}

/**
 * BTCPay Transaction Class.
 */
class Am_Paysystem_Transaction_BtсpayGf extends Am_Paysystem_Transaction_Incoming
{
    /**
     * @var ResultInvoice
     */
    private $btcpayInvoice;
    private $event;

    public function __construct(
        Am_Paysystem_Abstract $plugin,
        Am_Mvc_Request $request,
        Am_Mvc_Response $response,
        $invokeArgs
    ) {
        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->event = json_decode($request->getRawBody(), true);
    }

    public function getReceiptId()
    {
        return (string) $this->event['invoiceId'];
    }

    public function getUniqId()
    {
        return (string) $this->event['originalDeliveryId'];
    }

    public function findInvoiceId()
    {
        return $this->event['metadata']['orderId'] ?? null;
    }

    public function processValidated()
    {
        switch ($this->event['type']) {
            case 'InvoiceProcessing':
            case 'InvoiceInvalid':
                // Nothing to do here

                break;

            case 'InvoicePaymentSettled':
            case 'InvoiceSettled':
                // Get the full BTCPay invoice
                $btcpayInvoice = $this->getPlugin()->getBtcPayInvoice($this->event['invoiceId']);

                // Log partial payments for manual checking
                if ($btcpayInvoice->isPartiallyPaid()) {
                    $note = ___(
                        "Partial payment received (could be more transactions incoming). Please check:\n\naMember invoice #%s.\nBTCPay Invoice: #%s",
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id,
                        $this->event['invoiceId'],
                    );
                    $this->getPlugin()->addUserNote($this->invoice->getUser(), $note);
                    $this->log->add('Added partially paid user note');
                }

                // Log late paid invoices for manual checking
                if ($btcpayInvoice->isPaidLate()) {
                    $note = ___(
                        "The following BTCPay invoice was paid after it had expired. Please check:\n\naMember invoice #%s.\nBTCPay Invoice: #%s",
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id,
                        $this->event['invoiceId'],
                    );
                    $this->getPlugin()->addUserNote($this->invoice->getUser(), $note);
                    $this->log->add('Added paid late user note');
                }

                // Log overpayments for manual checking
                if ($btcpayInvoice->isOverpaid()) {
                    $note = ___(
                        "The following BTCPay invoice was overpaid. Please check:\n\naMember invoice #%s.\nBTCPay Invoice: #%s",
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id,
                        $this->event['invoiceId'],
                    );
                    $this->getPlugin()->addUserNote($this->invoice->getUser(), $note);
                    $this->log->add('Added overpaid user note');
                }

                // Handle settled invoices (full/final payment received)
                if ($btcpayInvoice->isSettled()) {
                    // Update aMember payment status
                    if (0 == doubleval($this->invoice->first_total) && Invoice::PENDING == $this->invoice->status) {
                        $this->invoice->addAccessPeriod($this);
                    } else {
                        $p = $this->invoice->addPayment($this);
                        $p->data()->set(
                            Am_Paysystem_BtcpayGf::BTCPAY_INVOICE_ID,
                            $this->request->getParam('invoiceId')
                        );
                        $p->save();
                    }
                }

                break;

            case 'InvoiceExpired':
                // Log partial payments for manual checking
                if ($this->event['partiallyPaid']) {
                    $note = ___(
                        "A partial payment was received for an expired BTCPay invoice. Please check:\n\naMember invoice #%s.\nBTCPay Invoice: #%s",
                        $this->invoice->invoice_id.'/'.$this->invoice->public_id,
                        $this->event['invoiceId'],
                    );
                    $this->getPlugin()->addUserNote($this->invoice->getUser(), $note);
                    $this->log->add('Added partially paid user note');
                }

                break;

            default:
                return false;
        }
    }

    public function validateSource()
    {
        // Validate store ID
        if ($this->event['storeId'] != $this->getPlugin()->getConfig('store_id')) {
            $this->log->add('BTCPay: Webhook is not for this store');

            return false;
        }

        // @see https://docs.btcpayserver.org/Development/GreenfieldExample-PHP/#validate-and-process-webhooks
        return Webhook::isIncomingWebhookRequestValid(
            $this->request->getRawBody(),
            $this->request->getHeader('BTCPay-Sig') ?? '',
            $this->getPlugin()->getConfig('webhook_secret')
        );
    }

    public function validateTerms()
    {
        return true; // Handled by BTCPay
    }

    public function validateStatus()
    {
        if (in_array($this->event['type'], BtcpayGfSetupWizardHelper::WEBHOOK_EVENTS)) {
            return true;
        }

        return false;
    }
}

// Setup Wizard Helper Class.
class BtcpayGfSetupWizardHelper
{
    public const REQUIRED_PERMISSIONS = [
        'btcpay.store.canviewinvoices',
        'btcpay.store.cancreateinvoice',
        'btcpay.store.canviewstoresettings',
        'btcpay.store.canmodifyinvoices',
    ];
    public const OPTIONAL_PERMISSIONS = [
        // 'btcpay.store.cancreatenonapprovedpullpayments',
        'btcpay.store.cancreatepullpayments',
        'btcpay.store.webhooks.canmodifywebhooks',
    ];

    public const WEBHOOK_EVENTS = [
        'InvoiceReceivedPayment',
        'InvoicePaymentSettled',
        'InvoiceProcessing',
        'InvoiceExpired',
        'InvoiceSettled',
        'InvoiceInvalid',
    ];

    private $serverUrl;
    private $apiKey;
    private $permissions;

    public function __construct($serverUrl, $data)
    {
        $this->serverUrl = $serverUrl ?? null;
        $this->apiKey = $data['apiKey'] ?? null;
        $this->permissions = $data['permissions'] ?? [];
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function getStoreID(): string
    {
        return explode(':', $this->permissions[0])[1];
    }

    public function hasRequiredPermissions(): bool
    {
        $permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
            return array_merge($carry, [explode(':', $permission)[0]]);
        }, []);

        // Remove optional permissions so that only required ones are left.
        $permissions = array_diff($permissions, self::OPTIONAL_PERMISSIONS);

        return empty(array_merge(
            array_diff(self::REQUIRED_PERMISSIONS, $permissions),
            array_diff($permissions, self::REQUIRED_PERMISSIONS)
        ));
    }

    public function hasSingleStore(): bool
    {
        $storeId = null;
        foreach ($this->permissions as $perms) {
            if (2 !== count($exploded = explode(':', $perms))) {
                return false;
            }

            if (null === ($receivedStoreId = $exploded[1])) {
                return false;
            }

            if ($storeId === $receivedStoreId) {
                continue;
            }

            if (null === $storeId) {
                $storeId = $receivedStoreId;

                continue;
            }

            return false;
        }

        return true;
    }

    public function hasRefundsPermission(): bool
    {
        $permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
            return array_merge($carry, [explode(':', $permission)[0]]);
        }, []);

        return in_array('btcpay.store.cancreatenonapprovedpullpayments', $permissions, true);
    }

    public function hasWebhookPermission(): bool
    {
        $permissions = array_reduce($this->permissions, static function (array $carry, string $permission) {
            return array_merge($carry, [explode(':', $permission)[0]]);
        }, []);

        return in_array('btcpay.store.webhooks.canmodifywebhooks', $permissions, true);
    }

    public function registerWebhook($url): ?WebhookResult
    {
        try {
            $this->deleteWebhooks($url); // delete existing webhooks for this url
            $whClient = new Webhook($this->serverUrl, $this->apiKey);

            return $whClient->createWebhook(
                $this->getStoreID(),
                $url,
                self::WEBHOOK_EVENTS,
                null
            );
        } catch (\Throwable $e) {
            throw new Am_Exception_InternalError('Error creating a new webhook on BTCPay Server instance: '.$e->getMessage());
        }

        return null;
    }

    public function deleteWebhooks($url): ?WebhookResult
    {
        try {
            $whClient = new Webhook($this->serverUrl, $this->apiKey);
            $webhooks = $whClient->getStoreWebhooks($this->getStoreID());
            foreach ($webhooks->all() as $v) {
                if ($url == $v['url']) {
                    $whClient->deleteWebhook($this->getStoreID(), $v['id']);
                }
            }
        } catch (\Throwable $e) {
            throw new Am_Exception_InternalError('Error deleting old webhooks on BTCPay Server instance: '.$e->getMessage());
        }

        return null;
    }
}
