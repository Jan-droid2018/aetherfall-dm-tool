<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Aetherfall\Formula\FormulaEngine;
use Aetherfall\Support\Json;

$engine = new FormulaEngine();
$texts = [];
foreach (['classes','creatures','bosses','spells'] as $area) {
    foreach (glob(BASE_PATH . "/json/{$area}/*.json") ?: [] as $file) {
        if (basename($file) === '_manifest.json') continue;
        $d = Json::decodeFile($file);
        if ($area === 'classes') foreach ($d['levels'] as $l) foreach ($l['abilities'] as $a) foreach (['attack_roll','calculation','dc','saving_throw'] as $k) if (!empty($a[$k])) $texts[] = [$area,$a['id'],$k,$a[$k]];
        if ($area === 'spells') foreach ($d['grades'] as $g) foreach ($g['spells'] as $a) foreach (['attack_roll','calculation','dc','saving_throw'] as $k) if (!empty($a[$k])) $texts[] = [$area,$a['id'],$k,$a[$k]];
        if (in_array($area,['creatures','bosses'],true)) foreach ($d['levels'] as $l) foreach ($l['actions']['actions'] as $a) foreach (['attack_or_dc','damage_effect'] as $k) if (!empty($a[$k])) $texts[] = [$area,$a['id'],$k,$a[$k]];
    }
}
$supported=0;$fallback=[];$missing=[];$dice=[];$expressions=[];
foreach ($texts as [$area,$id,$field,$text]) {
    $expression=$engine->extractMath((string)$text);
    if(!$expression){$fallback[]=[...[$area,$id,$field],$text,'kein isolierbarer mathematischer Ausdruck'];continue;}
    $expressions[$expression]=true;
    preg_match_all('/\b(?:\d*)[WwDd]\s*\d+\b/u',$expression,$dm);$diceValues=[];foreach($dm[0] as $notation){$notation=strtoupper(str_replace(' ','',$notation));$notation=str_replace('D','W',$notation);if(str_starts_with($notation,'W'))$notation='1'.$notation;$dice[$notation]=true;$diceValues[$notation]=1;}
    $first=$engine->evaluate($expression,['dice'=>$diceValues]);$variables=[];foreach($first['missing']??[] as $name){if(!str_starts_with($name,'Würfel ')){$variables[$name]=1;$missing[$name]=true;}}
    $second=$engine->evaluate($expression,['dice'=>$diceValues,'variables'=>$variables]);
    if($second['supported'])$supported++;else$fallback[]=[...[$area,$id,$field],$text,$second['error']??implode(', ',$second['missing']??[])];
}
echo 'Gefundene Formelfelder:       '.count($texts).PHP_EOL;
echo 'Unterschiedliche Ausdrücke:   '.count($expressions).PHP_EOL;
echo 'Mathematisch unterstützt:     '.$supported.PHP_EOL;
echo 'Manueller Fallback nötig:     '.count($fallback).PHP_EOL;
echo 'Würfelmuster:                 '.implode(', ',array_keys($dice)).PHP_EOL;
echo 'Variable Aliasse:             '.count($missing).PHP_EOL;
if($fallback){echo PHP_EOL."Erste 50 Fallback-Fälle:\n";foreach(array_slice($fallback,0,50)as$row)echo '- '.implode(' | ',array_map(static fn($v)=>preg_replace('/\s+/u',' ',(string)$v),$row)).PHP_EOL;}

