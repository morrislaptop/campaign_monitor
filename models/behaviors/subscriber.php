<?php
/**
 * Campaign Monitor Behavior class file.
 *
 * @filesource
 * @author Craig Morris
 * @link http://waww.com.au/campaign-monitor-behavior
 * @version	0.1
 * @license	http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package app
 * @subpackage app.models.behaviors
 */

/**
 * Model behavior to support synchronisation of member records with Campaign Monitor.
 *
 * Features:
 * - Will add members to campaign monitor after saving.
 * - Will unsubscribe members from campaign monitor after a member is deleted.
 * - Adding subscribers with custom fields from your member model (even if they have different names)
 * - Ability to check a "opt in" field in your model and will unsubscribe or subscribe. This feature can be turned off
 *
 * Usage:
 *
 	var $actsAs = array(
		'CampaignMonitor.Subscriber' => array(
			'ApiKey' => 'YOUR API KEY',
			'ListId' => 'YOUR LIST ID',
			'CustomFields' => array(
				'MODEL FIELD' => 'CAMPAIGN MONITOR FIELD',
				'FIELD' // < ---- ONLY IF YOUR MODEL FIELD AND CAMPAIGN MONITOR CUSTOM FIELD HAVE THE SAME NAME
			),
			'optin' => MODEL FIELD // Checked after save and subscribes / unsubscribes. False for no check.
		)
	);
 *
 *
 * @package app
 * @subpackage app.models.behaviors
 */
class SubscriberBehavior extends ModelBehavior
{
	/**
	* @var CampaignMonitor
	*/
	var $cm;

	function setup(&$model, $settings) {
		if (!isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = array(
				'ApiKey' => '',
				'ListId' => '',
				'CustomFields' => array(),
				'StaticFields' => array(),
				'email' => 'email',
				'name' => 'name',
				'optin' => 'optin'
			);
		}
		$this->settings[$model->alias] = array_merge($this->settings[$model->alias], (array) $settings);
		$this->quickCheck($model);
		$this->cm = $this->cm($model);
	}

	function quickCheck($model) {
		$settings = $this->settings[$model->alias];
		extract($settings);
		$fields = array($email, $name, $optin);
		$fields = array_filter($fields); // removes false entries if optin is set to false
		foreach ($CustomFields as $key => $field) {
			$fields[] = is_numeric($key) ? $field : $key;
		}
		foreach ($fields as $field) {
			if ( !$model->hasField($field) ) {
				trigger_error($model->alias . ' does not have the field: ' . $field);
			}
		}
		if ( empty($ApiKey) ) {
			trigger_error('ApiKey is missing');
		}
		if ( empty($ListId) ) {
			trigger_error('ListId is missing');
		}
	}

	function cm($model) {
		App::import('Vendor', 'CampaignMonitor.CampaignMonitor', array('file' => 'CampaignMonitor' . DS . 'CMBase.php'));
		$settings = $this->settings[$model->alias];
		extract($settings);
		$cm = new CampaignMonitor( $ApiKey, null, null, $ListId );
		return $cm;
	}

	function afterSave($model, $created) {
		$settings = $this->settings[$model->alias];
		extract($settings);
		$data = $model->read();
		
		// unsubscribe if this isnt new AND the optin field is empty (only if there is an optin field)
		if ( !$created && $optin && empty($data[$model->alias][$optin]) ) {
			$result = $this->unsubscribe($data[$model->alias][$email]);
		}
		// subscribe if the opt in field is set (or there is no opt in field)
		else if ( !$optin || !empty($data[$model->alias][$optin]) ) {
			$email = $data[$model->alias][$email];
			$name = $data[$model->alias][$name];
			$customFields = $this->_getCustomFields($data[$model->alias], $CustomFields, $StaticFields);
			$result = $this->subscribe($email, $name, $customFields);
		}
	}

	function _getCustomFields($data, $CustomFields, $StaticFields)
	{
		$myCustomFields = array();
		foreach ($CustomFields as $key => $field) {
			// if key is numeric, use the field as the model field and the CM custom field
			// otherwise, use the key as the model field and the field as the CM custom field.
			if ( is_numeric($key) ) {
				$myCustomFields[$field] = $data[$field];
			}
			else {
				$myCustomFields[$field] = $data[$key];
			}
		}
		foreach ($StaticFields as $field => $value) {
			$myCustomFields[$field] = $value;
		}
		return $myCustomFields;
	}

	function beforeDelete($model) {
		$settings = $this->settings[$model->alias];
		extract($settings);
		$email = $model->field($email);
		$this->unsubscribe($email);
	}

	function subscribe($email, $name = null, $custom_fields = array()) {
		$result = $this->cm->subscriberAddAndResubscribeWithCustomFields($email, $name, $custom_fields);
		if ($result['Code'] == 0)
			return true;
		else
			trigger_error('Campaign Monitor Error: ' . $result['Message']);
	}
	function unsubscribe($email) {
		$result = $this->cm->subscriberUnsubscribe($email);
		if ($result['Result']['Code'] == 0)
			return true;
		else
			trigger_error('Campaign Monitor Error: ' . $result['Result']['Message']);
	}
}
?>
