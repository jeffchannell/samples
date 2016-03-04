<?php
/**
 * @package		JCalPro
 * @subpackage	com_jcalpro
@ant_copyright_header@
 */

// TODO etag for event/instance for further cache


namespace Jcalpro;

defined('_JEXEC') or die;

class Event extends Object
{
	/**
	 * Frequency (recur_type) values
	 */
	const FREQ_NONE = '';
	const FREQ_SECONDLY = 'SECONDLY';
	const FREQ_MINUTELY = 'MINUTELY';
	const FREQ_HOURLY= 'HOURLY';
	const FREQ_DAILY= 'DAILY';
	const FREQ_WEEKLY= 'WEEKLY';
	const FREQ_MONTHLY= 'MONTHLY';
	const FREQ_YEARLY= 'YEARLY';
	
	/**
	 * Primary key
	 * @var mixed<null|int>
	 */
	public $id;
	
	/**
	 * Database object
	 * @var \JDatabase
	 */
	protected $db;
	
	/**
	 * Database table to store events
	 * @var string
	 */
	protected $table = '#__jcal_events';
	protected $properties_table = '#__jcal_events_properties';
	
	/**
	 * Make this blank for now
	 * @var array
	 */
	protected $columns = array();
	
	/**
	 * Array of column names that should be imploded before storing
	 * @var array
	 */
	protected $delimcolumns = array('exdate');
	
	/**
	 * Array of properties to store in the event table
	 * @var array
	 */
	protected $event_columns = array('uid', 'cal_id', 'title', 'alias', 'priority', 'published',
		'recurrence_id', 'recurrence_id_tz', 'event_start', 'event_start_tz',
		'event_end', 'event_end_tz', 'location_id',
		'created', 'created_by', 'modified', 'modified_by',
		'checked_out', 'checked_out_time'
	);
	
	/**
	 * Database table to store event recurrence rules
	 * @var string
	 */
	protected $recurrence_table = '#__jcal_recurrence';
	
	/**
	 * Database table to store event recurrence cache
	 * @var string
	 */
	protected $recurrence_cache_table = '#__jcal_recurrence_cache';
	
	/**
	 * Database table to store event recurrence queue
	 * @var string
	 */
	protected $recurrence_queue_table = '#__jcal_recurrence_queue';
	
	/**
	 * Array of properties to store in the event recurrence table
	 * @var array
	 */
	protected $recurrence_columns = array('recur_type', 'count', 'until', 'interval',
		'bysecond', 'byminute', 'byhour', 'byday', 'bymonthday', 'byyearday',
		'byweekno', 'bymonth', 'bysetpos', 'wkst', 'exdate'
	);
	
	/**
	 * Array of BYxxx properties to store in the event recurrence table, in the order they should be processed
	 * 
	 * From http://www.kanzaki.com/docs/ical/recur.html
	 * 
	 * "If multiple BYxxx rule parts are specified, then after evaluating the 
	 * specified FREQ and INTERVAL rule parts, the BYxxx rule parts are applied 
	 * to the current set of evaluated occurrences in the following order: 
	 * BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY, BYHOUR, BYMINUTE, 
	 * BYSECOND and BYSETPOS; then COUNT and UNTIL are evaluated."
	 * @var array
	 */
	protected $recurrence_byrules = array(
		'bymonth', 'byweekno', 'byyearday', 'bymonthday', 'byday', 'byhour', 'byminute', 'bysecond', 'bysetpos'
	);
	
	/**
	 * DateTime representing the event start date
	 * @var mixed<null|\Jcalpro\Date>
	 */
	protected $recurrence_root;
	
	/**
	 * DateTime representing the last recurrence value calculated
	 * @var mixed<null|\Jcalpro\Date>
	 */
	protected $recurrence_base;
	
	/**
	 * Internal maximum of how many event recurrences are processed at one time
	 * @var int
	 */
	protected $recurrence_limit = 50;
	
	/**
	 * \DateTime representing this instance's start date, which may be different than the base
	 * @var \DateTime
	 */
	protected $instance_start;
	
	/**
	 * \DateTime representing this instance's end date, which may be different than the base
	 * @var \DateTime
	 */
	protected $instance_end;
	
	/**
	 * flag to denote if this instance is a parent record or not
	 * @var bool
	 */
	protected $instance_only = false;
	
	protected $generate_on_save = true;
	
