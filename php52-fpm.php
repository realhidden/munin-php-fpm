#!/usr/bin/php
<?php

/**
* @author realhidden
* @author Devlopnet
* @author MorbZ
* @licence CC
*/

//grab all pools
//TODO: make it configurable
$config=explode("\n",file_get_contents("/etc/php52/php-fpm.conf"));
$allpool=array();
for ($i=0;$i<count($config);$i++)
{
    if (preg_match('/<value name\="name">(.*?)<\/value>/iU',$config[$i],$matches)==0)
	continue;

     $poolName=$matches[1];

     for(;$i<count($config);$i++)
     {
	if (preg_match('/<value name\="user">(.*?)<\/value>/iU',$config[$i],$matches2)==0)
            continue;

	$uid=$matches2[1];
	
	//add it to the pools
	$allpool[$uid]=$poolName;
	break;
     }
}

exec('find /etc/php5/fpm/pool.d/*.conf -type f -printf "%f\n"',$allpool);
foreach($allpool as &$pool)
{
   $pool=substr($pool,0,-5);
}

//grab processes
exec('ps -eo %cpu,etime,rss,uid,command | grep php-cgi | grep "\-\-fpm"', $result);

//iterate through processes
$groups = array();
foreach ($result as $line) {
	//split fields
	$line = trim($line);
	$args = preg_split('/\s+/', $line);

    if (strpos($args[4], 'php-cgi') === false) {
        continue;
    }
    list($cpu, $time, $ram, $uid, $command) = $args;
 
    //ignore root process
    if ($uid == "0") {
    	continue;
    }
    $groupName = $allpool[$uid];

	//add group
    if (!isset($groups[$groupName])) {
        $groups[$groupName] = array(
        	'count' => 0,
        	'memory' => 0,
        	'cpu' => 0,
        	'time' => 0
        );
    }
	
	//add values
	$groups[$groupName]['count']++;
	$groups[$groupName]['cpu'] += $cpu;
	$groups[$groupName]['time'] += timeToSeconds($time);
	$groups[$groupName]['memory'] += $ram / 1024;
}

//add missing pools
foreach($allpool as $groupName)
{
	if (isset($groups[$groupName]))
		continue;

	$groups[$groupName] = array(
                'count' => 0,
                'memory' => 0,
                'cpu' => 0,
                'time' => 0
        );
}

//check args
if(!isset($argv) || !isset($argv[0])) {
	die("Error: No Plugin name provided\n");
}
$fileCalled = basename($argv[0]);
$isConfig = isset($argv[1]) && $argv[1] == 'config';


//compatibility
$fileCalled=str_replace("php52","php",$fileCalled);

//which plugin?
switch ($fileCalled) {
// ------------------------------------------------------		
	case 'php-fpm-memory':
// ------------------------------------------------------
		$elements = array();
		foreach ($groups as $name=>$array) {
			$ramMb = ($array['count']==0) ? 0 : $array['memory'] / $array['count'];
			$label = 'Pool ' . $name;
			$elements[$name] = array(
				'label'	=>	$label,
				'type'	=>	'GAUGE',
				'value'	=>	$ramMb
			);
		}
		$config = array(
			'params' => array(
				'graph_title' => 'PHP-FPM Average Process Memory',
				'graph_vlabel' => 'MB'
			),
			'elements'	=>	$elements
		);	
		break;
// ------------------------------------------------------		
	case 'php-fpm-cpu':
// ------------------------------------------------------
		$elements = array();
		foreach ($groups as $name=>$array) {
			$cpu = $array['cpu'];
			$label = 'Pool ' . $name;
			$elements[$name] = array(
				'label'	=>	$label,
				'type'	=>	'GAUGE',
				'value'	=>	$cpu
			);
		}
		$config = array(
			'params' => array(
				'graph_title' => 'PHP-FPM CPU',
				'graph_vlabel' => '%',
				'graph_scale' => 'no'
			),
			'elements'	=>	$elements
		);	
		break;
// ------------------------------------------------------		
	case 'php-fpm-count':
// ------------------------------------------------------
		$elements = array();
		foreach ($groups as $name=>$array) {
			$label = 'Pool ' . $name;
			$elements[$name] = array(
				'label'	=>	$label,
				'type'	=>	'GAUGE',
				'value'	=>	$array['count']
			);
		}
		$config = array(
			'params' => array(
				'graph_title' => 'PHP-FPM Processes',
				'graph_vlabel' => 'processes'
			),
			'elements'	=>	$elements
		);	
		break;
// ------------------------------------------------------		
	case 'php-fpm-time':
// ------------------------------------------------------
		$elements = array();
		foreach ($groups as $name=>$array) {
			$time = ($array['count']==0) ? 0 : round($array['time'] / $array['count']);
			$label = 'Pool ' . $name;
			$elements[$name] = array(
				'label'	=>	$label,
				'type'	=>	'GAUGE',
				'value'	=>	$time
			);
		}
		$config = array(
			'params' => array(
				'graph_title' => 'PHP-FPM Average Process Age',
				'graph_vlabel' => 'seconds',
				'graph_scale' => 'no'
			),
			'elements'	=>	$elements
		);	
		break;
// ------------------------------------------------------
	default:
		die("Error: Unrecognized Plugin name $fileCalled\n");
}

//output
ksort($config['elements']);
if ($isConfig) {
	//graph params
	echo "graph_category PHP-FPM\n";
	foreach($config['params'] as $key=>$value) {
		echo $key . ' ' . $value . "\n";
	}
	
	//element params
	foreach($config['elements'] as $element=>$data) {
		foreach ($data as $key=>$value) {
			if ($key == 'value') continue;
			echo $element . '.' . $key . ' ' . $value . "\n";
		}
	}
} else {
	//element values
	foreach ($config['elements'] as $pool=>$element) {
		echo $pool . '.value ' . $element['value'] . "\n";
	}
}

//functions
function timeToSeconds ($time) {
	$seconds = 0;
	
	//days
	$parts = explode('-', $time);
	if(count($parts) == 2) {
		$seconds += $parts[0] * 86400;
		$time = $parts[1];
	}
	
	//hours
	$parts = explode(':', $time);
	if(count($parts) == 3) {
		$seconds += array_shift($parts) * 3600;
	}
	
	//minutes/seconds
	$seconds += $parts[0] * 60 + $parts[1];
	return $seconds;
}
