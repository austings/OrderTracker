<?php
/*
Plugin Name: SIS BUYIN Distribution Handler
Plugin URI: https://www.smashitsports.com/
Description: Buy In customers will have to wait 4-5 weeks for their order to arrive. 
This can be detrimental to our reviews if someone does not fully read the description of the product. 
This plugin handles sending reminders to the customer since it takes so long. 
Version: 1.0
Author: Austin Sierra
Author URI: https://wwww.smashitsports.com
*/
require_once('/nas/content/live/sisretaildev/wp-content/plugins/woocommerce-subscriptions/includes/libraries/action-scheduler/action-scheduler.php');
/**
* buyin_email_notifications will look at complete orders and determine if they are buyin items.
* If they are, they are added to a distribution list to send email updates to regarding their order status.
*
* @param {object} $order - the order that was made
* @param {object} $item - the product we are checking against
*
* @return nothing
*/
function buyin_email_notifications( $order, $item )
{
	$product = wc_get_product($item->get_product_id());
	$item_sku = $product->get_sku();
	//only skus starting with BUYIN are accepted
	if (strpos($item_sku,"BUYIN")===0) {
		//collect order data
		$customerEmail = $order->get_billing_email();
		$orderPaymentDate = $order->get_date_paid()->format ('m/d/Y');
		$orderID = $order->get_id();
		$nextReminder = date("m/d/Y",strtotime("Next Friday"));
		
		//get the distribution list file
		try {
			$disLis = file_get_contents('buyin-distribution-list.json');
			$disArr = json_decode($disLis, true);
			array_push($disArr,array('orderID' => $orderID, 'orderDate' =>$orderPaymentDate,
			'customerEmail' =>$customerEmail,'nextReminder'=> $nextReminder,'numberOfReminders' => 0));
		} catch (Exception $e) {
			//send error message
		}
		//https://stackoverflow.com/questions/16184047/how-to-add-item-to-the-json-file-formatted-array
		file_put_contents('buyin-distribution-list.json',json_encode($disArr,JSON_UNESCAPED_SLASHES));
		
	}
}


/*	as_schedule_recurring_action PARAMETERS
	$ timestamp (number) (required) - When the first instance of the job will run.
	$ interval_in_seconds (number) (required) -How long to wait between runs.
	$ hook (string) (required) -The hook to trigger.
	$ args (array) -Arguments to pass when the hook triggers. -Default:array()
	$ group (string) -The group to assign this job to. Default:''
*/

/**
 * Schedule an action with the hook 'send_buyin_emails_on_friday' to run at 1 30
 * so that our callback is run then.
 */
function eg_schedule_recurring_emails() {
	if ( false === as_next_scheduled_action( 'send_buyin_emails_on_friday' ) ) {
		as_schedule_recurring_action(strtotime('06/12/2020 13:27:00'), WEEK_IN_SECONDS, 'send_buyin_emails_on_friday', array(),'');
	}
}
add_action( 'init', 'eg_schedule_recurring_emails' );