	/**
	 * Cache calendars so we don't have to load them a bunch
	 * @var array
	 */
	static protected $calendar_cache = array();
	
	/**
	 * Constructor
	 * @param mixed $db database object
	 */
	public function __construct($db = null)
	{
		$this->{$this->properties_property} = new \stdClass();
		// allow db to be injected
		$this->db = is_null($db) ? \JFactory::getDbo() : $db;
		// preset the object properties as null
		foreach (array_merge($this->event_columns, $this->recurrence_columns) as $property)
		{
			$this->$property = null;
		}
	}
	
	/**
	 * Getter
	 * 
	 * @param string $property
	 * @return mixed
	 */
	public function __get($property)
	{
		switch ($property)
		{
			case $this->properties_property:
				return $this->$property;
			// time properties
			case 'user_yearday':
			case 'user_year':
			case 'user_monthday':
			case 'user_month':
			case 'user_weekday':
			case 'user_week':
			case 'user_day':
			case 'user_hour':
			case 'user_minute':
			case 'user_second':
				return $this->getStart()->toUser()->{substr($property, 5)}();
			case 'utc_yearday':
			case 'utc_year':
			case 'utc_monthday':
			case 'utc_month':
			case 'utc_weekday':
			case 'utc_week':
			case 'utc_day':
			case 'utc_hour':
			case 'utc_minute':
			case 'utc_second':
				return $this->getStart()->toUtc()->{substr($property, 4)}();
			// time properties
			case 'yearday':
			case 'year':
			case 'monthday':
			case 'month':
			case 'weekday':
			case 'week':
			case 'day':
			case 'hour':
			case 'minute':
			case 'second':
				return $this->getStart()->$property();
			// calendar
			case 'calendar':
				if (!is_null($this->cal_id))
				{
					return $this->getCalendar();
				}
				return null;
			// deleted
			case 'trashed':
				// TODO
				return false;
			case 'location':
				$value = null;
				if (!empty($this->location_id))
				{
					try
					{
						$location = Location::getInstance($this->location_id);
						$value = $location->id ? $location : $value;
					}
					catch (\Exception $e) {}
				}
				return $value;
			case 'tags':
				$helper = false;
				if (\JcalproTags::useTags())
				{
					$helper = \JcalproTags::getHelper();
					$helper->getTagIds($this->id, \Jcalpro::COM . '.event');
				}
				return $this->{$this->properties_property}->$property = $helper;
			case 'metadata':
				$meta = array();
				if (\JcalproTags::useTags())
				{
					$meta['tags'] = $this->tags;
				}
				return $this->{$this->properties_property}->$property = $meta;
		}
		if (property_exists($this->{$this->properties_property}, $property))
		{
			return $this->{$this->properties_property}->$property;
		}
	}
	
	/**
	 * Bind data to this instance
	 * 
	 * @param array $data
	 * @return \Jcalpro\Event
	 * @throws InvalidArgumentException
	 */
	public function bind(array $data)
	{
		// set values
		foreach ($data as $key => $value)
		{
			// check for properties
			if ($this->properties_property == $key && is_array($value))
			{
				$this->setProperties($value);
				continue;
			}
			// only allow public properties or instance properties
			if (!in_array($key, array_diff(array_merge(array('id'), $this->event_columns, $this->recurrence_columns), array('item_id'))))
			{
				continue;
			}
			// unset empty values
			if ('' === $value || is_null($value) || (is_array($value) && empty($value)))
			{
				$this->$key = null;
			}
			// fix timezones
			else if ('_tz' === substr($key, -3))
			{
				$timezone = new TimeZone($value);
				$this->$key = $timezone->getName();
			}
			// implode arrays
			else if (is_array($value))
			{
				$this->$key = implode(',', $value);
			}
			// set normal values
			else
			{
				$this->$key = $value;
			}
		}
		// chain
		return $this;
	}
	
