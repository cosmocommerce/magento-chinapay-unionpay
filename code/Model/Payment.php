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
class CosmoCommerce_Unionpay_Model_Payment extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = 'unionpay_payment';
    protected $_formBlockType = 'unionpay/form';
	protected $_gateway="https://payment.chinapay.com/pay/TransGet";

    // Unionpay return codes of payment
    const RETURN_CODE_ACCEPTED      = 'Success';
    const RETURN_CODE_TEST_ACCEPTED = 'Success';
    const RETURN_CODE_ERROR         = 'Fail';

    // Payment configuration
    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

    // Order instance
    protected $_order = null;

	

	protected $private_key = array();

    public function __construct()
    {
        bcscale(0);
		parent::__construct();
    }

    /**
     *  Returns Target URL
     *
     *  @return	  string Target URL
     */
    public function getUnionpayUrl()
    {
        $url = $this->_gateway;
        return $url;
    }

    /**
     *  Return back URL
     *
     *  @return	  string URL
     */
	protected function getReturnURL()
	{
		return Mage::getUrl('checkout/onepage/success', array('_secure' => true));
	}

	/**
	 *  Return URL for Unionpay success response
	 *
	 *  @return	  string URL
	 */
	protected function getSuccessURL()
	{
		return Mage::getUrl('checkout/onepage/success', array('_secure' => true));
	}

    /**
     *  Return URL for Unionpay failure response
     *
     *  @return	  string URL
     */
    protected function getErrorURL()
    {
        return Mage::getUrl('unionpay/payment/error', array('_secure' => true));
    }

	/**
	 *  Return URL for Unionpay notify response
	 *
	 *  @return	  string URL
	 */
	protected function getNotifyURL()
	{
		return Mage::getUrl('unionpay/payment/notify/', array('_secure' => true));
	}

    /**
     * Capture payment
     *
     * @param   Varien_Object $orderPayment
     * @return  Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        $payment->setStatus(self::STATUS_APPROVED)
            ->setLastTransId($this->getTransactionId());

        return $this;
    }

    /**
     *  Form block description
     *
     *  @return	 object
     */
    public function createFormBlock($name)
    {
        $block = $this->getLayout()->createBlock('unionpay/form_payment', $name);
        $block->setMethod($this->_code);
        $block->setPayment($this->getPayment());

        return $block;
    }

    /**
     *  Return Order Place Redirect URL
     *
     *  @return	  string Order Redirect URL
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('unionpay/payment/redirect');
    }

    /**
     *  Return Standard Checkout Form Fields for request to Unionpay
     *
     *  @return	  array Array of hidden form fields
     */
    public function getStandardCheckoutFormFields()
    {
        $session = Mage::getSingleton('checkout/session');
        
        $order = $this->getOrder();
        if (!($order instanceof Mage_Sales_Model_Order)) {
            Mage::throwException($this->_getHelper()->__('Cannot retrieve order object'));
        }
		$converted_final_price=$order->getGrandTotal();
		
		$security_code = $this->getConfigData('security_code');
		$merid = $this->buildKey($security_code);
		if(!$merid) {
			echo "导入私钥文件失败！";
			exit;
		}
			
		$site_url ="http://www.baidu.com";
		$ordid = $this->magento2chinapaysn($order->getRealOrderId(),$merid);
		$transamt = $this->formatamount(sprintf('%.2f',$converted_final_price));
		$curyid = "156";
		$transdate = date('Ymd',time());
		$transtype = "0001";
		$version = "20040916";
		$pagereturl = $this->getReturnURL();
		$bgreturl = $this->getNotifyURL();
		$gateid = "";
		$priv1 = "";    
		$chkvalue = $this->signOrder($merid,$ordid,$transamt,$curyid,$transdate,$transtype);
		
		
		if (!$chkvalue) {
			echo "签名失败！";
			exit;
		}
		
		$parameter = array('MerId'           => $merid,
						   'OrdId'           => $ordid,
						   'TransAmt'        => $transamt,
						   'CuryId'        => $curyid,
						   'TransDate'    => $transdate,
						   'TransType'           => $transtype, 
						   'Version'              => $version,
						   'BgRetUrl'      => $bgreturl, 
						   'PageRetUrl'             => $pagereturl,
						   'GateId'      => $gateid,
						   'Priv1'      => $priv1,
						   'ChkValue'      => $chkvalue
						);
		/*
		foreach($parameter as $d){
			echo $d;
			echo "<br />";
		}
		*/
        return $parameter;
    }
    
	
   
	/**
	 * Return authorized languages by Unionpay
	 *
	 * @param	none
	 * @return	array
	 */
	protected function _getAuthorizedLanguages()
	{
		$languages = array();
		
        foreach (Mage::getConfig()->getNode('global/payment/unionpay_payment/languages')->asArray() as $data) 
		{
			$languages[$data['code']] = $data['name'];
		}
		
		return $languages;
	}
	
	/**
	 * Return language code to send to Unionpay
	 *
	 * @param	none
	 * @return	String
	 */
	protected function _getLanguageCode()
	{
		// Store language
		$language = strtoupper(substr(Mage::getStoreConfig('general/locale/code'), 0, 2));

		// Authorized Languages
		$authorized_languages = $this->_getAuthorizedLanguages();

		if (count($authorized_languages) === 1) 
		{
			$codes = array_keys($authorized_languages);
			return $codes[0];
		}
		
		if (array_key_exists($language, $authorized_languages)) 
		{
			return $language;
		}
		
		// By default we use language selected in store admin
		return $this->getConfigData('language');
	}
 

	public function hex2binphp($hexdata)
	{
		$bindata = '';
		if (strlen($hexdata)%2 == 1) {
			$hexdata = "0{$hexdata}";
		}
		for ($i=0;$i<strlen($hexdata);$i+=2) {
			$bindata .= chr(hexdec(substr($hexdata,$i,2)));
		}
		return $bindata;
	}

	public function bin2int($bindata)
	{
		$hexdata = bin2hex($bindata);
		return $this->bchexdec($hexdata);
	}

	public function bchexdec($hexdata)
	{
		$ret = '0';
		$len = strlen($hexdata);
		for ($i = 0; $i < $len; $i++) {
			$hex = substr($hexdata, $i, 1);
			$dec = hexdec($hex);
			$exp = $len - $i - 1;
			$pow = bcpow('16', $exp);
			$tmp = bcmul($dec, $pow);
			$ret = bcadd($ret, $tmp);
		}
		return $ret;
	}

	public function bcdechex($decdata)
	{
		$s = $decdata;
		$ret = '';
		while ($s != '0') {
			$m = bcmod($s, '16');
			$s = bcdiv($s, '16');
			$hex = dechex($m);
			$ret = $hex . $ret;
		}
		return $ret;
	}

	public function sha1_128($string)
	{
		$hash = sha1($string);
		$sha_bin = $this->hex2binphp($hash);
		$sha_pad = $this->hex2binphp('0001ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff003021300906052b0e03021a05000414');
		return $sha_pad . $sha_bin;
	}

	public function mybcpowmod($num, $pow, $mod)
	{
		if (function_exists('bcpowmod')) {
			return bcpowmod($num, $pow, $mod);
		}
		return $this->emubcpowmod($num, $pow, $mod);
	}

	public function emubcpowmod($num, $pow, $mod)
	{
		$result = '1';
		do {
			if (!bccomp(bcmod($pow, '2'), '1')) {
				$result = bcmod(bcmul($result, $num), $mod);
			}
			$num = bcmod(bcpow($num, '2'), $mod);
			$pow = bcdiv($pow, '2');
		} while (bccomp($pow, '0'));
		return $result;
	}

	public function rsa_encrypt($private_key, $input)
	{
		$p = $this->bin2int($private_key["prime1"]);
		$q = $this->bin2int($private_key["prime2"]);
		$u = $this->bin2int($private_key["coefficient"]);
		$dP = $this->bin2int($private_key["prime_exponent1"]);
		$dQ = $this->bin2int($private_key["prime_exponent2"]);
		$c = $this->bin2int($input);
		$cp = bcmod($c, $p);
		$cq = bcmod($c, $q);
		$a = $this->mybcpowmod($cp, $dP, $p);
		$b = $this->mybcpowmod($cq, $dQ, $q);
		if (bccomp($a, $b) >= 0) {
			$result = bcsub($a, $b);
		} else {
			$result = bcsub($b, $a);
			$result = bcsub($p, $result);
		}
		$result = bcmod($result, $p);
		$result = bcmul($result, $u);
		$result = bcmod($result, $p);
		$result = bcmul($result, $q);
		$result = bcadd($result, $b);
		$ret = $this->bcdechex($result);
		$ret = strtoupper($this->padstr($ret));
		return (strlen($ret) == 256) ? $ret : false; 
	}

	public function padstr($src,$len=256,$chr='0',$d='L') {
		$ret = trim($src);
		$padlen = $len-strlen($ret);
		if ($padlen > 0) {
		$pad = str_repeat($chr,$padlen);
		if (strtoupper($d)=='L') {
		$ret = $pad.$ret;
		} else {
		$ret = $ret.$pad;
		}
		} 
		return $ret;
	}

	public function rsa_decrypt($input)
	{
		$private_key=$this->private_key;
		$check = $this->bchexdec($input);
		$modulus = $this->bin2int($private_key["modulus"]);
		$exponent = $this->bchexdec("010001");
		$result = bcpowmod($check, $exponent, $modulus);
		$rb = $this->bcdechex($result);
		return strtoupper($this->padstr($rb));
	}

	public function buildKey($key)
	{
		$private_key=$this->private_key;
		if (count($private_key) > 0) {
			foreach ($private_key as $name => $value) {
				unset($private_key[$name]);
			}
		}
		$ret = false;
		$key_file = parse_ini_file($key);
		if (!$key_file) {
			return $ret;
		}


		$hex = "";
		if (array_key_exists("MERID", $key_file)) {
			$ret = $key_file["MERID"];
			$private_key["MERID"] = $ret;
			$hex = substr($key_file["prikeyS"], 80);
		} else if (array_key_exists("PGID", $key_file)) {
			$ret = $key_file["PGID"];
			$private_key["PGID"] = $ret;
			$hex = substr($key_file["pubkeyS"], 48);
		} else {
			return $ret;
		}
		$bin = $this->hex2binphp($hex);
		$private_key["modulus"] = substr($bin, 0, 128);
		$cipher = MCRYPT_DES;
		$iv = str_repeat("\x00", 8);
		$prime1 = substr($bin, 384, 64);
		$enc = mcrypt_cbc($cipher, 'SCUBEPGW', $prime1, MCRYPT_DECRYPT, $iv);
		$private_key["prime1"] = $enc;
		$prime2 = substr($bin, 448, 64);
		$enc = mcrypt_cbc($cipher, 'SCUBEPGW', $prime2, MCRYPT_DECRYPT, $iv);
		$private_key["prime2"] = $enc;
		$prime_exponent1 = substr($bin, 512, 64);
		$enc = mcrypt_cbc($cipher, 'SCUBEPGW', $prime_exponent1, MCRYPT_DECRYPT, $iv);
		$private_key["prime_exponent1"] = $enc;
		$prime_exponent2 = substr($bin, 576, 64);
		$enc = mcrypt_cbc($cipher, 'SCUBEPGW', $prime_exponent2, MCRYPT_DECRYPT, $iv);
		$private_key["prime_exponent2"] = $enc;
		$coefficient = substr($bin, 640, 64);
		$enc = mcrypt_cbc($cipher, 'SCUBEPGW', $coefficient, MCRYPT_DECRYPT, $iv);
		$private_key["coefficient"] = $enc;
		
		
		$this->private_key=$private_key;
		return $ret;
	}

	public function sign($msg)
	{
		$private_key=$this->private_key;
		if (!array_key_exists("MERID", $private_key)) {
			return false;
		}
		$hb = $this->sha1_128($msg);
		
		return $this->rsa_encrypt($private_key, $hb);
	}

	public function signOrder($merid, $ordno, $amount, $curyid, $transdate, $transtype)
	{
		if (strlen($merid) != 15) return false;
		if (strlen($ordno) != 16) return false;
		if (strlen($amount) != 12) return false;
		if (strlen($curyid) != 3) return false;
		if (strlen($transdate) != 8) return false;
		if (strlen($transtype) != 4) return false;
		$plain = $merid . $ordno . $amount . $curyid . $transdate . $transtype;
		
		
		return $this->sign($plain);
	}

	public function verify($plain, $check)
	{
		$private_key=$this->private_key;
		if (!array_key_exists("PGID", $private_key)) {
			return false;
		}
		if (strlen($check) != 256) {
			return false;
		}
		$hb = $this->sha1_128($plain);
		$hbhex = strtoupper(bin2hex($hb));
		$rbhex = $this->rsa_decrypt($check);
		return $hbhex == $rbhex ? true : false;
	}

	public function verifyTransResponse($merid, $ordno, $amount, $curyid, $transdate, $transtype, $ordstatus, $check)
	{
		if (strlen($merid) != 15) return false;
		if (strlen($ordno) != 16) return false;
		if (strlen($amount) != 12) return false;
		if (strlen($curyid) != 3) return false;
		if (strlen($transdate) != 8) return false;
		if (strlen($transtype) != 4) return false;
		if (strlen($ordstatus) != 4) return false;
		if (strlen($check) != 256) return false;
		$plain = $merid . $ordno . $amount . $curyid . $transdate . $transtype . $ordstatus;
		return $this->verify($plain, $check);
	}	
	
	

	/*
	*本地订单号转为银联订单号
	*/
	public function magento2chinapaysn($order_sn, $vid){
		if($order_sn && $vid){
			$sub_vid = substr($vid, 10, 5);
			$sub_start = substr($order_sn, 0, 2);
			$sub_start=str_pad($sub_start, 4, "0", STR_PAD_LEFT);
			$sub_end = substr($order_sn, 2);
			return $sub_start . $sub_vid . $sub_end;
		}
	}

	/*
	*银联订单号转为本地订单号
	*/
	public function chinapaysn2magento($chinapaysn){
		if($chinapaysn){ 
			return substr($chinapaysn, 0, 4) . substr($chinapaysn, 9) ;
		}
	}

	/*
	*格式化交易金额，以分位单位的12位数字。
	*/
	public function formatamount($amount){
		if($amount){
			if(!strstr($amount, ".")){
				$amount = $amount.".00";
			}
			$amount = str_replace(".", "", $amount);
			$temp = $amount;
			for($i=0; $i< 12 - strlen($amount); $i++){
				$temp = "0" . $temp;
			}
			return $temp;
		}
	}
 
	
	public function arg_sort($array) {
		ksort($array);
		reset($array);
		return $array;
	}

	public function charset_encode($input,$_output_charset ,$_input_charset ="GBK" ) {
		$output = "";
		if($_input_charset == $_output_charset || $input ==null) {
			$output = $input;
		} elseif (function_exists("mb_convert_encoding")){
			$output = mb_convert_encoding($input,$_output_charset,$_input_charset);
		} elseif(function_exists("iconv")) {
			$output = iconv($_input_charset,$_output_charset,$input);
		} else die("sorry, you have no libs support for charset change.");
		return $output;
	}
   
}