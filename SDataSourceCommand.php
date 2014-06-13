<?php

class SDataSourceCommand extends CComponent
{
    public $params=array();

    private $_connection;
    private $_text;
    private $_statement;
    private $_paramLog=array();
    private $_query;
    private $_fetchMode = array(PDO::FETCH_ASSOC);
    private $_ploy;
    private $_args;

    public $tableName;
    public $db_mapping;
    public $db_config;

    public function __construct($ploy, $args)
    {
        $value = $ploy->getValue($args);
        $this->tableName = $ploy->getTableName($value);

        $mapExpr = $ploy->getTableDbMapExpr();
        $db_mapping = call_user_func_array($mapExpr, [$value]);
        $this->db_mapping = $db_mapping;
        $this->db_config = $ploy->db;

        if (is_array($db_mapping)) {
            if (!isset($db_mapping['write']) || !isset($db_mapping['read'])) {
                throw new CDbException('分库必须设置读写');
            }
        } else if (!is_string($db_mapping)) {
            throw new CDbException('mapping回调函数有误');
        }
        $this->_args = $args;
        $this->_ploy = $ploy;
        $schema = null;
        if ($ploy->autoCreate) {
            $schema = $ploy->schema;
        }

        //先获得一个主库写连接
        if (is_array($this->db_config)) {
            if (!isset($this->db_config['write']) || !isset($this->db_config['read'])) {
                throw new CDbException('非字符串，比需配置读写分类');
            }
        }

        $this->_query['from'] = $this->tableName;
        $this->swtichRandConnection('write');

        if (!$this->isTableExists($this->tableName) && $schema !== null) {
            $schema = str_replace('{tableName}', $this->tableName, $schema);

            $this->_connection->createCommand($schema)->execute();
        }
    }

    public function swtichRandConnection($type = 'write') {
        if (is_array($this->db_mapping)) {
            $write = $this->db_mapping[$type];

            if (is_string($write)) {
                $write = array($write);
            }

            shuffle($write);
            $key = array_rand($write, 1);
            $db_key = $write[$key];
            $conn = $this->db_config[$type][$db_key];
            if (is_array($conn)) {
                $this->_connection = Yii::createComponent($conn);
            } else {
                $this->_connection = Yii::app()->$conn;
            }

        } else if (is_string($this->db_mapping)) { //不分库
            if (!is_string($this->db_config)) {
                throw new CDbException('mapping表达式返回字符串，则db必须是一个字符串');
            }
            $this->_connection = Yii::app()->{$this->db_config};
        }
    }

    public function isTableExists($tableName) {
        $sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_NAME=:tableName";

        $count = $this->_connection->createCommand($sql)->queryScalar(array(':tableName' => $tableName));
        return $count == 1;
    }

    public function where($conditions, $params=array())
    {
        $this->_query['where']=$this->processConditions($conditions);

        foreach($params as $name=>$value)
            $this->params[$name]=$value;
        return $this;
    }

    public function andWhere($conditions,$params=array())
    {
        if(isset($this->_query['where']))
            $this->_query['where']=$this->processConditions(array('AND',$this->_query['where'],$conditions));
        else
            $this->_query['where']=$this->processConditions($conditions);

        foreach($params as $name=>$value)
            $this->params[$name]=$value;
        return $this;
    }

    public function orWhere($conditions,$params=array())
    {
        if(isset($this->_query['where']))
            $this->_query['where']=$this->processConditions(array('OR',$this->_query['where'],$conditions));
        else
            $this->_query['where']=$this->processConditions($conditions);

        foreach($params as $name=>$value)
            $this->params[$name]=$value;
        return $this;
    }

    /**
     * Returns the WHERE part in the query.
     * @return string the WHERE part (without 'WHERE' ) in the query.
     * @since 1.1.6
     */
    public function getWhere()
    {
        return isset($this->_query['where']) ? $this->_query['where'] : '';
    }