	/**
	 * Bind a \vevent object to this event
	 * @param \vevent $event
	 * @param mixed $timezone
	 * @return \Jcalpro\Event
	 */
	public function bindVevent(\vevent $event, $timezone)
	{
		$start = $event->getProperty('dtstart', false, false);
		$end   = $event->getProperty('dtend', false, false);
		$start_date = Date::createFromArray($start);
		if ($end)
		{
			$end_date = Date::createFromArray($end);
		}
		else
		{
			$end_date = clone $start_date;
			$end_date->toDayEnd();
		}
		$bind  = array(
			'uid'            => $event->getProperty('uid')
		,	'title'          => $event->getProperty('summary')
		,	'priority'       => $event->getProperty('priority')
		,	'event_start'    => $start_date->toSql()
		,	'event_start_tz' => $timezone
		,	'event_end'      => $end_date->toSql()
		,	'event_end_tz'   => $timezone
		,	'published'      => 1
		);
		if (($rrule = $event->getProperty('rrule')))
		{
			$rrulestring = $event->_format_recur('', array(array('value' => $rrule)));
			$rules = explode(';', trim($rrulestring, ':'));
			foreach ($rules as $rule)
			{
				list($key, $value) = explode('=', $rule, 2);
				if ('UNTIL' == $key)
				{
					$value = Date::_($value)->toSql();
				}
				if ('FREQ' == $key)
				{
					$key = 'recur_type';
				}
				$bind[strtolower($key)] = trim($value);
			}
		}
		return $this->bind($bind)->setProperty('description', $event->getProperty('description'));
	}
	
	/**
	 * Load a specific event
	 * 
	 * @param mixed $id
	 * @return \Jcalpro\Event
	 */
	public function load($id = null)
	{
		parent::load($id);
		$this->event_start = Date::_($this->event_start, 'UTC')->toTimezone($this->event_start_tz)->toSql();
		try
		{
			$this->event_end = Date::_($this->event_end, 'UTC')->toTimezone($this->event_end_tz)->toSql();
		}
		catch (Exception $ex)
		{
			$this->event_end = Date::ZERO_DATE_MYSQL;
		}
		return $this;
	}
	
	/**
	 * Saves this record instance
	 * 
	 * @return \Jcalpro\Event
	 * @throws UnexpectedValueException
	 */
	public function save()
	{
		if ($this->instance_only)
		{
			throw new \RuntimeException('Cannot save an instance-only event');
		}
		if (empty($this->alias))
		{
			$this->alias = \JFilterOutput::stringURLSafe($this->title);
		}
		if (empty($this->event_end_tz))
		{
			$this->event_end_tz = $this->event_start_tz;
		}
		// save start & end values
		$start = "$this->event_start";
		$end   = "$this->event_end";
		// set to UTC for save
		$this->event_start = Date::_($this->event_start, $this->event_start_tz)->toUtc()->toSql();
		try
		{
			$this->event_end = Date::_($this->event_end, $this->event_end_tz)->toUtc()->toSql();
		}
		catch (\Exception $ex)
		{
			$this->event_end = Date::ZERO_DATE_MYSQL;
		}
		// save event
		parent::save();
		// reset vars
		$this->event_start = "$start";
		$this->event_end   = "$end";
		// chain to recurrence handler - must be done after event dates have been processed
		return $this->saveRecurrence();
	}
	
	/**
	 * Applies changes to this instance's record
	 * 
	 * @return \Jcalpro\Event
	 * @throws \UnexpectedValueException
	 */
	protected function apply()
	{
		if (empty($this->id))
		{
			throw new \UnexpectedValueException('Cannot apply data to an empty record');
		}
		if (empty($this->alias))
		{
			$this->alias = \JFilterOutput::stringURLSafe($this->title);
		}
		// force modified columns
		$this->uid = (is_null($this->uid) || '' === $this->uid) ? $this->generateUid() : $this->uid;
		$this->modified = Date::_()->toSql();
		$this->modified_by = Factory::getUser()->id;
		// start building the query
		$query = $this->db->getQuery(true)
			->update($this->table)
			->where('id = ' . (int) $this->id)
		;
		// add only the event columns
		foreach ($this->event_columns as $property)
		{
			$query->set($this->db->quoteName($property) . ' = ' . $this->getQuotedColumn($property));
		}
		// run the query
		$this->db->setQuery($query)->query();
		return $this;
	}
	
