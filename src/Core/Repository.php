<?php

namespace DScribe\Core;

use DBScribe\ArrayCollection,
    DBScribe\Table,
    DScribe\Core\IModel,
    DScribe\Core\Repository,
    DScribe\DB\Mapper,
    Exception;

/**
 * Description of Repository
 *
 * @author topman
 */
class Repository implements IRepository {

    const ORDER_ASC = Table::ORDER_ASC;
    const ORDER_DESC = Table::ORDER_DESC;

    /**
     * Table Mapper object
     * @var Mapper|Table
     */
    private $table;
    private $isSelect;
    private $alwaysJoin;

    /**
     * Class constructor
     * @param Mapper $table
     */
    public function __construct(Mapper $table) {
        $db = Engine::getDB();
        $table->setConnection($db);
        $this->table = $db->table($table->getTableName(), $table);
        $table->init($this->table);
        $this->table->delayExecute();
        $this->alwaysJoin = array();
    }

    /**
     * Fetches all rows in the database
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed
     */
    public function fetchAll($toJson = false) {
        if (!$this->table)
            return new ArrayCollection();
        $return = $this->select(array(), $toJson)->execute();

        if (is_bool($return))
            return new ArrayCollection();

        return $return;
    }

    /**
     * Finds row(s) by a column value
     * @param string $column
     * @param mixed $value
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed
     */
    public function findBy($column, $value, $toJson = false) {
        if (!$this->table)
            return new ArrayCollection();

        $return = $this->table->select(array(array(\DBScribe\Util::camelTo_($column) => $value)), $toJson)->execute();
        if (is_bool($return))
            return new ArrayCollection();

        return $return;
    }

    /**
     * Finds a row by a column value
     * @param column $column
     * @param mixed $value
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed Null if no row
     */
    public function findOneBy($column, $value, $toJson = false) {
        return $this->findOneWhere(array(array($column => $value)), $toJson);
    }

    /**
     * Finds a row by the id column
     * @param mixed $idValue
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed Null if no row
     */
    public function findOne($idValue, $toJson = false) {
        if (is_object($idValue)) {
            $getPK = 'get' . ucfirst(\Util::_toCamel($this->table->getPrimaryKey()));
            $idValue = $idValue->$getPK();
        }

        return $this->findOneBy($this->table->getPrimaryKey(), $idValue, $toJson);
    }

    /**
     * Finds a row by the primary column
     * @param mixed $id
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed
     */
    public function find($id, $toJson = false) {
        return $this->findBy($this->table->getPrimaryKey(), $id, $toJson);
    }

    /**
     * Finds row(s) with the given criteria
     * @param array|IModel $criteria
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed
     */
    public function findWhere($criteria, $toJson = false) {
        if (!$this->table)
            return new ArrayCollection();

        if (!is_array($criteria)) {
            $criteria = array($criteria);
        }

        $return = $this->table->select($criteria, $toJson)->execute();
        if (!is_object($return) && !$toJson)
            return new ArrayCollection();
        return $return;
    }

    /**
     * Finds a row with the given criteria
     * @param array|IModel $criteria
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return mixed
     */
    public function findOneWhere($criteria, $toJson = false) {
        $return = ($toJson) ? '{}' : null;
        $result = $this->findWhere($criteria);
        if (!is_bool($result)) {
            $return = ($toJson) ? json_encode($result->first()) : $result->first();
        }
        return $return;
    }

    public function __call($name, $arguments) {
        try {
            if (strtolower(substr($name, 0, 6)) === 'findby') {
                $column = ucfirst(substr($name, 6));
                return call_user_func_array(array($this, 'findBy'), array_merge(array($column), $arguments));
            }
            else if (!method_exists($this, $name)) {
                if ($this->table && method_exists($this->table, $name)) {
                    $return = call_user_func_array(array($this->table, $name), $arguments);
                    return (is_object($return) && is_a($return, 'DBScribe\Table')) ? $this : $return;
                }
            }
        }
        catch (Exception $ex) {
            throw new \Exception($ex->getMessage());
        }
    }

