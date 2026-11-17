<?php

$MESS['STRUCTURA_HASFIELDS_DUPLICATE_CODE']   = 'Field code «#CODE#» is duplicated #WHERE#. Field codes must be unique within their level (the block itself, a Group, a Repeater row, a Flexible layout) — otherwise one value silently overwrites another. Different levels do not conflict: the same code in the block and inside a Repeater are different addresses.';
$MESS['STRUCTURA_HASFIELDS_DUPLICATE_TOP']    = 'at the top level of the block';
$MESS['STRUCTURA_HASFIELDS_DUPLICATE_INSIDE'] = 'inside «#OWNER#»';
$MESS['STRUCTURA_HASFIELDS_BAD_CODE']         = 'Invalid field code «#CODE#». Latin letters, digits and underscore are allowed, the first character must not be a digit. In particular, a dot is forbidden: it builds the flat field path (repeater.child), and a code with a dot breaks field addressing, validation error paths and row cloning.';
$MESS['STRUCTURA_HASFIELDS_NO_OPTIONS']       = 'Field «#CODE#» has no options: add ->options([...]). Without them the field renders empty space — one element per option, and there are no options.';
