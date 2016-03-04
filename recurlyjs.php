<?php
/* 
 * Copyright (C) 2014 Jeff Channell <me@jeffchannell.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

defined('_JEXEC') or die;

JLoader::register('Jcurly', JPATH_ADMINISTRATOR . '/components/com_jcurly/classes/jcurly.php');
JLoader::register('JcurlyBaseModel', JPATH_ADMINISTRATOR . '/components/com_jcurly/classes/model.php');

class JcurlyModelRecurlyjs extends JcurlyBaseModel
{
	/**
	 * Gets a JForm object for editing a plan
	 * 
	 * @param array $data
	 * @param boolean $loadData
	 * @return boolean false if no form found
	 * @return JForm instance of a JForm
	 */
	public function getForm($data = array(), $loadData = true)
	{
		// import the basics
		jimport('joomla.form.form');
		JForm::addFormPath(JPATH_COMPONENT_ADMINISTRATOR . '/model/form');
		// get the form
		$form = JForm::getInstance('com_jcurly.checkout', 'checkout', array('load_data' => $loadData, 'control' => 'checkout'));
		// just bail if there's no form
		if (false == $form)
		{
			return false;
		}
		// add in default data
		$rdata = $this->getRecurlyAccountData();
		$data  = array_merge($data, $rdata);
		// bind the data
		if (!empty($data))
		{
			$form->bind($data);
		}
		// set default country
		$default_country = $form->getFieldAttribute('country', 'default');
		if (empty($default_country))
		{
			$form->setFieldAttribute('country', 'default', $this->params->get('default_country', 'US'));
		}
		// filter fields
		$this->filterAddressFields($form);
		// add requirements
		foreach ($this->getRequiredAddressFields() as $field)
		{
			$form->setFieldAttribute($field, 'required', 'true');
		}
		// remove VAT if necessary
		if (0 === (int) $this->params->get('collect_vat', 0))
		{
			$form->removeField('vat_number');
		}
		// remove company if necessary
		if (0 === (int) $this->params->get('collect_company', 0))
		{
			$form->removeField('company_name');
		}
		// return the form
		return $form;
	}
	
	/**
	 * Removes address form fields based on configuration settings
	 * 
	 * @param JForm $form
	 */
	protected function filterAddressFields($form)
	{
		if ((int) $this->params->get('collect_address', 1))
		{
			return;
		}
		switch ((int) $this->params->get('address_requirement', 0))
		{
			// no filter
			case Jcurly::ADDRESS_REQUIREMENT_FULL:
			default:
				break;
			// with the rest of the filters, no breaks in between cases
			// when there are no address fields used, remove only the postal code
			case Jcurly::ADDRESS_REQUIREMENT_NONE:
				$form->removeField('postal_code');
			// postal code only should remove the address fields
			case Jcurly::ADDRESS_REQUIREMENT_POSTAL_CODE:
				$form->removeField('address1');
				$form->removeField('address2');
			// street and postal code removes the rest
			case Jcurly::ADDRESS_REQUIREMENT_STREET:
				$form->removeField('city');
				$form->removeField('state');
				$form->removeField('country');
		}
	}
	
	/**
	 * Compile a list of required address fields
	 * 
	 * @return array
	 */
	protected function getRequiredAddressFields()
	{
		$fields = array('month', 'year');
		switch ((int) $this->params->get('address_requirement', 0))
		{
			case Jcurly::ADDRESS_REQUIREMENT_FULL:
				$fields = array_merge($fields, array('city', 'state', 'country'));
			case Jcurly::ADDRESS_REQUIREMENT_STREET:
				$fields = array_merge($fields, array('address1'));
			case Jcurly::ADDRESS_REQUIREMENT_POSTAL_CODE:
				$fields = array_merge($fields, array('postal_code'));
			case Jcurly::ADDRESS_REQUIREMENT_NONE:
			default:
				break;
		}
		return array_values(array_unique($fields));
	}
	
	/**
	 * Get a list of all the address fields
	 * 
	 * @return array
	 */
	protected function getAddressFields()
	{
		$fields = $this->getRequiredAddressFields();
		if ((bool) (int) $this->params->get('collect_address', 1))
		{
			$fields = array_values(array_unique(array_merge($fields, array(
				'city', 'state', 'country', 'address1', 'address2', 'postal_code'
			))));
		}
		return $fields;
	}
	
	/**
	 * Gets recurly data for account info fields
	 * 
	 * @param type $user_id
	 * @return type
	 */
	public function getRecurlyAccountData($user_id = null)
	{
		$data = array();
		// ensure this user id points to a real user
		$user = JFactory::getUser($user_id);
		if ($user->guest)
		{
			return $data;
		}
		$user_id = $user->get('id');
		// try to get a recurly account, if possible
		$model = new JcurlyModelRecurly();
		try
		{
			$account = $model->getAccountByJoomlaId($user_id);
		}
		catch (Exception $e)
		{
			if (defined('JDEBUG') && JDEBUG)
			{
				$this->app->enqueueMessage($e->getMessage());
			}
			return $data;
		}
		// load billing info
		$billing_info = $account->billing_info;
		if (is_a($billing_info, 'Recurly_Stub'))
		{
			$billing_info = $account->billing_info->get();
		}
		// collect billing fields
		$billing_fields = $this->getAddressFields();
		if ((int) $this->params->get('collect_vat', 0))
		{
			$billing_fields = array_merge($billing_fields, array('vat_number'));
		}
		// collect account fields
		$account_fields = array('first_name', 'last_name');
		if ((int) $this->params->get('collect_company', 0))
		{
			$account_fields = array_merge($account_fields, array('company_name'));
		}
		// build the data array
		if (is_object($account))
		{
			foreach ($account_fields as $var)
			{
				$data[$var] = $account->$var;
			}
		}
		if (is_object($billing_info))
		{
			foreach ($billing_fields as $var)
			{
				$rvar = ('postal_code' === $var ? 'zip' : $var);
				$data[$var] = $billing_info->$rvar;
			}
		}
		return $data;
	}
}