	/**
	 * Creates a new record
	 * 
	 * @return \JcalproEvent
	 * @throws UnexpectedValueException
	 */
	protected function create()
	{
		if (!is_null($this->id))
		{
			if (empty($this->id))
			{
				$this->id = null;
			}
			else
			{
				throw new \UnexpectedValueException('Cannot create an existing event');
			}
		}
		$columns = array_merge(array('id'), $this->event_columns);
		$values = array();
		$forced = array(
			'created' => Date::_()->toSql()
		,	'created_by' => Factory::getUser()->id
		,	'modified' => Date::ZERO_DATE_MYSQL
		,	'modified_by' => 0
		,	'uid' => (is_null($this->uid) || '' === $this->uid) ? $this->generateUid() : $this->uid
		);
		foreach ($columns as $i => $property)
		{
			$value = $this->getQuotedColumn($property);
			if (array_key_exists($property, $forced))
			{
				$value = $this->db->quote($forced[$property]);
			}
			$values[] = $value;
			$columns[$i] = $this->db->quoteName($property);
		}
		$this->db->setQuery($this->db->getQuery(true)
			->insert($this->table)
			->columns($columns)
			->values(implode(',', $values))
		)->query();
		$this->id = $this->db->insertid();
		return $this;
	}
	
	/**
	 * Saves this instance record's recurrence values
	 * 
	 * @return \Jcalpro\Event
	 */
	protected function saveRecurrence()
	{
		if (!$this->shouldHaveRecurrence())
		{
			$this->deleteRecurrence();
			return $this;
		}
		if (is_null($this->recurrence_id))
		{
			$this->createRecurrence()->createQueueRecord();
		}
		else
		{
			$this->deleteRecurrenceCache()->deleteQueueRecord()->applyRecurrence()->createQueueRecord();
		}
		if ($this->generate_on_save)
		{
			$this->generateRecurrenceCache();
		}
		return $this;
	}
	
	/**
	 * Applies changes to this instance's event recurrence
	 * 
	 * @return \Jcalpro\Event
	 */
	protected function applyRecurrence()
	{
		$query = $this->db->getQuery(true)
			->update($this->recurrence_table)
			->set('item_id = ' . (int) $this->id)
			->where('id = ' . (int) $this->recurrence_id)
		;
		foreach ($this->recurrence_columns as $property)
		{
			$query->set($this->db->quoteName($property) . ' = ' . $this->getQuotedColumn($property));
		}
		$this->db->setQuery($query)->query();
		return $this;
	}
	
	/**
	 * Creates a new recurrence record
	 * 
	 * @return \Jcalpro\Event
	 */
	protected function createRecurrence()
	{
		$columns = array_merge(array(), $this->recurrence_columns);
		$values = array();
		foreach ($columns as $i => $property)
		{
			$values[] = $this->getQuotedColumn($property);
			$columns[$i] = $this->db->quoteName($property);
		}
		$columns[] = $this->db->quoteName('item_id');
		$values[] = (int) $this->id;
		$this->db->setQuery($this->db->getQuery(true)
			->insert('#__jcal_recurrence')
			->columns($columns)
			->values(implode(',', $values))
		)->query();
		$this->recurrence_id = $this->db->insertid();
		$this->db->setQuery($this->db->getQuery(true)
			->update($this->table)
			->set('recurrence_id = ' . (int) $this->recurrence_id)
			->where('id = ' . (int) $this->id)
		)->query();
		return $this;
	}
	
	/**
	 * Clear this instance of all data
	 * 
	 * @return \Jcalpro\Event
	 */
	public function clear()
	{
		// reset db column properties
		foreach (array_merge(array('id', 'instance_start', 'instance_end'), $this->event_columns, $this->recurrence_columns) as $property)
		{
			$this->$property = null;
		}
		// reset instance
		$this->instance_only = false;
		// reset extra properties
		$this->{$this->properties_property} = new \stdClass();
		// return self (for chaining)
		return $this;
	}
	
	/**
	 * Delete the record associated with this instance and reset the instance
	 * 
	 * @param bool $clear
	 * @return \Jcalpro\Event
	 */
	public function delete($clear = true)
	{
		if ($this->instance_only)
		{
			throw new \RuntimeException('Cannot delete an instance-only event');
		}
		$this->deleteRecurrence($clear);
		return parent::delete($clear);
	}
	
