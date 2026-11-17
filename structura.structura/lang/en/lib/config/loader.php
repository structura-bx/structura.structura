<?php

$MESS['STRUCTURA_LOADER_BLOCK_CODE_CONFLICT'] = 'Block from folder «#FOLDER#» skipped: code «#CODE#» is already taken by the block from folder «#EXISTING#». A block code is its folder name and must be unique across the whole tree. The skipped block is not shown on forms, cannot be added, and its saved values are not visible anywhere. Rename one of the folders (values of the renamed folder are addressed by the old code — migrate them).';
$MESS['STRUCTURA_LOADER_GROUP_WRONG_TYPE'] = '#GROUP#/.group.php must return Structura\\Structura\\BlockGroup. The old format (a string or an array) is no longer supported: return (new BlockGroup(\'Name\'))->sort(100)->unique();';
$MESS['STRUCTURA_LOADER_CONFIG_WRONG_TYPE'] = 'config.php must return Structura\\Structura\\Block';
$MESS['STRUCTURA_LOADER_BLOCK_ERROR'] = 'Block «#CODE#» (#REL#): #ERROR#';
$MESS['STRUCTURA_LOADER_BLOCK_CODE_MISMATCH'] = 'Block «#CODE#» (#REL#): code «#DECLARED#» from config.php is ignored — block identity is defined by the folder name, so the block is available as «#CODE#». The second Block() argument does not set the code and is kept only for compatibility: remove it, and to change the code rename the folder.';
