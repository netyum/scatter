<?php
/**
 * Class SDataSourcePloy
 * 数据源配置策略
 * @author syang
 * @date 2014-6-12
 */
class SDataSourcePloy extends CComponent
{
    //散表表达式 参数名
    public $scatter = [];

    //数据库连接配置
    public $db;

    public $tableNamePattern = "";

    public $schema = '';

    public $autoCreate = false;

    /**
     * 散表表名 模式
     * @var string 散表表达式返回值会规换{value}值
     */
    private $_pattern = "{value}";

    /** @var callback 散表表达式 */
    private $_scatterExpr = null;

    /** @var callback 表与数据库映射关系表 */
    private $_tableDbMapExpr = null;


    private $_valueCache = [];

    /**
     * @param $pattern string
     */
    public function setPattern($pattern)
    {
        if (strpos($pattern, '{value}') === false) {
            throw new CDbException('散表表名模式中必须存在{value}字符串');
        }
        $this->_pattern = $pattern;
    }

    public function getPattern()
    {
        return $this->_pattern;
    }

    /**
     * 散表表达式回调
     * @param $expr callback
     * @throws CDbException
     */
    public function setScatterExpr($expr)
    {
        if (!is_callable($expr)) {
            throw new CDbException('散表表达式必须是一个回调函数');
        }
        $this->_scatterExpr = $expr;
    }

    public function getScatterExpr()
    {
        return $this->_scatterExpr;
    }

    /**
     * 表与数据库映射关系表达式
     * @param $expr  callback
     * @throws CDbException
     */
    public function setTableDbMapExpr($expr)
    {
        if (!is_callable($expr)) {
            throw new CDbException('表与数据库映射关系表达式必须是一个回调函数');
        }
        $this->_tableDbMapExpr = $expr;
    }

    public function getTableDbMapExpr()
    {
        return $this->_tableDbMapExpr;
    }

    /**
     * 散表
     */
    public function scatter() {
        $args = func_get_args();
        if (count($this->scatter) != count($args)) {
            throw new CDbException('策略scatter定义的散表参数个数与实现传入参数个数不符');
        }

        return new SDataSourceCommand($this, $args);
    }

    public function getValue($args) {
        $cacheKey = md5(json_encode($args));
        if (isset($this->_valueCache['$cacheKey'])) {
            return $this->_valueCache['$cacheKey'];
        } else {
            return call_user_func_array($this->scatterExpr, $args);
        }
    }

    public function getTableName($value) {
        $pattern = $this->pattern;
        $tableName = str_replace('{value}', $value, $pattern);
        $tableName = str_replace('%', $this->tableNamePattern, $tableName);
        return $tableName;
    }
}