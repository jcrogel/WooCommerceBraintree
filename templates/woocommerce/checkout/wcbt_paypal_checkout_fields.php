<div id="paypal-button"></div>

<?php if($this->environment === "sandbox")
 {

     ?>
        <div id="btsandbox">You are in sandbox mode.</p>
        <p>Use test credit cards Visa: 4111111111111111 or Amex: 378282246310005</p>
<?php
 }
 ?>
<div id="credit_card_container">
<div class="cc-or"><h2><span>or</span></h2></div>

<?php

$cards = $this->get_my_cards();

$selected = false;
if ( ! empty( $cards ) ){
?>
<ul class="stored_ccs">
<?php

    foreach ( $cards as $card ) {
        ?><li class="stored_cc" data-vid="<?php  echo $card->token; ?>">
            <div class="stored_cc_value card-radio"><input type="radio" name="ccid" value="<?php  echo $card->token; ?>" <?php if(!$selected){ echo checked; } ?>></div>
            <div class="stored_cc_value card-type"><?php echo $card->cardType; ?></div>
            <div class="stored_cc_value card-last4"><?php echo $card->last4; ?></div>
            <div class="stored_cc_value card-exp"><?php echo $card->expirationMonth; ?></div>
            <div class="stored_cc_value card-year"><?php echo $card->expirationYear; ?></div>
            <div class="stored_cc_value stored_cc_sec_data" data-vid="<?php  echo $card->token; ?>" <?php if(!$selected){ echo "style=\"display: inline-block;\""; } ?>>
                <div class="stored_cc_zip_ccv">
                	<label for="braintree-cc-cvv_<?php  echo $card->token; ?>">CVV</label>
            	    <input id="braintree-cc-cvv_<?php  echo $card->token; ?>" data-name="stored_cc_cvv" class="stored_cc_cvv" value="" placeholder="CVV">
                </div>
                <div class="stored_cc_zip_ccv">
                	<label for="braintree-cc-zip_<?php  echo $card->token; ?>">ZIP</label>
            	    <input id="braintree-cc-zip_<?php  echo $card->token; ?>" data-name="stored_cc_zip" class="stored_cc_zip" value="" placeholder="ZIP">
                </div>
            </div>
            <div class="stored_cc_value"><button value="X" class='remove_card' data-vid="<?php  echo $card->token; ?>">X</button></div>
        </li>
        <?php
        if(!$selected)
        {
            $selected = true;
        }
	}
?>
</ul><div id="btv0_use_new_card" <?php if($selected){ echo "style=\"display: block;text-decoration:underline;\""; } ?>>Want to use a new credit card?</div>
<?php
}
?>

<div id="creditcard_form" <?php if($selected){ echo "style=\"display: none;\""; } ?>>
	<div class="cc-block">
		<label for="braintree-cc-number">Credit Card Number</label>
		<input id="braintree-cc-number" value="" placeholder="Credit Card Number">
	</div>
	<div class="cc-block">
    	<label for="braintree-cc-exp-month">Expiration Date</label>
        <select id="braintree-cc-exp-month" class="woocommerce-select dropdown woocommerce-cc-month" style="width:auto;" data-encrypted-name="month">
        <option value="">Month</option>
        <?php foreach ( range( 1, 12 ) as $month ) : ?>
            <option value="<?php printf( '%02d', $month ) ?>"><?php printf( '%02d', $month ) ?></option>
        <?php endforeach; ?>
        </select>

        <select id="braintree-cc-exp-year" class="woocommerce-select dropdown woocommerce-cc-year" style="width:auto;" data-encrypted-name="year">
            <option value="">Year</option>
            <?php foreach ( range( date( 'Y' ), date( 'Y' ) + 10 ) as $year ) : ?>
            <option value="<?php echo $year ?>"><?php echo $year ?></option>
    	<?php endforeach; ?>
    </select>
    </div>
    <div class="cc-block">
    	<label for="braintree-cc-ccv">CVV</label>
	    <input id="braintree-cc-ccv" value="" placeholder="CCV">
    </div>
    <div class="cc-block">
    	<label for="braintree-cc-zip">ZIP</label>
	    <input id="braintree-cc-zip" name="wcbtv0-cc-zip" value="" placeholder="ZIP">
    </div>
</div>
<input type="hidden" id="payment-method-nonce" name="payment-method-nonce" value="">
<input type="hidden" id="btvz-payment-type" name="btvz-payment-type" value="">
</div>


<script type="text/javascript">


    (function ($) {
        var btToken = '<?php echo $this->get_client_token(); ?>';
        $(document).ready(function(){setupPayment(btToken)} );
   }(jQuery));

</script>


