<?php
/**
 * Class SDataSourcePloy
 * 数据源
 * @author syang
 * @date 2014-6-12
 */
class SDataSource extends CComponent
{
    /**
     * @var stirng 数据id名
     */
    public $id;

    public $tableName;

    public $schema;

    public $autoCreate = false;

    public $ployName = 'default';

    private $_ploys = [];
    private $_ploysConfig = [];


    //设置策略
    public function setPloys($ploys)
    {
        if (!is_array($ploys)) {
            throw new CDbException('策略配置格式不正确');
        }

        if (!isset($ploys['default'])) {
            throw new CDbException('策略必需存在一个default键的配置');
        }
        if (!isset($ploys[$this->ployName])) {
            throw new CDbException('没有给定的策略名');
        }

        $this->setPloy($this->ployName, $ploys[$this->ployName]);
    }

    public function setPloy($key, $ploy)
    {
        if (!isset($ploy['class'])) {
            $ploy['class'] = 'SDataSourcePloy';
            $ploy['tableNamePattern'] = $this->tableName;
            $ploy['autoCreate'] = $this->autoCreate;
            $ploy['schema'] = $this->schema;
        }
        $this->_ploys[$key] = Yii::createComponent($ploy);
        $this->_ploysConfig[$key] = $ploy;
    }

    public function getPloy($key, $isConfig = false)
    {
        if ($isConfig == true) {
            return isset($this->_ploysConfig[$key]) ? $this->_ploysConfig[$key] : null;
        } else {
            return isset($this->_ploys[$key]) ? $this->_ploys[$key] : null;
        }
    }


}