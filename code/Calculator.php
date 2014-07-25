<?php

namespace Shop\Discount;

class Calculator{
	
	protected $order;
	protected $discounts;

	protected $log = array();

	public function __construct(\Order $order, $context = array()) {
		$this->order = $order;
		//get qualifying discounts for this order
		$this->discounts = \Discount::get_matching($this->order, $context);
	}

	/**
	 * Work out the discount for a given order.
	 * @param Order $order
	 * @return double - discount amount
	 */
	public function calculate() {
		$total = 0;
		$modifier = $this->order->getModifier("OrderDiscountModifier", true);
		//clear any existing linked discounts
		$modifier->Discounts()->removeAll();

		//work out all item-level discounts, and load into infoitems
		$infoitems = $this->createPriceInfoList($this->order->Items());
		foreach($this->getItemDiscounts() as $discount){
			//item discounts will update info items
			$action = $discount->Type == "Percent" ?
				new \ItemPercentDiscount($infoitems, $discount) :	
				new \ItemFixedDiscount($infoitems, $discount);
			$amount = $action->perform();
		}

		//select best item-level discounts
		foreach($infoitems as $infoitem){
			$bestadjustment = $infoitem->getBestAdjustment();
			if(!$bestadjustment){
				continue;
			}
			$amount = $bestadjustment->getValue();
			//prevent discounting more than original price
			if($amount > $infoitem->getOriginalTotal()){
				$amount = $infoitem->getOriginalTotal();
			}
			$total += $amount;
			//remove any existing linked discounts
			$infoitem->getItem()->Discounts()->removeAll();
			$infoitem->getItem()->Discounts()->add(
				$bestadjustment->getAdjuster(),
				array('DiscountAmount' => $amount)
			);
			$this->logDiscountAmount("Item", $amount, $bestadjustment->getAdjuster());
		}

		//work out all cart-level discounts, and load into cartpriceinfo
		$cartpriceinfo = new PriceInfo($this->order->SubTotal());
		foreach($this->getCartDiscounts()as $discount){
			$action = new \SubtotalDiscountAction(
				$this->getDiscountableAmount($discount),
				$discount
			);
			$cartpriceinfo->adjustPrice(
				new Adjustment($action->perform(), $discount)
			);
		}

		$cartremainder = $cartpriceinfo->getOriginalPrice() - $total;
		//keep remainder sane, i.e above 0
		$cartremainder = $cartremainder < 0 ? 0 : $cartremainder;

		//select best cart-level disount
		if($bestadjustment = $cartpriceinfo->getBestAdjustment()){
			$discount = $bestadjustment->getAdjuster();
			$amount = $bestadjustment->getValue();
			//don't let amount be greater than remainder
			$amount = $amount > $cartremainder ? $cartremainder : $amount;
			$total += $amount;
			$modifier->Discounts()->add(
				$discount,
				array('DiscountAmount' => $amount)
			);
			$this->logDiscountAmount("Cart", $amount, $discount);
		}

		if(\ClassInfo::exists('ShippingFrameworkModifier') && $shipping = $this->order->getModifier("ShippingFrameworkModifier")){
			//work out all shipping-level discounts, and load into shippingpriceinfo
			$shippingpriceinfo = new PriceInfo($shipping->Amount);
			foreach($this->getShippingDiscounts()as $discount){
				$action = new \SubtotalDiscountAction($shipping->Amount,$discount);
				$shippingpriceinfo->adjustPrice(
					new Adjustment($action->perform(), $discount)
				);
			}
			//select best shipping-level disount
			if($bestadjustment = $shippingpriceinfo->getBestAdjustment()){
				$discount = $bestadjustment->getAdjuster();
				$amount = $bestadjustment->getValue();
				//don't let amount be greater than remainder
				$total += $amount;
				$modifier->Discounts()->add(
					$discount,
					array('DiscountAmount' => $amount)
				);
				$this->logDiscountAmount("Shipping", $amount, $discount);
			}			
		}

		return $total;
	}

	/**
	 * Work out the total discountable amount for a given discount
	 */
	protected function getDiscountableAmount($discount){
		$amount = 0;
		foreach($this->order->Items() as $item){
			if(
				$discount->itemMatchesCategoryCriteria($item, $discount) &&
				$discount->itemMatchesProductCriteria($item, $discount)
			){
				$amount += method_exists($item, "DiscountableAmount") ?
							$item->DiscountableAmount() :
							$item->Total();
			}
		}

		return $amount;
	}

	protected function createPriceInfoList(\DataList $list) {
		$output = array();
		foreach($list as $item){
			$output[] = new ItemPriceInfo($item);
		}
		return $output;
	}

	protected function getItemDiscounts() {
		return $this->discounts->filter("ForItems", true);
	}

	protected function getCartDiscounts() {
		return $this->discounts->filter("ForCart", true);
	}

	protected function getShippingDiscounts() {
		return $this->discounts->filter("ForShipping", true);
	}

	/**
	 * Store details about discounts for loggging / debubgging
	 * @param  [type]   $level    [description]
	 * @param  [type]   $amount   [description]
	 * @param  Discount $discount [description]
	 * @return [type]             [description]
	 */
	public function logDiscountAmount($level, $amount, \Discount $discount) {
		$this->log[] = array(
			"Level" => $level,
			"Amount" => $amount,
			"Discount" => $discount->Title
		);
	}

	public function getLog(){
		return $this->log;
	}

}