	/**
	 * Deletes the recurrence record for this instance's record
	 * 
	 * @param bool $clear
	 * @return \Jcalpro\Event
	 */
	public function deleteRecurrence($clear = true)
	{
		if ($this->instance_only)
		{
			throw new \RuntimeException('Cannot delete recurrence of an instance-only event');
		}
		// don't trust the id we have stored in case recurrence was removed
		$recurrence_id = $this->db->setQuery($this->db->getQuery(true)
			->select('id')
			->from($this->recurrence_table)
			->where('item_id = ' . (int) $this->id)
		)->loadResult();
		if (!empty($recurrence_id))
		{
			$this->db->setQuery($this->db->getQuery(true)
				->delete($this->recurrence_table)
				->where('id = ' . (int) $recurrence_id)
			)->query();
			$this->deleteRecurrenceCache($recurrence_id)->deleteQueueRecord($recurrence_id);
		}
		if ($clear)
		{
			foreach ($this->recurrence_columns as $property)
			{
				$this->$property = null;
			}
			$this->db->setQuery($this->db->getQuery(true)
				->update($this->table)
				->set('recurrence_id = NULL')
				->set('recurrence_id_tz = NULL')
				->where('id = ' . (int) $this->id)
			)->query();
		}
		return $this;
	}
	
	/**
	 * Deletes the cached recurrence records
	 * 
	 * NOTE: this DOES NOT reset the queue entry!
	 * 
	 * @param type $recurrence_id
	 * @return \Jcalpro\Event
	 */
	public function deleteRecurrenceCache($recurrence_id = null)
	{
		if ($this->instance_only)
		{
			throw new \RuntimeException('Cannot delete cache for an instance-only event', 500);
		}
		if (is_null($recurrence_id))
		{
			$recurrence_id = $this->recurrence_id;
		}
		$this->db->setQuery($this->db->getQuery(true)
			->delete($this->recurrence_cache_table)
			->where('recurrence_id = ' . (int) $recurrence_id)
		)->query();
		return $this;
	}
	
	/**
	 * Deletes the queue record for this event
	 * 
	 * @param type $recurrence_id
	 * @return \Jcalpro\Event
	 */
	public function deleteQueueRecord($recurrence_id = null)
	{
		if ($this->instance_only)
		{
			throw new \RuntimeException('Cannot delete queue for an instance-only event');
		}
		if (is_null($recurrence_id))
		{
			$recurrence_id = $this->recurrence_id;
		}
		$this->db->setQuery($this->db->getQuery(true)
			->delete($this->recurrence_queue_table)
			->where('recurrence_id = ' . (int) $recurrence_id)
		)->query();
		return $this;
	}
	
	/**
	 * Checks if this instance should have a recurrence record
	 * 
	 * @return boolean
	 */
	public function shouldHaveRecurrence()
	{
		switch ($this->recur_type)
		{
			case Event::FREQ_DAILY:
			case Event::FREQ_HOURLY:
			case Event::FREQ_MINUTELY:
			case Event::FREQ_MONTHLY:
			case Event::FREQ_SECONDLY:
			case Event::FREQ_WEEKLY:
			case Event::FREQ_YEARLY:
				return true;
		}
		return false;
	}
	
	/**
	 * Fetches the recurrence queue record for this event
	 * 
	 * @return boolean false if no record (or event does not recur)
	 * @return object recurrence queue record
	 */
	public function getQueueRecord()
	{
		if (!$this->shouldHaveRecurrence())
		{
			return false;
		}
		$record = $this->db->setQuery($this->db->getQuery(true)
			->select('*')
			->from($this->recurrence_queue_table)
			->where('recurrence_id = ' . (int) $this->recurrence_id)
		)->loadObject();
		if (!is_object($record) || empty($record))
		{
			return false;
		}
		return $record;
	}
	
	/**
	 * Creates a new queue record for a given recurrence id
	 * 
	 * @param type $recurrence_id
	 * @param type $base
	 * @return \Jcalpro\Event
	 */
	public function createQueueRecord($recurrence_id = null, $base = null)
	{
		if (is_null($recurrence_id))
		{
			$recurrence_id = $this->recurrence_id;
		}
		if (is_null($base))
		{
			$base = $this->event_start;
		}
		if (!is_a($base, '\\Jcalpro\\Date'))
		{
			$base = Date::_($base, $this->event_start_tz);
		}
		$this->_createRecord($this->recurrence_queue_table, array(
			'recurrence_id' => $recurrence_id
		,	'base' => $base->toUtc()->toSql()
		,	'created' => Date::_(null, 'UTC')->toSql()
		));
		return $this;
	}
	
