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
		$this->cm = $this->cm($model);
	}

	/**
	* Creates the CM object to be used by the class.
	*
	* @param mixed $model
	* @return CampaignMonitor
	*/
	function cm(&$model) {
		App::import('Vendor', 'CampaignMonitor.CampaignMonitor', array('file' => 'CampaignMonitor' . DS . 'CMBase.php'));
		$settings = $this->settings[$model->alias];
		extract($settings);
		$cm = new CampaignMonitor( $ApiKey, null, null, $ListId );
		return $cm;
	}

	/**
	* Checks the data after a save and subscribes / unsubscribes them
	*
	* @param mixed $model
	* @param mixed $created
	*/
	function afterSave(&$model, $created) {
		$settings = $this->settings[$model->alias];
		extract($settings);
		$data = $model->read();
		list($email, $name, $hasOpted) = $this->_extract($model, $data);

		// unsubscribe if this is existing and he hasnt opted in
		if ( !$created && !$hasOpted ) {
			$result = $this->_unsubscribe($email);
		}
		// subscribe he has opted
		else if ( $hasOpted ) {
			$result = $this->subscribe($model, $data);
		}
	}

	/**
	* Returns the email, name and optin values from the data based on the settings. hasOpted
	* will be true if there is no optin field set.
	*
	* @param mixed $data
	*/
	function _extract(&$model, $data)
	{
		$settings = $this->settings[$model->alias];
		extract($settings);

		// Get data out for email.
		$alias = $model->alias;
		if ( strpos($email, '.') ) {
			list($alias, $email) = explode('.', $email);
		}
		$email = $data[$alias][$email];

		// Get data out for name.
		$alias = $model->alias;
		if ( strpos($name, '.') ) {
			list($alias, $name) = explode('.', $name);
		}
		$name = $data[$alias][$name];

		// Get data out for optin
		$hasOpted = true;
		if ( $optin ) {
			$alias = $model->alias;
			if ( strpos($optin, '.') ) {
				list($alias, $optin) = explode('.', $optin);
			}
			$hasOpted = $data[$alias][$optin];
		}

		$arr = array($email, $name, $hasOpted);
		return $arr;
	}

	/**
	* Returns an array of all the custom fields to be sent to campaign monitor, this
	* comprises of the CustomFields (from the model data) and the static fields (
	* these are usually constant flags or something)
	*
	* @param mixed $data
	* @param mixed $CustomFields
	* @param mixed $StaticFields
	*/
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

	/**
	* If deleting a model, lets unsubsribe them
	*
	* @param mixed $model
	* @return boolean
	*/
	function beforeDelete(&$model) {
		$this->unsubscribe($model);
	}

	/**
	* Subscribes the model into campaign monitor
	*
	* @param mixed $model
	* @param mixed $data
	*/
	function subscribe(&$model, $data = null)
	{
		$settings = $this->settings[$model->alias];
		extract($settings);
		if ( !$data ) {
			$data = $model->read();
		}
		list($email, $name, $hasOpted) = $this->_extract($model, $data);

		$custom_fields = $this->_getCustomFields($data, $CustomFields, $StaticFields);

		$this->_subscribe($email, $name, $custom_fields);
	}
	function _subscribe($email, $name = null, $custom_fields = array()) {
		$result = $this->cm->subscriberAddAndResubscribeWithCustomFields($email, $name, $custom_fields);
		if ($result['Code'] == 0)
			return true;
		else
			trigger_error('Campaign Monitor Error: ' . $result['Message']);
	}

	/**
	* Unsubscribes the model from campaign monitor
	*
	* @param mixed $model
	*/
	function unsubscribe(&$model) {
		$settings = $this->settings[$model->alias];
		extract($settings);
		$data = $model->read();
		list($email, $name, $hasOpted) = $this->_extract($model, $data);
		$this->_unsubscribe($email);
	}
	function _unsubscribe($email) {
		$result = $this->cm->subscriberUnsubscribe($email);
		if ($result['Result']['Code'] == 0)
			return true;
		else
			trigger_error('Campaign Monitor Error: ' . $result['Result']['Message']);
	}
}
?>
