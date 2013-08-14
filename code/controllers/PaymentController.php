<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    CosmoCommerce
 * @package     CosmoCommerce_Unionpay
 * @copyright   Copyright (c) 2009-2013 CosmoCommerce,LLC. (http://www.cosmocommerce.com)
 * @contact :
 * T: +86-021-66346672
 * L: Shanghai,China
 * M:sales@cosmocommerce.com
 */
class CosmoCommerce_Unionpay_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Order instance
     */
    protected $_order;
	protected $_gateway="https://payment.chinapay.com/pay/TransGet?";

    /**
     *  Get order
     *
     *  @param    none
     *  @return	  Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        if ($this->_order == null)
        {
            $session = Mage::getSingleton('checkout/session');
            $this->_order = Mage::getModel('sales/order');
            $this->_order->loadByIncrementId($session->getLastRealOrderId());
        }
        return $this->_order;
    }

    /**
     * When a customer chooses Unionpay on Checkout/Payment page
     *
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setUnionpayPaymentQuoteId($session->getQuoteId());

        $order = $this->getOrder();

        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }

        $order->addStatusToHistory(
        $order->getStatus(),
        Mage::helper('unionpay')->__('Customer was redirected to Unionpay')
        );
        $order->save();

        $this->getResponse()
        ->setBody($this->getLayout()
        ->createBlock('unionpay/redirect')
        ->setOrder($order)
        ->toHtml());

        $session->unsQuoteId();
    }

    public function notifyAction()
    {
        if ($this->getRequest()->isPost())
        {
            $postData = $this->getRequest()->getPost();
            $method = 'post';


        } else if ($this->getRequest()->isGet())
        {
            $postData = $this->getRequest()->getQuery();
            $method = 'get';

        } else
        {
            return;
        }
		$unionpay = Mage::getModel('unionpay/payment');
		
		$pubKey=$unionpay->getConfigData('partner_id');
		
			
		$merid=$postData['merid'];
		$orderno=$postData['orderno'];
		$amount=$postData['amount'];
		$currencycode=$postData['currencycode'];
		$transdate=$postData['transdate'];
		$transtype=$postData['transtype'];
		$status=$postData['status'];
		$checkvalue=$postData['checkvalue'];
		
		$mer_id = $unionpay->buildKey($pubKey);
		if(!$mer_id) { 
			Mage::log('导入私钥文件失败！', null, 'unionpay_callback.log');
			exit;
		}
		$flag=verifyTransResponse($merid,$orderno,$amount,$currencycode,$transdate,$transtype,$status,$checkvalue);
		
		$real_ordid = $unionpay->chinapaysn2magento($orderno);
		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($real_ordid);
		if(!$flag) {
			Mage::log('验证签名失败！', null, 'unionpay_callback.log'); 
			$order->addStatusToHistory(
			$order->getStatus(),
			Mage::helper('unionpay')->__('验证错误'));
			try{
				$order->save();
			} catch(Exception $e){
				
			}
		}else{
			Mage::log('验证签名ok！', null, 'unionpay_callback.log'); 	if ($status == '1001'){
			if ($status == '1001'){
			
				$order->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
				$order->addStatusToHistory(
				$alipay->getConfigData('order_status_payment_accepted'),
				Mage::helper('alipay')->__('付款成功'));
				try{
					$order->save();
				} catch(Exception $e){
					
				}
				
				
				
			}else{
			
				$order->addStatusToHistory(
				$order->getStatus(),
				Mage::helper('unionpay')->__('付款失败'));
				try{
					$order->save();
				} catch(Exception $e){
					
				}
			}
		}
	
	
		
		
    }

    protected function saveInvoice(Mage_Sales_Model_Order $order)
    {
        if ($order->canInvoice())
        {
            $convertor = Mage::getModel('sales/convert_order');
            $invoice = $convertor->toInvoice($order);
            foreach ($order->getAllItems() as $orderItem)
            {
                if (!$orderItem->getQtyToInvoice())
                {
                    continue ;
                }
                $item = $convertor->itemToInvoiceItem($orderItem);
                $item->setQty($orderItem->getQtyToInvoice());
                $invoice->addItem($item);
            }
            $invoice->collectTotals();
            $invoice->register()->capture();
            Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
            return true;
        }

        return false;
    }

    /**
     *  Success payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function successAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getUnionpayPaymentQuoteId());
        $session->unsUnionpayPaymentQuoteId();

        $order = $this->getOrder();

        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }

        $order->addStatusToHistory(
        $order->getStatus(),
        Mage::helper('unionpay')->__('Customer successfully returned from Unionpay')
        );

        $order->save();

        $this->_redirect('checkout/onepage/success');
    }

    /**
     *  Failure payment page
     *
     *  @param    none
     *  @return	  void
     */
    public function errorAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $errorMsg = Mage::helper('unionpay')->__(' There was an error occurred during paying process.');

        $order = $this->getOrder();

        if (!$order->getId())
        {
            $this->norouteAction();
            return;
        }
        if ($order instanceof Mage_Sales_Model_Order && $order->getId())
        {
            $order->addStatusToHistory(
            Mage_Sales_Model_Order::STATE_CANCELED,//$order->getStatus(),
            Mage::helper('unionpay')->__('Customer returned from Unionpay.').$errorMsg
            );

            $order->save();
        }

        $this->loadLayout();
        $this->renderLayout();
        Mage::getSingleton('checkout/session')->unsLastRealOrderId();
    }
	
	
}