	/**
	 * Generate the next round of cached recurrence entries for this event
	 * 
	 * @return \Jcalpro\Event
	 */
	public function generateRecurrenceCache()
	{
		// no recurrence
		if (!$this->shouldHaveRecurrence())
		{
			return $this;
		}
		// set the root date to this event's start date
		$this->recurrence_root = $this->getStartDate();
		// get the queue record
		$record = $this->getQueueRecord();
		if (false === $record)
		{
			return $this;
		}
		// set the base date from the record
		$this->recurrence_base = Date::_($record->base, 'UTC')->toTimezone($this->event_start_tz);
		$this->runRecurrenceEngine();
		// done for now
		return $this;
	}
	
	/**
	 * Run the recurrence engine
	 */
	protected function runRecurrenceEngine()
	{
		// load the recurrence engine code
		if (!class_exists('\Recurr\Recurrence'))
		{
			jimport('recurr.loader');
		}
		$timezone    = $this->event_start_tz;
		// our start 
		$startDate   = new Date($this->event_start, $timezone);
		$endDate     = new Date($this->event_end, $this->event_end_tz);
		$rule        = new \Recurr\Rule($this->getRruleString(), $startDate, $endDate, $timezone);
		$transformer = new \Recurr\Transformer\ArrayTransformer();
		$generated   = 0;
		$continues   = false;
		$base        = clone $this->recurrence_base;
		$recurrences = $transformer->transform($rule)->startsAfter($base);
		foreach ($recurrences as $recurrence)
		{
			if ($recurrence->getStart()->format(Date::JCL_FORMAT_MYSQL) == $this->recurrence_base->toSql())
			{
				continue;
			}
			$this->recurrence_base = Date::_($recurrence->getStart()->format(Date::JCL_FORMAT_MYSQL), $this->event_start_tz)->toUtc();
			$this->_createRecord($this->recurrence_cache_table, $this->_cacheRecord($this->recurrence_base));
			$this->recurrence_base->toTimezone($timezone);
			$generated++;
			if ($this->recurrence_limit == $generated)
			{
				$continues = true;
				break;
			}
		}
		// remove from the queue
		$this->deleteQueueRecord();
		// re-add if processing needs to continue later
		if ($continues)
		{
			$this->createQueueRecord($this->recurrence_id, $this->recurrence_base);
		}
	}
	
	/**
	 * Get the recurrence rules as an RRULE string for iCalendar
	 * @param boolean $exdate
	 * @return string
	 */
	public function getRruleString($exdate = true)
	{
		// create the rule array for iCalcreator
		$rrule = array('FREQ=' . $this->recur_type);
		// add in the count/until parts
		if (!is_null($this->until))
		{
			$rrule[] = 'UNTIL=' . $this->until;
		}
		else if (!is_null($this->count))
		{
			$rrule[] = 'COUNT=' . $this->count;
		}
		$rrule[] = 'INTERVAL=' . $this->getInterval();
		// add the byrules
		foreach ($this->recurrence_byrules as $rule)
		{
			if (is_null($this->$rule))
			{
				continue;
			}
			$rrule[] = strtoupper($rule) . '=' . $this->$rule;
		}
		// EXDATE is optional as it is generally separate in ics but not in \Recurr
		if ($exdate && !is_null($this->exdate))
		{
			// EXDATE is stored explicitly in UTC
			$exdates = explode(',', $this->exdate);
			$parts = array();
			foreach ($exdates as $exdate)
			{
				$parts[] = Date::_($exdate)->toTimezone($this->event_start_tz)->format(Date::JCL_FORMAT_EXDATE);
			}
			$rrule[] = 'EXDATE=' . implode(',', $parts);
		}
		return implode(';', $rrule);
	}
	
	/**
	 * Get the excluded dates as an array of \Jcalpro\Date items
	 * @return mixed<null|array>
	 */
	public function getExdates()
	{
		if (is_null($this->exdate))
		{
			return null;
		}
		$exdates = array();
		$parts = explode(',', $this->exdate);
		foreach ($parts as $part)
		{
			if (empty($part))
			{
				continue;
			}
			try
			{
				$date = Date::_($part, 'UTC')->toTimezone($this->event_start_tz);
			}
			catch (\Exception $e)
			{
				continue;
			}
			$exdates[] = $date;
		}
		return empty($exdates) ? null : $exdates;
	}
	
