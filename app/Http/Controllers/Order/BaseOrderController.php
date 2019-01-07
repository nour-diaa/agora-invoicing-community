<?php

namespace App\Http\Controllers\Order;

use App\Model\Common\StatusSetting;
use App\Model\Order\Order;
use App\Model\Product\Product;
use App\User;
use Bugsnag;
use Crypt;
use DateTime;
use DateTimeZone;
use App\Http\Controllers\License\LicensePermissionsController;

class BaseOrderController extends ExtendedOrderController
{
    public function getEndDate($model)
    {
        $end = '--';
        $ends = $model->subscription()->first();
        if ($ends) {
            if ($ends->ends_at != '0000-00-00 00:00:00') {
                $date1 = new DateTime($ends->ends_at);
                $tz = \Auth::user()->timezone()->first()->name;
                $date1->setTimezone(new DateTimeZone($tz));
                $end = $date1->format('M j, Y, g:i a ');
            }
        }

        return $end;
    }

    public function getUrl($model, $status, $sub)
    {
        $url = '';
        if ($status == 'success') {
            if ($sub) {
                $url = '<a href='.url('renew/'.$sub->id)." 
                class='btn btn-sm btn-primary btn-xs'><i class='fa fa-refresh'
                 style='color:white;'> </i>&nbsp;&nbsp;Renew</a>";
            }
        }

        return '<p><a href='.url('orders/'.$model->id)." 
        class='btn btn-sm btn-primary btn-xs'><i class='fa fa-eye'
         style='color:white;'> </i>&nbsp;&nbsp;View</a> $url</p>";
    }

    /**
     * inserting the values to orders table.
     *
     * @param type $invoiceid
     * @param type $order_status
     *
     * @throws \Exception
     *
     * @return string
     */
    public function executeOrder($invoiceid, $order_status = 'executed')
    {
        try {
            $invoice_items = $this->invoice_items->where('invoice_id', $invoiceid)->get();
            $user_id = $this->invoice->find($invoiceid)->user_id;
            if (count($invoice_items) > 0) {
                foreach ($invoice_items as $item) {
                    if ($item) {
                        $items = $this->getIfItemPresent($item, $invoiceid, $user_id, $order_status);
                    }
                }
            }

            return 'success';
        } catch (\Exception $ex) {
            Bugsnag::notifyException($ex);

            throw new \Exception($ex->getMessage());
        }
    }

    public function getIfItemPresent($item, $invoiceid, $user_id, $order_status)
    {
        $product = $this->getProductByName($item->product_name)->id;
        $version = $this->getProductByName($item->product_name)->version;
        if ($version == null) {
            $version = $this->product_upload->select('version')->where('product_id', $product)->first();
        }
        $price = $item->subtotal;
        $qty = $item->quantity;
        $serial_key = $this->generateSerialKey();
        $domain = $item->domain;
        $plan_id = $this->plan($item->id);
        $order = $this->order->create([
            'invoice_id'      => $invoiceid,
            'invoice_item_id' => $item->id,
            'client'          => $user_id,
            'order_status'    => $order_status,
            'serial_key'      => Crypt::encrypt($serial_key),
            'product'         => $product,
            'price_override'  => $price,
            'qty'             => $qty,
            'domain'          => $domain,
            'number'          => $this->generateNumber(),
        ]);
        $this->addOrderInvoiceRelation($invoiceid, $order->id);
        if ($this->checkOrderCreateSubscription($order->id) == true) {
            $this->addSubscription($order->id, $plan_id, $version, $product, $serial_key);
        }
        $this->sendOrderMail($user_id, $order->id, $item->id);
        //Update Subscriber To Mailchimp
        $mailchimp = new \App\Http\Controllers\Common\MailChimpController();
        $email = User::where('id', $user_id)->pluck('email')->first();
        if ($price > 0) {
            $r = $mailchimp->updateSubscriberForPaidProduct($email, $product);
        } else {
            $r = $mailchimp->updateSubscriberForFreeProduct($email, $product);
        }
    }

    /**
     * get the product model by name.
     *
     * @param type $name
     *
     * @throws \Exception
     *
     * @return type
     */
    public function getProductByName($name)
    {
        try {
            return $this->product->where('name', $name)->first();
        } catch (\Exception $ex) {
            Bugsnag::notifyException($ex);

            throw new \Exception($ex->getMessage());
        }
    }

    /**
     * inserting the values to subscription table.
     *
     * @param int $orderid
     * @param int $planid
     * @param string $version
     * @param int $product
      * @param string $serial_key

     * @throws \Exception
     * @author Ashutosh Pathak <ashutosh.pathak@ladybirdweb.com>
     */
    public function addSubscription($orderid, $planid, $version, $product, $serial_key)
    {
        try {
            $permissions = LicensePermissionsController::getPermissionsForProduct($product);
            if ($version == null) {
                $version = '';
            }
            if ($planid != 0) {
                $days = $this->plan->where('id', $planid)->first()->days;
                $licenseExpiry =  $this->getLicenseExpiryDate($permissions['generateLicenseExpiryDate'], $days);
                $updatesExpiry = $this->getUpdatesExpiryDate($permissions['generateUpdatesxpiryDate'], $days);
                $user_id = $this->order->find($orderid)->client;
                $this->subscription->create(['user_id' => $user_id,
                    'plan_id'  => $planid, 'order_id' => $orderid, 'update_ends_at' =>$updatesExpiry, 'ends_at' => $licenseExpiry,
                     'version' => $version, 'product_id' =>$product, ]);
            }
            $licenseStatus = StatusSetting::pluck('license_status')->first();
            if ($licenseStatus == 1) {
                $cont = new \App\Http\Controllers\License\LicenseController();
                $createNewLicense = $cont->createNewLicene($orderid, $product, $user_id, $licenseExpiry,$updatesExpiry,$serial_key);
            }
        } catch (\Exception $ex) {
            Bugsnag::notifyException($ex);
            app('log')->error($ex->getMessage());
            throw new \Exception('Can not Generate Subscription');
        }
    }
    
