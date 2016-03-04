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

JLoader::register('JcurlyBaseController', JPATH_ADMINISTRATOR . '/components/com_jcurly/classes/controller.php');

/**
 * JcurlyControllerForm class.
 *
 * @see JControllerBase
 */
class JcurlyControllerForm extends JcurlyBaseController
{
	public $recurly_model;
	
	public $user_model;
	
	public function show()
	{
		$this->recurly_model = new JcurlyModelRecurlyjs();
		$this->user_model    = new JcurlyModelUser();
		$this->view          = new JcurlyViewFormHtml($this->user_model);
		$this->view->setLayout('form');
		try
		{
			$this->view->form              = $this->recurly_model->getForm();
			$this->view->login_form        = $this->user_model->getUserForm('login');
			$this->view->registration_form = $this->user_model->getUserForm('registration');
			$this->view->user              = JFactory::getUser();
			// set ids
			$user_id = (int) $this->view->user->get('id');
			if (!empty($user_id))
			{
				$this->view->user_id      = $user_id;
				$this->view->account_code = $this->user_model->getAccountCode($user_id);
			}
			else
			{
				$this->view->user_id      = '';
				$this->view->account_code = '';
			}
			// set plan from request
			$this->view->form->setValue('plan', null, $this->input->get('plan', ''));
			// set language
			$this->view->registration_form->setValue('language', 'params', JFactory::getLanguage()->getTag());
		}
		catch (Exception $e)
		{
			JError::raiseError(500, $e->getMessage());
		}
		// dispatch a before display event
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onJcurlyFormBeforeDisplay', array(&$this->view));
		// show the view
		echo $this->view;
	}
}