    /**
     * Sets the WHERE part in the query.
     * @param mixed $value the where part. Please refer to {@link where()} for details
     * on how to specify this parameter.
     * @since 1.1.6
     */
    public function setWhere($value)
    {
        $this->where($value);
    }

    public function queryAll($fetchAssociative=true,$params=array())
    {
        return $this->queryInternal('fetchAll',$fetchAssociative ? $this->_fetchMode : PDO::FETCH_NUM, $params);
    }

    public function getConnection()
    {
        return $this->_connection;
    }

    public function getPdoStatement()
    {
        return $this->_statement;
    }

    public function prepare()
    {
        if($this->_statement==null)
        {
            try
            {
                $this->_statement=$this->getConnection()->getPdoInstance()->prepare($this->getText());
                $this->_paramLog=array();
            }
            catch(Exception $e)
            {
                Yii::log('Error in preparing SQL: '.$this->getText(),CLogger::LEVEL_ERROR,'system.db.CDbCommand');
                $errorInfo=$e instanceof PDOException ? $e->errorInfo : null;
                throw new CDbException(Yii::t('yii','CDbCommand failed to prepare the SQL statement: {error}',
                    array('{error}'=>$e->getMessage())),(int)$e->getCode(),$errorInfo);
            }
        }
    }

    private function queryInternal($method,$mode,$params=array())
    {
        $params=array_merge($this->params,$params);
        $this->_connection->setActive(false);
        $this->_connection = null;
        $this->swtichRandConnection('read');
        $this->_connection->setActive(true);

        if($this->_connection->enableParamLogging && ($pars=array_merge($this->_paramLog,$params))!==array())
        {
            $p=array();
            foreach($pars as $name=>$value)
                $p[$name]=$name.'='.var_export($value,true);
            $par='. Bound with '.implode(', ',$p);
        }
        else
            $par='';

        Yii::trace('Querying SQL: '.$this->getText().$par,'system.db.CDbCommand');

        if($this->_connection->queryCachingCount>0 && $method!==''
            && $this->_connection->queryCachingDuration>0
            && $this->_connection->queryCacheID!==false
            && ($cache=Yii::app()->getComponent($this->_connection->queryCacheID))!==null)
        {
            $this->_connection->queryCachingCount--;
            $cacheKey='yii:dbquery'.':'.$method.':'.$this->_connection->connectionString.':'.$this->_connection->username;
            $cacheKey.=':'.$this->getText().':'.serialize(array_merge($this->_paramLog,$params));
            if(($result=$cache->get($cacheKey))!==false)
            {
                Yii::trace('Query result found in cache','system.db.CDbCommand');
                return $result[0];
            }
        }

        try
        {
            if($this->_connection->enableProfiling)
                Yii::beginProfile('system.db.CDbCommand.query('.$this->getText().$par.')','system.db.CDbCommand.query');

            $this->prepare();
            if($params===array()) {
                $this->_statement->execute();
            }
            else
                $this->_statement->execute($params);

            if($method==='')
                $result=new CDbDataReader($this);
            else
            {
                $mode=(array)$mode;
                call_user_func_array(array($this->_statement, 'setFetchMode'), $mode);
                $result=$this->_statement->$method();
                $this->_statement->closeCursor();
            }

            if($this->_connection->enableProfiling)
                Yii::endProfile('system.db.CDbCommand.query('.$this->getText().$par.')','system.db.CDbCommand.query');

            if(isset($cache,$cacheKey))
                $cache->set($cacheKey, array($result), $this->_connection->queryCachingDuration, $this->_connection->queryCachingDependency);

            return $result;
        }
        catch(Exception $e)
        {
            if($this->_connection->enableProfiling)
                Yii::endProfile('system.db.CDbCommand.query('.$this->getText().$par.')','system.db.CDbCommand.query');

            $errorInfo=$e instanceof PDOException ? $e->errorInfo : null;
            $message=$e->getMessage();
            Yii::log(Yii::t('yii','CDbCommand::{method}() failed: {error}. The SQL statement executed was: {sql}.',
                array('{method}'=>$method, '{error}'=>$message, '{sql}'=>$this->getText().$par)),CLogger::LEVEL_ERROR,'system.db.CDbCommand');

            if(YII_DEBUG)
                $message.='. The SQL statement executed was: '.$this->getText().$par;

            throw new CDbException(Yii::t('yii','CDbCommand failed to execute the SQL statement: {error}',
                array('{error}'=>$message)),(int)$e->getCode(),$errorInfo);
        }
    }