    /**
     * Join with given table on every select
     * @see \DBScribe\Table::join()
     * @param string $tableName
     * @param array $options
     * @return \DScribe\Core\Repository
     */
    public function alwaysJoin($tableName, array $options = array()) {
        $this->alwaysJoin[$tableName] = $options;
        return $this;
    }

    private function insertJoins() {
        foreach ($this->alwaysJoin as $tableName => $options) {
            $this->join($tableName, $options);
        }
    }

    /**
     * Selects data from database with non-NULL model properties as criteria
     * @param array|IModel $model a model or an array of models as criteria
     * @param boolean $toJson Indicates whether to return a JSON formatted string
     * @return Repository
     */
    public function select($model = array(), $toJson = false) {
        if (!$this->table)
            return $this;

        $this->insertJoins();
        if (!is_array($model)) {
            $model = array($model);
        }

        $this->checkModels($model);
        call_user_func_array(array($this->table, 'select'), array($model, $toJson));
        $this->isSelect = true;
        return $this;
    }

    /**
     * Inserts one or more models into the database
     * @param array|IModel $model a model or an array of models to insert
     * @return Repository
     */
    public function insert($model) {
        if (!$this->table)
            return $this;

        if (!is_array($model)) {
            $model = array($model);
        }

        $this->checkModels($model);
        call_user_func_array(array($this->table, 'insert'), array($model));
        return $this;
    }

    /**
     * Updates the database with the model properties
     * @param array|IModel $model a model or an array of models to insert
     * @param string $whereProperty Property to use as the criterion for the update. Default is "id"
     * @return Repository
     */
    public function update($model, $whereProperty = 'id') {
        if (!$this->table)
            return $this;

        if (!is_array($model)) {
            $model = array($model);
        }

        $this->checkModels($model);
        call_user_func_array(array($this->table, 'update'), array($model, $whereProperty));
        return $this;
    }

    /**
     * Deletes data from the database
     * @param array|IModel $model a model or an array of models to insert
     * @return Repository
     */
    public function delete($model) {
        if (!$this->table)
            return $this;

        if (!is_array($model)) {
            $model = array($model);
        }

        $this->checkModels($model);
        call_user_func_array(array($this->table, 'delete'), array($model));
        return $this;
    }

    private function checkModels(array &$models) {
        foreach ($models as &$model) {
            if (is_object($model) && !is_a($model, 'DBScribe\Row'))
                throw new Exception('Param $model must either be a model that implements "DScribe\Core\IModel" or an array of such model');
            elseif (is_array($model)) {
                $newModel = array();
                foreach ($model as $ppt => $val) {
                    $newModel[\Util::camelTo_($ppt)] = $val;
                }
                $model = $newModel;
            }
        }

        return true;
    }

    /**
     * Limits the number of rows to return
     * @param int $step No of rows to return
     * @param int $start Row no to start from
     * @return Repository
     */
    public function limit($step, $start = 0) {
        if (!$this->table)
            return $this;

        call_user_func_array(array($this->table, 'limit'), array($step, $start));
        return $this;
    }

    /**
     * Orders the returned rows
     * @param string $column
     * @param string $direction One of \DScribe\Core\Repository::ORDER_ASC or \DScribe\Core\Repository::ORDER_DESC
     * @return Repository
     */
    public function orderBy($column, $direction = Repository::ORDER_ASC) {
        if (!$this->table)
            return $this;

        call_user_func_array(array($this->table, 'orderBy'), array(\Util::camelTo_($column), $direction));
        return $this;
    }

    /**
     * Fetches the name of the table the repository is attached to
     * @return string
     */
    public function getTableName() {
        if ($this->table)
            return $this->table->getName();
    }

    /**
     * Executes the database operation
     * @return mixed
     */
    public function execute() {
        if (!$this->table)
            return false;

        return call_user_func_array(array($this->table, 'execute'), func_get_args());
    }

    /**
     * Commits all database transactions
     * @return boolean
     */
    public function flush() {
        if (!$this->table)
            return false;

        return Engine::getDB()->flush();
    }

}