	/**
	 * Exclude a Date from this event's recurrence
	 * 
	 * @param \Jcalpro\Date $exdate
	 * @return \Jcalpro\Event
	 */
	public function addExdate(Date $exdate)
	{
		if (is_null($exdates = $this->getExdates()))
		{
			$exdates = array();
		}
		$exdates[] = $exdate;
		sort($exdates);
		$parts = array();
		foreach ($exdates as $date)
		{
			$parts[] = $date->toUtc()->format(Date::JCL_FORMAT_EXDATE);
		}
		$this->exdate = implode(',', $parts);
		return $this;
	}
	
	/**
	 * Re-include an excluded Date
	 * 
	 * @param \Jcalpro\Date $exdate
	 * @return \Jcalpro\Event
	 */
	public function removeExdate(Date $exdate)
	{
		if (is_null($exdates = $this->getExdates()))
		{
			return $this;
		}
		$parts = array();
		foreach ($exdates as $date)
		{
			if ($date == $exdate)
			{
				continue;
			}
			$parts[] = $date->toUtc()->format(Date::JCL_FORMAT_EXDATE);
		}
		$this->exdate = implode(',', $parts);
		return $this;
	}
	
	/**
	 * Generate a cache record array
	 * 
	 * @param \DateTime $date
	 * @return array
	 */
	protected function _cacheRecord(\DateTime $date = null)
	{
		if (is_null($date))
		{
			$date = $this->recurrence_base;
		}
		return array('recurrence_id' => $this->recurrence_id, 'date' => Date::_($date->format(Date::JCL_FORMAT_MYSQL)));
	}
	
	/**
	 * Save a generic record to a database table
	 * 
	 * @param string $table
	 * @param array $data
	 * @param array $safeparams
	 * @return type
	 */
	protected function _createRecord($table, $data, $safeparams = array())
	{
		$data    = (array) $data;
		$columns = array();
		$values  = array();
		while (list($column, $value) = each($data))
		{
			$columns[] = $this->db->quoteName($column);
			$values[]  = !empty($safeparams) && in_array($column, $safeparams) ? $value : $this->db->quote($value);
		}
		return $this->db->setQuery($this->db->getQuery(true)
			->insert($table)
			->columns($columns)
			->values(implode(',', $values))
		)->query();
	}
	
	/**
	 * Get this event's start date
	 * 
	 * @return \Jcalpro\Date
	 * @throws \UnexpectedValueException
	 */
	public function getStartDate()
	{
		if (is_null($this->event_start))
		{
			throw new \UnexpectedValueException('Cannot convert null to DateTime');
		}
		return new Date($this->event_start, $this->event_start_tz);
	}
	
	/**
	 * Set this instance's recurrence limit
	 * 
	 * @param type $limit
	 * @return \Jcalpro\Event
	 */
	public function setRecurrenceLimit($limit)
	{
		$this->recurrence_limit = (int) $limit;
		return $this;
	}
	
	/**
	 * Get the number of items in the recurrence cache for this event
	 * 
	 * @return int
	 */
	public function getRecurrenceCacheCount(/*$start = null, $end = null*/)
	{
		if (empty($this->recurrence_id))
		{
			return 0;
		}
		return (int) $this->db->setQuery($this->db->getQuery(true)
			->select('COUNT(recurrence_id)')
			->from('#__jcal_recurrence_cache')
			->where($this->db->quoteName('recurrence_id') . ' = ' . $this->db->quote($this->recurrence_id))
		)->loadResult();
	}
	
	/**
	 * Check if this event has any BY* RRULE parts
	 * 
	 * @return type
	 */
	public function hasByrules()
	{
		$rules = false;
		foreach ($this->recurrence_byrules as $rule)
		{
			if ($rules = !is_null($this->$rule))
			{
				break;
			}
		}
		return $rules;
	}
	
	/**
	 * Get BY* RRULE values
	 * 
	 * @param type $rule
	 * @return mixed<null|array>
	 * @throws \InvalidArgumentException
	 */
	public function getByruleValues($rule)
	{
		$rule = strtolower($rule);
		if (!in_array($rule, $this->recurrence_byrules))
		{
			throw new \InvalidArgumentException('Unknown byrule');
		}
		if (!is_null($this->$rule))
		{
			$rules = explode(',', $this->$rule);
			if ('byday' !== $rule)
			{
				array_walk($rules, 'intval');
			}
			else
			{
				array_walk($rules, 'strtoupper');
				$rules = array_intersect(array('SU','MO','TU','WE','TH','FR','SA'), $rules);
			}
			return $rules;
		}
		return null;
	}
	