    public function buildQuery($query)
    {
        $sql=!empty($query['distinct']) ? 'SELECT DISTINCT' : 'SELECT';
        $sql.=' '.(!empty($query['select']) ? $query['select'] : '*');

        if(!empty($query['from']))
            $sql.="\nFROM ".$query['from'];

        if(!empty($query['join']))
            $sql.="\n".(is_array($query['join']) ? implode("\n",$query['join']) : $query['join']);

        if(!empty($query['where']))
            $sql.="\nWHERE ".$query['where'];

        if(!empty($query['group']))
            $sql.="\nGROUP BY ".$query['group'];

        if(!empty($query['having']))
            $sql.="\nHAVING ".$query['having'];

        if(!empty($query['union']))
            $sql.="\nUNION (\n".(is_array($query['union']) ? implode("\n) UNION (\n",$query['union']) : $query['union']) . ')';

        if(!empty($query['order']))
            $sql.="\nORDER BY ".$query['order'];

        $limit=isset($query['limit']) ? (int)$query['limit'] : -1;
        $offset=isset($query['offset']) ? (int)$query['offset'] : -1;
        if($limit>=0 || $offset>0)
            $sql=$this->_connection->getCommandBuilder()->applyLimit($sql,$limit,$offset);

        return $sql;
    }

    public function select($columns='*', $option='')
    {
        if(is_string($columns) && strpos($columns,'(')!==false)
            $this->_query['select']=$columns;
        else
        {
            if(!is_array($columns))
                $columns=preg_split('/\s*,\s*/',trim($columns),-1,PREG_SPLIT_NO_EMPTY);

            foreach($columns as $i=>$column)
            {
                if(is_object($column))
                    $columns[$i]=(string)$column;
                elseif(strpos($column,'(')===false)
                {
                    if(preg_match('/^(.*?)(?i:\s+as\s+|\s+)(.*)$/',$column,$matches))
                        $columns[$i]=$this->_connection->quoteColumnName($matches[1]).' AS '.$this->_connection->quoteColumnName($matches[2]);
                    else
                        $columns[$i]=$this->_connection->quoteColumnName($column);
                }
            }
            $this->_query['select']=implode(', ',$columns);
        }
        if($option!='')
            $this->_query['select']=$option.' '.$this->_query['select'];
        return $this;
    }

    /**
     * Returns the SELECT part in the query.
     * @return string the SELECT part (without 'SELECT') in the query.
     * @since 1.1.6
     */
    public function getSelect()
    {
        return isset($this->_query['select']) ? $this->_query['select'] : '';
    }

    /**
     * Sets the SELECT part in the query.
     * @param mixed $value the data to be selected. Please refer to {@link select()} for details
     * on how to specify this parameter.
     * @since 1.1.6
     */
    public function setSelect($value)
    {
        $this->select($value);
    }

