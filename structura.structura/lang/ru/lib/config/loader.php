<?php

$MESS['STRUCTURA_LOADER_BLOCK_CODE_CONFLICT'] = 'Блок из папки «#FOLDER#» пропущен: код «#CODE#» уже занят блоком из папки «#EXISTING#». Код блока — это имя папки, и он обязан быть уникален по всему дереву. Пропущенный блок не показывается на формах, добавить его нельзя, а его сохранённые значения не видны нигде. Переименуйте одну из папок (значения переименованной адресованы старым кодом — перенесите их).';
$MESS['STRUCTURA_LOADER_GROUP_WRONG_TYPE'] = '#GROUP#/.group.php должен возвращать Structura\\Structura\\BlockGroup. Старый формат (строка или массив) больше не поддерживается: return (new BlockGroup(\'Имя\'))->sort(100)->unique();';
$MESS['STRUCTURA_LOADER_CONFIG_WRONG_TYPE'] = 'config.php должен возвращать Structura\\Structura\\Block';
$MESS['STRUCTURA_LOADER_BLOCK_ERROR'] = 'Блок «#CODE#» (#REL#): #ERROR#';
$MESS['STRUCTURA_LOADER_BLOCK_CODE_MISMATCH'] = 'Блок «#CODE#» (#REL#): код «#DECLARED#» из config.php игнорируется — идентичность блока задаёт имя папки, поэтому блок доступен как «#CODE#». Второй аргумент Block() кода не задаёт и оставлен только для совместимости: уберите его, а чтобы сменить код — переименуйте папку.';
