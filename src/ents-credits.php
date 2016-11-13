<?php

/**
 * Class Am_Plugin_EntsCredits
 */
class Am_Plugin_EntsCredits extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = "1.0.0";

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle("ENTS: Credits");

        $form->addInteger("credits_per_dollar")->setLabel(___("Credits per dollar"))->addRule('gte', 1);

        $form->addFieldsPrefix("misc.ents-credits.");
    }

    public function isConfigured()
    {
        $hasCreditPerDollar = $this->getConfig("credits_per_dollar", 0) > 0;
        $hasCreditsPlugin = $this->getDi()->plugins_misc->loadGet("credits");
        return $hasCreditPerDollar && $hasCreditsPlugin;
    }

    /**
     * Gets the dollar credit balance for a user
     * @param $userId int the user ID to lookup
     * @return float the amount of credit, in dollars
     */
    public function getDollarBalance($userId)
    {
        if (!$this->isConfigured()) return 0;
        return $this->getDi()->plugins_misc->loadGet("credits")->balance($userId) / (int)$this->getConfig("credits_per_dollar");
    }

    /**
     * Finds the product that would allow the user to purchase $1 in credit. If a product
     * cannot be found, null is returned.
     *
     * The product conditions are:
     * - It must have the same number of credits specified in the config
     * - It must be $1
     * - It cannot be recurring (or have a second price)
     * - It must allow the quantity to be adjusted
     *
     * @return Product The product found, or null if none
     */
    public function findProductForCreditPurchase()
    {
        if (!$this->isConfigured()) return null;

        $productsTable = $this->getDi()->productTable;
        $products = $productsTable->findBy(); // find all
        $creditsPerDollar = (int)$this->getConfig("credits_per_dollar");

        foreach ($products as $product) {
            if ($product->getRebillTimes() != 0) continue;
            if (!$product->getIsVariableQty()) continue;
            if ($product->getFirstPrice() != 1.00) continue;
            if ($product->data()->get("credit") != $creditsPerDollar) continue;
            return $product;
        }

        return null;
    }

    // Remove 'Credits' link from user menu - we'll be replacing this with our own view
    function onUserMenu(Am_Event $event)
    {
        if (!$this->isConfigured()) return;

        $menu = $event->getMenu();
        $menu->addPage(array(
            "id" => "ents-credits",
            "label" => ___("Credits"),
            "controller" => "ents-credits",
            "action" => "index",
            "order" => 900
        ));
    }

    function getReadme()
    {
        return <<<CUT
This plugin enhances the capability of the Credits plugin (found here: http://www.amember.com/docs/Integration/Credits). 

It is recomended to disable the user links in the Credits settings page.

A product must be created that has the following characteristics:
* The number of credits awarded for purchasing the item must equal what is specified above
* The cost of the product must be $1
* The product cannot be recurring or have a second price
* The product must allow a user-defined quantity to be purchased

It is also recommended to disable the product and make it a lifetime product.

Plugin created by ENTS (Edmonton New Technology Society)
* Source: https://github.com/ENTS-Source/amember-credits
* For help and support please contact us: https://ents.ca/contact/
CUT;
    }
}

/**
 * Class EntsCreditsController
 */
class EntsCreditsController extends Am_Mvc_Controller
{

    private function getEntsCreditPlugin()
    {
        return $this->getDi()->plugins_misc->loadGet("ents-credits");
    }

    public function indexAction()
    {
        $user = $this->getDi()->auth->getUser();
        $entsPlugin = $this->getEntsCreditPlugin();

        $historyQuery = new Am_Query($this->getDi()->creditTable);
        $historyQuery->addOrder("dattm", true);
        $historyQuery->addWhere("user_id=?", $user->user_id);

        $grid = new Am_Grid_ReadOnly("_tr", "Transaction History", $historyQuery, $this->getRequest(), $this->view, $this->getDi());
        $grid->addField(new Am_Grid_Field_Date("dattm", "Timestamp"))->setFormatDatetime();
        $grid->addField("value", "Amount", false, Am_Grid_Field::RIGHT)->setFormatFunction(function ($value) {
            $entsPlugin = $this->getEntsCreditPlugin();
            $value = round((int)$value / (int)$entsPlugin->getConfig("credits_per_dollar"), 2);
            return "$" . $value;
        });
        $grid->addField("comment", "Description", false);

        $this->view->grid = $grid;
        $this->view->balance = $entsPlugin->getDollarBalance($user->user_id);
        $this->view->display("ents-credits/index.phtml");
    }

    public function addAction()
    {
        $user = $this->getDi()->auth->getUser();
        $entsPlugin = $this->getEntsCreditPlugin();

        $amount = $this->getRequest()->getParam("amount", null);
        $this->view->amount = $amount;

        if ($amount) {
            if (strval($amount) != strval(intval($amount))) {
                $this->view->validationError = "Not a valid integer, please try again.";
            } else {
                $amount = intval($amount);
                $product = $entsPlugin->findProductForCreditPurchase();

                $invoice = $this->getDi()->invoiceRecord;
                $invoice->add($product, $amount);
                $invoice->setUser($user);
                $errors = $invoice->validate();
                if ($errors) {
                    throw new Exception("Could not create invoice: " . $errors);
                }

                $invoice->calculate();
                $invoice->insert();

                header("Location: " . ROOT_SURL . "/pay/" . $invoice->getSecureId("payment-link"));
                return;
            }
        }

        $this->view->balance = $entsPlugin->getDollarBalance($user->user_id);
        $this->view->display("ents-credits/add.phtml");
    }
}