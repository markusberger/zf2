<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Db
 */

namespace Zend\Db\TableGateway;

use Zend\Db\Adapter\Adapter,
    Zend\Db\Sql\TableIdentifier,
    Zend\Db\ResultSet\ResultSet,
    Zend\Db\Sql\Sql,
    Zend\Db\Sql\Select,
    Zend\Db\Sql\Insert,
    Zend\Db\Sql\Update,
    Zend\Db\Sql\Delete;

/**
 * @category   Zend
 * @package    Zend_Db
 * @subpackage TableGateway
 *
 * @property Adapter $adapter
 * @property int $lastInsertValue
 * @property string $table
 */
abstract class AbstractTableGateway implements TableGatewayInterface
{

    /**
     * @var bool
     */
    protected $isInitialized = false;

    /**
     * @var Adapter
     */
    protected $adapter = null;

    /**
     * @var string
     */
    protected $table = null;

    /**
     * @var array
     */
    protected $columns = array();

    /**
     * @var Feature\FeatureSet
     */
    protected $featureSet = null;

    /**
     * @var ResultSet
     */
    protected $resultSetPrototype = null;

    /**
     * @var Sql\Sql
     */
    protected $sql = null;

    /**
     *
     * @var integer
     */
    protected $lastInsertValue = null;

    /**
     * @return bool
     */
    public function isInitialized()
    {
        return $this->isInitialized;
    }

    /**
     * Initialize
     *
     * @return null
     */
    public function initialize()
    {
        if ($this->isInitialized) {
            return;
        }

        if (!$this->featureSet instanceof Feature\FeatureSet) {
            $this->featureSet = new Feature\FeatureSet;
        }

        $this->featureSet->setTableGateway($this);
        $this->featureSet->apply('preInitialize', array());

        if (!$this->adapter instanceof Adapter) {
            throw new Exception\RuntimeException('This table does not have an Adapter setup');
        }

        if (!is_string($this->table) && !$this->table instanceof TableIdentifier) {
            throw new Exception\RuntimeException('This table object does not have a valid table set.');
        }

        if (!$this->resultSetPrototype instanceof ResultSet) {
            $this->resultSetPrototype = new ResultSet;
        }

        if (!$this->sql instanceof Sql) {
            $this->sql = new Sql($this->adapter, $this->table);
        }

        $this->featureSet->apply('postInitialize', array());

        $this->isInitialized = true;
    }

    /**
     * Get table name
     * 
     * @return string 
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get adapter
     * 
     * @return Adapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @return array
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * @return Feature\FeatureSet
     */
    public function getFeatureSet()
    {
        return $this->featureSet;
    }

    /**
     * Get select result prototype
     *
     * @return ResultSet
     */
    public function getResultSetPrototype()
    {
        return $this->resultSetPrototype;
    }

