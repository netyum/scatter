scatter table for Yii1.x
=======

CONFIG

```php
'import'=>array(
    'application.models.*',
    'application.components.*',
    'application.extensions.scatter.*' //add the line
),
```

Add Componenet
```php
'components'=>array(
    'dsm' => array(
        'class' => 'SDataSourceManager',
        'configPath' => 'application.config.datasource',
        //datasource config file path. support alias.
    ),
```

Create datasource config file.
```php
<?php
$config = array(
    'id' => 'position',             //source name，for developer.
    'tableName' => 't_position',    //main tablename.
    'autoCreate' => true,          //if not exists use schema attribute auto create table.
    'schema' => '
CREATE TABLE `{tableName}` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `state` tinyint(4) NOT NULL,
  `longitude` varchar(15) NOT NULL,
  `latitude` varchar(15) NOT NULL,
  `google_lng` varchar(15) NOT NULL,
  `google_lat` varchar(15) NOT NULL,
  `baidu_lng` varchar(15) NOT NULL,
  `baidu_lat` varchar(15) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `created` (`created`),
  KEY `user_id_2` (`user_id`,`baidu_lng`,`baidu_lat`)
) ENGINE=MyISAM AUTO_INCREMENT=0 DEFAULT CHARSET=utf8;
	',  //{tableName} will replaced real tablename
    'ploys' => [		                //scatter ploy
        'default' => [
            'scatter' => ['user_id'],       //值, 数组，会传给expr的匿名函数,
			'pattern' => '%_{value}',       //表名模式 %就是上面的tableName
			'scatterExpr' => function ($user_id) {
                return intval($user_id % 100);
            },
			'db' => [
                'write' => [
                    'w1' => 'db',
                    'w2' => 'db1',
                ],
                'read' => [
                    'r1' => 'db',	//主配置文件中的db
                    'r2' => 'db1',
                    'r3' =>	[
                        'class' => 'CDbConnection',
                        'connectionString' => 'sqlite:'. Yii::getPathOfAlias('application.data').'/3.db',
                    ]
                ]
			],
            //表与数据库对应
			'tableDbMapExpr' => function($scatterValue) {
                if ($scatterValue > 30) {
                    return [
                        'write' => 'w1',
                        'read' => 'r1',
                    ];
				} else {
                    return [
                        'write' => 'w2',
                        'read' => 'r2'
                    ];
				}
			}
		],
	]
];

return $config;
```

USED

```php
$ds = Yii::app()->dsm->load('position');
// the position was datasource。it will reload position.php

// current $ds was SDataSourcePloy object.

//query
$data = $ds->scatter('123')->select('*')->queryAll();

//insert
$ret = $ds->scatter('123')->insert([
    //'user_id' => 123,
    'state' => 2,
    'longitude'=> 3,
    'latitude' => 4,
    'google_lng' => 5,
    'google_lat' => 6,
    'baidu_lng' => 7,
    'baidu_lat' => 8,
    'created' => date('Y-m-d H:i:s')
]);
```

>Note: scatter function params 123 is user_id in source config.

```
        'default' => [
            'scatter' => ['user_id'],
```

so auto set value for user_id column;
