<?= $parameters['service'] ?>:<?= PHP_EOL ?>
  <?= $parameters['entityName'] ?>:<?= PHP_EOL ?>
<?php foreach ($parameters['fields'] as $field): ?>
<?php
switch ($field['type']) {
case 'relation':
if ($field['isCollection']) {
$value = '[\'@' . $field['targetFixtureName'] . '\']';
} else {
$value = '\'@' . $field['targetFixtureName'] . '\'';
}
break;
case 'smallint':
$value = 1;
break;
case 'integer':
$value = 1;
break;
case 'boolean':
$value = 1;
break;
case 'datetime':
$value = '\'<(new \DateTime("now"))>\'';
break;
case 'date':
$value = '\'<(new \DateTime("now"))>\'';
break;
default:
$value = '\'\'';
}
?>
    <?= $field['fieldName'] ?>: <?= $value ?><?= PHP_EOL ?>
<?php endforeach; ?>