/**
* parse_json_and_send_buyin_emails will be called every Friday at noon and send emails to customers on the distribution list.
* This will send a maximum of three emails, and will consist of promotional materials as well as updates to the production of their
* order.
* 
* 1st email- 1st week, update that the order was queued by the manufacturer
* 2nd email- 2nd week, update that production is underway
* 3rd email- 4th week, update that their product has left the manufacturer and will be entering the QA process before being shipped
* @return nothing
*/
function parse_json_and_send_buyin_emails()
{
	
	//try in case file loading fails
	try {
		
		

		
		//get distribution list file
		$disLis = file_get_contents('buyin-distribution-list.json');
		$disArr = json_decode($disLis, true);
		
		
		$orderList = array();//prevent sending two emails if the customer ordered two buyin items
		$overwrite = array();//the new return array
		
		//Loop through the distribution list and send an email depending on how many emails we already sent
		//the first item in the distribution list (mock item) will remain to retain the json schema
		
		while(count($disArr)!=0) {
			
			//initialize the email.
			$uid = 'order-tracker'; //will map it to this UID
			global $phpmailer;
			$body = '<div style="text-align:center; color: #000000;">
			<table border=”0″ cellpadding=”0″ cellspacing=”0″ width=”600″ style="margin-left:auto;margin-right:auto; background-image: linear-gradient(white, lightgray);">
			<tr><th><h1>Your order is on its way!</h1><br></th></tr>
			<tr><td><img src="cid:order-tracker" width="800" height="614"/><br></td></tr>
			<tr><td><h2> While you wait for your order, take advantage of our special Face Mask sale:</h2><br></td></tr>
			<tr><td style="text-align:center;"><a href="https://www.smashitsports.com/face-cover-promo/"><img src="cid:ppe-buyin-promotion" width="400" height="209"/></a><br></td></tr>	
			<tr><td style="text-align:center;"><p>Click the image above to see the latest deals.</p></td></tr>
			</table></div>';
			
			$thisObject = array_shift($disArr);
			//prevent sending emails twice for the same orderID. If the customer orders two buyin items, for example
			if(in_array($thisObject['orderID'],$orderList))
			{
				continue;
			}
			else
			{
				array_push ($orderList,$thisObject['orderID']);
			}
			
			//fetch order status and don't send email if status is 'Completed' or 'Cancelled'
			$orderObj = wc_get_order($thisObject['orderID']);
			if($orderObj->get_status()=="completed"||$orderObj->get_status()=="cancelled")
			{
				continue;
			}
			switch (intval($thisObject['numberOfReminders'])) {
				case 0:
					//write email
					$file = plugin_dir_path( __FILE__ ) . '/week1email.jpg'; //phpmailer will load this file
					$name = 'week1email.jpg'; //this will be the file name for the attachment
					$phpmailerInitAction = function(&$phpmailer)use($file,$uid,$name) {
						$phpmailer->AddEmbeddedImage($file, $uid, $name);//add embedded image
						$phpmailer->AddEmbeddedImage(plugin_dir_path( __FILE__ ) . '/ppe.jpg', 'ppe-buyin-promotion', 'ppe.jpg');//add ppe image
					};
					add_action( 'phpmailer_init', $phpmailerInitAction);
					$body = str_replace("Your order is on its way!","Your order #".$thisObject['orderID']." has been queued!",$body);
					wp_mail( $thisObject['customerEmail'], "Smash It Sports Order Tracker", $body,['Content-Type: text/html; charset=UTF-8']);
					//update json listing
					$thisObject['nextReminder'] = date("m/d/Y",strtotime("Next Friday"));
					$thisObject['numberOfReminders'] = $thisObject['numberOfReminders']+1;
					array_push($overwrite,$thisObject);
					remove_action('phpmailer_init', $phpmailerInitAction);
					break;
				case 1:
					//write email
					$file = plugin_dir_path( __FILE__ ) . '/week2email.jpg'; //phpmailer will load this file
					$name = 'week2email.jpg'; //this will be the file name for the attachment
					$phpmailerInitAction = function(&$phpmailer)use($file,$uid,$name) {
						$phpmailer->AddEmbeddedImage($file, $uid, $name);//add embedded image
						$phpmailer->AddEmbeddedImage(plugin_dir_path( __FILE__ ) . '/ppe.jpg', 'ppe-buyin-promotion', 'ppe.jpg');//add ppe image
					};
					add_action( 'phpmailer_init', $phpmailerInitAction);
					$body = str_replace("Your order is on its way!","Your order #".$thisObject['orderID']." is in production!",$body);
					wp_mail( $thisObject['customerEmail'], "Smash It Sports Order Tracker", $body,['Content-Type: text/html; charset=UTF-8']);
					$thisObject['nextReminder'] = date("m/d/Y",strtotime("Next Friday"));
					$thisObject['numberOfReminders'] = $thisObject['numberOfReminders']+1;
					array_push($overwrite,$thisObject);
					remove_action('phpmailer_init', $phpmailerInitAction);
					break;
				case 2:
					//write email
					$file = plugin_dir_path( __FILE__ ) . '/week3email.jpg'; //phpmailer will load this file
					$name = 'week4email.jpg'; //this will be the file name for the attachment
					$phpmailerInitAction = function(&$phpmailer)use($file,$uid,$name) {
						$phpmailer->AddEmbeddedImage($file, $uid, $name);//add embedded image
						$phpmailer->AddEmbeddedImage(plugin_dir_path( __FILE__ ) . '/ppe.jpg', 'ppe-buyin-promotion', 'ppe.jpg');//add ppe image
					};
					add_action( 'phpmailer_init', $phpmailerInitAction);
					$body = str_replace("Your order is on its way!","Your order #".$thisObject['orderID']." being sent for review!",$body);
					wp_mail( $thisObject['customerEmail'], "Smash It Sports Order Tracker", $body,['Content-Type: text/html; charset=UTF-8']);
					$thisObject['nextReminder'] = date("m/d/Y",strtotime("Next Friday"));
					$thisObject['numberOfReminders'] = $thisObject['numberOfReminders']+1;
					array_push($overwrite,$thisObject);
					remove_action('phpmailer_init', $phpmailerInitAction);
					break;
				case 3:
					//write email
					$file = plugin_dir_path( __FILE__ ) . '/week4email.jpg'; //phpmailer will load this file
					$name = 'week4email.jpg'; //this will be the file name for the attachment
					$phpmailerInitAction = function(&$phpmailer)use($file,$uid,$name) {
						$phpmailer->AddEmbeddedImage($file, $uid, $name);//add embedded image
						$phpmailer->AddEmbeddedImage(plugin_dir_path( __FILE__ ) . '/ppe.jpg', 'ppe-buyin-promotion', 'ppe.jpg');//add ppe image
					};
					add_action( 'phpmailer_init', $phpmailerInitAction);
					$body = str_replace("Your order is on its way!","Your order #".$thisObject['orderID']." is on its way!",$body);
					wp_mail( $thisObject['customerEmail'], "Smash It Sports Order Tracker", $body,['Content-Type: text/html; charset=UTF-8']);
					//remove this item from the reminder list
					remove_action('phpmailer_init', $phpmailerInitAction);
					break;
			}

		}
		file_put_contents('buyin-distribution-list.json',json_encode($overwrite,JSON_UNESCAPED_SLASHES));
	} catch (Exception $e) {
		//send error message
	}
}
add_action( 'send_buyin_emails_on_friday', 'parse_json_and_send_buyin_emails',1,0 );

?>