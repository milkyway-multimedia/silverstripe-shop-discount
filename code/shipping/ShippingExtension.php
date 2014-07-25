<?php namespace Shop\Discount;
/**
 * Milkyway Multimedia
 * ShippingExtension.php
 *
 * @package reggardocolaianni.com
 * @author Mellisa Hankins <mell@milkywaymultimedia.com.au>
 */

class ShippingExtension extends DataExtension {
    public function updateCMSFields($fields) {
        if($appliesTo = $fields->fieldByName('AppliesTo')) {
            $appliesTo->push(CheckboxField::create("ForShipping", "Shipping subtotal"));
        }
    }
} 