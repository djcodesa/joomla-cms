<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_installer
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/extension.php';

/**
 * Installer Manage Model
 *
 * @package     Joomla.Administrator
 * @subpackage  com_installer
 * @since       1.5
 */
class InstallerModelManage extends InstallerModel
{
	/**
	 * Constructor.
	 *
	 * @param   array  $config  An optional associative array of configuration settings.
	 *
	 * @see     JController
	 * @since   1.6
	 */
	public function __construct($config = array())
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array('name', 'client_id', 'status', 'type', 'folder', 'extension_id',);
		}

		parent::__construct($config);
	}

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @param   string  $ordering   An optional ordering field.
	 * @param   string  $direction  An optional direction (asc|desc).
	 *
	 * @return  void
	 *
	 * @since   1.6
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		$app = JFactory::getApplication();

		// Load the filter state.
		$search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
		$this->setState('filter.search', $search);

		$clientId = $this->getUserStateFromRequest($this->context . '.filter.client_id', 'filter_client_id', '');
		$this->setState('filter.client_id', $clientId);

		$status = $this->getUserStateFromRequest($this->context . '.filter.status', 'filter_status', '');
		$this->setState('filter.status', $status);

		$categoryId = $this->getUserStateFromRequest($this->context . '.filter.type', 'filter_type', '');
		$this->setState('filter.type', $categoryId);

		$group = $this->getUserStateFromRequest($this->context . '.filter.group', 'filter_group', '');
		$this->setState('filter.group', $group);

		$this->setState('message', $app->getUserState('com_installer.message'));
		$this->setState('extension_message', $app->getUserState('com_installer.extension_message'));
		$app->setUserState('com_installer.message', '');
		$app->setUserState('com_installer.extension_message', '');

		parent::populateState('name', 'asc');
	}

	/**
	 * Enable/Disable an extension.
	 *
	 * @param   array  &$eid   Extension ids to un/publish
	 * @param   int    $value  Publish value
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.5
	 */
	public function publish(&$eid = array(), $value = 1)
	{
		$user = JFactory::getUser();
		if ($user->authorise('core.edit.state', 'com_installer'))
		{
			$result = true;

			/*
			 * Ensure eid is an array of extension ids
			 * TODO: If it isn't an array do we want to set an error and fail?
			 */
			if (!is_array($eid))
			{
				$eid = array($eid);
			}

			// Get a table object for the extension type
			$table = JTable::getInstance('Extension');
			JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_templates/tables');

			// Enable the extension in the table and store it in the database
			foreach ($eid as $i => $id)
			{
				$table->load($id);
				if ($table->type == 'template')
				{
					$style = JTable::getInstance('Style', 'TemplatesTable');
					if ($style->load(array('template' => $table->element, 'client_id' => $table->client_id, 'home' => 1)))
					{
						JError::raiseNotice(403, JText::_('COM_INSTALLER_ERROR_DISABLE_DEFAULT_TEMPLATE_NOT_PERMITTED'));
						unset($eid[$i]);
						continue;
					}
				}
				if ($table->protected == 1)
				{
					$result = false;
					JError::raiseWarning(403, JText::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'));
				}
				else
				{
					$table->enabled = $value;
				}
				if (!$table->store())
				{
					$this->setError($table->getError());
					$result = false;
				}
			}
		}
		else
		{
			$result = false;
			JError::raiseWarning(403, JText::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'));
		}
		return $result;
	}

	/**
	 * Refreshes the cached manifest information for an extension.
	 *
	 * @param   int  $eid  extension identifier (key in #__extensions)
	 *
	 * @return  boolean  result of refresh
	 *
	 * @since   1.6
	 */
	public function refresh($eid)
	{
		if (!is_array($eid))
		{
			$eid = array($eid => 0);
		}

		// Get an installer object for the extension type
		$installer = JInstaller::getInstance();
		$result = 0;

		// Uninstall the chosen extensions
		foreach ($eid as $id)
		{
			$result |= $installer->refreshManifestCache($id);
		}
		return $result;
	}

	/**
	 * Remove (uninstall) an extension
	 *
	 * @param   array  $eid  An array of identifiers
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.5
	 */
	public function remove($eid = array())
	{
		$user = JFactory::getUser();
		if ($user->authorise('core.delete', 'com_installer'))
		{

			$failed = array();

			/*
			 * Ensure eid is an array of extension ids in the form id => client_id
			 * TODO: If it isn't an array do we want to set an error and fail?
			 */
			if (!is_array($eid))
			{
				$eid = array($eid => 0);
			}

			// Get an installer object for the extension type
			$installer = JInstaller::getInstance();
			$row = JTable::getInstance('extension');

			// Uninstall the chosen extensions
			foreach ($eid as $id)
			{
				$id = trim($id);
				$row->load($id);
				if ($row->type && $row->type != 'language')
				{
					$result = $installer->uninstall($row->type, $id);

					// Build an array of extensions that failed to uninstall
					if ($result === false)
					{
						$failed[] = $id;
					}
				}
				else
				{
					$failed[] = $id;
				}
			}

			$langstring = 'COM_INSTALLER_TYPE_TYPE_' . strtoupper($row->type);
			$rowtype = JText::_($langstring);
			if (strpos($rowtype, $langstring) !== false)
			{
				$rowtype = $row->type;
			}

			if (count($failed))
			{
				if ($row->type == 'language')
				{

					// One should always uninstall a language package, not a single language
					$msg = JText::_('COM_INSTALLER_UNINSTALL_LANGUAGE');
					$result = false;
				}
				else
				{

					// There was an error in uninstalling the package
					$msg = JText::sprintf('COM_INSTALLER_UNINSTALL_ERROR', $rowtype);
					$result = false;
				}
			}
			else
			{

				// Package uninstalled sucessfully
				$msg = JText::sprintf('COM_INSTALLER_UNINSTALL_SUCCESS', $rowtype);
				$result = true;
			}
			$app = JFactory::getApplication();
			$app->enqueueMessage($msg);
			$this->setState('action', 'remove');
			$this->setState('name', $installer->get('name'));
			$app->setUserState('com_installer.message', $installer->message);
			$app->setUserState('com_installer.extension_message', $installer->get('extension_message'));
			return $result;
		}
		else
		{
			$result = false;
			JError::raiseWarning(403, JText::_('JERROR_CORE_DELETE_NOT_PERMITTED'));
		}
	}

	/**
	 * Method to get the database query
	 *
	 * @return  JDatabaseQuery  The database query
	 *
	 * @since   1.6
	 */
	protected function getListQuery()
	{
		$status = $this->getState('filter.status');
		$type = $this->getState('filter.type');
		$client = $this->getState('filter.client_id');
		$group = $this->getState('filter.group');
		$query = JFactory::getDbo()->getQuery(true)
			->select('*')
			->select('2*protected+(1-protected)*enabled as status')
			->from('#__extensions')
			->where('state=0');
		if ($status != '')
		{
			if ($status == '2')
			{
				$query->where('protected = 1');
			}
			else
			{
				$query->where('protected = 0')
					->where('enabled=' . (int) $status);
			}
		}
		if ($type)
		{
			$query->where('type=' . $this->_db->quote($type));
		}
		if ($client != '')
		{
			$query->where('client_id=' . (int) $client);
		}
		if ($group != '' && in_array($type, array('plugin', 'library', '')))
		{
			$query->where('folder=' . $this->_db->quote($group == '*' ? '' : $group));
		}

		// Filter by search in id
		$search = $this->getState('filter.search');
		if (!empty($search) && stripos($search, 'id:') === 0)
		{
			$query->where('extension_id = ' . (int) substr($search, 3));
		}

		return $query;
	}
}