    /**
     * @return Sql
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * Select
     * 
     * @param string|array|\Closure $where
     * @return ResultSet
     */
    public function select($where = null)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }

        $select = $this->sql->select();

        if ($where instanceof \Closure) {
            $where($select);
        } elseif ($where !== null) {
            $select->where($where);
        }

        return $this->selectWith($select);
    }

    /**
     * @param Sql\Select $select
     * @return null|ResultSet
     * @throws \RuntimeException
     */
    public function selectWith(Select $select)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        return $this->executeSelect($select);
    }

    /**
     * @param Select $select
     * @return ResultSet
     * @throws \RuntimeException
     */
    protected function executeSelect(Select $select)
    {
        $selectState = $select->getRawState();
		$selectTable = ($selectState['table'] instanceof TableIdentifier) ? $selectState['table']->getTable() : $selectState['table'];
		
        if ($selectTable != $this->table) {
            throw new \RuntimeException('The table name of the provided select object must match that of the table');
        }

        if ($selectState['columns'] == array(Select::SQL_STAR)
            && $this->columns !== array()) {
            $select->columns($this->columns);
        }

        // apply preSelect features
        $this->featureSet->apply('preSelect', array($select));

        // prepare and execute
        $statement = $this->sql->prepareStatementForSqlObject($select);
        $result = $statement->execute();

        // build result set
        $resultSet = clone $this->resultSetPrototype;
        $resultSet->setDataSource($result);

        // apply postSelect features
        $this->featureSet->apply('postSelect', array($statement, $result, $resultSet));

        return $resultSet;
    }

    /**
     * Insert
     *
     * @param  array $set
     * @return int
     */
    public function insert($set)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $insert = $this->sql->insert();
        $insert->values($set);
        return $this->executeInsert($insert);
    }

    /**
     * @param Insert $insert
     * @return mixed
     */
    public function insertWith(Insert $insert)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        return $this->executeInsert($insert);
    }

    /**
     * @todo add $columns support
     *
     * @param Insert $insert
     * @return mixed
     * @throws Exception\RuntimeException
     */
    protected function executeInsert(Insert $insert)
    {
        $insertState = $insert->getRawState();
        if ($insertState['table'] != $this->table) {
            throw new Exception\RuntimeException('The table name of the provided Insert object must match that of the table');
        }

        // apply preInsert features
        $this->featureSet->apply('preInsert', array($insert));

        $statement = $this->sql->prepareStatementForSqlObject($insert);
        $result = $statement->execute();
        $this->lastInsertValue = $this->adapter->getDriver()->getConnection()->getLastGeneratedValue();

        // apply postInsert features
        $this->featureSet->apply('postInsert', array($statement, $result));

        return $result->getAffectedRows();
    }

    /**
     * Update
     *
     * @param  array $set
     * @param  string|array|closure $where
     * @return int
     */
    public function update($set, $where = null)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $sql = $this->sql;
        $update = $sql->update();
        $update->set($set);
        $update->where($where);
        return $this->executeUpdate($update);
    }

    /**
     * @param \Zend\Db\Sql\Update $update
     * @return mixed
     */
    public function updateWith(Update $update)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        return $this->executeUpdate($update);
    }

    /**
     * @todo add $columns support
     *
     * @param Update $update
     * @return mixed
     * @throws Exception\RuntimeException
     */
    protected function executeUpdate(Update $update)
    {
        $updateState = $update->getRawState();
        if ($updateState['table'] != $this->table) {
            throw new Exception\RuntimeException('The table name of the provided Update object must match that of the table');
        }

        // apply preUpdate features
        $this->featureSet->apply('preUpdate', array($update));

        $statement = $this->sql->prepareStatementForSqlObject($update);
        $result = $statement->execute();

        // apply postUpdate features
        $this->featureSet->apply('postUpdate', array($statement, $result));

        return $result->getAffectedRows();
    }

    /**
     * Delete
     *
     * @param  Closure $where
     * @return int
     */
    public function delete($where)
    {
        if (!$this->isInitialized) {
            $this->initialize();
        }
        $delete = $this->sql->delete();
        if ($where instanceof \Closure) {
            $where($delete);
        } else {
            $delete->where($where);
        }
        return $this->executeDelete($delete);
    }

    /**
     * @param Delete $delete
     * @return mixed
     */
    public function deleteWith(Delete $delete)
    {
        $this->initialize();
        return $this->executeDelete($delete);
    }

    /**
     * @todo add $columns support
     *
     * @param Delete $delete
     * @return mixed
     * @throws Exception\RuntimeException
     */
    protected function executeDelete(Delete $delete)
    {
        $deleteState = $delete->getRawState();
        if ($deleteState['table'] != $this->table) {
            throw new Exception\RuntimeException('The table name of the provided Update object must match that of the table');
        }

        // pre delete update
        $this->featureSet->apply('preDelete', array($delete));

        $statement = $this->sql->prepareStatementForSqlObject($delete);
        $result = $statement->execute();

        // apply postDelete features
        $this->featureSet->apply('postDelete', array($statement, $result));

        return $result->getAffectedRows();
    }

    /**
     * Get last insert value
     * 
     * @return integer 
     */
    public function getLastInsertValue()
    {
        return $this->lastInsertValue;
    }

    /**
     * __get
     * 
     * @param  string $property
     * @return mixed
     */
    public function __get($property)
    {
        switch (strtolower($property)) {
            case 'lastinsertvalue':
                return $this->lastInsertValue;
            case 'adapter':
                return $this->adapter;
            case 'table':
                return $this->table;
        }
        if ($this->featureSet->canCallMagicGet($property)) {
            return $this->featureSet->callMagicGet($property);
        }
        throw new Exception\InvalidArgumentException('Invalid magic property access in ' . __CLASS__ . '::__get()');
    }

    /**
     * @param $property
     * @return mixed
     * @throws Exception\InvalidArgumentException
     */
    public function __set($property, $value)
    {
        if ($this->featureSet->canCallMagicSet($property)) {
            return $this->featureSet->callMagicSet($property, $value);
        }
        throw new Exception\InvalidArgumentException('Invalid magic property access in ' . __CLASS__ . '::__set()');
    }

    /**
     * @param $method
     * @param $arguments
     * @return mixed
     * @throws Exception\InvalidArgumentException
     */
    public function __call($method, $arguments)
    {
        if ($this->featureSet->canCallMagicCall($method)) {
            return $this->featureSet->callMagicCall($method, $arguments);
        }
        throw new Exception\InvalidArgumentException('Invalid method (' . $method . ') called, caught by ' . __CLASS__ . '::__call()');
    }

    /**
     * __clone
     */
    public function __clone()
    {
        $this->resultSetPrototype = (isset($this->resultSetPrototype)) ? clone $this->resultSetPrototype : null;
        $this->sql = clone $this->sql;
        if (is_object($this->table)) {
            $this->table = clone $this->table;
        }
    }

}
