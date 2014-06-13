<?php

/**
 * Class SDataSource
 * 散表数据源管理器
 *
 * @author syang
 * @date 2014-6-12
 */
class SDataSourceManager extends CApplicationComponent
{
    /* 配置文件路径 */
    private $_configPath = null;

    private $_sourceCache = [];

    /**
     * @param $path string 路径，可以是别名
     */
    public function setConfigPath($path) {
        $aliasPath = Yii::getPathOfAlias($path);
        if ($aliasPath) {
            $configPath = $aliasPath;
        } else {
            $configPath = $path;
        }
        if (!is_dir($configPath)) {
            throw new CDbException('数据源配置路径不是有效目录');
        }
        $this->_configPath = $configPath;
    }

    /**
     * @return mixd
     * @throws CDbException
     */
    public function getConfigPath() {
        if ($this->_configPath === null) {
            throw new CDbException('未设置数据源配置路径');
        }
        return $this->_configPath;
    }

    /**
     * 加载数据源
     * @param $sourceName
     * @param $ployName
     */
    public function load($sourceName, $ployName = 'default') {
        $cacheKey = md5( md5($sourceName) . md5($ployName));

        if (isset($this->_sourceCache[$cacheKey])) {
            return $this->_sourceCache[$cacheKey];
        } else {
            $configFile = $this->_configPath .'/' . $sourceName .'.php';
            if (!is_file($configFile) || !is_readable($configFile)) {
                throw new CDbException('数据源配置文件不存在或不可读');
            }
            $config = require($configFile);
            if (!isset($config['class'])) {
                $config['class'] = 'SDataSource';
            }
            $config['ployName'] = $ployName;
            $dataSource = Yii::createComponent($config);
            $this->_sourceCache[$cacheKey] = $dataSource->getPloy($ployName);
            return $this->_sourceCache[$cacheKey];
        }
    }
}