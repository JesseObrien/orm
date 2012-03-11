<?php

/**
 * \Softdelete\Model class
 * 
 * Used to allow the FuelPHP ORM package to "soft" delete rows
 * by tagging a property in the database with a timestamp value 
 * to signify when the row was considered "deleted".
 * 
 * @author PulseInfoframe Inc
 * @copyright 2012 PulseInfoframe Inc
 * @license MIT 
 * @link http://www.pulseinfoframe.com
 * 
 */

namespace Orm\Softdelete;

\Fuel::add_package('orm');

class Model extends \Orm\Model
{

	protected static $_valid_relations = array(
		'belongs_to'    => 'Orm\Softdelete\BelongsTo',
		'has_one'       => 'Orm\Softdelete\HasOne',
		'has_many'      => 'Orm\Softdelete\HasMany',
		'many_many'     => 'Orm\Softdelete\ManyMany',
	);

	public static $_soft_delete_property = 'deleted_at';
	public $mysql_timestamp = false;

	/**
	 * Check to see if this object is soft-deleted
	 * return bool
	 */
	public function is_soft_deleted(){
		// @TODO check for mysql vs timestamp
		return (bool)$this->{self::$_soft_delete_property} !== 0;
	}

	public static function find($id = null, array $options = array() )
	{
		// @TODO Add check for mysql date vs timestamp

		if( ! empty( $options['include_deleted'] ) )
		{
			return parent::find( $id, $options );
		}
		else
		{
			$options = array();
			$options['where'] = array( array( static::$_soft_delete_property, 0 ) );
			return parent::find( $id, $options );
		}		
	}

	/**
	 * Soft-delete this object
	 * return \Softdelete\Model
	 */
	public function delete( $cascade = null, $use_transaction = false ){

		// if the object is frozen, return
		if( $this->frozen() or $this->is_new() )
		{
			return $this;
		}

		// @TODO Not sure if this is the right way to do a transaction, check ORM package
		if($use_transaction)
		{
			$db = \Database_Connection::instance(static::connection());
			$db->start_transaction();
		}

		try
		{
			// Launch observer
			$this->observe('before_delete');
			$this->observe('before_softdelete');

			// Set the soft-deleted property to a mysql time or timestmap
			$this->{self::$_soft_delete_property} = $this->mysql_timestamp ? \Date::forge()->format('mysql') : \Date::forge()->get_timestamp();
			$this->save();

			// Call delete on each related object, specifying "parent deleted" as false
			$this->freeze();
			foreach($this->relations() as $rel_name => $rel)
			{
				$rel->delete($this, $this->{$rel_name}, false, is_array($cascade) ? in_array($rel_name, $cascade) : $cascade);
			}
			$this->unfreeze();

			$this->freeze();
			// Call delete on each related object, specifying "parent deleted" as true
			foreach($this->relations() as $rel_name => $rel)
			{
				$rel->delete($this, $this->{$rel_name}, true, is_array($cascade) ? in_array($rel_name, $cascade) : $cascade);
			}
			$this->unfreeze();

			// Call the observers
			$this->observe('after_delete');
			$this->observe('after_softdelete');

			// If transactions are being used, commit this one
			$use_transaction and $db->commit_transaction();
		}
		catch( \Exception $e )
		{
			$use_transaction and $db->rollback_transaction();
			throw $e;
		}

		return $this;
	}

	/**
	 * Restore this object from being deleted
	 * return \Softdelete\Model
	 */
	public function restore()
	{
		// If the object is frozen, return
		if( $this->frozen() )
		{
			return $this;
		}

		if( $this->is_soft_deleted() )
		{
			$this->observe('before_restore');
			// @TODO this might need to be null
			$this->{self::$_soft_delete_property} = 0;
			$this->save();
		}
		// Restore the object
		return $this;
	}

	/**
	 * Alias for restore()
	 * return \Softdelete\Model
	 */
	public function undelete()
	{
		$this->restore();
		return $this;
	}

}