    /**
     *  Get the Expiry Date for License.
     * @param  boolean    $permissions [Whether Permissons for generating License Expiry Date are there or not]
     * @param  int    $days        [No of days that would get addeed to the current date ]
     * @return string              [The final License Expiry date that is generated]
     */
    protected function getLicenseExpiryDate(bool $permissions, int $days)
    {
        $ends_at = '';
        if ($days > 0 && $permissions == 1) {
            $dt = \Carbon\Carbon::now();
            $ends_at = $dt->addDays($days);
        }
        return $ends_at;
    }
    
    /**
    *  Get the Expiry Date for Updates.
    * @param  boolean    $permissions [Whether Permissons for generating Updates Expiry Date are there or not]
    * @param  int    $days        [No of days that would get added to the current date ]
    * @return string              [The final Updates Expiry date that is generated]
    */
    protected function getUpdatesExpiryDate(bool $permissions, int $days)
    {
        $update_ends_at = '';
        if ($days > 0 && $permissions == 1) {
            $dt = \Carbon\Carbon::now();
            $update_ends_at = $dt->addDays($days);
        }
        return $update_ends_at;
    }

    public function addOrderInvoiceRelation($invoiceid, $orderid)
    {
        try {
            $relation = new \App\Model\Order\OrderInvoiceRelation();
            $relation->create(['order_id' => $orderid, 'invoice_id' => $invoiceid]);
        } catch (\Exception $ex) {
            Bugsnag::notifyException($ex);

            throw new \Exception($ex->getMessage());
        }
    }

    public function checkOrderCreateSubscription($orderid)
    {
        $order = $this->order->find($orderid);
        $result = true;
        $invoice = $this->invoice_items->where('invoice_id', $order->invoice_id)->first();
        if ($invoice) {
            $product_name = $invoice->product_name;
            $renew_con = new RenewController();
            $product = $renew_con->getProductByName($product_name);
            if ($product) {
                $subscription = $product->subscription;
                if ($subscription == 0) {
                    $result = false;
                }
            }
        }

        return $result;
    }

    public function sendOrderMail($userid, $orderid, $itemid)
    {
        //order
        $order = $this->order->find($orderid);
        //product
        $product = $this->product($itemid);
        //user
        $productId = Product::where('name', $product)->pluck('id')->first();
        $users = new User();
        $user = $users->find($userid);
        //check in the settings
        $settings = new \App\Model\Common\Setting();
        $setting = $settings->where('id', 1)->first();
        $orders = new Order();
        $order = $orders->where('id', $orderid)->first();
        $invoice = $this->invoice->find($order->invoice_id);
        $number = $invoice->number;
        $downloadurl = '';
        if ($user && $order->order_status == 'Executed') {
            $downloadurl = url('product/'.'download'.'/'.$productId.'/'.$number);
        }
        // $downloadurl = $this->downloadUrl($userid, $orderid,$productId);
        $myaccounturl = url('my-order/'.$orderid);
        $invoiceurl = $this->invoiceUrl($orderid);
        //template
        $mail = $this->getMail($setting, $user, $downloadurl, $invoiceurl, $order, $product, $orderid, $myaccounturl);
    }

    public function getMail($setting, $user, $downloadurl, $invoiceurl, $order, $product, $orderid, $myaccounturl)
    {
        $templates = new \App\Model\Common\Template();
        $temp_id = $setting->order_mail;
        $template = $templates->where('id', $temp_id)->first();
        $from = $setting->email;
        $to = $user->email;
        $subject = $template->name;
        $data = $template->data;
        $replace = [
            'name'         => $user->first_name.' '.$user->last_name,
             'serialkeyurl'=> $myaccounturl,
            'downloadurl'  => $downloadurl,
            'invoiceurl'   => $invoiceurl,
            'product'      => $product,
            'number'       => $order->number,
            'expiry'       => $this->expiry($orderid),
            'url'          => $this->renew($orderid),

            ];
        $type = '';
        if ($template) {
            $type_id = $template->type;
            $temp_type = new \App\Model\Common\TemplateType();
            $type = $temp_type->where('id', $type_id)->first()->name;
        }
        $templateController = new \App\Http\Controllers\Common\TemplateController();
        $mail = $templateController->mailing($from, $to, $data, $subject, $replace, $type);

        return $mail;
    }

    public function invoiceUrl($orderid)
    {
        $orders = new Order();
        $order = $orders->where('id', $orderid)->first();
        $invoiceid = $order->invoice_id;
        $url = url('my-invoice/'.$invoiceid);

        return $url;
    }

    /**
     * get the price of a product by id.
     *
     * @param type $product_id
     *
     * @throws \Exception
     *
     * @return type collection
     */
    public function getPrice($product_id)
    {
        try {
            return $this->price->where('product_id', $product_id)->first();
        } catch (\Exception $ex) {
            Bugsnag::notifyException($ex);

            throw new \Exception($ex->getMessage());
        }
    }

    public function downloadUrl($userid, $orderid)
    {
        $orders = new Order();
        $order = $orders->where('id', $orderid)->first();
        $invoice = $this->invoice->find($order->invoice_id);
        $number = $invoice->number;
        $url = url('download/'.$userid.'/'.$number);

        return $url;
    }
}