	/**
	 * This event's interval
	 * 
	 * @return int
	 */
	public function getInterval()
	{
		return (int) (empty($this->interval) ? 1 : $this->interval);
	}
	
	/**
	 * Override to add extra data from recurrences
	 * @return type
	 */
	public function getQuery()
	{
		// get the parent query
		$query = parent::getQuery()
			// join over the extra tables
			->leftJoin($this->recurrence_table . ' AS r ON r.id = a.recurrence_id')
			->leftJoin(Calendar::getInstance()->getTableName() . ' AS c ON c.id = a.cal_id')
		;
		// load the calendar id
		$query->select('c.title AS calendar_title');
		// load the recurrence data
		foreach ($this->recurrence_columns as $column)
		{
			$query->select("r.$column");
		}
		return $query;
	}
	
	/**
	 * Sets this object as just an instance, preventing certain actions from being taken
	 * 
	 * @param type $start
	 * @param type $end
	 * @return \Jcalpro\Event
	 */
	public function setInstance($start, $end)
	{
		$this->instance_start = Date::_($start)->toTimezone($this->event_start_tz);
		$this->instance_end   = Date::_($end)->toTimezone($this->event_end_tz);
		$this->instance_only  = true;
		return $this;
	}
	
	/**
	 * Public access for instance status
	 * 
	 * @return boolean
	 */
	public function isInstance()
	{
		return (bool) $this->instance_only;
	}
	
	/**
	 * Easy access to check if an event is an all day event
	 * 
	 * @return boolean
	 */
	public function isAllDay()
	{
		$check = Date::_($this->event_start)->addDay();
		try
		{
			$end = Date::_($this->event_end);
		}
		catch (Exception $ex)
		{
			$end = false;
		}
		return $check == $end;
	}
	
	/**
	 * Public access to generate flag
	 * 
	 * @param type $generate
	 * @return \Jcalpro\Event
	 */
	public function setGenerateFlag($generate = true)
	{
		$this->generate_on_save = $generate;
		return $this;
	}
	
	/**
	 * Get the event's calendar as an object
	 * 
	 * @return \Jcalpro\Calendar
	 */
	public function getCalendar()
	{
		$key = 'cal_' . $this->cal_id;
		if (!array_key_exists($key, static::$calendar_cache))
		{
			static::$calendar_cache[$key] = Calendar::getInstance($this->cal_id);
		}
		return static::$calendar_cache[$key];
	}
	
	/**
	 * The start date
	 * 
	 * @return \Jcalpro\Date
	 */
	public function getStart()
	{
		if ($this->instance_only)
		{
			return clone $this->instance_start;
		}
		return Date::_($this->event_start, $this->event_start_tz);
	}
	
	/**
	 * The end date
	 * 
	 * @return \Jcalpro\Date
	 */
	public function getEnd()
	{
		if ($this->instance_only)
		{
			return clone $this->instance_end;
		}
		return Date::_($this->event_end, $this->event_end_tz);
	}
	
	/**
	 * Convert this object to an array
	 * 
	 * @return array
	 */
	public function toArray()
	{
		$data = parent::toArray();
		foreach ($this->event_columns as $column)
		{
			$data[$column] = $this->$column;
		}
		foreach ($this->recurrence_columns as $column)
		{
			if (false === strpos($this->$column, ','))
			{
				$data[$column] = $this->$column;
			}
			else
			{
				$data[$column] = explode(',', $this->$column);
			}
		}
		return $data;
	}
	
	/**
	 * Generate a unique id for this event
	 * 
	 * @return string
	 */
	public function generateUid()
	{
		$host = \JUri::getInstance()->getHost();
		if (empty($host))
		{
			$host = filter_input(INPUT_SERVER, 'SERVER_ADDR', FILTER_VALIDATE_IP);
		}
		return $this->getStart()->toUtc()->format('Ymd\This\Z') . uniqid('-') . '@' . $host;
	}
	
	/**
	 * ACL check
	 * 
	 * @return bool
	 */
	public function canEdit()
	{
		return Acl::_('edit', 'event') || (Acl::_('edit.own', 'event') && Factory::getUser()->id === $this->created_by);
	}
}