    public function execute($params=array())
    {
        $this->_connection->setActive(false);
        $this->_connection = null;
        $this->swtichRandConnection('write');
        $this->_connection->setActive(true);

        if($this->_connection->enableParamLogging && ($pars=array_merge($this->_paramLog,$params))!==array())
        {
            $p=array();
            foreach($pars as $name=>$value)
                $p[$name]=$name.'='.var_export($value,true);
            $par='. Bound with ' .implode(', ',$p);
        }
        else
            $par='';
        Yii::trace('Executing SQL: '.$this->getText().$par,'system.db.CDbCommand');
        try
        {
            if($this->_connection->enableProfiling)
                Yii::beginProfile('system.db.CDbCommand.execute('.$this->getText().$par.')','system.db.CDbCommand.execute');

            $this->prepare();
            if($params===array())
                $this->_statement->execute();
            else
                $this->_statement->execute($params);
            $n=$this->_statement->rowCount();

            if($this->_connection->enableProfiling)
                Yii::endProfile('system.db.CDbCommand.execute('.$this->getText().$par.')','system.db.CDbCommand.execute');

            return $n;
        }
        catch(Exception $e)
        {
            if($this->_connection->enableProfiling)
                Yii::endProfile('system.db.CDbCommand.execute('.$this->getText().$par.')','system.db.CDbCommand.execute');

            $errorInfo=$e instanceof PDOException ? $e->errorInfo : null;
            $message=$e->getMessage();
            Yii::log(Yii::t('yii','CDbCommand::execute() failed: {error}. The SQL statement executed was: {sql}.',
                array('{error}'=>$message, '{sql}'=>$this->getText().$par)),CLogger::LEVEL_ERROR,'system.db.CDbCommand');

            if(YII_DEBUG)
                $message.='. The SQL statement executed was: '.$this->getText().$par;

            throw new CDbException(Yii::t('yii','CDbCommand failed to execute the SQL statement: {error}',
                array('{error}'=>$message)),(int)$e->getCode(),$errorInfo);
        }
    }

    /**
     * @return string the SQL statement to be executed
     */
    public function getText()
    {
        if($this->_text=='' && !empty($this->_query))
            $this->setText($this->buildQuery($this->_query));
        return $this->_text;
    }

    /**
     * Specifies the SQL statement to be executed.
     * Any previous execution will be terminated or cancel.
     * @param string $value the SQL statement to be executed
     * @return static this command instance
     */
    public function setText($value)
    {
        if($this->_connection->tablePrefix!==null && $value!='')
            $this->_text=preg_replace('/{{(.*?)}}/',$this->_connection->tablePrefix.'\1',$value);
        else
            $this->_text=$value;
        $this->cancel();
        return $this;
    }

    /**
     * Cancels the execution of the SQL statement.
     */
    public function cancel()
    {
        $this->_statement=null;
    }

    public function insert($columns)
    {
        //散表配置字段
        $scatter_columns = array();
        $scatter = $this->_ploy->scatter;
        foreach($scatter as $key =>  $value) {
            $scatter_columns[$value] = $this->_args[$key];
        }
        $columns = array_merge($columns, $scatter_columns);
        $params=array();
        $names=array();
        $placeholders=array();
        foreach($columns as $name=>$value)
        {
            $names[]=$this->_connection->quoteColumnName($name);
            if($value instanceof CDbExpression)
            {
                $placeholders[] = $value->expression;
                foreach($value->params as $n => $v)
                    $params[$n] = $v;
            }
            else
            {
                $placeholders[] = ':' . $name;
                $params[':' . $name] = $value;
            }
        }
        $sql='INSERT INTO ' . $this->_connection->quoteTableName($this->tableName)
            . ' (' . implode(', ',$names) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        return $this->setText($sql)->execute($params);
    }

    public function update($columns, $conditions='', $params=array())
    {
        $lines=array();
        foreach($columns as $name=>$value)
        {
            if($value instanceof CDbExpression)
            {
                $lines[]=$this->_connection->quoteColumnName($name) . '=' . $value->expression;
                foreach($value->params as $n => $v)
                    $params[$n] = $v;
            }
            else
            {
                $lines[]=$this->_connection->quoteColumnName($name) . '=:' . $name;
                $params[':' . $name]=$value;
            }
        }
        $sql='UPDATE ' . $this->_connection->quoteTableName($this->tableName) . ' SET ' . implode(', ', $lines);
        if(($where=$this->processConditions($conditions))!='')
            $sql.=' WHERE '.$where;
        return $this->setText($sql)->execute($params);
    }
}